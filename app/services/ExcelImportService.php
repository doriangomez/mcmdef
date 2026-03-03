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
    $columnasEsperadas = 26;
    $map = [
        'cuenta' => 1,
        'cliente' => 2,
        'nit' => 3,
        'direccion' => 4,
        'contacto' => 5,
        'telefono' => 6,
        'canal' => 7,
        'empleado_de_ventas' => 8,
        'regional' => 9,
        'nro_documento' => 10,
        'nro_ref_de_cliente' => 11,
        'tipo' => 12,
        'fecha_contabilizacion' => 13,
        'fecha_vencimiento' => 14,
        'valor_documento' => 15,
        'saldo_pendiente' => 16,
        'moneda' => 17,
        'dias_vencido' => 18,
        'actual' => 19,
        '1_30_dias' => 20,
        '31_60_dias' => 21,
        '61_90_dias' => 22,
        '91_180_dias' => 23,
        '181_360_dias' => 24,
        '361_dias' => 25,
    ];

    if (count($rows) < 2) {
        return ['ok' => false, 'structural_error' => true, 'errors' => [build_validation_error(0, 'archivo', '', 'El archivo debe incluir al menos encabezado y una fila de datos')], 'headers' => $expected, 'records' => [], 'totals' => ['saldo' => 0.0, 'buckets' => 0.0, 'documentos' => 0]];
    }

    if (empty($rows)) {
        return ['ok' => false, 'structural_error' => true, 'errors' => [build_validation_error(0, 'archivo', '', 'Archivo vacío')], 'headers' => $expected, 'records' => [], 'totals' => ['saldo' => 0.0, 'buckets' => 0.0, 'documentos' => 0]];
    }

    $headers = $rows[0];
    if (count($headers) !== $columnasEsperadas) {
        return [
            'ok' => false,
            'structural_error' => true,
            'errors' => [build_validation_error(1, 'columnas', count($headers), 'Error estructural: Se esperaban ' . $columnasEsperadas . ' columnas y se encontraron ' . count($headers))],
            'headers' => $expected,
            'records' => [],
            'totals' => ['saldo' => 0.0, 'buckets' => 0.0, 'documentos' => 0],
        ];
    }

    $errors = [];
    $records = [];
    $totalSaldoGlobal = 0.0;
    $totalBucketsGlobal = 0.0;
    $totalDocumentos = 0;
    $required = cartera_expected_required_headers();
    $duplicateMap = [];
    $numericFields = ['valor_documento', 'saldo_pendiente', 'actual', '1_30_dias', '31_60_dias', '61_90_dias', '91_180_dias', '181_360_dias', '361_dias'];

    for ($i = 1; $i < count($rows); $i++) {
        $excelRow = $i + 1;
        $normalizedRow = array_slice(array_pad($rows[$i], $columnasEsperadas, ''), 0, $columnasEsperadas);

        $rowData = ['#' => $normalizedRow[0] ?? ''];
        foreach ($map as $field => $columnIndex) {
            $rowData[$field] = $normalizedRow[$columnIndex] ?? '';
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


        foreach ($numericFields as $numericField) {
            if (trim((string)$rowData[$numericField]) !== '' && normalize_decimal_value($rowData[$numericField]) === null) {
                $errors[] = build_validation_error($excelRow, $numericField, $rowData[$numericField], 'Valor numérico inválido');
            }
        }

        $valorDoc = normalize_decimal_value($rowData['valor_documento']);
        $saldoPend = normalize_decimal_value($rowData['saldo_pendiente']);
        $bucketActual = normalize_decimal_value($rowData['actual']) ?? 0.0;
        $bucket1_30 = normalize_decimal_value($rowData['1_30_dias']) ?? 0.0;
        $bucket31_60 = normalize_decimal_value($rowData['31_60_dias']) ?? 0.0;
        $bucket61_90 = normalize_decimal_value($rowData['61_90_dias']) ?? 0.0;
        $bucket91_180 = normalize_decimal_value($rowData['91_180_dias']) ?? 0.0;
        $bucket181_360 = normalize_decimal_value($rowData['181_360_dias']) ?? 0.0;
        $bucket361Plus = normalize_decimal_value($rowData['361_dias']) ?? 0.0;
        $sumBuckets = $bucketActual + $bucket1_30 + $bucket31_60 + $bucket61_90 + $bucket91_180 + $bucket181_360 + $bucket361Plus;

        if ($saldoPend !== null) {
            $totalSaldoGlobal += $saldoPend;
            $totalBucketsGlobal += $sumBuckets;
            $totalDocumentos++;
        }

        if ($saldoPend !== null) {
            if (round($sumBuckets, 2) !== round($saldoPend, 2)) {
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

        $normalizedForHash = [];
        foreach ($rowData as $field => $value) {
            $normalizedForHash[$field] = trim((string)$value);
        }
        $key = md5((string)json_encode($normalizedForHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (isset($duplicateMap[$key])) {
            $errors[] = build_validation_error($excelRow, 'clave', $key, 'Duplicado en archivo por hash de fila completa');
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

    if (round($totalSaldoGlobal, 2) !== round($totalBucketsGlobal, 2)) {
        $errors[] = build_validation_error(0, 'global', '', 'Error global: La suma total de buckets no coincide con el total de saldo pendiente del archivo.');
    }
    if ($totalDocumentos === 0) {
        $errors[] = build_validation_error(0, 'global', '', 'Error global: El archivo no contiene documentos válidos.');
    }

    return [
        'ok' => empty($errors),
        'structural_error' => false,
        'errors' => $errors,
        'headers' => $expected,
        'records' => $records,
        'totals' => ['saldo' => $totalSaldoGlobal, 'buckets' => $totalBucketsGlobal, 'documentos' => $totalDocumentos],
    ];
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

function build_document_batch_values(array $batch, int $cargaId): array
{
    $placeholders = [];
    $params = [];

    foreach ($batch as $record) {
        $diasVencido = $record['dias_vencido'] ?? calculate_dias_mora((string)$record['fecha_vencimiento']);
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
        $params[] = $cargaId;
        $params[] = (int)$record['cliente_id'];
        $params[] = $record['cuenta'];
        $params[] = $record['cliente'];
        $params[] = $record['canal'] !== '' ? $record['canal'] : null;
        $params[] = $record['regional'] !== '' ? $record['regional'] : null;
        $params[] = $record['nro_documento'];
        $params[] = $record['nro_ref_cliente'] !== '' ? $record['nro_ref_cliente'] : null;
        $params[] = $record['tipo'];
        $params[] = $record['fecha_contabilizacion'];
        $params[] = $record['fecha_vencimiento'];
        $params[] = $record['valor_documento'];
        $params[] = $record['saldo_pendiente'];
        $params[] = $record['moneda'];
        $params[] = $diasVencido;
        $params[] = $record['bucket_actual'];
        $params[] = $record['bucket_1_30'];
        $params[] = $record['bucket_31_60'];
        $params[] = $record['bucket_61_90'];
        $params[] = $record['bucket_91_180'];
        $params[] = $record['bucket_181_360'];
        $params[] = $record['bucket_361_plus'];
        $params[] = 'activo';
        $params[] = null;
    }

    return ['placeholders' => $placeholders, 'params' => $params];
}

function process_cartera_records(PDO $pdo, int $cargaId, array $records): array
{
    $insertedCount = 0;
    $batchSize = 1000;
    $batch = [];

    foreach ($records as $record) {
        $record['cliente_id'] = upsert_cliente($pdo, $record);
        $batch[] = $record;

        if (count($batch) === $batchSize) {
            $insertedCount += insert_document_batch($pdo, $cargaId, $batch);
            $batch = [];
        }
    }

    if (!empty($batch)) {
        $insertedCount += insert_document_batch($pdo, $cargaId, $batch);
    }

    return ['new_count' => $insertedCount, 'updated_count' => 0];
}

function insert_document_batch(PDO $pdo, int $cargaId, array $batch): int
{
    if (empty($batch)) {
        return 0;
    }

    $payload = build_document_batch_values($batch, $cargaId);
    $sql = 'INSERT INTO cartera_documentos (
            id_carga,
            cliente_id,
            cuenta,
            cliente,
            canal,
            regional,
            nro_documento,
            nro_ref_cliente,
            tipo,
            fecha_contabilizacion,
            fecha_vencimiento,
            valor_documento,
            saldo_pendiente,
            moneda,
            dias_vencido,
            bucket_actual,
            bucket_1_30,
            bucket_31_60,
            bucket_61_90,
            bucket_91_180,
            bucket_181_360,
            bucket_361_plus,
            estado_documento,
            estado_documento_detalle,
            created_at
        ) VALUES ' . implode(', ', $payload['placeholders']);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($payload['params']);

    return count($batch);
}

function validate_duplicate_keys_in_db(PDO $pdo, array $records): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cartera_documentos d WHERE d.cuenta = ? AND d.nro_documento = ? AND d.tipo = ? AND d.fecha_contabilizacion = ? AND d.estado_documento = 'activo'");
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
    $stmt = $pdo->prepare("UPDATE cartera_documentos SET estado_documento = 'inactivo', estado_documento_detalle = 'lote_anulado' WHERE id_carga = ? AND estado_documento = 'activo'");
    $stmt->execute([$cargaId]);
    return ['restored' => 0, 'removed' => $stmt->rowCount()];
}
