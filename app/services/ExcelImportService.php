<?php

declare(strict_types=1);

function cartera_expected_headers(): array
{
    return [
        '#',
        'cuenta',
        'cliente',
        'nit',
        'direccion',
        'contacto',
        'telefono',
        'canal',
        'empleado_de_ventas',
        'regional',
        'nro_documento',
        'nro_ref_de_cliente',
        'tipo',
        'fecha_contabilizacion',
        'fecha_vencimiento',
        'valor_documento',
        'saldo_pendiente',
        'moneda',
        'dias_vencido',
        'actual',
        '1_30_dias',
        '31_60_dias',
        '61_90_dias',
        '91_180_dias',
        '181_360_dias',
        '361_dias',
    ];
}

function cartera_expected_required_headers(): array
{
    return [
        'cuenta',
        'cliente',
        'nit',
        'nro_documento',
        'tipo',
        'fecha_contabilizacion',
        'fecha_vencimiento',
        'valor_documento',
        'saldo_pendiente',
        'moneda',
        'actual',
        '1_30_dias',
        '31_60_dias',
        '61_90_dias',
        '91_180_dias',
        '181_360_dias',
        '361_dias',
    ];
}

function normalize_header_name(string $header): string
{
    $header = trim($header);
    if ($header === '#') {
        return '#';
    }
    $header = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) ?: $header;
    $header = strtolower($header);
    $header = str_replace('+', ' ', $header);
    $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
    $header = trim((string)$header, '_');

    $aliases = [
        'cliente' => 'cliente',
        'empleado_de_ventas' => 'empleado_de_ventas',
        'dias_vencido' => 'dias_vencido',
        'actual' => 'actual',
        '1_30_dias' => '1_30_dias',
        '31_60_dias' => '31_60_dias',
        '61_90_dias' => '61_90_dias',
        '91_180_dias' => '91_180_dias',
        '181_360_dias' => '181_360_dias',
        '361_dias' => '361_dias',
        '361_mas_dias' => '361_dias',
        '361_plus_dias' => '361_dias',
        'nro_documento' => 'nro_documento',
        'fecha_contabilizacion' => 'fecha_contabilizacion',
        'fecha_vencimiento' => 'fecha_vencimiento',
    ];

    return $aliases[$header] ?? $header;
}

function supports_xlsx_import(): bool
{
    if (!class_exists('\Shuchkin\SimpleXLSX')) {
        $libraryPath = __DIR__ . '/../libraries/SimpleXLSX.php';
        if (is_file($libraryPath)) {
            require_once $libraryPath;
        }
    }

    return class_exists('\Shuchkin\SimpleXLSX');
}

function parse_input_file(string $path, string $extension = ''): array
{
    $extension = strtolower($extension);
    if (in_array($extension, ['xlsx', 'xls'], true)) {
        if (!supports_xlsx_import()) {
            throw new RuntimeException('No fue posible inicializar el lector SimpleXLSX embebido.');
        }

        if ($xlsx = \Shuchkin\SimpleXLSX::parse($path)) {
            return $xlsx->rows();
        }

        throw new RuntimeException(\Shuchkin\SimpleXLSX::parseError());
    }

    return parse_csv_rows($path);
}

function parse_csv_rows(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $rows = [];
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = ($firstLine !== false && substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

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

    if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
    if (!($dt instanceof DateTimeImmutable)) {
        return null;
    }

    $errors = DateTimeImmutable::getLastErrors();
    if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        return null;
    }

    return $dt->format('Y-m-d');
}

function approx_equal(float $a, float $b, float $epsilon = 0.01): bool
{
    return abs($a - $b) <= $epsilon;
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
    if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } elseif (str_contains($normalized, ',')) {
        $normalized = str_replace(',', '.', $normalized);
    }
    return is_numeric($normalized) ? (float)$normalized : null;
}


function build_validation_error(int $fila, string $campo, mixed $valor, string $motivo): array
{
    $valorTexto = is_scalar($valor) || $valor === null ? trim((string)($valor ?? '')) : json_encode($valor, JSON_UNESCAPED_UNICODE);
    return ['fila' => $fila, 'campo' => $campo, 'valor' => $valorTexto, 'motivo' => $motivo];
}

function calculate_dias_mora(string $fechaVencimiento): int
{
    $due = new DateTimeImmutable($fechaVencimiento);
    $today = new DateTimeImmutable('today');
    return $due > $today ? 0 : (int)$due->diff($today)->days;
}

