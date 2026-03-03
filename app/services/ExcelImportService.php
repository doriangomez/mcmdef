<?php

function cartera_expected_headers(): array
{
    return [
        'nit',
        'nombre_cliente',
        'tipo_documento',
        'numero_documento',
        'fecha_emision',
        'fecha_vencimiento',
        'valor_original',
        'saldo_actual',
        'dias_mora',
        'periodo',
        'canal',
        'regional',
        'asesor_comercial',
        'ejecutivo_cartera',
        'uen',
        'marca',
    ];
}

function normalize_header_name(string $header): string
{
    $header = strtolower(trim($header));
    $header = str_replace([' ', '-', '/'], '_', $header);
    return preg_replace('/_+/', '_', $header) ?? $header;
}

function parse_input_file(string $path): array
{
    if (supports_xlsx_import() && class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        return $sheet->toArray(null, true, true, false);
    }

    return parse_csv_rows($path);
}

function supports_xlsx_import(): bool
{
    $vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }
    return class_exists('\PhpOffice\PhpSpreadsheet\IOFactory');
}

function parse_csv_rows(string $path): array
{
    $rows = [];
    if (!is_file($path)) {
        return $rows;
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return $rows;
    }

    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = ',';
    if ($firstLine !== false && substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
        $delimiter = ';';
    }

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rows[] = $data;
    }
    fclose($handle);
    return $rows;
}

function normalize_date_value(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (is_numeric($raw)) {
        $base = new DateTimeImmutable('1899-12-30');
        return $base->modify('+' . (int)$raw . ' days')->format('Y-m-d');
    }

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed->format('Y-m-d');
        }
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function normalize_decimal_value(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_numeric($value)) {
        return (float)$value;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $normalized = str_replace(['$', ' '], '', $raw);
    if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } elseif (strpos($normalized, ',') !== false) {
        $normalized = str_replace(',', '.', $normalized);
    }

    if (!is_numeric($normalized)) {
        return null;
    }

    return (float)$normalized;
}

function calculate_dias_mora(string $fechaVencimiento): int
{
    $due = new DateTimeImmutable($fechaVencimiento);
    $today = new DateTimeImmutable('today');
    if ($due > $today) {
        return 0;
    }
    return (int)$due->diff($today)->days;
}

function normalize_estado_compromiso(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $v = strtolower(trim($value));
    if ($v === '') {
        return null;
    }

    return in_array($v, ['pendiente', 'cumplido', 'incumplido'], true) ? $v : null;
}

