<?php

declare(strict_types=1);

require_once __DIR__ . '/ExcelImportService.php';
require_once __DIR__ . '/PeriodoControlService.php';

function recaudo_log(string $message, array $context = []): void
{
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $message .= ' | ' . $json;
        }
    }

    error_log('[recaudo-simple] ' . $message);
}

function recaudo_header_aliases(): array
{
    return [
        'documento' => ['documento', 'numero_documento', 'nro_documento', 'documento_aplicado', 'nro_documento_aplicado', 'cedula', 'nit'],
        'valor_pagado' => ['valor_pagado', 'valor', 'importe_aplicado', 'importe', 'valor_aplicado', 'pago', 'valor_pago'],
        'fecha_pago' => ['fecha_pago', 'fecha', 'fecha_aplicacion', 'fecha_recibo'],
        'periodo' => ['periodo', 'mes', 'periodo_recaudo'],
        'nro_recibo' => ['nro_recibo', 'numero_recibo', 'recibo', 'comprobante'],
        'cliente' => ['cliente', 'nombre_cliente'],
        'vendedor' => ['vendedor', 'asesor', 'empleado_de_ventas'],
        'observacion' => ['observacion', 'detalle', 'descripcion'],
    ];
}

function recaudo_default_column_map(): array
{
    return [
        'documento' => 0,
        'valor_pagado' => 1,
        'fecha_pago' => 2,
        'nro_recibo' => 3,
        'cliente' => 4,
        'vendedor' => 5,
        'periodo' => 6,
        'observacion' => 7,
    ];
}

function recaudo_map_headers(array $headers): array
{
    $normalizedToIndex = [];
    foreach ($headers as $index => $header) {
        $normalized = normalize_header_name($header);
        if ($normalized !== '' && !array_key_exists($normalized, $normalizedToIndex)) {
            $normalizedToIndex[$normalized] = $index;
        }
    }

    $map = [];
    foreach (recaudo_header_aliases() as $field => $aliases) {
        foreach ($aliases as $alias) {
            $key = normalize_header_name($alias);
            if (array_key_exists($key, $normalizedToIndex)) {
                $map[$field] = $normalizedToIndex[$key];
                break;
            }
        }
    }

    return $map;
}

function recaudo_detect_structure(array $rows): array
{
    $headers = $rows[0] ?? [];
    $map = recaudo_map_headers($headers);
    $hasHeaders = isset($map['documento'], $map['valor_pagado']);

    if ($hasHeaders) {
        return [
            'has_headers' => true,
            'start_row' => 1,
            'map' => $map,
        ];
    }

    return [
        'has_headers' => false,
        'start_row' => 0,
        'map' => recaudo_default_column_map(),
    ];
}

function recaudo_matches_known_structure(array $rows): bool
{
    if ($rows === []) {
        return false;
    }

    $headers = is_array($rows[0] ?? null) ? $rows[0] : [];
    $nonEmptyHeaders = array_values(array_filter(
        array_map(static fn(mixed $header): string => trim((string)$header), $headers),
        static fn(string $header): bool => $header !== ''
    ));

    if ($nonEmptyHeaders === []) {
        return false;
    }

    $map = recaudo_map_headers($headers);
    if (!isset($map['documento'], $map['valor_pagado'])) {
        return false;
    }

    $recognizedHeaders = count($map);
    $hasContextColumn = isset($map['fecha_pago'], $map['periodo'])
        || isset($map['nro_recibo'])
        || isset($map['cliente'])
        || isset($map['vendedor'])
        || isset($map['observacion']);

    return $recognizedHeaders >= 3 || $hasContextColumn;
}

function detect_non_cartera_upload_module(array $rows): ?array
{
    if (recaudo_matches_known_structure($rows)) {
        return [
            'module' => 'recaudos',
            'message' => 'El archivo cargado no corresponde a cartera. Verifique que está utilizando el módulo correcto (ej: recaudos).',
        ];
    }

    return null;
}