function validate_cartera_rows(array $rows): array
{
    $expected = cartera_expected_headers();
    if (count($rows) < 2) {
        return ['ok' => false, 'errors' => [build_validation_error(0, 'archivo', '', 'El archivo debe incluir al menos encabezado y una fila de datos')], 'headers' => $expected, 'records' => []];
    }

    if (empty($rows)) {
        return ['ok' => false, 'errors' => [build_validation_error(0, 'archivo', '', 'Archivo vacío')], 'headers' => $expected, 'records' => []];
    }

    $headers = array_map(static fn($h): string => normalize_header_name((string)$h), $rows[0]);
    $requiredHeaders = cartera_expected_required_headers();
    foreach ($requiredHeaders as $col) {
        if (!in_array($col, $headers, true)) {
            return ['ok' => false, 'errors' => [build_validation_error(1, 'columnas', $col, 'Falta la columna obligatoria: ' . $col)], 'headers' => $expected, 'records' => []];
        }
    }

    $errors = [];
    $records = [];
    $required = cartera_expected_required_headers();
    $duplicateMap = [];
    $numericFields = ['valor_documento', 'saldo_pendiente', 'actual', '1_30_dias', '31_60_dias', '61_90_dias', '91_180_dias', '181_360_dias', '361_dias'];

    for ($i = 1; $i < count($rows); $i++) {
        $excelRow = $i + 1;
        $normalizedRow = array_pad($rows[$i], count($headers), '');
        $byHeader = [];
        foreach ($headers as $idx => $headerName) {
            $byHeader[$headerName] = $normalizedRow[$idx] ?? '';
        }

        $rowData = [];
        foreach ($expected as $headerName) {
            $rowData[$headerName] = $byHeader[$headerName] ?? '';
        }

        if (count(array_filter($rowData, static fn($v): bool => trim((string)$v) !== '')) === 0) {
            $errors[] = build_validation_error($excelRow, 'fila', '', 'No se permiten filas totalmente vacías');
            continue;
        }

        $before = count($errors);
        foreach ($required as $field) {
            if (trim((string)$rowData[$field]) === '') {
                $errors[] = build_validation_error($excelRow, $field, $rowData[$field], 'Campo crítico vacío');
            }
        }

        $fechaCont = normalize_date_value($rowData['fecha_contabilizacion']);
        $fechaVen = normalize_date_value($rowData['fecha_vencimiento']);
        if ($fechaVen === null) {
            $errors[] = build_validation_error($excelRow, 'fecha_vencimiento', $rowData['fecha_vencimiento'], 'Fecha inválida. Formato requerido: dd/mm/yyyy');
        }

        if ($fechaCont === null) {
            $errors[] = build_validation_error($excelRow, 'fecha_contabilizacion', $rowData['fecha_contabilizacion'], 'Fecha inválida. Formato requerido: dd/mm/yyyy');
        }

        if ($fechaCont !== null && $fechaVen !== null && $fechaVen < $fechaCont) {
            $errors[] = build_validation_error($excelRow, 'fecha_vencimiento', $rowData['fecha_vencimiento'], 'Debe ser mayor o igual a fecha_contabilizacion');
        }

        foreach ($numericFields as $numericField) {
            if (trim((string)$rowData[$numericField]) !== '' && normalize_decimal_value($rowData[$numericField]) === null) {
                $errors[] = build_validation_error($excelRow, $numericField, $rowData[$numericField], 'Valor numérico inválido');
            }
        }

        $valorDoc = normalize_decimal_value($rowData['valor_documento']);
        $saldoPend = normalize_decimal_value($rowData['saldo_pendiente']);
        if ($valorDoc !== null && $valorDoc < 0) {
            $errors[] = build_validation_error($excelRow, 'valor_documento', $rowData['valor_documento'], 'Valores negativos no permitidos');
        }
        if ($saldoPend !== null && $saldoPend < 0) {
            $errors[] = build_validation_error($excelRow, 'saldo_pendiente', $rowData['saldo_pendiente'], 'Valores negativos no permitidos');
        }
        $bucketActual = normalize_decimal_value($rowData['actual']) ?? 0.0;
        $bucket1_30 = normalize_decimal_value($rowData['1_30_dias']) ?? 0.0;
        $bucket31_60 = normalize_decimal_value($rowData['31_60_dias']) ?? 0.0;
        $bucket61_90 = normalize_decimal_value($rowData['61_90_dias']) ?? 0.0;
        $bucket91_180 = normalize_decimal_value($rowData['91_180_dias']) ?? 0.0;
        $bucket181_360 = normalize_decimal_value($rowData['181_360_dias']) ?? 0.0;
        $bucket361Plus = normalize_decimal_value($rowData['361_dias']) ?? 0.0;

        foreach (['actual' => $bucketActual, '1_30_dias' => $bucket1_30, '31_60_dias' => $bucket31_60, '61_90_dias' => $bucket61_90, '91_180_dias' => $bucket91_180, '181_360_dias' => $bucket181_360, '361_dias' => $bucket361Plus] as $bucketField => $bucketValue) {
            if ($bucketValue < 0) {
                $errors[] = build_validation_error($excelRow, $bucketField, $rowData[$bucketField], 'Valores negativos no permitidos');
            }
        }

        if ($saldoPend !== null) {
            $sumBuckets = $bucketActual + $bucket1_30 + $bucket31_60 + $bucket61_90 + $bucket91_180 + $bucket181_360 + $bucket361Plus;
            if (!approx_equal($sumBuckets, $saldoPend)) {
                $errors[] = build_validation_error($excelRow, 'buckets', $rowData['saldo_pendiente'], 'Fila ' . $excelRow . ': La suma de buckets no coincide con el saldo pendiente');
            }
        }

        $diasVencido = null;
        if (trim((string)$rowData['dias_vencido']) !== '') {
            if (!is_numeric($rowData['dias_vencido'])) {
                $errors[] = build_validation_error($excelRow, 'dias_vencido', $rowData['dias_vencido'], 'Debe ser numérico');
            } else {
                $diasVencido = (int)$rowData['dias_vencido'];
            }
        }

        $key = implode('|', [trim((string)$rowData['cuenta']), trim((string)$rowData['nro_documento']), trim((string)$rowData['tipo'])]);
        if (isset($duplicateMap[$key])) {
            $errors[] = build_validation_error($excelRow, 'clave', $key, 'Duplicado en archivo por (cuenta+nro_documento+tipo)');
        }
        $duplicateMap[$key] = true;

        if (count($errors) > $before) {
            continue;
        }

        $records[] = [
            'cuenta' => trim((string)$rowData['cuenta']),
            'cliente' => trim((string)$rowData['cliente']),
            'nit' => trim((string)$rowData['nit']),
            'direccion' => trim((string)$rowData['direccion']),
            'contacto' => trim((string)$rowData['contacto']),
            'telefono' => trim((string)$rowData['telefono']),
            'canal' => trim((string)$rowData['canal']),
            'empleado_ventas' => trim((string)$rowData['empleado_de_ventas']),
            'regional' => trim((string)$rowData['regional']),
            'nro_documento' => trim((string)$rowData['nro_documento']),
            'nro_ref_cliente' => trim((string)$rowData['nro_ref_de_cliente']),
            'tipo' => trim((string)$rowData['tipo']),
            'fecha_contabilizacion' => $fechaCont,
            'fecha_vencimiento' => $fechaVen,
            'valor_documento' => $valorDoc ?? 0.0,
            'saldo_pendiente' => $saldoPend ?? 0.0,
            'moneda' => trim((string)$rowData['moneda']),
            'dias_vencido' => $diasVencido,
            'bucket_actual' => $bucketActual,
            'bucket_1_30' => $bucket1_30,
            'bucket_31_60' => $bucket31_60,
            'bucket_61_90' => $bucket61_90,
            'bucket_91_180' => $bucket91_180,
            'bucket_181_360' => $bucket181_360,
            'bucket_361_plus' => $bucket361Plus,
            'excel_row' => $excelRow,
        ];
    }

    return ['ok' => empty($errors), 'errors' => $errors, 'headers' => $expected, 'records' => $records];
}

