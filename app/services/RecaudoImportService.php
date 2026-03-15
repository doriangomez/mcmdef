<?php

declare(strict_types=1);

require_once __DIR__ . '/ExcelImportService.php';

function recaudo_expected_required_headers(): array
{
    return [
        'nro_recibo',
        'fecha_recibo',
        'cliente',
        'nro_documento_aplicado',
        'fecha_aplicacion',
        'importe_aplicado',
    ];
}

function recaudo_header_aliases(): array
{
    return [
        'nro_recibo' => ['nro_de_recibo', 'nro_recibo', 'numero_recibo'],
        'fecha_recibo' => ['fecha_de_recibo', 'fecha_recibo'],
        'total_pago_recibido' => ['total_pago_recibido', 'total_pago', 'valor_recibo'],
        'saldo' => ['saldo'],
        'id_conciliacion' => ['id_conciliacion', 'id_conciliacion_sap'],
        'cliente' => ['cliente'],
        'vendedor' => ['vendedor', 'empleado_de_ventas', 'empleado_ventas'],
        'tipo_documento_aplicado' => ['tipo_documento_aplicado', 'tipo_documento'],
        'nro_documento_aplicado' => ['nro_documento_aplicado', 'documento_aplicado', 'nro_documento'],
        'fecha_vencimiento' => ['fecha_de_vencimiento', 'fecha_vencimiento'],
        'fecha_aplicacion' => ['fecha_de_aplicacion', 'fecha_aplicacion'],
        'total_documento' => ['total_documento', 'valor_documento'],
        'importe_aplicado' => ['importe_aplicado', 'valor_aplicado'],
        'saldo_pendiente' => ['saldo_pendiente', 'saldo_pendiente_documento'],
        'grupo' => ['grupo'],
        'regional' => ['regional'],
    ];
}