function recaudo_cell_to_string(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_int($value)) {
        return (string)$value;
    }

    if (is_float($value)) {
        $formatted = number_format($value, 10, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return trim((string)$value);
}

function recaudo_normalize_document(mixed $value): string
{
    $document = recaudo_cell_to_string($value);
    if ($document === '') {
        return '';
    }

    return preg_replace('/\s+/u', '', $document) ?? $document;
}

function recaudo_is_blank_row(array $row): bool
{
    foreach ($row as $cell) {
        if (recaudo_cell_to_string($cell) !== '') {
            return false;
        }
    }

    return true;
}

function recaudo_detect_period(array $preparedRow): string
{
    $periodo = periodo_normalizar((string)($preparedRow['periodo'] ?? ''));
    if ($periodo !== '') {
        return $periodo;
    }

    $fechaPago = (string)($preparedRow['fecha_pago'] ?? '');
    if ($fechaPago !== '') {
        return substr($fechaPago, 0, 7);
    }

    return date('Y-m');
}

function recaudo_resolve_load_period(array $validRows): string
{
    $periods = [];
    foreach ($validRows as $row) {
        $period = periodo_normalizar((string)($row['periodo'] ?? ''));
        if ($period === '') {
            continue;
        }

        $periods[$period] = ($periods[$period] ?? 0) + 1;
    }

    if ($periods === []) {
        return date('Y-m');
    }

    arsort($periods);
    return (string)array_key_first($periods);
}

function recaudo_prepare_rows(array $rows): array
{
    $structure = recaudo_detect_structure($rows);
    $map = $structure['map'];
    $startRow = (int)$structure['start_row'];

    $summary = [
        'total_leidas' => max(count($rows) - $startRow, 0),
        'procesadas_ok' => 0,
        'con_error' => 0,
        'vacias_ignoradas' => 0,
        'total_insertado' => 0.0,
    ];
    $validRows = [];
    $errors = [];

    for ($index = $startRow, $len = count($rows); $index < $len; $index++) {
        $row = $rows[$index] ?? [];
        $excelRow = $index + 1;

        if (recaudo_is_blank_row($row)) {
            $summary['vacias_ignoradas']++;
            continue;
        }

        $documento = recaudo_normalize_document($row[$map['documento']] ?? null);
        $valorPagado = normalize_decimal_value($row[$map['valor_pagado']] ?? null);
        $fechaPago = isset($map['fecha_pago']) ? normalize_date_value($row[$map['fecha_pago']] ?? null) : null;
        $periodo = isset($map['periodo']) ? periodo_normalizar(recaudo_cell_to_string($row[$map['periodo']] ?? null)) : '';
        $nroRecibo = isset($map['nro_recibo']) ? recaudo_cell_to_string($row[$map['nro_recibo']] ?? null) : '';
        $cliente = isset($map['cliente']) ? recaudo_cell_to_string($row[$map['cliente']] ?? null) : '';
        $vendedor = isset($map['vendedor']) ? recaudo_cell_to_string($row[$map['vendedor']] ?? null) : '';
        $observacion = isset($map['observacion']) ? recaudo_cell_to_string($row[$map['observacion']] ?? null) : '';

        if ($documento === '') {
            $summary['con_error']++;
            if (count($errors) < 5) {
                $errors[] = build_validation_error($excelRow, 'documento', $row[$map['documento']] ?? '', 'La fila no tiene número de documento.');
            }
            continue;
        }

        if ($valorPagado === null) {
            $summary['con_error']++;
            if (count($errors) < 5) {
                $errors[] = build_validation_error($excelRow, 'valor_pagado', $row[$map['valor_pagado']] ?? '', 'La fila no tiene un valor pagado válido.');
            }
            continue;
        }

        $prepared = [
            'documento' => $documento,
            'valor_pagado' => $valorPagado,
            'fecha_pago' => $fechaPago,
            'periodo' => $periodo,
            'nro_recibo' => $nroRecibo,
            'cliente' => $cliente,
            'vendedor' => $vendedor,
            'observacion' => $observacion,
            'fila_excel' => $excelRow,
        ];
        $prepared['periodo'] = recaudo_detect_period($prepared);

        $validRows[] = $prepared;
        $summary['procesadas_ok']++;
        $summary['total_insertado'] += $valorPagado;
    }

    return [
        'summary' => $summary,
        'valid_rows' => $validRows,
        'errors' => $errors,
        'has_headers' => (bool)$structure['has_headers'],
    ];
}

function recaudo_generate_upload_hash(string $tmpPath, string $filename): string
{
    $contents = is_file($tmpPath) ? (string)file_get_contents($tmpPath) : '';
    return hash('sha256', $filename . '|' . microtime(true) . '|' . $contents);
}

function recaudo_insert_load(PDO $pdo, array $file, array $result, int $userId): int
{
    $validRows = $result['valid_rows'] ?? [];
    $summary = $result['summary'] ?? [];
    $loadPeriod = recaudo_resolve_load_period($validRows);

    $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM cargas_recaudo WHERE periodo = ?');
    $versionStmt->execute([$loadPeriod]);
    $version = ((int)$versionStmt->fetchColumn()) + 1;

    $hash = recaudo_generate_upload_hash((string)($file['tmp_name'] ?? ''), (string)($file['name'] ?? 'recaudo'));

    $loadStmt = $pdo->prepare('INSERT INTO cargas_recaudo (archivo, hash_sha256, usuario_id, fecha_carga, periodo, total_registros, total_recaudo, version, activo, estado, created_at) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, 1, "activa", NOW())');
    $loadStmt->execute([
        (string)($file['name'] ?? 'recaudo'),
        $hash,
        $userId,
        $loadPeriod,
        (int)($summary['procesadas_ok'] ?? 0),
        (float)($summary['total_insertado'] ?? 0),
        $version,
    ]);

    $cargaId = (int)$pdo->lastInsertId();

    $detailStmt = $pdo->prepare('INSERT INTO recaudo_detalle (carga_id, nro_recibo, fecha_recibo, fecha_aplicacion, documento_aplicado, tipo_documento, cliente, vendedor, importe_aplicado, saldo_documento, periodo, uen, canal, regional, bucket, cartera_documento_id, cliente_conciliado, estado_conciliacion, observacion_conciliacion, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 0, ?, NULL, NULL, NULL, NULL, NULL, 0, NULL, ?, NOW())');

    foreach ($validRows as $row) {
        $detailStmt->execute([
            $cargaId,
            (string)($row['nro_recibo'] !== '' ? $row['nro_recibo'] : 'SIN-RECIBO'),
            $row['fecha_pago'] ?: null,
            $row['fecha_pago'] ?: null,
            (string)$row['documento'],
            (string)($row['cliente'] ?? ''),
            (string)($row['vendedor'] ?? ''),
            (float)$row['valor_pagado'],
            (string)$row['periodo'],
            (string)($row['observacion'] ?? ''),
        ]);
    }

    if (!empty($result['errors'])) {
        $errorStmt = $pdo->prepare('INSERT INTO recaudo_validacion_errores (carga_id, fila, campo, valor, motivo, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        foreach ($result['errors'] as $error) {
            $errorStmt->execute([
                $cargaId,
                (int)($error['fila'] ?? 0),
                (string)($error['campo'] ?? ''),
                (string)($error['valor'] ?? ''),
                (string)($error['motivo'] ?? ''),
            ]);
        }
    }

    periodo_control_registrar_recaudo($pdo, $loadPeriod);

    return $cargaId;
}

function recaudo_delete_load(PDO $pdo, int $cargaId): void
{
    $pdo->prepare('DELETE FROM conciliacion_cartera_recaudo WHERE id_carga_recaudo = ? OR recaudo_id = ?')->execute([$cargaId, $cargaId]);
    $pdo->prepare('DELETE FROM recaudo_agregados WHERE carga_id = ?')->execute([$cargaId]);
    $pdo->prepare('DELETE FROM recaudo_validacion_errores WHERE carga_id = ?')->execute([$cargaId]);
    $pdo->prepare('DELETE FROM recaudo_detalle WHERE carga_id = ?')->execute([$cargaId]);
    $pdo->prepare('DELETE FROM cargas_recaudo WHERE id = ?')->execute([$cargaId]);
}

function recaudo_fetch_history(PDO $pdo, int $limit = 20): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->query('SELECT c.id, c.archivo, c.periodo, c.total_registros, c.total_recaudo, c.version, c.fecha_carga, c.estado, u.nombre AS usuario FROM cargas_recaudo c LEFT JOIN usuarios u ON u.id = c.usuario_id ORDER BY c.id DESC LIMIT ' . $limit);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function recaudo_fetch_load_detail(PDO $pdo, int $cargaId): array
{
    $metaStmt = $pdo->prepare('SELECT c.id, c.archivo, c.periodo, c.total_registros, c.total_recaudo, c.version, c.fecha_carga, c.estado, u.nombre AS usuario FROM cargas_recaudo c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ? LIMIT 1');
    $metaStmt->execute([$cargaId]);
    $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $rowsStmt = $pdo->prepare('SELECT id, nro_recibo, fecha_aplicacion, documento_aplicado, cliente, vendedor, importe_aplicado, periodo, observacion_conciliacion FROM recaudo_detalle WHERE carga_id = ? ORDER BY id ASC LIMIT 100');
    $rowsStmt->execute([$cargaId]);

    $errorsStmt = $pdo->prepare('SELECT fila, campo, valor, motivo FROM recaudo_validacion_errores WHERE carga_id = ? ORDER BY id ASC LIMIT 100');
    $errorsStmt->execute([$cargaId]);

    return [
        'meta' => $meta,
        'rows' => $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'errors' => $errorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}