function persist_carga_errors(PDO $pdo, int $cargaId, array $errors): void
{
    if (empty($errors)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO carga_errores (carga_id, fila_excel, campo, motivo, created_at) VALUES (?, ?, ?, ?, NOW())');
    foreach ($errors as $error) {
        $stmt->execute([$cargaId, (int)($error['fila'] ?? 0), (string)($error['campo'] ?? 'general'), (string)($error['motivo'] ?? 'Error no especificado')]);
    }
}

function upsert_cliente(PDO $pdo, array $record): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO clientes (cuenta, nombre, nit, direccion, contacto, telefono, canal, regional, empleado_ventas, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), direccion = VALUES(direccion), contacto = VALUES(contacto), telefono = VALUES(telefono), canal = VALUES(canal), regional = VALUES(regional), empleado_ventas = VALUES(empleado_ventas), updated_at = NOW()'
    );
    $stmt->execute([$record['cuenta'], $record['cliente'], $record['nit'], $record['direccion'] ?: null, $record['contacto'] ?: null, $record['telefono'] ?: null, $record['canal'] ?: null, $record['regional'] ?: null, $record['empleado_ventas'] ?: null]);

    $lookup = $pdo->prepare('SELECT id FROM clientes WHERE cuenta = ? LIMIT 1');
    $lookup->execute([$record['cuenta']]);
    return (int)$lookup->fetchColumn();
}