function validate_cartera_rows(array $rows): array
{
    $expected = cartera_expected_headers();
    $errors = [];
    $records = [];

    if (empty($rows)) {
        return [
            'ok' => false,
            'errors' => [['fila' => 0, 'campo' => 'archivo', 'motivo' => 'Archivo vacío']],
            'headers' => $expected,
            'records' => [],
        ];
    }

    $headers = array_map(
        static fn($header): string => normalize_header_name((string)$header),
        array_pad($rows[0], count($expected), '')
    );

    if ($headers !== $expected) {
        $errors[] = [
            'fila' => 1,
            'campo' => 'columnas',
            'motivo' => 'Estructura inválida. Orden esperado: ' . implode(', ', $expected),
        ];
        return [
            'ok' => false,
            'errors' => $errors,
            'headers' => $expected,
            'records' => [],
        ];
    }

    $duplicateMap = [];
    $requiredFields = [
        'nit',
        'nombre_cliente',
        'tipo_documento',
        'numero_documento',
        'fecha_emision',
        'fecha_vencimiento',
        'saldo_actual',
    ];

    for ($i = 1, $total = count($rows); $i < $total; $i++) {
        $excelRow = $i + 1;
        $rowValues = array_pad($rows[$i], count($expected), '');
        $rowData = array_combine($expected, $rowValues);
        if ($rowData === false) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'fila', 'motivo' => 'No se pudo mapear la fila'];
            continue;
        }

        $isEmptyRow = true;
        foreach ($rowData as $cell) {
            if (trim((string)$cell) !== '') {
                $isEmptyRow = false;
                break;
            }
        }
        if ($isEmptyRow) {
            continue;
        }

        $errorCountBeforeRow = count($errors);

        foreach ($requiredFields as $field) {
            if (trim((string)$rowData[$field]) === '') {
                $errors[] = ['fila' => $excelRow, 'campo' => $field, 'motivo' => 'Campo crítico vacío'];
            }
        }

        $tipoDocumento = trim((string)$rowData['tipo_documento']);
        if (!in_array($tipoDocumento, ['Factura', 'NC'], true)) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'tipo_documento', 'motivo' => 'Valor permitido: Factura o NC'];
        }

        $fechaEmision = normalize_date_value($rowData['fecha_emision']);
        $fechaVencimiento = normalize_date_value($rowData['fecha_vencimiento']);
        if ($fechaEmision === null) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'fecha_emision', 'motivo' => 'Fecha inválida'];
        }
        if ($fechaVencimiento === null) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'fecha_vencimiento', 'motivo' => 'Fecha inválida'];
        }
        if ($fechaEmision !== null && $fechaVencimiento !== null && $fechaEmision > $fechaVencimiento) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'fecha_emision', 'motivo' => 'No puede ser mayor que fecha_vencimiento'];
        }

        $valorOriginal = normalize_decimal_value($rowData['valor_original']);
        $saldoActual = normalize_decimal_value($rowData['saldo_actual']);
        if ($valorOriginal === null) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'valor_original', 'motivo' => 'Valor numérico inválido'];
        }
        if ($saldoActual === null) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'saldo_actual', 'motivo' => 'Valor numérico inválido'];
        }

        $diasMora = null;
        if (trim((string)$rowData['dias_mora']) !== '') {
            if (!is_numeric($rowData['dias_mora'])) {
                $errors[] = ['fila' => $excelRow, 'campo' => 'dias_mora', 'motivo' => 'Debe ser numérico'];
            } else {
                $diasMora = (int)$rowData['dias_mora'];
            }
        }

        $key = trim((string)$rowData['nit']) . '|' . $tipoDocumento . '|' . trim((string)$rowData['numero_documento']);
        if (isset($duplicateMap[$key])) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'clave', 'motivo' => 'Duplicado en archivo por (nit+tipo+numero)'];
        }
        $duplicateMap[$key] = true;

        if (count($errors) > $errorCountBeforeRow) {
            continue;
        }

        $records[] = [
            'nit' => trim((string)$rowData['nit']),
            'nombre_cliente' => trim((string)$rowData['nombre_cliente']),
            'tipo_documento' => $tipoDocumento,
            'numero_documento' => trim((string)$rowData['numero_documento']),
            'fecha_emision' => $fechaEmision,
            'fecha_vencimiento' => $fechaVencimiento,
            'valor_original' => $valorOriginal ?? 0.0,
            'saldo_actual' => $saldoActual ?? 0.0,
            'dias_mora' => $diasMora,
            'periodo' => trim((string)$rowData['periodo']),
            'canal' => trim((string)$rowData['canal']),
            'regional' => trim((string)$rowData['regional']),
            'asesor_comercial' => trim((string)$rowData['asesor_comercial']),
            'ejecutivo_cartera' => trim((string)$rowData['ejecutivo_cartera']),
            'uen' => trim((string)$rowData['uen']),
            'marca' => trim((string)$rowData['marca']),
            'excel_row' => $excelRow,
        ];
    }

    if (empty($records)) {
        $errors[] = ['fila' => 0, 'campo' => 'archivo', 'motivo' => 'No se encontraron registros válidos para procesar'];
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'headers' => $expected,
        'records' => $records,
    ];
}

function persist_carga_errors(PDO $pdo, int $cargaId, array $errors): void
{
    if (empty($errors)) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO carga_errores (carga_id, fila_excel, campo, motivo, created_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    foreach ($errors as $error) {
        $stmt->execute([
            $cargaId,
            (int)($error['fila'] ?? 0),
            (string)($error['campo'] ?? 'general'),
            (string)($error['motivo'] ?? 'Error no especificado'),
        ]);
    }
}

function upsert_cliente(PDO $pdo, array $record): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO clientes (nit, nombre, canal, regional, asesor_comercial, ejecutivo_cartera, uen, marca, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           nombre = VALUES(nombre),
           canal = VALUES(canal),
           regional = VALUES(regional),
           asesor_comercial = VALUES(asesor_comercial),
           ejecutivo_cartera = VALUES(ejecutivo_cartera),
           uen = VALUES(uen),
           marca = VALUES(marca),
           updated_at = NOW()'
    );
    $stmt->execute([
        $record['nit'],
        $record['nombre_cliente'],
        $record['canal'] !== '' ? $record['canal'] : null,
        $record['regional'] !== '' ? $record['regional'] : null,
        $record['asesor_comercial'] !== '' ? $record['asesor_comercial'] : null,
        $record['ejecutivo_cartera'] !== '' ? $record['ejecutivo_cartera'] : null,
        $record['uen'] !== '' ? $record['uen'] : null,
        $record['marca'] !== '' ? $record['marca'] : null,
    ]);

    $clientLookup = $pdo->prepare('SELECT id FROM clientes WHERE nit = ? LIMIT 1');
    $clientLookup->execute([$record['nit']]);
    return (int)$clientLookup->fetchColumn();
}

