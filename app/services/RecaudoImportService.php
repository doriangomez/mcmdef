<?php

declare(strict_types=1);

require_once __DIR__ . '/ExcelImportService.php';
require_once __DIR__ . '/PeriodoControlService.php';

function recaudo_expected_required_headers(): array
{
    return [
        'nro_documento_aplicado',
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


function recaudo_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function recaudo_ensure_reconciliation_schema(PDO $pdo): void
{
    if (recaudo_table_exists($pdo, 'conciliacion_cartera_recaudo')) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS conciliacion_cartera_recaudo (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        periodo_cartera VARCHAR(7) NULL,
        periodo_recaudo VARCHAR(7) NULL,
        cartera_id BIGINT NULL,
        recaudo_id BIGINT NOT NULL,
        numero_documento VARCHAR(80) NOT NULL,
        cliente_cartera VARCHAR(180) NULL,
        cliente_recaudo VARCHAR(180) NULL,
        valor_factura DECIMAL(18,2) NOT NULL DEFAULT 0,
        valor_pagado DECIMAL(18,2) NOT NULL DEFAULT 0,
        saldo_resultante DECIMAL(18,2) NOT NULL DEFAULT 0,
        estado_conciliacion ENUM('conciliado_total', 'conciliado_parcial', 'sin_pago', 'pago_sin_factura', 'pago_excedido', 'periodo_diferente', 'tipo_no_coincide') NOT NULL,
        nivel_confianza INT NOT NULL DEFAULT 100,
        detalle_validacion TEXT NULL,
        fecha_conciliacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_conciliacion_recaudo_id (recaudo_id),
        INDEX idx_conciliacion_documento (numero_documento),
        INDEX idx_conciliacion_estado (estado_conciliacion),
        INDEX idx_conciliacion_periodo (periodo_cartera, periodo_recaudo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
    $periodo = periodo_control_obtener_activo($pdo);
    if ($periodo !== null) {
        return $periodo;
    }

    $stmt = $pdo->query("SELECT DATE_FORMAT(MAX(d.fecha_contabilizacion), '%Y-%m') AS periodo
        FROM cartera_documentos d
        INNER JOIN cargas_cartera c ON c.id = d.id_carga
        WHERE c.estado = 'activa' AND c.activo = 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $periodoLegacy = trim((string)($row['periodo'] ?? ''));
    return $periodoLegacy !== '' ? $periodoLegacy : null;
}

function cartera_ultimo_periodo_cargado(PDO $pdo): ?string
{
    $stmt = $pdo->query("SELECT DATE_FORMAT(MAX(d.fecha_contabilizacion), '%Y-%m') AS periodo
        FROM cartera_documentos d
        INNER JOIN cargas_cartera c ON c.id = d.id_carga
        WHERE c.estado = 'activa' AND c.activo = 1");
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
        $periodoDetectado = cartera_ultimo_periodo_cargado($pdo) ?? date('Y-m');
        $warnings[] = build_validation_error(1, 'periodo', '', 'No fue posible detectar el periodo en el archivo. Se usará periodo de referencia.');
    }

    $ultimoPeriodoCartera = cartera_ultimo_periodo_cargado($pdo);
    if ($ultimoPeriodoCartera !== null && strcmp($periodoDetectado, $ultimoPeriodoCartera) < 0) {
        $warnings[] = build_validation_error(0, 'periodo', $periodoDetectado, 'El recaudo corresponde a un periodo anterior. Verifique que la cartera correspondiente esté cargada.');
    }

    $documentNumbers = [];
    for ($i = 1, $len = count($rows); $i < $len; $i++) {
        $doc = recaudo_normalize_document_number((string)($rows[$i][$map['nro_documento_aplicado']] ?? ''));
        if ($doc !== '') {
            $documentNumbers[$doc] = true;
        }
    }

    if (empty($documentNumbers)) {
        return ['errors' => [build_validation_error(0, 'nro_documento_aplicado', '', 'No se encontraron documentos para conciliar.')], 'warnings' => $warnings];
    }

    $placeholders = implode(',', array_fill(0, count($documentNumbers), '?'));
    $stmt = $pdo->prepare("SELECT id, nro_documento, tipo, documento_uid, cliente, saldo_pendiente, valor_documento, uens AS uen, canal, regional, dias_vencido FROM cartera_documentos WHERE nro_documento IN ($placeholders) ORDER BY id DESC");
    $stmt->execute(array_keys($documentNumbers));
    $docsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $docsByNumber = [];
    foreach ($docsRaw as $doc) {
        $number = trim((string)($doc['nro_documento'] ?? ''));
        if ($number !== '' && !isset($docsByNumber[$number])) {
            $docsByNumber[$number] = $doc;
        }
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

        $tipoDocumento = normalize_document_type(trim((string)($row[$map['tipo_documento_aplicado']] ?? '')));
        $nroDocumento = recaudo_normalize_document_number((string)($row[$map['nro_documento_aplicado']] ?? ''));
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

        $doc = $docsByNumber[$nroDocumento] ?? null;
        $clienteCartera = trim((string)($doc['cliente'] ?? ''));
        $clienteMatch = ($doc === null || $cliente === '' || mb_strtolower($cliente) === mb_strtolower($clienteCartera));
        $tipoCartera = normalize_document_type(trim((string)($doc['tipo'] ?? '')));
        $tipoMatch = ($tipoDocumento === '' || $tipoCartera === '' || $tipoDocumento === $tipoCartera);

        $summary['validas']++;
        $summary['total_aplicado'] += $importe;

        $validRows[] = [
            'fila' => $fila,
            'periodo' => $periodoRegistro,
            'nro_recibo' => trim((string)($row[$map['nro_recibo']] ?? '')),
            'tipo_documento_aplicado' => $tipoDocumento,
            'nro_documento_aplicado' => $nroDocumento,
            'fecha_recibo' => $fechaRecibo,
            'fecha_aplicacion' => $fechaAplicacion ?? $fechaRecibo,
            'cliente' => $cliente,
            'vendedor' => trim((string)($row[$map['vendedor']] ?? '')),
            'tipo_documento' => trim((string)($row[$map['tipo_documento_aplicado'] ?? -1] ?? '')),
            'documento_aplicado' => $nroDocumento,
            'importe_aplicado' => $importe,
            'saldo_documento' => (float)($doc['saldo_pendiente'] ?? 0),
            'uen' => trim((string)($doc['uen'] ?? '')),
            'canal' => trim((string)($doc['canal'] ?? '')),
            'regional' => trim((string)($doc['regional'] ?? '')),
            'bucket' => $doc !== null ? cartera_bucket_label((int)($doc['dias_vencido'] ?? 0)) : 'Sin factura',
            'cartera_documento_id' => $doc !== null ? (int)$doc['id'] : null,
            'cliente_conciliado' => $clienteMatch ? 1 : 0,
            'tipo_coincide' => $tipoMatch ? 1 : 0,
        ];

        if ($doc !== null && !$clienteMatch) {
            $warnings[] = build_validation_error($fila, 'cliente', $cliente, 'Cliente en recaudo no coincide con cliente en cartera (validación recomendada).');
        }
        if (!$tipoMatch) {
            $warnings[] = build_validation_error($fila, 'tipo_documento_aplicado', $tipoDocumento, 'Tipo de documento no coincide con cartera, se usará como validación secundaria.');
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'valid_rows' => $validRows, 'summary' => $summary, 'periodo_detectado' => $periodoDetectado];
}

function recaudo_normalize_document_number(string $value): string
{
    $normalized = preg_replace('/[\s\-\.]+/u', '', trim($value));
    return $normalized !== null ? $normalized : trim($value);
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
    $insertDetalle = $pdo->prepare('INSERT INTO recaudo_detalle (carga_id, nro_recibo, fecha_recibo, fecha_aplicacion, documento_aplicado, tipo_documento, cliente, vendedor, importe_aplicado, saldo_documento, periodo, uen, canal, regional, bucket, cartera_documento_id, cliente_conciliado, estado_conciliacion, observacion_conciliacion, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
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
            $row['regional'],
            $row['bucket'],
            $row['cartera_documento_id'],
            $row['cliente_conciliado'],
            $row['cartera_documento_id'] === null ? 'pago_sin_factura' : 'pendiente_conciliacion',
            $row['tipo_coincide'] === 0 ? 'Tipo no coincide con cartera.' : null,
        ]);

        if ($row['cartera_documento_id'] !== null) {
            $updateSaldo->execute([
                $row['importe_aplicado'],
                $row['importe_aplicado'],
                $row['importe_aplicado'],
                $row['cartera_documento_id'],
            ]);
        }
    }
}

function recaudo_run_reconciliation(PDO $pdo, int $cargaId): void
{
    recaudo_ensure_reconciliation_schema($pdo);
    $cargaStmt = $pdo->prepare('SELECT periodo FROM cargas_recaudo WHERE id = ? LIMIT 1');
    $cargaStmt->execute([$cargaId]);
    $periodoRecaudo = (string)(($cargaStmt->fetch(PDO::FETCH_ASSOC) ?: [])['periodo'] ?? '');

    $periodoCarteraStmt = $pdo->query("SELECT DATE_FORMAT(MAX(d.fecha_contabilizacion), '%Y-%m') AS periodo FROM cartera_documentos d INNER JOIN cargas_cartera c ON c.id = d.id_carga WHERE c.estado = 'activa' AND c.activo = 1");
    $periodoCartera = (string)(($periodoCarteraStmt->fetch(PDO::FETCH_ASSOC) ?: [])['periodo'] ?? '');

    $pdo->prepare('DELETE FROM conciliacion_cartera_recaudo WHERE recaudo_id = ?')->execute([$cargaId]);

    $sql = "SELECT c.id AS cartera_id, c.nro_documento AS numero_documento, c.cliente, c.valor_documento, c.tipo, c.saldo_pendiente,
                COALESCE(SUM(r.importe_aplicado),0) AS total_pagado,
                MAX(r.tipo_documento) AS tipo_recaudo,
                MAX(r.cliente) AS cliente_recaudo
            FROM cartera_documentos c
            INNER JOIN cargas_cartera cc ON cc.id = c.id_carga AND cc.estado = 'activa' AND cc.activo = 1
            LEFT JOIN recaudo_detalle r ON r.carga_id = ? AND r.documento_aplicado = c.nro_documento
            GROUP BY c.id, c.nro_documento, c.cliente, c.valor_documento, c.tipo, c.saldo_pendiente";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cargaId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $insert = $pdo->prepare('INSERT INTO conciliacion_cartera_recaudo (periodo_cartera, periodo_recaudo, cartera_id, recaudo_id, numero_documento, cliente_cartera, cliente_recaudo, valor_factura, valor_pagado, saldo_resultante, estado_conciliacion, nivel_confianza, detalle_validacion, fecha_conciliacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');

    foreach ($rows as $row) {
        $valorFactura = (float)($row['valor_documento'] ?? 0);
        $valorPagado = (float)($row['total_pagado'] ?? 0);
        $saldo = $valorFactura - $valorPagado;
        $estado = 'sin_pago';
        if ($valorPagado > $valorFactura) {
            $estado = 'pago_excedido';
        } elseif (abs($saldo) < 0.01 && $valorPagado > 0) {
            $estado = 'conciliado_total';
        } elseif ($valorPagado > 0 && $valorPagado < $valorFactura) {
            $estado = 'conciliado_parcial';
        }

        $tipoCartera = normalize_document_type((string)($row['tipo'] ?? ''));
        $tipoRecaudo = normalize_document_type((string)($row['tipo_recaudo'] ?? ''));
        $detalle = [];
        $confianza = 100;
        if ($tipoRecaudo !== '' && $tipoCartera !== '' && $tipoCartera !== $tipoRecaudo) {
            $detalle[] = 'Coincide número de documento, pero tipo diferente entre cartera y recaudo.';
            $confianza = 80;
        }
        if ($periodoCartera !== '' && $periodoRecaudo !== '' && $periodoCartera !== $periodoRecaudo) {
            $detalle[] = 'Periodo cartera y recaudo son diferentes.';
            $confianza = min($confianza, 85);
        }

        $insert->execute([
            $periodoCartera !== '' ? $periodoCartera : null,
            $periodoRecaudo !== '' ? $periodoRecaudo : null,
            (int)$row['cartera_id'],
            $cargaId,
            (string)$row['numero_documento'],
            (string)$row['cliente'],
            (string)($row['cliente_recaudo'] ?? ''),
            $valorFactura,
            $valorPagado,
            $saldo,
            $estado,
            $confianza,
            implode(' ', $detalle),
        ]);
    }

    $orphans = $pdo->prepare("SELECT documento_aplicado, MAX(cliente) AS cliente_recaudo, SUM(importe_aplicado) AS total_pagado
        FROM recaudo_detalle
        WHERE carga_id = ? AND (cartera_documento_id IS NULL OR cartera_documento_id = 0)
        GROUP BY documento_aplicado");
    $orphans->execute([$cargaId]);
    $orphanRows = $orphans->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($orphanRows as $row) {
        $insert->execute([
            $periodoCartera !== '' ? $periodoCartera : null,
            $periodoRecaudo !== '' ? $periodoRecaudo : null,
            null,
            $cargaId,
            (string)$row['documento_aplicado'],
            '',
            (string)($row['cliente_recaudo'] ?? ''),
            0,
            (float)$row['total_pagado'],
            0 - (float)$row['total_pagado'],
            'pago_sin_factura',
            50,
            'Pago registrado sin factura encontrada en cartera.',
        ]);
    }

    $updateDetalle = $pdo->prepare('UPDATE recaudo_detalle d INNER JOIN conciliacion_cartera_recaudo c ON c.recaudo_id = d.carga_id AND c.numero_documento = d.documento_aplicado AND (c.cartera_id = d.cartera_documento_id OR d.cartera_documento_id IS NULL) SET d.estado_conciliacion = CASE WHEN c.estado_conciliacion = "sin_pago" THEN d.estado_conciliacion ELSE c.estado_conciliacion END, d.observacion_conciliacion = c.detalle_validacion WHERE d.carga_id = ?');
    $updateDetalle->execute([$cargaId]);
}

function recaudo_build_aggregates(PDO $pdo, int $cargaId): void
{
    $periodoStmt = $pdo->prepare('SELECT periodo FROM cargas_recaudo WHERE id = ?');
    $periodoStmt->execute([$cargaId]);
    $periodo = (string)(($periodoStmt->fetch(PDO::FETCH_ASSOC) ?: [])['periodo'] ?? '');

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