function process_cartera_records(PDO $pdo, int $cargaId, array $records): array
{
    $newCount = 0;
    $updatedCount = 0;

    $findDoc = $pdo->prepare('SELECT id FROM documentos_cartera WHERE cliente_id = ? AND nro_documento = ? AND tipo = ? AND fecha_contabilizacion = ? LIMIT 1');
    $upsertDoc = $pdo->prepare(
        'INSERT INTO documentos_cartera (cliente_id, nro_documento, nro_ref_cliente, tipo, fecha_contabilizacion, fecha_vencimiento, valor_documento, saldo_pendiente, moneda, dias_vencido, bucket_actual, bucket_1_30, bucket_31_60, bucket_61_90, bucket_91_180, bucket_181_360, bucket_361_plus, carga_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE nro_ref_cliente = VALUES(nro_ref_cliente), fecha_vencimiento = VALUES(fecha_vencimiento), valor_documento = VALUES(valor_documento), saldo_pendiente = VALUES(saldo_pendiente), moneda = VALUES(moneda), dias_vencido = VALUES(dias_vencido), bucket_actual = VALUES(bucket_actual), bucket_1_30 = VALUES(bucket_1_30), bucket_31_60 = VALUES(bucket_31_60), bucket_61_90 = VALUES(bucket_61_90), bucket_91_180 = VALUES(bucket_91_180), bucket_181_360 = VALUES(bucket_181_360), bucket_361_plus = VALUES(bucket_361_plus), carga_id = VALUES(carga_id), updated_at = NOW()'
    );

    foreach ($records as $record) {
        $clienteId = upsert_cliente($pdo, $record);
        $diasVencido = $record['dias_vencido'] ?? calculate_dias_mora((string)$record['fecha_vencimiento']);

        $findDoc->execute([$clienteId, $record['nro_documento'], $record['tipo'], $record['fecha_contabilizacion']]);
        if ($findDoc->fetchColumn()) {
            $updatedCount++;
        } else {
            $newCount++;
        }

        $upsertDoc->execute([
            $clienteId,
            $record['nro_documento'],
            $record['nro_ref_cliente'] !== '' ? $record['nro_ref_cliente'] : null,
            $record['tipo'],
            $record['fecha_contabilizacion'],
            $record['fecha_vencimiento'],
            $record['valor_documento'],
            $record['saldo_pendiente'],
            $record['moneda'],
            $diasVencido,
            $record['bucket_actual'],
            $record['bucket_1_30'],
            $record['bucket_31_60'],
            $record['bucket_61_90'],
            $record['bucket_91_180'],
            $record['bucket_181_360'],
            $record['bucket_361_plus'],
            $cargaId,
        ]);
    }

    return ['new_count' => $newCount, 'updated_count' => $updatedCount];
}

function validate_duplicate_keys_in_db(PDO $pdo, array $records): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documentos_cartera d INNER JOIN clientes c ON c.id = d.cliente_id WHERE c.cuenta = ? AND d.nro_documento = ? AND d.tipo = ? AND d.fecha_contabilizacion = ?');
    $errors = [];
    foreach ($records as $record) {
        $stmt->execute([$record['cuenta'], $record['nro_documento'], $record['tipo'], $record['fecha_contabilizacion']]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = build_validation_error((int)$record['excel_row'], 'clave', implode('|', [$record['cuenta'], $record['nro_documento'], $record['tipo'], $record['fecha_contabilizacion']]), 'Duplicado en base de datos para (cuenta+nro_documento+tipo+fecha_contabilizacion)');
        }
    }
    return $errors;
}

function revert_last_carga(PDO $pdo, int $cargaId): array
{
    $stmt = $pdo->prepare('DELETE FROM documentos_cartera WHERE carga_id = ?');
    $stmt->execute([$cargaId]);
    return ['restored' => 0, 'removed' => $stmt->rowCount()];
}