function process_cartera_records(PDO $pdo, int $cargaId, array $records): array
{
    $newCount = 0;
    $updatedCount = 0;

    $findDoc = $pdo->prepare(
        'SELECT d.id
         FROM documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id
         WHERE c.nit = ? AND d.tipo_documento = ? AND d.numero_documento = ?
         LIMIT 1'
    );

    $upsertDoc = $pdo->prepare(
        'INSERT INTO documentos
         (cliente_id, tipo_documento, numero_documento, fecha_emision, fecha_vencimiento, valor_original, saldo_actual, dias_mora, periodo, estado_documento, id_carga_origen, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           fecha_emision = VALUES(fecha_emision),
           fecha_vencimiento = VALUES(fecha_vencimiento),
           valor_original = VALUES(valor_original),
           saldo_actual = VALUES(saldo_actual),
           dias_mora = VALUES(dias_mora),
           periodo = VALUES(periodo),
           estado_documento = VALUES(estado_documento),
           id_carga_origen = VALUES(id_carga_origen),
           updated_at = NOW()'
    );

    $insertSnapshot = $pdo->prepare(
        'INSERT INTO documentos_snapshot
         (carga_id, nit, nombre_cliente, tipo_documento, numero_documento, fecha_emision, fecha_vencimiento, valor_original, saldo_actual, dias_mora, periodo, canal, regional, asesor_comercial, ejecutivo_cartera, uen, marca, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    foreach ($records as $record) {
        $clienteId = upsert_cliente($pdo, $record);

        $diasMora = $record['dias_mora'];
        if ($diasMora === null) {
            $diasMora = calculate_dias_mora($record['fecha_vencimiento']);
        }

        $estadoDocumento = 'vigente';
        if ((float)$record['saldo_actual'] <= 0) {
            $estadoDocumento = 'cancelado';
        } elseif ($diasMora > 0) {
            $estadoDocumento = 'vencido';
        }

        $findDoc->execute([$record['nit'], $record['tipo_documento'], $record['numero_documento']]);
        $existingId = $findDoc->fetchColumn();
        if ($existingId) {
            $updatedCount++;
        } else {
            $newCount++;
        }

        $upsertDoc->execute([
            $clienteId,
            $record['tipo_documento'],
            $record['numero_documento'],
            $record['fecha_emision'],
            $record['fecha_vencimiento'],
            $record['valor_original'],
            $record['saldo_actual'],
            $diasMora,
            $record['periodo'] !== '' ? $record['periodo'] : null,
            $estadoDocumento,
            $cargaId,
        ]);

        $insertSnapshot->execute([
            $cargaId,
            $record['nit'],
            $record['nombre_cliente'],
            $record['tipo_documento'],
            $record['numero_documento'],
            $record['fecha_emision'],
            $record['fecha_vencimiento'],
            $record['valor_original'],
            $record['saldo_actual'],
            $diasMora,
            $record['periodo'] !== '' ? $record['periodo'] : null,
            $record['canal'] !== '' ? $record['canal'] : null,
            $record['regional'] !== '' ? $record['regional'] : null,
            $record['asesor_comercial'] !== '' ? $record['asesor_comercial'] : null,
            $record['ejecutivo_cartera'] !== '' ? $record['ejecutivo_cartera'] : null,
            $record['uen'] !== '' ? $record['uen'] : null,
            $record['marca'] !== '' ? $record['marca'] : null,
        ]);
    }

    return [
        'new_count' => $newCount,
        'updated_count' => $updatedCount,
    ];
}

function validate_duplicate_keys_in_db(PDO $pdo, array $records): array
{
    $errors = [];
    if (empty($records)) {
        return $errors;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id
         WHERE c.nit = ? AND d.tipo_documento = ? AND d.numero_documento = ?'
    );

    foreach ($records as $record) {
        $stmt->execute([
            $record['nit'],
            $record['tipo_documento'],
            $record['numero_documento'],
        ]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 1) {
            $errors[] = [
                'fila' => (int)$record['excel_row'],
                'campo' => 'clave',
                'motivo' => 'Duplicado en base de datos para (nit+tipo+numero)',
            ];
        }
    }

    return $errors;
}

function revert_last_carga(PDO $pdo, int $cargaId): array
{
    $cargaStmt = $pdo->prepare('SELECT * FROM cargas_cartera WHERE id = ? LIMIT 1');
    $cargaStmt->execute([$cargaId]);
    $carga = $cargaStmt->fetch();
    if (!$carga) {
        throw new RuntimeException('La carga indicada no existe.');
    }
    if ($carga['estado'] !== 'procesado') {
        throw new RuntimeException('Solo se pueden revertir cargas en estado procesado.');
    }

    $laterLoadsStmt = $pdo->prepare("SELECT COUNT(*) FROM cargas_cartera WHERE id > ? AND estado = 'procesado'");
    $laterLoadsStmt->execute([$cargaId]);
    if ((int)$laterLoadsStmt->fetchColumn() > 0) {
        throw new RuntimeException('Por seguridad, solo se permite revertir la última carga procesada.');
    }

    $snapshotsStmt = $pdo->prepare(
        'SELECT *
         FROM documentos_snapshot
         WHERE carga_id = ?
         ORDER BY id DESC'
    );
    $snapshotsStmt->execute([$cargaId]);
    $snapshots = $snapshotsStmt->fetchAll();

    $deleteDocStmt = $pdo->prepare(
        'DELETE d
         FROM documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id
         WHERE c.nit = ? AND d.tipo_documento = ? AND d.numero_documento = ?'
    );
    $previousSnapshotStmt = $pdo->prepare(
        'SELECT *
         FROM documentos_snapshot
         WHERE nit = ? AND tipo_documento = ? AND numero_documento = ? AND carga_id < ?
         ORDER BY carga_id DESC, id DESC
         LIMIT 1'
    );

    $restored = 0;
    $removed = 0;

    foreach ($snapshots as $snapshot) {
        $previousSnapshotStmt->execute([
            $snapshot['nit'],
            $snapshot['tipo_documento'],
            $snapshot['numero_documento'],
            $cargaId,
        ]);
        $previous = $previousSnapshotStmt->fetch();

        if ($previous) {
            $record = [
                'nit' => $previous['nit'],
                'nombre_cliente' => $previous['nombre_cliente'],
                'canal' => (string)($previous['canal'] ?? ''),
                'regional' => (string)($previous['regional'] ?? ''),
                'asesor_comercial' => (string)($previous['asesor_comercial'] ?? ''),
                'ejecutivo_cartera' => (string)($previous['ejecutivo_cartera'] ?? ''),
                'uen' => (string)($previous['uen'] ?? ''),
                'marca' => (string)($previous['marca'] ?? ''),
                'tipo_documento' => $previous['tipo_documento'],
                'numero_documento' => $previous['numero_documento'],
                'fecha_emision' => $previous['fecha_emision'],
                'fecha_vencimiento' => $previous['fecha_vencimiento'],
                'valor_original' => (float)$previous['valor_original'],
                'saldo_actual' => (float)$previous['saldo_actual'],
                'dias_mora' => (int)$previous['dias_mora'],
                'periodo' => (string)($previous['periodo'] ?? ''),
            ];
            $clienteId = upsert_cliente($pdo, $record);
            $estado = 'vigente';
            if ((float)$record['saldo_actual'] <= 0) {
                $estado = 'cancelado';
            } elseif ((int)$record['dias_mora'] > 0) {
                $estado = 'vencido';
            }

            $upsertDoc = $pdo->prepare(
                'INSERT INTO documentos
                 (cliente_id, tipo_documento, numero_documento, fecha_emision, fecha_vencimiento, valor_original, saldo_actual, dias_mora, periodo, estado_documento, id_carga_origen, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                   fecha_emision = VALUES(fecha_emision),
                   fecha_vencimiento = VALUES(fecha_vencimiento),
                   valor_original = VALUES(valor_original),
                   saldo_actual = VALUES(saldo_actual),
                   dias_mora = VALUES(dias_mora),
                   periodo = VALUES(periodo),
                   estado_documento = VALUES(estado_documento),
                   id_carga_origen = VALUES(id_carga_origen),
                   updated_at = NOW()'
            );
            $upsertDoc->execute([
                $clienteId,
                $record['tipo_documento'],
                $record['numero_documento'],
                $record['fecha_emision'],
                $record['fecha_vencimiento'],
                $record['valor_original'],
                $record['saldo_actual'],
                $record['dias_mora'],
                $record['periodo'] !== '' ? $record['periodo'] : null,
                $estado,
                (int)$previous['carga_id'],
            ]);
            $restored++;
            continue;
        }

        $deleteDocStmt->execute([
            $snapshot['nit'],
            $snapshot['tipo_documento'],
            $snapshot['numero_documento'],
        ]);
        $removed += $deleteDocStmt->rowCount();
    }

    return ['restored' => $restored, 'removed' => $removed];
}
