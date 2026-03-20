<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientService.php';

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
        'uens',
        'empleado_de_ventas',
        'regional',
        'nro_documento',
        'nro_ref_de_cliente',
        'tipo',
        'fecha_contabilizacion',
        'fecha_activacion',
        'fecha_vencimiento',
        'valor_documento',
        'saldo_pendiente',
        'moneda',
        'dias_vencido',
        // Se aceptan para compatibilidad de plantillas antiguas/nuevas,
        // pero siempre se ignoran durante el cálculo interno.
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
    ];
}

function cartera_expected_optional_headers(): array
{
    return [
        'direccion',
        'contacto',
        'telefono',
        'canal',
        'uens',
        'empleado_de_ventas',
        'regional',
        'nro_ref_de_cliente',
        'fecha_activacion',
        'dias_vencido',
    ];
}

function cartera_expected_calculated_headers(): array
{
    return [
        'actual',
        '1_30_dias',
        '31_60_dias',
        '61_90_dias',
        '91_180_dias',
        '181_360_dias',
        '361_dias',
    ];
}

function normalize_header_name(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }

    $normalized = mb_strtolower($raw, 'UTF-8');
    $normalized = strtr($normalized, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '+' => 'plus', '#' => 'numero']);
    $normalized = preg_replace('/[^a-z0-9]+/u', '_', $normalized) ?? $normalized;
    return trim($normalized, '_');
}

function cartera_header_aliases(): array
{
    return [
        'cuenta' => ['cuenta'],
        'cliente' => ['cliente'],
        'nit' => ['nit'],
        'direccion' => ['direccion'],
        'contacto' => ['contacto'],
        'telefono' => ['telefono'],
        'canal' => ['canal'],
        'uens' => ['uens', 'uen', 'u_e_n_s'],
        'empleado_de_ventas' => ['empleado_de_ventas', 'empleado_ventas', 'asesor', 'asesor_comercial'],
        'regional' => ['regional'],
        'nro_documento' => ['nro_documento', 'numero_documento', 'documento'],
        'nro_ref_de_cliente' => ['nro_ref_de_cliente', 'nro_ref_cliente', 'referencia_cliente', 'transaccion'],
        'tipo' => ['tipo', 'tipo_documento'],
        'fecha_contabilizacion' => ['fecha_contabilizacion', 'fecha_emision'],
        'fecha_activacion' => ['fecha_activacion', 'fecha_de_activacion', 'activation_date', 'fecha_ingreso_cliente'],
        'fecha_vencimiento' => ['fecha_vencimiento'],
        'valor_documento' => ['valor_documento', 'valor_original'],
        'saldo_pendiente' => ['saldo_pendiente', 'saldo_actual', 'saldo'],
        'moneda' => ['moneda'],
        'dias_vencido' => ['dias_vencido', 'dias_mora', 'dias_vencimiento'],
        'actual' => ['actual'],
        '1_30_dias' => ['1_30_dias', '1_30'],
        '31_60_dias' => ['31_60_dias', '31_60'],
        '61_90_dias' => ['61_90_dias', '61_90'],
        '91_180_dias' => ['91_180_dias', '91_180'],
        '181_360_dias' => ['181_360_dias', '181_360'],
        '361_dias' => ['361_dias', '361_plus_dias', '361_plus', 'mas_de_360_dias'],
    ];
}

function cartera_field_labels(): array
{
    return [
        'cuenta' => 'cuenta',
        'cliente' => 'cliente',
        'nit' => 'nit',
        'direccion' => 'direccion',
        'contacto' => 'contacto',
        'telefono' => 'telefono',
        'canal' => 'canal',
        'uens' => 'uens',
        'empleado_de_ventas' => 'empleado_de_ventas',
        'regional' => 'regional',
        'nro_documento' => 'nro_documento',
        'nro_ref_de_cliente' => 'transacción',
        'tipo' => 'tipo',
        'moneda' => 'moneda',
    ];
}

function cartera_text_field_limits(): array
{
    return [
        'cuenta' => 80,
        'cliente' => 180,
        'nit' => 30,
        'direccion' => 220,
        'contacto' => 120,
        'telefono' => 60,
        'canal' => 80,
        'uens' => 120,
        'empleado_de_ventas' => 120,
        'regional' => 80,
        'nro_documento' => 80,
        'nro_ref_de_cliente' => 255,
        'tipo' => 50,
        'moneda' => 12,
    ];
}

