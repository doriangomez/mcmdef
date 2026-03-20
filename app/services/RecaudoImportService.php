<?php

declare(strict_types=1);

require_once __DIR__ . '/ExcelImportService.php';
require_once __DIR__ . '/ClientService.php';
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS conciliacion_cartera_recaudo (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        id_recaudo_detalle BIGINT NULL,
        id_cartera_documento BIGINT NULL,
        id_carga_recaudo BIGINT NOT NULL,
        estado ENUM('conciliado_total','conciliado_parcial','pago_excedido','sin_pago','pago_sin_factura','tipo_no_coincide','periodo_diferente') NOT NULL,
        importe_aplicado DECIMAL(18,2) NOT NULL DEFAULT 0,
        saldo_pendiente_cartera DECIMAL(18,2) NOT NULL DEFAULT 0,
        diferencia DECIMAL(18,2) NOT NULL DEFAULT 0,
        fecha_conciliacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        observacion TEXT NULL,
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
        estado_conciliacion ENUM('conciliado_total','conciliado_parcial','sin_pago','pago_sin_factura','pago_excedido','periodo_diferente','tipo_no_coincide') NOT NULL,
        nivel_confianza INT NOT NULL DEFAULT 100,
        detalle_validacion TEXT NULL,
        INDEX idx_conciliacion_carga_recaudo (id_carga_recaudo),
        INDEX idx_conciliacion_cartera_documento (id_cartera_documento),
        INDEX idx_conciliacion_estado_nuevo (estado),
        INDEX idx_conciliacion_recaudo_id (recaudo_id),
        INDEX idx_conciliacion_documento (numero_documento),
        INDEX idx_conciliacion_estado (estado_conciliacion),
        INDEX idx_conciliacion_periodo (periodo_cartera, periodo_recaudo),
        CONSTRAINT fk_conciliacion_recaudo FOREIGN KEY (recaudo_id) REFERENCES cargas_recaudo(id),
        CONSTRAINT fk_conciliacion_cartera FOREIGN KEY (cartera_id) REFERENCES cartera_documentos(id),
        CONSTRAINT fk_conciliacion_recaudo_detalle FOREIGN KEY (id_recaudo_detalle) REFERENCES recaudo_detalle(id),
        CONSTRAINT fk_conciliacion_cartera_documento FOREIGN KEY (id_cartera_documento) REFERENCES cartera_documentos(id),
        CONSTRAINT fk_conciliacion_carga_recaudo FOREIGN KEY (id_carga_recaudo) REFERENCES cargas_recaudo(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("ALTER TABLE conciliacion_cartera_recaudo
        ADD COLUMN IF NOT EXISTS id_recaudo_detalle BIGINT NULL AFTER id,
        ADD COLUMN IF NOT EXISTS id_cartera_documento BIGINT NULL AFTER id_recaudo_detalle,
        ADD COLUMN IF NOT EXISTS id_carga_recaudo BIGINT NULL AFTER id_cartera_documento,
        ADD COLUMN IF NOT EXISTS estado ENUM('conciliado_total','conciliado_parcial','pago_excedido','sin_pago','pago_sin_factura','tipo_no_coincide','periodo_diferente') NULL AFTER id_carga_recaudo,
        ADD COLUMN IF NOT EXISTS importe_aplicado DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER estado,
        ADD COLUMN IF NOT EXISTS saldo_pendiente_cartera DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER importe_aplicado,
        ADD COLUMN IF NOT EXISTS diferencia DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER saldo_pendiente_cartera,
        ADD COLUMN IF NOT EXISTS observacion TEXT NULL AFTER fecha_conciliacion,
        ADD COLUMN IF NOT EXISTS periodo_cartera VARCHAR(7) NULL AFTER observacion,
        ADD COLUMN IF NOT EXISTS periodo_recaudo VARCHAR(7) NULL AFTER periodo_cartera,
        ADD COLUMN IF NOT EXISTS cartera_id BIGINT NULL AFTER periodo_recaudo,
        ADD COLUMN IF NOT EXISTS recaudo_id BIGINT NULL AFTER cartera_id,
        ADD COLUMN IF NOT EXISTS numero_documento VARCHAR(80) NULL AFTER recaudo_id,
        ADD COLUMN IF NOT EXISTS cliente_cartera VARCHAR(180) NULL AFTER numero_documento,
        ADD COLUMN IF NOT EXISTS cliente_recaudo VARCHAR(180) NULL AFTER cliente_cartera,
        ADD COLUMN IF NOT EXISTS valor_factura DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER cliente_recaudo,
        ADD COLUMN IF NOT EXISTS valor_pagado DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER valor_factura,
        ADD COLUMN IF NOT EXISTS saldo_resultante DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER valor_pagado,
        ADD COLUMN IF NOT EXISTS estado_conciliacion ENUM('conciliado_total','conciliado_parcial','sin_pago','pago_sin_factura','pago_excedido','periodo_diferente','tipo_no_coincide') NULL AFTER saldo_resultante,
        ADD COLUMN IF NOT EXISTS nivel_confianza INT NOT NULL DEFAULT 100 AFTER estado_conciliacion,
        ADD COLUMN IF NOT EXISTS detalle_validacion TEXT NULL AFTER nivel_confianza");

    $pdo->exec("ALTER TABLE conciliacion_cartera_recaudo
        ADD INDEX IF NOT EXISTS idx_conciliacion_carga_recaudo (id_carga_recaudo),
        ADD INDEX IF NOT EXISTS idx_conciliacion_cartera_documento (id_cartera_documento),
        ADD INDEX IF NOT EXISTS idx_conciliacion_estado_nuevo (estado),
        ADD INDEX IF NOT EXISTS idx_conciliacion_recaudo_id (recaudo_id),
        ADD INDEX IF NOT EXISTS idx_conciliacion_documento (numero_documento),
        ADD INDEX IF NOT EXISTS idx_conciliacion_estado (estado_conciliacion),
        ADD INDEX IF NOT EXISTS idx_conciliacion_periodo (periodo_cartera, periodo_recaudo)");

    $pdo->exec("UPDATE conciliacion_cartera_recaudo
        SET id_carga_recaudo = COALESCE(id_carga_recaudo, recaudo_id),
            id_cartera_documento = COALESCE(id_cartera_documento, cartera_id),
            estado = COALESCE(estado, estado_conciliacion),
            importe_aplicado = CASE WHEN COALESCE(importe_aplicado, 0) = 0 AND COALESCE(valor_pagado, 0) <> 0 THEN valor_pagado ELSE COALESCE(importe_aplicado, 0) END,
            saldo_pendiente_cartera = CASE WHEN COALESCE(saldo_pendiente_cartera, 0) = 0 AND COALESCE(valor_factura, 0) <> 0 THEN valor_factura ELSE COALESCE(saldo_pendiente_cartera, 0) END,
            diferencia = CASE WHEN COALESCE(diferencia, 0) = 0 AND (COALESCE(valor_pagado, 0) <> 0 OR COALESCE(valor_factura, 0) <> 0) THEN COALESCE(valor_pagado, 0) - COALESCE(valor_factura, 0) ELSE COALESCE(diferencia, 0) END,
            observacion = COALESCE(NULLIF(observacion, ''), detalle_validacion)");
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

function recaudo_tipo_homologacion(): array
{
    return [
        'factura' => ['FVNAL1', 'FVNAL2', 'FVNAL3', 'FVEXP1', 'FVEXP2'],
        'nota debito' => ['NDNAL', 'NDEXP'],
        'nota credito' => ['NCNAL', 'NCEXP'],
        'asientos contables' => ['AC'],
        'recibo de caja' => ['RC'],
        'saldo inicial' => ['SI'],
    ];
}

function recaudo_normalize_compare_text(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $normalized = mb_strtolower($value, 'UTF-8');
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
    $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', (string)$normalized);

    return trim((string)$normalized);
}

function recaudo_normalize_recaudo_document_type(?string $tipo): string
{
    $normalized = recaudo_normalize_compare_text($tipo);
    if ($normalized === '') {
        return '';
    }

    if (str_contains($normalized, 'factura')) {
        return 'factura';
    }
    if (str_contains($normalized, 'nota') && str_contains($normalized, 'debito')) {
        return 'nota debito';
    }
    if (str_contains($normalized, 'nota') && str_contains($normalized, 'credito')) {
        return 'nota credito';
    }
    if (str_contains($normalized, 'asiento')) {
        return 'asientos contables';
    }
    if (str_contains($normalized, 'recibo') && str_contains($normalized, 'caja')) {
        return 'recibo de caja';
    }
    if (str_contains($normalized, 'saldo') && str_contains($normalized, 'inicial')) {
        return 'saldo inicial';
    }

    return $normalized;
}

function recaudo_normalize_cartera_document_type(?string $tipo): string
{
    $normalized = mb_strtoupper(trim((string)$tipo), 'UTF-8');
    foreach (recaudo_tipo_homologacion() as $homologado => $tiposSap) {
        if (in_array($normalized, $tiposSap, true)) {
            return $homologado;
        }
    }

    return recaudo_normalize_recaudo_document_type($normalized);
}

function recaudo_documento_periodo(array $doc): string
{
    foreach (['periodo_documento', 'periodo', 'periodo_carga', 'periodo_detectado'] as $field) {
        $value = trim((string)($doc[$field] ?? ''));
        if ($value !== '') {
            return substr($value, 0, 7);
        }
    }

    $fecha = trim((string)($doc['fecha_contabilizacion'] ?? ''));
    return $fecha !== '' ? substr($fecha, 0, 7) : '';
}

function recaudo_fetch_cartera_documents(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT
            d.id,
            d.id_carga,
            d.cliente_id,
            d.nro_documento,
            d.tipo,
            d.documento_uid,
            d.cliente,
            d.saldo_pendiente,
            d.valor_documento,
            d.uens AS uen,
            d.canal,
            d.regional,
            d.dias_vencido,
            d.estado_documento,
            d.fecha_contabilizacion,
            COALESCE(NULLIF(TRIM(d.periodo), ''), NULLIF(TRIM(cc.periodo_detectado), ''), DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m')) AS periodo_documento
        FROM cartera_documentos d
        INNER JOIN cargas_cartera cc ON cc.id = d.id_carga
        WHERE cc.estado = 'activa' AND cc.activo = 1
        ORDER BY d.id DESC");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function recaudo_index_cartera_documents(array $docs): array
{
    $byNumber = [];
    foreach ($docs as $doc) {
        $number = recaudo_normalize_document_number((string)($doc['nro_documento'] ?? ''));
        if ($number === '') {
            continue;
        }
        if (!isset($byNumber[$number])) {
            $byNumber[$number] = [];
        }
        $byNumber[$number][] = $doc;
    }

    return $byNumber;
}

function recaudo_pick_best_document(array $documents, string $periodoRecaudo = '', bool $requireTypeMatch = false, string $tipoRecaudo = ''): ?array
{
    if ($documents === []) {
        return null;
    }

    usort($documents, static function (array $a, array $b) use ($periodoRecaudo, $requireTypeMatch, $tipoRecaudo): int {
        $scoreA = 0;
        $scoreB = 0;

        $periodoA = recaudo_documento_periodo($a);
        $periodoB = recaudo_documento_periodo($b);
        if ($periodoRecaudo !== '') {
            if ($periodoA === $periodoRecaudo) {
                $scoreA += 40;
            }
            if ($periodoB === $periodoRecaudo) {
                $scoreB += 40;
            }
        }

        if (($a['estado_documento'] ?? '') === 'activo') {
            $scoreA += 20;
        }
        if (($b['estado_documento'] ?? '') === 'activo') {
            $scoreB += 20;
        }

        if ($requireTypeMatch && $tipoRecaudo !== '') {
            if (recaudo_normalize_cartera_document_type((string)($a['tipo'] ?? '')) === $tipoRecaudo) {
                $scoreA += 30;
            }
            if (recaudo_normalize_cartera_document_type((string)($b['tipo'] ?? '')) === $tipoRecaudo) {
                $scoreB += 30;
            }
        }

        if ($scoreA === $scoreB) {
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        }

        return $scoreB <=> $scoreA;
    });

    return $documents[0] ?? null;
}

function recaudo_build_match_context(array $documents, string $periodoRecaudo, string $tipoRecaudo): array
{
    $context = [
        'matching_period' => [],
        'matching_period_and_type' => [],
        'active_documents' => [],
    ];

    foreach ($documents as $doc) {
        $periodoDoc = recaudo_documento_periodo($doc);
        $tipoDoc = recaudo_normalize_cartera_document_type((string)($doc['tipo'] ?? ''));
        if (($doc['estado_documento'] ?? '') === 'activo') {
            $context['active_documents'][] = $doc;
        }
        if ($periodoRecaudo !== '' && $periodoDoc === $periodoRecaudo) {
            $context['matching_period'][] = $doc;
            if ($tipoRecaudo !== '' && $tipoDoc === $tipoRecaudo) {
                $context['matching_period_and_type'][] = $doc;
            }
        }
    }

    return $context;
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

    $docsByNumber = recaudo_index_cartera_documents(recaudo_fetch_cartera_documents($pdo));

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

        $tipoDocumentoTexto = trim((string)($row[$map['tipo_documento_aplicado']] ?? ''));
        $tipoDocumento = recaudo_normalize_recaudo_document_type($tipoDocumentoTexto);
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

        $documents = $docsByNumber[$nroDocumento] ?? [];
        $matchContext = recaudo_build_match_context($documents, $periodoRegistro, $tipoDocumento);
        $applicableDoc = recaudo_pick_best_document($matchContext['matching_period_and_type'], $periodoRegistro, true, $tipoDocumento);
        $referenceDoc = $applicableDoc
            ?? recaudo_pick_best_document($matchContext['matching_period'], $periodoRegistro)
            ?? recaudo_pick_best_document($matchContext['active_documents'], $periodoRegistro)
            ?? recaudo_pick_best_document($documents, $periodoRegistro);

        $clienteCartera = trim((string)($referenceDoc['cliente'] ?? ''));
        $clienteMatch = ($referenceDoc === null || $cliente === '' || mb_strtolower($cliente) === mb_strtolower($clienteCartera));
        $tipoCartera = recaudo_normalize_cartera_document_type((string)($referenceDoc['tipo'] ?? ''));
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
            'tipo_documento' => $tipoDocumentoTexto,
            'documento_aplicado' => $nroDocumento,
            'importe_aplicado' => $importe,
            'saldo_documento' => (float)($referenceDoc['saldo_pendiente'] ?? 0),
            'uen' => trim((string)($referenceDoc['uen'] ?? '')),
            'canal' => trim((string)($referenceDoc['canal'] ?? '')),
            'regional' => trim((string)($referenceDoc['regional'] ?? '')),
            'bucket' => $referenceDoc !== null ? cartera_bucket_label((int)($referenceDoc['dias_vencido'] ?? 0)) : 'Sin factura',
            'cartera_documento_id' => $applicableDoc !== null ? (int)$applicableDoc['id'] : null,
            'cartera_documento_referencia_id' => $referenceDoc !== null ? (int)$referenceDoc['id'] : null,
            'cliente_id' => $applicableDoc !== null ? (int)($applicableDoc['cliente_id'] ?? 0) : (int)($referenceDoc['cliente_id'] ?? 0),
            'cliente_conciliado' => $clienteMatch ? 1 : 0,
            'tipo_coincide' => $tipoMatch ? 1 : 0,
        ];

        if ($referenceDoc !== null && !$clienteMatch) {
            $warnings[] = build_validation_error($fila, 'cliente', $cliente, 'Cliente en recaudo no coincide con cliente en cartera (validación recomendada).');
        }
        if ($referenceDoc !== null && !$tipoMatch) {
            $warnings[] = build_validation_error($fila, 'tipo_documento_aplicado', $tipoDocumentoTexto, 'Tipo de documento no coincide con cartera, el recaudo se cargará sin aplicar saldo automáticamente.');
        }
        if ($referenceDoc !== null && $applicableDoc === null && recaudo_documento_periodo($referenceDoc) !== $periodoRegistro) {
            $warnings[] = build_validation_error($fila, 'periodo', $periodoRegistro, 'El documento existe en cartera, pero en un periodo diferente.');
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'valid_rows' => $validRows, 'summary' => $summary, 'periodo_detectado' => $periodoDetectado];
}

function recaudo_normalize_document_number(string $value): string
{
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }

    $normalized = str_replace([',', ' '], ['', ''], $normalized);
    if (preg_match('/^-?\d+(?:\.0+)?$/', $normalized) === 1) {
        return (string)(int)((float)$normalized);
    }

    $digitsOnly = preg_replace('/\s+/u', '', $normalized);
    if ($digitsOnly === null) {
        return $normalized;
    }

    return preg_replace('/\.0+$/', '', $digitsOnly) ?? $digitsOnly;
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
    ensure_client_management_schema($pdo);

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

        $recaudoDetalleId = (int)$pdo->lastInsertId();
        $clienteId = (int)($row['cliente_id'] ?? 0);
        if ($clienteId <= 0 && trim((string)($row['cliente'] ?? '')) !== '') {
            $clienteId = upsert_master_client($pdo, [
                'cliente' => trim((string)$row['cliente']),
                'nit' => '',
                'cuenta' => '',
                'direccion' => '',
                'contacto' => '',
                'telefono' => '',
                'canal' => trim((string)($row['canal'] ?? '')),
                'regional' => trim((string)($row['regional'] ?? '')),
                'empleado_ventas' => trim((string)($row['vendedor'] ?? '')),
                'fecha_activacion' => substr((string)($row['fecha_aplicacion'] ?? date('Y-m-d')), 0, 10),
            ], substr((string)($row['fecha_aplicacion'] ?? date('Y-m-d')), 0, 10));
        }

        if ($clienteId > 0) {
            $descripcion = 'Pago aplicado';
            if (trim((string)($row['documento_aplicado'] ?? '')) !== '') {
                $descripcion .= ' al documento ' . trim((string)$row['documento_aplicado']);
            }
            if (trim((string)($row['nro_recibo'] ?? '')) !== '') {
                $descripcion .= ' mediante recibo ' . trim((string)$row['nro_recibo']);
            }
            register_client_payment(
                $pdo,
                $clienteId,
                (string)($row['fecha_aplicacion'] ?? date('Y-m-d H:i:s')),
                (float)($row['importe_aplicado'] ?? 0),
                $descripcion,
                $row['cartera_documento_id'] !== null ? (int)$row['cartera_documento_id'] : null,
                $recaudoDetalleId
            );
        }
    }
}

function procesarConciliacion(int $idCargaRecaudo, ?PDO $pdo = null): void
{
    if ($pdo === null) {
        throw new InvalidArgumentException('Se requiere una conexión PDO para procesar la conciliación.');
    }

    recaudo_ensure_reconciliation_schema($pdo);

    $cargaStmt = $pdo->prepare('SELECT periodo FROM cargas_recaudo WHERE id = ? LIMIT 1');
    $cargaStmt->execute([$idCargaRecaudo]);
    $periodoRecaudo = (string)(($cargaStmt->fetch(PDO::FETCH_ASSOC) ?: [])['periodo'] ?? '');

    $pdo->prepare('DELETE FROM conciliacion_cartera_recaudo WHERE id_carga_recaudo = ? OR recaudo_id = ?')->execute([$idCargaRecaudo, $idCargaRecaudo]);

    $docs = recaudo_fetch_cartera_documents($pdo);
    $docsByNumber = recaudo_index_cartera_documents($docs);

    $detalleStmt = $pdo->prepare('SELECT id, carga_id, documento_aplicado, tipo_documento, cliente, importe_aplicado, saldo_documento, periodo, vendedor, cartera_documento_id FROM recaudo_detalle WHERE carga_id = ? ORDER BY id ASC');
    $detalleStmt->execute([$idCargaRecaudo]);
    $detalles = $detalleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $insert = $pdo->prepare('INSERT INTO conciliacion_cartera_recaudo (
        id_recaudo_detalle,
        id_cartera_documento,
        id_carga_recaudo,
        estado,
        importe_aplicado,
        saldo_pendiente_cartera,
        diferencia,
        fecha_conciliacion,
        observacion,
        periodo_cartera,
        periodo_recaudo,
        cartera_id,
        recaudo_id,
        numero_documento,
        cliente_cartera,
        cliente_recaudo,
        valor_factura,
        valor_pagado,
        saldo_resultante,
        estado_conciliacion,
        nivel_confianza,
        detalle_validacion
    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $updateDetalle = $pdo->prepare('UPDATE recaudo_detalle SET cartera_documento_id = ?, estado_conciliacion = ?, observacion_conciliacion = ? WHERE id = ?');

    $matchedActiveDocumentIds = [];

    foreach ($detalles as $detalle) {
        $documento = recaudo_normalize_document_number((string)($detalle['documento_aplicado'] ?? ''));
        $periodoDetalle = trim((string)($detalle['periodo'] ?? ''));
        $tipoRecaudo = recaudo_normalize_recaudo_document_type((string)($detalle['tipo_documento'] ?? ''));
        $documentos = $docsByNumber[$documento] ?? [];
        $matchContext = recaudo_build_match_context($documentos, $periodoDetalle, $tipoRecaudo);
        $documentoExacto = recaudo_pick_best_document($matchContext['matching_period_and_type'], $periodoDetalle, true, $tipoRecaudo);
        $documentoMismoPeriodo = recaudo_pick_best_document($matchContext['matching_period'], $periodoDetalle);
        $documentoActivo = recaudo_pick_best_document($matchContext['active_documents'], $periodoDetalle);
        $documentoReferencia = $documentoExacto ?? $documentoMismoPeriodo ?? $documentoActivo ?? recaudo_pick_best_document($documentos, $periodoDetalle);

        $saldoReferencia = $documentoExacto !== null
            ? (float)($detalle['saldo_documento'] ?? $documentoExacto['saldo_pendiente'] ?? 0)
            : (float)($documentoReferencia['saldo_pendiente'] ?? $detalle['saldo_documento'] ?? 0);
        $importeAplicado = (float)($detalle['importe_aplicado'] ?? 0);
        $diferencia = $importeAplicado - $saldoReferencia;

        $estado = 'pago_sin_factura';
        $observacion = 'No se encontró el documento aplicado en la cartera activa.';
        $documentoConciliado = null;
        $confianza = 50;
        $periodoCartera = $documentoReferencia !== null ? recaudo_documento_periodo($documentoReferencia) : null;

        if ($documentoExacto !== null) {
            $documentoConciliado = $documentoExacto;
            $confianza = 100;
            if (abs($diferencia) <= 1) {
                $estado = 'conciliado_total';
                $observacion = 'El importe aplicado cubre el saldo pendiente de cartera dentro de la tolerancia configurada.';
            } elseif ($importeAplicado < $saldoReferencia) {
                $estado = 'conciliado_parcial';
                $observacion = 'El importe aplicado es menor al saldo pendiente del documento en cartera.';
            } else {
                $estado = 'pago_excedido';
                $observacion = 'El importe aplicado supera el saldo pendiente del documento en cartera.';
            }
        } elseif ($documentoMismoPeriodo !== null) {
            $documentoConciliado = $documentoMismoPeriodo;
            $estado = 'tipo_no_coincide';
            $confianza = 80;
            $tipoCartera = recaudo_normalize_cartera_document_type((string)($documentoMismoPeriodo['tipo'] ?? ''));
            $observacion = 'El documento existe en cartera para el mismo periodo, pero el tipo homologado no coincide (cartera: ' . $tipoCartera . ', recaudo: ' . ($tipoRecaudo !== '' ? $tipoRecaudo : 'sin tipo') . ').';
        } elseif ($documentoActivo !== null || $documentoReferencia !== null) {
            $documentoConciliado = $documentoActivo ?? $documentoReferencia;
            $estado = 'periodo_diferente';
            $confianza = 75;
            $periodoEncontrado = $documentoConciliado !== null ? recaudo_documento_periodo($documentoConciliado) : '';
            $observacion = 'El documento existe en cartera, pero pertenece a un periodo diferente (cartera: ' . ($periodoEncontrado !== '' ? $periodoEncontrado : 'sin periodo') . ', recaudo: ' . ($periodoDetalle !== '' ? $periodoDetalle : 'sin periodo') . ').';
        }

        if ($documentoConciliado !== null && ($documentoConciliado['estado_documento'] ?? '') === 'activo') {
            $matchedActiveDocumentIds[(int)$documentoConciliado['id']] = true;
        }

        $carteraDocumentoId = $documentoConciliado !== null ? (int)$documentoConciliado['id'] : null;
        $clienteCartera = $documentoConciliado !== null ? (string)($documentoConciliado['cliente'] ?? '') : '';
        $valorFactura = $documentoConciliado !== null ? (float)($documentoConciliado['valor_documento'] ?? $saldoReferencia) : $saldoReferencia;
        $saldoResultante = $saldoReferencia - $importeAplicado;

        $insert->execute([
            (int)$detalle['id'],
            $carteraDocumentoId,
            $idCargaRecaudo,
            $estado,
            $importeAplicado,
            $saldoReferencia,
            $diferencia,
            $observacion,
            $periodoCartera,
            $periodoRecaudo !== '' ? $periodoRecaudo : ($periodoDetalle !== '' ? $periodoDetalle : null),
            $carteraDocumentoId,
            $idCargaRecaudo,
            $documento,
            $clienteCartera,
            (string)($detalle['cliente'] ?? ''),
            $valorFactura,
            $importeAplicado,
            $saldoResultante,
            $estado,
            $confianza,
            $observacion,
        ]);

        $updateDetalle->execute([
            $carteraDocumentoId,
            $estado,
            $observacion,
            (int)$detalle['id'],
        ]);
    }

    foreach ($docs as $doc) {
        if (($doc['estado_documento'] ?? '') !== 'activo') {
            continue;
        }
        $docId = (int)($doc['id'] ?? 0);
        if ($docId <= 0 || isset($matchedActiveDocumentIds[$docId])) {
            continue;
        }

        $saldoPendiente = (float)($doc['saldo_pendiente'] ?? 0);
        $periodoDoc = recaudo_documento_periodo($doc);
        $observacion = 'Documento activo en cartera sin registro de pago dentro del lote de recaudo procesado.';

        $insert->execute([
            null,
            $docId,
            $idCargaRecaudo,
            'sin_pago',
            0,
            $saldoPendiente,
            0 - $saldoPendiente,
            $observacion,
            $periodoDoc !== '' ? $periodoDoc : null,
            $periodoRecaudo !== '' ? $periodoRecaudo : null,
            $docId,
            $idCargaRecaudo,
            recaudo_normalize_document_number((string)($doc['nro_documento'] ?? '')),
            (string)($doc['cliente'] ?? ''),
            '',
            (float)($doc['valor_documento'] ?? $saldoPendiente),
            0,
            $saldoPendiente,
            'sin_pago',
            100,
            $observacion,
        ]);
    }
}

function recaudo_run_reconciliation(PDO $pdo, int $cargaId): void
{
    procesarConciliacion($cargaId, $pdo);
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