function recaudo_map_headers_by_name(array $headers): array
{
    $normalizedToIndex = [];
    foreach ($headers as $index => $header) {
        $normalized = normalize_header_name($header);
        if ($normalized !== '' && !array_key_exists($normalized, $normalizedToIndex)) {
            $normalizedToIndex[$normalized] = $index;
        }
    }

    $fieldMap = [];
    foreach (recaudo_header_aliases() as $field => $aliases) {
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

function recaudo_detect_period(array $rows, array $map): ?string
{
    $counter = [];
    for ($i = 1, $len = count($rows); $i < $len; $i++) {
        $row = $rows[$i] ?? [];
        $fechaAplicacion = normalize_date_value($row[$map['fecha_aplicacion']] ?? null);
        $fechaRecibo = normalize_date_value($row[$map['fecha_recibo']] ?? null);
        $fecha = $fechaAplicacion ?? $fechaRecibo;
        if ($fecha === null) {
            continue;
        }

        $periodo = substr($fecha, 0, 7);
        if (!isset($counter[$periodo])) {
            $counter[$periodo] = 0;
        }
        $counter[$periodo]++;
    }

    if (empty($counter)) {
        return null;
    }

    arsort($counter);
    return array_key_first($counter);
}

function cartera_periodo_activo(PDO $pdo): ?string
{
    $stmt = $pdo->query("SELECT DATE_FORMAT(MAX(d.fecha_contabilizacion), '%Y-%m') AS periodo\n        FROM cartera_documentos d\n        INNER JOIN cargas_cartera c ON c.id = d.id_carga\n        WHERE c.estado = 'activa'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $periodo = trim((string)($row['periodo'] ?? ''));
    return $periodo !== '' ? $periodo : null;
}

function recaudo_validate_and_prepare(PDO $pdo, array $rows): array
{
    if (count($rows) < 2) {
        return ['errors' => [build_validation_error(0, 'archivo', '', 'El archivo no contiene registros para procesar.')]];
    }

    $headers = $rows[0] ?? [];
    $map = recaudo_map_headers_by_name($headers);
    $errors = [];
    $warnings = [];

    foreach (recaudo_expected_required_headers() as $required) {
        if (!array_key_exists($required, $map)) {
            $errors[] = build_validation_error(1, $required, '', 'Columna requerida no encontrada en el archivo de recaudo.');
        }
    }
    if (!empty($errors)) {
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    $periodoDetectado = recaudo_detect_period($rows, $map);
    if ($periodoDetectado === null) {
        $errors[] = build_validation_error(1, 'periodo', '', 'No fue posible detectar el periodo desde fecha_aplicacion o fecha_recibo.');
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    $periodoCartera = cartera_periodo_activo($pdo);
    if ($periodoCartera !== null && $periodoCartera !== $periodoDetectado) {
        $warnings[] = build_validation_error(0, 'periodo', $periodoDetectado, 'El recaudo corresponde a un periodo diferente a la cartera activa.');
    }

    $documentNumbers = [];
    for ($i = 1, $len = count($rows); $i < $len; $i++) {
        $doc = trim((string)($rows[$i][$map['nro_documento_aplicado']] ?? ''));
        if ($doc !== '') {
            $documentNumbers[$doc] = true;
        }
    }

    if (empty($documentNumbers)) {
        return ['errors' => [build_validation_error(0, 'nro_documento_aplicado', '', 'No se encontraron documentos para conciliar.')], 'warnings' => $warnings];
    }

    $placeholders = implode(',', array_fill(0, count($documentNumbers), '?'));
    $stmt = $pdo->prepare("SELECT id, nro_documento, cliente, saldo_pendiente, uens, canal, dias_vencido FROM cartera_documentos WHERE nro_documento IN ($placeholders) ORDER BY id DESC");
    $stmt->execute(array_keys($documentNumbers));
    $docsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $docsByNumber = [];
    foreach ($docsRaw as $doc) {
        $nro = (string)$doc['nro_documento'];
        if (!isset($docsByNumber[$nro])) {
            $docsByNumber[$nro] = $doc;
        }
    }

    $workingBalance = [];
    foreach ($docsByNumber as $nro => $doc) {
        $workingBalance[$nro] = (float)$doc['saldo_pendiente'];
    }

    $validRows = [];
    $summary = ['total' => 0, 'validas' => 0, 'con_error' => 0, 'total_aplicado' => 0.0];

    for ($i = 1, $len = count($rows); $i < $len; $i++) {
        $fila = $i + 1;
        $row = $rows[$i] ?? [];
        $hasData = false;
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                $hasData = true;
                break;
            }
        }
        if (!$hasData) {
            continue;
        }
        $summary['total']++;

        $nroDocumento = trim((string)($row[$map['nro_documento_aplicado']] ?? ''));
        $cliente = trim((string)($row[$map['cliente']] ?? ''));
        $importe = normalize_decimal_value($row[$map['importe_aplicado']] ?? null);
        $fechaAplicacion = normalize_date_value($row[$map['fecha_aplicacion']] ?? null);
        $fechaRecibo = normalize_date_value($row[$map['fecha_recibo']] ?? null);
        $periodoRegistro = substr((string)($fechaAplicacion ?? $fechaRecibo ?? ''), 0, 7);

        if ($nroDocumento === '') {
            $errors[] = build_validation_error($fila, 'nro_documento_aplicado', '', 'Documento aplicado vacío.');
            $summary['con_error']++;
            continue;
        }
        if (!isset($docsByNumber[$nroDocumento])) {
            $errors[] = build_validation_error($fila, 'nro_documento_aplicado', $nroDocumento, 'Documento no encontrado en cartera.');
            $summary['con_error']++;
            continue;
        }
        if ($importe === null || $importe <= 0) {
            $errors[] = build_validation_error($fila, 'importe_aplicado', (string)($row[$map['importe_aplicado']] ?? ''), 'Importe aplicado inválido.');
            $summary['con_error']++;
            continue;
        }
        if ($periodoRegistro === '') {
            $errors[] = build_validation_error($fila, 'fecha_aplicacion', '', 'No se pudo identificar periodo del registro.');
            $summary['con_error']++;
            continue;
        }

        $saldoActual = $workingBalance[$nroDocumento] ?? 0.0;
        if ($importe > $saldoActual) {
            $errors[] = build_validation_error($fila, 'importe_aplicado', (string)$importe, 'Error de conciliación: recaudo mayor al saldo.');
            $summary['con_error']++;
            continue;
        }

        $doc = $docsByNumber[$nroDocumento];
        $clienteCartera = trim((string)($doc['cliente'] ?? ''));
        $clienteMatch = ($cliente === '' || mb_strtolower($cliente) === mb_strtolower($clienteCartera));

        $workingBalance[$nroDocumento] = max(0, $saldoActual - $importe);
        $summary['validas']++;
        $summary['total_aplicado'] += $importe;

        $validRows[] = [
            'fila' => $fila,
            'periodo' => $periodoRegistro,
            'nro_recibo' => trim((string)($row[$map['nro_recibo']] ?? '')),
            'fecha_recibo' => $fechaRecibo,
            'fecha_aplicacion' => $fechaAplicacion ?? $fechaRecibo,
            'cliente' => $cliente,
            'vendedor' => trim((string)($row[$map['vendedor']] ?? '')),
            'tipo_documento' => trim((string)($row[$map['tipo_documento_aplicado']] ?? '')),
            'documento_aplicado' => $nroDocumento,
            'importe_aplicado' => $importe,
            'saldo_documento' => $workingBalance[$nroDocumento],
            'uen' => trim((string)($doc['uens'] ?? '')),
            'canal' => trim((string)($doc['canal'] ?? '')),
            'bucket' => cartera_bucket_label((int)($doc['dias_vencido'] ?? 0)),
            'cartera_documento_id' => (int)$doc['id'],
            'cliente_conciliado' => $clienteMatch ? 1 : 0,
        ];

        if (!$clienteMatch) {
            $warnings[] = build_validation_error($fila, 'cliente', $cliente, 'Cliente en recaudo no coincide con cliente en cartera (validación recomendada).');
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'valid_rows' => $validRows, 'summary' => $summary, 'periodo_detectado' => $periodoDetectado];
}

function cartera_bucket_label(int $diasVencido): string
{
    if ($diasVencido <= 0) {
        return 'Actual';
    }
    if ($diasVencido <= 30) {
        return '1-30';
    }
    if ($diasVencido <= 60) {
        return '31-60';
    }
    if ($diasVencido <= 90) {
        return '61-90';
    }
    if ($diasVencido <= 180) {
        return '91-180';
    }
    if ($diasVencido <= 360) {
        return '181-360';
    }
    return '361+';
}

function recaudo_apply_rows(PDO $pdo, int $cargaId, array $rows): void
{
    $insertDetalle = $pdo->prepare('INSERT INTO recaudo_detalle (carga_id, nro_recibo, fecha_recibo, fecha_aplicacion, documento_aplicado, tipo_documento, cliente, vendedor, importe_aplicado, saldo_documento, periodo, uen, canal, bucket, cartera_documento_id, cliente_conciliado, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $updateSaldo = $pdo->prepare('UPDATE cartera_documentos SET saldo_pendiente = GREATEST(saldo_pendiente - ?, 0), estado_documento = CASE WHEN (saldo_pendiente - ?) <= 0 THEN "inactivo" ELSE estado_documento END, estado_documento_detalle = CASE WHEN (saldo_pendiente - ?) <= 0 THEN "pagado_recaudo" ELSE estado_documento_detalle END WHERE id = ?');

    foreach ($rows as $row) {
        $insertDetalle->execute([
            $cargaId,
            $row['nro_recibo'],
            $row['fecha_recibo'],
            $row['fecha_aplicacion'],
            $row['documento_aplicado'],
            $row['tipo_documento'],
            $row['cliente'],
            $row['vendedor'],
            $row['importe_aplicado'],
            $row['saldo_documento'],
            $row['periodo'],
            $row['uen'],
            $row['canal'],
            $row['bucket'],
            $row['cartera_documento_id'],
            $row['cliente_conciliado'],
        ]);

        $updateSaldo->execute([
            $row['importe_aplicado'],
            $row['importe_aplicado'],
            $row['importe_aplicado'],
            $row['cartera_documento_id'],
        ]);
    }
}

function recaudo_build_aggregates(PDO $pdo, int $cargaId): void
{
    $periodoStmt = $pdo->prepare('SELECT periodo_detectado FROM recaudo_cargas WHERE id = ?');
    $periodoStmt->execute([$cargaId]);
    $periodo = (string)(($periodoStmt->fetch(PDO::FETCH_ASSOC) ?: [])['periodo_detectado'] ?? '');

    $total = (float)(($pdo->query('SELECT COALESCE(SUM(importe_aplicado),0) total FROM recaudo_detalle WHERE carga_id = ' . (int)$cargaId)->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0])['total'] ?? 0);

    $stmt = $pdo->prepare('INSERT INTO recaudo_agregados (carga_id, periodo, tipo_agregado, clave, valor_recaudo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE valor_recaudo = VALUES(valor_recaudo), updated_at = NOW()');
    $stmt->execute([$cargaId, $periodo, 'total', 'TOTAL', $total]);

    $grouped = [
        'vendedor' => 'COALESCE(NULLIF(TRIM(vendedor),""),"Sin vendedor")',
        'cliente' => 'COALESCE(NULLIF(TRIM(cliente),""),"Sin cliente")',
        'uen' => 'COALESCE(NULLIF(TRIM(uen),""),"Sin UEN")',
    ];

    foreach ($grouped as $tipo => $expr) {
        $sql = "SELECT $expr clave, COALESCE(SUM(importe_aplicado),0) total FROM recaudo_detalle WHERE carga_id = ? GROUP BY clave";
        $q = $pdo->prepare($sql);
        $q->execute([$cargaId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $stmt->execute([$cargaId, $periodo, $tipo, (string)$row['clave'], (float)$row['total']]);
        }
    }
}