function validate_text_field_lengths(array &$errors, int $excelRow, array $rowData): void
{
    $labels = cartera_field_labels();

    foreach (cartera_text_field_limits() as $field => $maxLength) {
        $value = trim((string)($rowData[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $errors[] = build_validation_error(
                $excelRow,
                $labels[$field] ?? $field,
                $value,
                'Supera la longitud máxima permitida de ' . $maxLength . ' caracteres'
            );
        }
    }
}

function map_headers_by_name(array $headers): array
{
    $normalizedToIndex = [];
    foreach ($headers as $index => $header) {
        $normalized = normalize_header_name($header);
        if ($normalized !== '' && !array_key_exists($normalized, $normalizedToIndex)) {
            $normalizedToIndex[$normalized] = $index;
        }
    }

    $fieldMap = [];
    foreach (cartera_header_aliases() as $field => $aliases) {
        foreach ($aliases as $alias) {
            $key = normalize_header_name($alias);
            if (array_key_exists($key, $normalizedToIndex)) {
                $fieldMap[$field] = $normalizedToIndex[$key];
                break;
            }
        }
    }

    return $fieldMap;
}

function is_cartera_calculated_header(mixed $header): bool
{
    $normalized = normalize_header_name($header);
    if ($normalized === '') {
        return false;
    }

    foreach (cartera_expected_calculated_headers() as $field) {
        foreach (cartera_header_aliases()[$field] ?? [] as $alias) {
            if ($normalized === normalize_header_name($alias)) {
                return true;
            }
        }
    }

    if ($normalized === 'actual' || $normalized === 'corriente') {
        return true;
    }

    return (bool)preg_match('/^(?:\d+_\d+|\d+plus|\d+_plus|mas_de_\d+|\d+_o_mas)(?:_dias)?$/', $normalized);
}

function validate_cartera_file_structure(array $rows): array
{
    $expected = cartera_expected_headers();
    $required = cartera_expected_required_headers();
    $optional = cartera_expected_optional_headers();
    $aliases = cartera_header_aliases();

    if (empty($rows)) {
        return [
            'ok' => false,
            'template_mismatch' => false,
            'errors' => [build_validation_error(0, 'archivo', '', 'Archivo vacío')],
            'headers' => $expected,
            'map' => [],
        ];
    }

    if (count($rows) < 2) {
        return [
            'ok' => false,
            'template_mismatch' => false,
            'errors' => [build_validation_error(0, 'archivo', '', 'El archivo debe incluir al menos encabezado y una fila de datos')],
            'headers' => $expected,
            'map' => [],
        ];
    }

    $headers = is_array($rows[0]) ? $rows[0] : [];
    $nonEmptyHeaders = array_values(array_filter(
        array_map(static fn(mixed $header): string => trim((string)$header), $headers),
        static fn(string $header): bool => $header !== ''
    ));

    if (empty($nonEmptyHeaders)) {
        return [
            'ok' => false,
            'template_mismatch' => false,
            'errors' => [build_validation_error(0, 'encabezado', '', 'La primera fila del archivo está vacía. Debe contener los encabezados de la plantilla de cartera.')],
            'headers' => $expected,
            'map' => [],
        ];
    }

    $map = map_headers_by_name($headers);
    $recognizedHeaders = array_keys($map);
    $missingRequired = array_values(array_diff($required, $recognizedHeaders));

    $allowedHeaderNames = ['numero' => true];
    foreach (array_merge($required, $optional, cartera_expected_calculated_headers()) as $field) {
        foreach ($aliases[$field] ?? [] as $alias) {
            $allowedHeaderNames[normalize_header_name($alias)] = true;
        }
    }

    $unexpectedHeaders = [];
    foreach ($nonEmptyHeaders as $header) {
        $normalizedHeader = normalize_header_name($header);
        if ($normalizedHeader === '' || isset($allowedHeaderNames[$normalizedHeader]) || is_cartera_calculated_header($header)) {
            continue;
        }
        $unexpectedHeaders[] = $header;
    }

    $recognizedRequired = array_values(array_intersect($required, $recognizedHeaders));
    $templateMismatch = empty($recognizedHeaders)
        || (count($recognizedRequired) < 4 && !empty($unexpectedHeaders))
        || (count($recognizedHeaders) < 5 && count($nonEmptyHeaders) >= 5);

    if ($templateMismatch) {
        return [
            'ok' => false,
            'template_mismatch' => true,
            'errors' => [build_validation_error(0, 'archivo', '', 'El archivo cargado no corresponde a la plantilla de cartera.')],
            'headers' => $expected,
            'map' => [],
        ];
    }

    $errors = [];
    if (!empty($missingRequired)) {
        $errors[] = build_validation_error(
            1,
            'encabezado',
            implode(', ', $missingRequired),
            'Faltan columnas obligatorias en la plantilla: ' . implode(', ', $missingRequired)
        );
    }

    if (!empty($unexpectedHeaders)) {
        $errors[] = build_validation_error(
            1,
            'encabezado',
            implode(', ', $unexpectedHeaders),
            'La estructura del archivo contiene columnas no reconocidas por la plantilla de cartera: ' . implode(', ', $unexpectedHeaders)
        );
    }

    return [
        'ok' => empty($errors),
        'template_mismatch' => false,
        'errors' => $errors,
        'headers' => $expected,
        'map' => $map,
    ];
}

function calculate_bucket_values(int $diasVencido, float $saldoPendiente): array
{
    $buckets = ['bucket_actual' => 0.0, 'bucket_1_30' => 0.0, 'bucket_31_60' => 0.0, 'bucket_61_90' => 0.0, 'bucket_91_180' => 0.0, 'bucket_181_360' => 0.0, 'bucket_361_plus' => 0.0];
    if ($diasVencido <= 0) {
        $buckets['bucket_actual'] = $saldoPendiente;
    } elseif ($diasVencido <= 30) {
        $buckets['bucket_1_30'] = $saldoPendiente;
    } elseif ($diasVencido <= 60) {
        $buckets['bucket_31_60'] = $saldoPendiente;
    } elseif ($diasVencido <= 90) {
        $buckets['bucket_61_90'] = $saldoPendiente;
    } elseif ($diasVencido <= 180) {
        $buckets['bucket_91_180'] = $saldoPendiente;
    } elseif ($diasVencido <= 360) {
        $buckets['bucket_181_360'] = $saldoPendiente;
    } else {
        $buckets['bucket_361_plus'] = $saldoPendiente;
    }

    return $buckets;
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

function normalize_document_type(string $tipo): string
{
    return mb_strtoupper(trim($tipo), 'UTF-8');
}

function build_documento_uid(string $tipo, string $nroDocumento): string
{
    return normalize_document_type($tipo) . '-' . trim($nroDocumento);
}

function classify_financial_document_type(string $tipo): string
{
    $normalized = normalize_document_type($tipo);
    if (in_array($normalized, ['NCN', 'NCI'], true)) {
        return 'nota_credito';
    }
    if ($normalized === 'RC') {
        return 'recibo';
    }
    if ($normalized === 'AC') {
        return 'ajuste';
    }
    if (in_array($normalized, ['FA', 'POS'], true)) {
        return 'factura';
    }

    return 'factura';
}

function validate_cartera_rows(array $rows): array
{
    $expected = cartera_expected_headers();
    $structure = validate_cartera_file_structure($rows);
    if (!($structure['ok'] ?? false)) {
        return [
            'ok' => false,
            'structural_error' => true,
            'template_mismatch' => (bool)($structure['template_mismatch'] ?? false),
            'errors' => $structure['errors'] ?? [],
            'headers' => $expected,
            'records' => [],
            'totals' => ['saldo' => 0.0, 'buckets' => 0.0, 'documentos' => 0],
        ];
    }

    $map = $structure['map'] ?? [];

    $errors = [];
    $records = [];
    $totalSaldoGlobal = 0.0;
    $totalBucketsGlobal = 0.0;
    $totalDocumentos = 0;
    $required = cartera_expected_required_headers();
    $duplicateMap = [];
    // Los buckets de vencimiento que vengan desde Excel se ignoran por diseño.
    $numericFields = ['valor_documento', 'saldo_pendiente'];

    for ($i = 1; $i < count($rows); $i++) {
        $excelRow = $i + 1;
        $currentRow = is_array($rows[$i]) ? $rows[$i] : [];
        $rowData = [];
        foreach ($expected as $field) {
            if ($field === '#') {
                continue;
            }
            $rowData[$field] = isset($map[$field]) ? ($currentRow[$map[$field]] ?? '') : '';
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

        validate_text_field_lengths($errors, $excelRow, $rowData);

        $fechaCont = normalize_date_value($rowData['fecha_contabilizacion']);
        $fechaAct = normalize_date_value($rowData['fecha_activacion']);
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
        $diasVencido = null;
        if (trim((string)$rowData['dias_vencido']) !== '') {
            if (!is_numeric($rowData['dias_vencido'])) {
                $errors[] = build_validation_error($excelRow, 'dias_vencido', $rowData['dias_vencido'], 'Debe ser numérico');
            } else {
                $diasVencido = (int)$rowData['dias_vencido'];
            }
        }

        $diasBase = $diasVencido;
        if ($diasBase === null && $fechaVen !== null) {
            $diasBase = calculate_dias_mora($fechaVen);
            $diasVencido = $diasBase;
        }

        $bucketActual = 0.0;
        $bucket1_30 = 0.0;
        $bucket31_60 = 0.0;
        $bucket61_90 = 0.0;
        $bucket91_180 = 0.0;
        $bucket181_360 = 0.0;
        $bucket361Plus = 0.0;

        if ($saldoPend !== null && $diasBase !== null) {
            $calculatedBuckets = calculate_bucket_values($diasBase, $saldoPend);
            $bucketActual = $calculatedBuckets['bucket_actual'];
            $bucket1_30 = $calculatedBuckets['bucket_1_30'];
            $bucket31_60 = $calculatedBuckets['bucket_31_60'];
            $bucket61_90 = $calculatedBuckets['bucket_61_90'];
            $bucket91_180 = $calculatedBuckets['bucket_91_180'];
            $bucket181_360 = $calculatedBuckets['bucket_181_360'];
            $bucket361Plus = $calculatedBuckets['bucket_361_plus'];
        }

        $sumBuckets = $bucketActual + $bucket1_30 + $bucket31_60 + $bucket61_90 + $bucket91_180 + $bucket181_360 + $bucket361Plus;

        if ($saldoPend !== null) {
            $totalSaldoGlobal += $saldoPend;
            $totalBucketsGlobal += $sumBuckets;
            $totalDocumentos++;
            if (round($sumBuckets, 2) !== round($saldoPend, 2)) {
                $errors[] = build_validation_error($excelRow, 'buckets', $rowData['saldo_pendiente'], 'Fila ' . $excelRow . ': La suma de buckets no coincide con el saldo pendiente');
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
            'uens' => trim((string)$rowData['uens']),
            'empleado_ventas' => trim((string)$rowData['empleado_de_ventas']),
            'regional' => trim((string)$rowData['regional']),
            'nro_documento' => trim((string)$rowData['nro_documento']),
            'nro_ref_cliente' => trim((string)$rowData['nro_ref_de_cliente']),
            'tipo' => normalize_document_type((string)$rowData['tipo']),
            'documento_uid' => build_documento_uid((string)$rowData['tipo'], (string)$rowData['nro_documento']),
            'tipo_documento_financiero' => classify_financial_document_type((string)$rowData['tipo']),
            'fecha_contabilizacion' => $fechaCont,
            'fecha_activacion' => $fechaAct ?? $fechaCont ?? date('Y-m-d'),
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
        'template_mismatch' => false,
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
    return upsert_master_client($pdo, $record);
}

function build_document_batch_values(array $batch, int $cargaId): array
{
    $placeholders = [];
    $params = [];

    foreach ($batch as $record) {
        $diasVencido = $record['dias_vencido'] ?? calculate_dias_mora((string)$record['fecha_vencimiento']);
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
        $params[] = $cargaId;
        $params[] = (int)$record['cliente_id'];
        $params[] = $record['cuenta'];
        $params[] = $record['cliente'];
        $params[] = $record['canal'] !== '' ? $record['canal'] : null;
        $params[] = $record['uens'] !== '' ? $record['uens'] : null;
        $params[] = $record['regional'] !== '' ? $record['regional'] : null;
        $params[] = $record['nro_documento'];
        $params[] = $record['nro_ref_cliente'] !== '' ? $record['nro_ref_cliente'] : null;
        $params[] = $record['tipo'];
        $params[] = $record['documento_uid'];
        $params[] = $record['tipo_documento_financiero'];
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
    ensure_client_management_schema($pdo);

    $insertedCount = 0;
    $updatedCount = 0;
    $closedCount = 0;
    $batchSize = 1000;
    $batch = [];

    $activeByKey = load_active_documents_by_logical_key($pdo);
    $inactiveLookupStmt = $pdo->prepare('SELECT id, cliente_id, saldo_pendiente, nro_documento FROM cartera_documentos WHERE cuenta = ? AND nro_documento = ? AND tipo = ? ORDER BY id DESC LIMIT 1');
    $updateStmt = build_update_document_statement($pdo);
    $idsToClose = [];
    $loadAggregates = [];
    $eventDate = date('Y-m-d H:i:s');

    foreach ($records as $record) {
        $record['cliente_id'] = upsert_cliente($pdo, $record);
        if (!isset($loadAggregates[$record['cliente_id']])) {
            $loadAggregates[$record['cliente_id']] = ['documentos' => 0, 'valor' => 0.0, 'periodos' => []];
        }
        $loadAggregates[$record['cliente_id']]['documentos']++;
        $loadAggregates[$record['cliente_id']]['valor'] += (float)($record['saldo_pendiente'] ?? 0);
        $periodo = substr((string)($record['fecha_contabilizacion'] ?? ''), 0, 7);
        if ($periodo !== '') {
            $loadAggregates[$record['cliente_id']]['periodos'][] = $periodo;
        }

        $key = build_document_logical_key($record);

        if (isset($activeByKey[$key])) {
            $entry = $activeByKey[$key];
            register_client_document_adjustment(
                $pdo,
                (int)$record['cliente_id'],
                (int)$entry['primary_id'],
                (string)($record['nro_documento'] ?? ''),
                (float)($entry['saldo_pendiente'] ?? 0),
                (float)($record['saldo_pendiente'] ?? 0),
                $cargaId,
                $eventDate
            );
            $updatedCount += update_existing_document($updateStmt, $record, $cargaId, (int)$entry['primary_id']);
            if (!empty($entry['duplicate_ids'])) {
                $idsToClose = array_merge($idsToClose, $entry['duplicate_ids']);
            }
            unset($activeByKey[$key]);
            continue;
        }

        $inactiveLookupStmt->execute([$record['cuenta'], $record['nro_documento'], $record['tipo']]);
        $inactiveRow = $inactiveLookupStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($inactiveRow !== null) {
            register_client_document_adjustment(
                $pdo,
                (int)$record['cliente_id'],
                (int)$inactiveRow['id'],
                (string)($record['nro_documento'] ?? ''),
                (float)($inactiveRow['saldo_pendiente'] ?? 0),
                (float)($record['saldo_pendiente'] ?? 0),
                $cargaId,
                $eventDate
            );
            $updatedCount += update_existing_document($updateStmt, $record, $cargaId, (int)$inactiveRow['id']);
            continue;
        }

        $batch[] = $record;
        if (count($batch) === $batchSize) {
            $insertedCount += insert_document_batch($pdo, $cargaId, $batch);
            $batch = [];
        }
    }

    if (!empty($batch)) {
        $insertedCount += insert_document_batch($pdo, $cargaId, $batch);
    }

    foreach ($activeByKey as $entry) {
        $idsToClose[] = (int)$entry['primary_id'];
        if (!empty($entry['duplicate_ids'])) {
            $idsToClose = array_merge($idsToClose, $entry['duplicate_ids']);
        }
    }

    if (!empty($idsToClose)) {
        $closedCount = close_documents_by_ids($pdo, $idsToClose, 'recaudado_cerrado_por_no_aparecer_en_corte');
    }

    register_client_load_events($pdo, $cargaId, $eventDate, $loadAggregates);

    return ['new_count' => $insertedCount, 'updated_count' => $updatedCount, 'closed_count' => $closedCount];
}

function load_active_documents_by_logical_key(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, cliente_id, cuenta, nro_documento, tipo, saldo_pendiente FROM cartera_documentos WHERE estado_documento = 'activo' ORDER BY id DESC");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $indexed = [];
    foreach ($rows as $row) {
        $key = build_document_logical_key($row);
        if (!isset($indexed[$key])) {
            $indexed[$key] = [
                'primary_id' => (int)$row['id'],
                'cliente_id' => (int)($row['cliente_id'] ?? 0),
                'saldo_pendiente' => (float)($row['saldo_pendiente'] ?? 0),
                'duplicate_ids' => [],
            ];
            continue;
        }

        $indexed[$key]['duplicate_ids'][] = (int)$row['id'];
    }

    return $indexed;
}

function build_update_document_statement(PDO $pdo): PDOStatement
{
    return $pdo->prepare(
        'UPDATE cartera_documentos
         SET id_carga = ?,
             cliente_id = ?,
             cuenta = ?,
             cliente = ?,
             canal = ?,
             uens = ?,
             regional = ?,
             nro_documento = ?,
             nro_ref_cliente = ?,
             tipo = ?,
             documento_uid = ?,
             tipo_documento_financiero = ?,
             fecha_contabilizacion = ?,
             fecha_vencimiento = ?,
             valor_documento = ?,
             saldo_pendiente = ?,
             moneda = ?,
             dias_vencido = ?,
             bucket_actual = ?,
             bucket_1_30 = ?,
             bucket_31_60 = ?,
             bucket_61_90 = ?,
             bucket_91_180 = ?,
             bucket_181_360 = ?,
             bucket_361_plus = ?,
             estado_documento = ?,
             estado_documento_detalle = ?
         WHERE id = ?'
    );
}

function update_existing_document(PDOStatement $stmt, array $record, int $cargaId, int $documentId): int
{
    $diasVencido = $record['dias_vencido'] ?? calculate_dias_mora((string)$record['fecha_vencimiento']);
    $stmt->execute([
        $cargaId,
        (int)$record['cliente_id'],
        $record['cuenta'],
        $record['cliente'],
        $record['canal'] !== '' ? $record['canal'] : null,
        $record['uens'] !== '' ? $record['uens'] : null,
        $record['regional'] !== '' ? $record['regional'] : null,
        $record['nro_documento'],
        $record['nro_ref_cliente'] !== '' ? $record['nro_ref_cliente'] : null,
        $record['tipo'],
        $record['documento_uid'],
        $record['tipo_documento_financiero'],
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
        'activo',
        null,
        $documentId,
    ]);

    return $stmt->rowCount() > 0 ? 1 : 0;
}

function close_documents_by_ids(PDO $pdo, array $ids, string $detail): int
{
    ensure_client_management_schema($pdo);

    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) {
        return 0;
    }

    $fetchPlaceholders = implode(', ', array_fill(0, count($ids), '?'));
    $fetchStmt = $pdo->prepare('SELECT id, cliente_id, nro_documento, saldo_pendiente, id_carga FROM cartera_documentos WHERE id IN (' . $fetchPlaceholders . ')');
    $fetchStmt->execute($ids);
    $docs = $fetchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $affected = 0;
    $chunkSize = 1000;
    for ($offset = 0, $total = count($ids); $offset < $total; $offset += $chunkSize) {
        $chunk = array_slice($ids, $offset, $chunkSize);
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $sql = "UPDATE cartera_documentos SET estado_documento = 'inactivo', estado_documento_detalle = ? WHERE id IN ({$placeholders}) AND estado_documento = 'activo'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$detail], $chunk));
        $affected += $stmt->rowCount();
    }

    $eventDate = date('Y-m-d H:i:s');
    foreach ($docs as $doc) {
        register_client_document_removal(
            $pdo,
            (int)($doc['cliente_id'] ?? 0),
            (int)$doc['id'],
            (string)($doc['nro_documento'] ?? ''),
            (float)($doc['saldo_pendiente'] ?? 0),
            $detail,
            isset($doc['id_carga']) ? (int)$doc['id_carga'] : null,
            $eventDate
        );
    }

    return $affected;
}

function build_document_logical_key(array $record): string
{
    return implode('|', [
        mb_strtolower(trim((string)($record['cuenta'] ?? '')), 'UTF-8'),
        mb_strtolower(trim((string)($record['nro_documento'] ?? '')), 'UTF-8'),
        mb_strtolower(trim((string)($record['tipo'] ?? '')), 'UTF-8'),
    ]);
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
            uens,
            regional,
            nro_documento,
            nro_ref_cliente,
            tipo,
            documento_uid,
            tipo_documento_financiero,
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
    return [];
}


function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function revert_last_carga(PDO $pdo, int $cargaId): array
{
    $stmt = $pdo->prepare("UPDATE cartera_documentos SET estado_documento = 'inactivo', estado_documento_detalle = 'lote_anulado' WHERE id_carga = ? AND estado_documento = 'activo'");
    $stmt->execute([$cargaId]);
    return ['restored' => 0, 'removed' => $stmt->rowCount()];
}
