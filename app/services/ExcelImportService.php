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
        '361_plus_dias',
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
    $header = str_replace('+', ' plus ', $header);
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
        '361_plus_dias' => '361_plus_dias',
    ];

    return $aliases[$header] ?? $header;
}

function supports_xlsx_import(): bool
{
    $vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }
    return class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory');
}

function parse_input_file(string $path): array
{
    if (supports_xlsx_import() && class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
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

function calculate_dias_mora(string $fechaVencimiento): int
{
    $due = new DateTimeImmutable($fechaVencimiento);
    $today = new DateTimeImmutable('today');
    return $due > $today ? 0 : (int)$due->diff($today)->days;
}

function validate_cartera_rows(array $rows): array
{
    $expected = cartera_expected_headers();
    if (empty($rows)) {
        return ['ok' => false, 'errors' => [['fila' => 0, 'campo' => 'archivo', 'motivo' => 'Archivo vacío']], 'headers' => $expected, 'records' => []];
    }

    if (count($rows[0]) !== count($expected)) {
        return ['ok' => false, 'errors' => [['fila' => 1, 'campo' => 'columnas', 'motivo' => 'Estructura inválida. Se esperaban exactamente 26 columnas']], 'headers' => $expected, 'records' => []];
    }

    $headers = array_map(static fn($h): string => normalize_header_name((string)$h), $rows[0]);
    if ($headers !== $expected) {
        return ['ok' => false, 'errors' => [['fila' => 1, 'campo' => 'columnas', 'motivo' => 'Estructura inválida. Orden esperado: ' . implode(', ', $expected)]], 'headers' => $expected, 'records' => []];
    }

    $errors = [];
    $records = [];
    $required = ['cuenta', 'cliente', 'nit', 'nro_documento', 'tipo', 'fecha_contabilizacion', 'fecha_vencimiento', 'valor_documento', 'saldo_pendiente', 'moneda'];
    $duplicateMap = [];
    $numericFields = ['valor_documento', 'saldo_pendiente', 'actual', '1_30_dias', '31_60_dias', '61_90_dias', '91_180_dias', '181_360_dias', '361_plus_dias'];

    for ($i = 1; $i < count($rows); $i++) {
        $excelRow = $i + 1;
        $rowData = array_combine($expected, array_pad($rows[$i], count($expected), ''));
        if ($rowData === false) {
            continue;
        }

        if (count(array_filter($rowData, static fn($v): bool => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $before = count($errors);
        foreach ($required as $field) {
            if (trim((string)$rowData[$field]) === '') {
                $errors[] = ['fila' => $excelRow, 'campo' => $field, 'motivo' => 'Campo crítico vacío'];
            }
        }

        $fechaCont = normalize_date_value($rowData['fecha_contabilizacion']);
        $fechaVen = normalize_date_value($rowData['fecha_vencimiento']);
        if ($fechaVen === null) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'fecha_vencimiento', 'motivo' => 'Fecha inválida. Formato requerido: dd/mm/yyyy'];
        }

        if ($fechaCont === null) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'fecha_contabilizacion', 'motivo' => 'Fecha inválida. Formato requerido: dd/mm/yyyy'];
        }

        if ($fechaCont !== null && $fechaVen !== null && $fechaVen < $fechaCont) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'fecha_vencimiento', 'motivo' => 'Debe ser mayor o igual a fecha_contabilizacion'];
        }

        foreach ($numericFields as $numericField) {
            if (trim((string)$rowData[$numericField]) !== '' && normalize_decimal_value($rowData[$numericField]) === null) {
                $errors[] = ['fila' => $excelRow, 'campo' => $numericField, 'motivo' => 'Valor numérico inválido'];
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
        $bucket361Plus = normalize_decimal_value($rowData['361_plus_dias']) ?? 0.0;

        if ($saldoPend !== null) {
            $sumBuckets = $bucketActual + $bucket1_30 + $bucket31_60 + $bucket61_90 + $bucket91_180 + $bucket181_360 + $bucket361Plus;
            if (!approx_equal($sumBuckets, $saldoPend)) {
                $errors[] = ['fila' => $excelRow, 'campo' => 'buckets', 'motivo' => 'La suma de buckets debe coincidir con saldo_pendiente'];
            }
        }

        $diasVencido = null;
        if (trim((string)$rowData['dias_vencido']) !== '') {
            if (!is_numeric($rowData['dias_vencido'])) {
                $errors[] = ['fila' => $excelRow, 'campo' => 'dias_vencido', 'motivo' => 'Debe ser numérico'];
            } else {
                $diasVencido = (int)$rowData['dias_vencido'];
            }
        }

        $key = implode('|', [trim((string)$rowData['cuenta']), trim((string)$rowData['nro_documento']), trim((string)$rowData['tipo']), (string)$fechaCont]);
        if (isset($duplicateMap[$key])) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'clave', 'motivo' => 'Duplicado en archivo por (cuenta+nro_documento+tipo+fecha_contabilizacion)'];
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
            $errors[] = ['fila' => (int)$record['excel_row'], 'campo' => 'clave', 'motivo' => 'Duplicado en base de datos para (cuenta+nro_documento+tipo+fecha_contabilizacion)'];
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
