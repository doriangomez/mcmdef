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

function recaudo_validate_and_prepare(PDO $pdo, array $rows, string $periodoCarga): array
{
    if (count($rows) < 2) {
        return ['errors' => [build_validation_error(0, 'archivo', '', 'El archivo no contiene registros para procesar.')]];
    }

    $headers = $rows[0] ?? [];
    $map = recaudo_map_headers_by_name($headers);
    $errors = [];

    foreach (recaudo_expected_required_headers() as $required) {
        if (!array_key_exists($required, $map)) {
            $errors[] = build_validation_error(1, $required, '', 'Columna requerida no encontrada en el archivo de recaudo.');
        }
    }
    if (!empty($errors)) {
        return ['errors' => $errors];
    }

    $documentNumbers = [];
    for ($i = 1, $len = count($rows); $i < $len; $i++) {
        $doc = trim((string)($rows[$i][$map['nro_documento_aplicado']] ?? ''));
        if ($doc !== '') {
            $documentNumbers[$doc] = true;
        }
    }

    if (empty($documentNumbers)) {
        return ['errors' => [build_validation_error(0, 'nro_documento_aplicado', '', 'No se encontraron documentos para conciliar.')]];
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
            'periodo_carga' => $periodoCarga,
            'nro_recibo' => trim((string)($row[$map['nro_recibo']] ?? '')),
            'fecha_recibo' => $fechaRecibo,
            'cliente' => $cliente,
            'vendedor' => trim((string)($row[$map['vendedor']] ?? '')),
            'regional' => trim((string)($row[$map['regional']] ?? '')),
            'documento' => $nroDocumento,
            'fecha_aplicacion' => $fechaAplicacion ?? $fechaRecibo,
            'importe_aplicado' => $importe,
            'uen' => trim((string)($doc['uens'] ?? '')),
            'canal' => trim((string)($doc['canal'] ?? '')),
            'bucket' => cartera_bucket_label((int)($doc['dias_vencido'] ?? 0)),
            'cartera_documento_id' => (int)$doc['id'],
            'cliente_conciliado' => $clienteMatch ? 1 : 0,
        ];

        if (!$clienteMatch) {
            $errors[] = build_validation_error($fila, 'cliente', $cliente, 'Cliente en recaudo no coincide con cliente en cartera (validación recomendada).');
        }
    }

    return ['errors' => $errors, 'valid_rows' => $validRows, 'summary' => $summary];
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
    $insertRecaudo = $pdo->prepare('INSERT INTO recaudos (carga_recaudo_id, periodo_carga, nro_recibo, fecha_recibo, cliente, vendedor, regional, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $insertAplicacion = $pdo->prepare('INSERT INTO recaudo_aplicacion (documento, cartera_documento_id, recaudo_id, fecha_aplicacion, importe_aplicado, uen, canal, bucket, cliente_conciliado, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $updateSaldo = $pdo->prepare('UPDATE cartera_documentos SET saldo_pendiente = GREATEST(saldo_pendiente - ?, 0), estado_documento = CASE WHEN (saldo_pendiente - ?) <= 0 THEN "inactivo" ELSE estado_documento END, estado_documento_detalle = CASE WHEN (saldo_pendiente - ?) <= 0 THEN "pagado_recaudo" ELSE estado_documento_detalle END WHERE id = ?');

    $recaudoIds = [];
    foreach ($rows as $row) {
        $key = implode('|', [
            $row['periodo_carga'],
            $row['nro_recibo'],
            $row['fecha_recibo'] ?? '',
            $row['cliente'],
            $row['vendedor'],
            $row['regional'],
        ]);

        if (!isset($recaudoIds[$key])) {
            $insertRecaudo->execute([
                $cargaId,
                $row['periodo_carga'],
                $row['nro_recibo'],
                $row['fecha_recibo'],
                $row['cliente'],
                $row['vendedor'],
                $row['regional'],
            ]);
            $recaudoIds[$key] = (int)$pdo->lastInsertId();
        }

        $recaudoId = $recaudoIds[$key];
        $insertAplicacion->execute([
            $row['documento'],
            $row['cartera_documento_id'],
            $recaudoId,
            $row['fecha_aplicacion'],
            $row['importe_aplicado'],
            $row['uen'],
            $row['canal'],
            $row['bucket'],
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
