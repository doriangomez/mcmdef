<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/RecaudoImportService.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_once __DIR__ . '/../../../app/services/PeriodoControlService.php';

require_role(['admin', 'analista']);

$msg = '';
$errorMsg = '';
$errors = [];
$warnings = [];
$summary = ['total' => 0, 'validas' => 0, 'con_error' => 0, 'total_aplicado' => 0.0];
$periodoDetectado = null;
$validationResult = null;
$diagnosticResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'eliminar_carga_recaudo') {
    $cargaId = (int)($_POST['carga_id'] ?? 0);
    if ($cargaId <= 0) {
        $errorMsg = 'La carga indicada no es válida.';
    } else {
        try {
            $pdo->beginTransaction();
            $cargaStmt = $pdo->prepare('SELECT id, archivo, periodo, version, activo, estado, total_registros, total_recaudo FROM cargas_recaudo WHERE id = ?');
            $cargaStmt->execute([$cargaId]);
            $carga = $cargaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$carga) {
                throw new RuntimeException('La carga de recaudo no existe.');
            }

            $pdo->prepare('DELETE FROM conciliacion_cartera_recaudo WHERE id_carga_recaudo = ? OR recaudo_id = ?')->execute([$cargaId, $cargaId]);
            $pdo->prepare('DELETE FROM recaudo_detalle WHERE carga_id = ?')->execute([$cargaId]);
            $pdo->prepare('DELETE FROM recaudo_validacion_errores WHERE carga_id = ?')->execute([$cargaId]);
            $pdo->prepare('DELETE FROM recaudo_agregados WHERE carga_id = ?')->execute([$cargaId]);
            $pdo->prepare('DELETE FROM cargas_recaudo WHERE id = ?')->execute([$cargaId]);

            audit_log($pdo, 'cargas_recaudo', $cargaId, 'carga_recaudo_eliminada', 'activa', 'eliminada', (int)$_SESSION['user']['id']);
            $pdo->commit();
            $msg = 'Se eliminó la carga de recaudo #' . $cargaId . ' y su detalle asociado.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = 'No fue posible eliminar la carga de recaudo: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_type']) && $_POST['upload_type'] === 'recaudo') {
    $file = $_FILES['archivo_recaudo'] ?? null;
    if (!$file || (int)$file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = build_validation_error(0, 'archivo_recaudo', '', 'Debe adjuntar un archivo de recaudo válido.');
    } else {
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            $errors[] = build_validation_error(0, 'archivo_recaudo', (string)$file['name'], 'Formato no permitido. Use CSV/XLSX/XLS.');
        }
    }

    if (empty($errors)) {
        try {
            $rows = parse_input_file((string)$file['tmp_name'], $ext);
            $validation = recaudo_validate_and_prepare($pdo, $rows);
            $errors = $validation['errors'] ?? [];
            $warnings = $validation['warnings'] ?? [];
            $summary = $validation['summary'] ?? $summary;
            $validRows = $validation['valid_rows'] ?? [];
            $periodoDetectado = $validation['periodo_detectado'] ?? null;
            $diagnosticResult = $validation['diagnostic'] ?? null;
            $validationResult = [
                'periodo_detectado' => $periodoDetectado,
                'errors' => $errors,
                'warnings' => $warnings,
                'summary' => $summary,
                'diagnostic' => $diagnosticResult,
            ];

            if (!empty($errors)) {
                $msg = 'Carga de recaudo rechazada por validaciones obligatorias.';
            } elseif (empty($validRows)) {
                $msg = 'No hay registros válidos para aplicar.';
            } else {
                $pdo->beginTransaction();
                $hash = hash_file('sha256', (string)$file['tmp_name']) ?: '';
                if ($hash === '') {
                    throw new RuntimeException('No fue posible calcular hash SHA-256 del archivo de recaudo.');
                }

                $dupStmt = $pdo->prepare('SELECT id FROM cargas_recaudo WHERE hash_sha256 = ? LIMIT 1');
                $dupStmt->execute([$hash]);
                if ($dupStmt->fetchColumn()) {
                    throw new RuntimeException('Archivo duplicado detectado por hash SHA-256.');
                }

                $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM cargas_recaudo WHERE periodo = ?');
                $versionStmt->execute([(string)$periodoDetectado]);
                $versionPeriodo = ((int)$versionStmt->fetchColumn()) + 1;

                // Se conservan cargas históricas para permitir trazabilidad y conciliación posterior.

                $cargaStmt = $pdo->prepare('INSERT INTO cargas_recaudo (archivo, hash_sha256, periodo, fecha_carga, usuario_id, total_registros, total_recaudo, version, activo, estado, created_at) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, 1, "activa", NOW())');
                $cargaStmt->execute([
                    (string)$file['name'],
                    $hash,
                    (string)$periodoDetectado,
                    (int)($_SESSION['user']['id'] ?? 0),
                    (int)$summary['validas'],
                    (float)$summary['total_aplicado'],
                    $versionPeriodo,
                ]);
                $cargaId = (int)$pdo->lastInsertId();

                if (!empty($warnings)) {
                    $errStmt = $pdo->prepare('INSERT INTO recaudo_validacion_errores (carga_id, fila, campo, valor, motivo, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                    foreach ($warnings as $warning) {
                        $errStmt->execute([
                            $cargaId,
                            (int)($warning['fila'] ?? 0),
                            (string)($warning['campo'] ?? ''),
                            (string)($warning['valor'] ?? ''),
                            (string)($warning['motivo'] ?? ''),
                        ]);
                    }
                }

                if ($diagnosticResult !== null) {
                    recaudo_diagnostic_write($cargaId, $diagnosticResult);
                }
                recaudo_apply_rows($pdo, $cargaId, $validRows);
                recaudo_run_reconciliation($pdo, $cargaId);
                $diagnosticResult = recaudo_diagnostic_load($cargaId);
                $validationResult['diagnostic'] = $diagnosticResult;
                recaudo_build_aggregates($pdo, $cargaId);
                recaudo_auto_conciliar($pdo, $cargaId);
                if ($periodoDetectado !== null) {
                    periodo_control_registrar_recaudo($pdo, (string)$periodoDetectado);
                }
                audit_log($pdo, 'cargas_recaudo', $cargaId, 'carga_recaudo_creada', null, 'activa', (int)$_SESSION['user']['id']);
                $pdo->commit();
                $msg = 'Recaudo cargado correctamente y conciliación automática ejecutada. Periodo detectado: ' . $periodoDetectado . '. Importe aplicado: $' . number_format((float)$summary['total_aplicado'], 2, ',', '.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = build_validation_error(0, 'proceso', '', $e->getMessage());
            $validationResult = [
                'periodo_detectado' => $periodoDetectado,
                'errors' => $errors,
                'warnings' => $warnings,
                'summary' => $summary,
                'diagnostic' => $diagnosticResult,
            ];
            $msg = 'No fue posible procesar el recaudo.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_type']) && $_POST['upload_type'] === 'presupuesto') {
    $file = $_FILES['archivo_presupuesto'] ?? null;
    if (!$file || (int)$file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = build_validation_error(0, 'archivo_presupuesto', '', 'Debe adjuntar archivo de presupuesto.');
    } else {
        try {
            $rows = parse_input_file((string)$file['tmp_name'], strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)));
            $headers = $rows[0] ?? [];
            $map = [];
            foreach ($headers as $idx => $header) {
                $map[normalize_header_name($header)] = $idx;
            }
            foreach (['periodo', 'vendedor', 'valor_presupuesto'] as $required) {
                if (!isset($map[$required])) {
                    throw new RuntimeException('El presupuesto debe incluir columnas: periodo, vendedor, valor_presupuesto.');
                }
            }

            $stmt = $pdo->prepare('INSERT INTO presupuesto_recaudo (periodo, vendedor, valor_presupuesto, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE valor_presupuesto = VALUES(valor_presupuesto), updated_at = NOW()');
            $periodosPresupuesto = [];
            for ($i = 1, $len = count($rows); $i < $len; $i++) {
                $r = $rows[$i] ?? [];
                $periodo = trim((string)($r[$map['periodo']] ?? ''));
                $vendedor = trim((string)($r[$map['vendedor']] ?? ''));
                $valor = normalize_decimal_value($r[$map['valor_presupuesto']] ?? null);
                if ($periodo === '' || $vendedor === '' || $valor === null) {
                    continue;
                }
                $stmt->execute([$periodo, $vendedor, $valor]);
                $periodoNormalizado = periodo_normalizar($periodo);
                if ($periodoNormalizado !== '') {
                    $periodosPresupuesto[$periodoNormalizado] = true;
                }
            }

            foreach (array_keys($periodosPresupuesto) as $periodoPresupuesto) {
                periodo_control_registrar_presupuesto($pdo, $periodoPresupuesto);
            }
            $msg = 'Presupuesto de recaudo cargado correctamente.';
        } catch (Throwable $e) {
            $errors[] = build_validation_error(0, 'archivo_presupuesto', '', $e->getMessage());
        }
    }
}

$periodoActivoSeleccionado = trim((string)($_GET['periodo'] ?? ''));
if ($periodoActivoSeleccionado === '') {
    $periodoActivoSeleccionado = periodo_control_obtener_activo($pdo) ?? 'todos';
}

$latestLoadSql = 'SELECT c.periodo, MAX(c.id) AS carga_id FROM cargas_recaudo c WHERE c.estado = "activa" AND c.activo = 1 GROUP BY c.periodo';
$wherePeriodo = '';
if ($periodoActivoSeleccionado !== 'todos' && periodo_normalizar($periodoActivoSeleccionado) !== '') {
    $wherePeriodo = ' WHERE d.periodo = ' . $pdo->quote($periodoActivoSeleccionado);
}

$kpi = $pdo->query('SELECT COALESCE(SUM(d.importe_aplicado),0) recaudo_periodo, COALESCE((SELECT SUM(d2.saldo_pendiente) FROM cartera_documentos d2 INNER JOIN cargas_cartera c2 ON c2.id = d2.id_carga WHERE c2.activo = 1 AND c2.estado = "activa" AND d2.estado_documento = "activo"),0) cartera_total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id ' . $wherePeriodo)->fetch(PDO::FETCH_ASSOC) ?: ['recaudo_periodo' => 0, 'cartera_total' => 0];
$recaudoPeriodo = (float)$kpi['recaudo_periodo'];
$carteraTotal = (float)$kpi['cartera_total'];
$recuperacionPct = $carteraTotal > 0 ? ($recaudoPeriodo / $carteraTotal) * 100 : 0;

$byVendedor = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.vendedor), ""), "Sin vendedor") categoria, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id ' . $wherePeriodo . ' GROUP BY categoria ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byUen = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.uen), ""), "Sin UEN") categoria, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id ' . $wherePeriodo . ' GROUP BY categoria ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byBucket = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.bucket), ""), "Sin bucket") categoria, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id ' . $wherePeriodo . ' GROUP BY categoria ORDER BY total DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$trend = $pdo->query('SELECT d.periodo, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id ' . $wherePeriodo . ' GROUP BY d.periodo ORDER BY d.periodo')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$paretoClientes = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.cliente), ""), "Sin cliente") cliente, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id ' . $wherePeriodo . ' GROUP BY cliente ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$wherePresupuesto = '';
if ($periodoActivoSeleccionado !== 'todos' && periodo_normalizar($periodoActivoSeleccionado) !== '') {
    $wherePresupuesto = ' WHERE p.periodo = ' . $pdo->quote($periodoActivoSeleccionado);
}

$vsPresupuesto = $pdo->query('SELECT p.periodo AS periodo, COALESCE(SUM(p.valor_presupuesto),0) AS presupuesto, COALESCE(SUM(t.recaudo_real),0) AS recaudo_real FROM presupuesto_recaudo p LEFT JOIN (SELECT d.periodo AS periodo, d.vendedor AS vendedor, SUM(d.importe_aplicado) AS recaudo_real FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id GROUP BY d.periodo, d.vendedor) AS t ON t.periodo = p.periodo AND t.vendedor = p.vendedor' . $wherePresupuesto . ' GROUP BY p.periodo ORDER BY p.periodo')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$periodosControl = $pdo->query('SELECT periodo, cartera_cargada, recaudo_cargado, presupuesto_cargado, estado, periodo_activo FROM control_periodos_cartera ORDER BY periodo DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$historial = $pdo->query('SELECT c.id, c.archivo, c.hash_sha256, c.periodo, c.total_registros, c.total_recaudo, c.version, c.activo, c.fecha_carga, c.estado, u.nombre AS usuario FROM cargas_recaudo c LEFT JOIN usuarios u ON u.id = c.usuario_id ORDER BY c.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$detalleCargaId = (int)($_GET['detalle_carga_id'] ?? 0);
$detalleRegistros = [];
$detalleErrores = [];
$detalleDiagnostico = null;

$periodoCarteraFiltro = trim((string)($_GET['periodo_cartera'] ?? ''));
$periodoRecaudoFiltro = trim((string)($_GET['periodo_recaudo'] ?? ''));
$uenFiltro = trim((string)($_GET['uen'] ?? ''));
$canalFiltro = trim((string)($_GET['canal'] ?? ''));
$vendedorFiltro = trim((string)($_GET['vendedor'] ?? ''));
$estadoConciliacionFiltro = trim((string)($_GET['estado_conciliacion'] ?? ''));

$whereConc = [];
$paramsConc = [];
if ($periodoCarteraFiltro !== '') { $whereConc[] = 'c.periodo_cartera = ?'; $paramsConc[] = $periodoCarteraFiltro; }
if ($periodoRecaudoFiltro !== '') { $whereConc[] = 'c.periodo_recaudo = ?'; $paramsConc[] = $periodoRecaudoFiltro; }
if ($uenFiltro !== '') { $whereConc[] = 'COALESCE(cd.uens, "") = ?'; $paramsConc[] = $uenFiltro; }
if ($canalFiltro !== '') { $whereConc[] = 'COALESCE(cd.canal, "") = ?'; $paramsConc[] = $canalFiltro; }
if ($vendedorFiltro !== '') { $whereConc[] = 'COALESCE(rd.vendedor, "") = ?'; $paramsConc[] = $vendedorFiltro; }
if ($estadoConciliacionFiltro !== '') { $whereConc[] = 'c.estado_conciliacion = ?'; $paramsConc[] = $estadoConciliacionFiltro; }
$whereConcSql = $whereConc ? (' WHERE ' . implode(' AND ', $whereConc)) : '';
$whereConcSqlAgg = str_replace('c.', 'c2.', $whereConcSql);

$conciliacionRows = [];
$kpiConc = ['cartera_total' => 0, 'cartera_conciliada_total' => 0, 'cartera_conciliada_parcial' => 0, 'cartera_sin_pago' => 0, 'pagos_sin_factura' => 0, 'recaudo_aplicado' => 0];

try {
    recaudo_ensure_reconciliation_schema($pdo);

    $concStmt = $pdo->prepare('SELECT c.numero_documento, COALESCE(NULLIF(c.cliente_cartera, ""), c.cliente_recaudo, "") AS cliente, COALESCE(c.saldo_pendiente_cartera, c.valor_factura, 0) AS valor_factura, COALESCE(c.importe_aplicado, c.valor_pagado, 0) AS valor_pagado, COALESCE(c.diferencia, c.saldo_resultante, 0) AS saldo_resultante, COALESCE(c.estado, c.estado_conciliacion) AS estado_conciliacion, COALESCE(c.observacion, c.detalle_validacion, "") AS detalle_validacion FROM conciliacion_cartera_recaudo c LEFT JOIN cartera_documentos cd ON cd.id = COALESCE(c.id_cartera_documento, c.cartera_id) LEFT JOIN recaudo_detalle rd ON rd.id = c.id_recaudo_detalle' . $whereConcSql . ' ORDER BY c.id DESC LIMIT 300');
    $concStmt->execute($paramsConc);
    $conciliacionRows = $concStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $kpiConcStmt = $pdo->prepare('SELECT COALESCE((SELECT SUM(base.saldo_ref) FROM (SELECT COALESCE(c2.id_cartera_documento, c2.cartera_id) AS doc_id, MAX(COALESCE(c2.saldo_pendiente_cartera, c2.valor_factura, 0)) AS saldo_ref FROM conciliacion_cartera_recaudo c2 LEFT JOIN cartera_documentos cd ON cd.id = COALESCE(c2.id_cartera_documento, c2.cartera_id) LEFT JOIN recaudo_detalle rd ON rd.id = c2.id_recaudo_detalle' . $whereConcSqlAgg . ' AND COALESCE(c2.id_cartera_documento, c2.cartera_id) IS NOT NULL GROUP BY COALESCE(c2.id_cartera_documento, c2.cartera_id)) AS base), 0) AS cartera_total, COALESCE(SUM(CASE WHEN COALESCE(c.estado, c.estado_conciliacion) = "conciliado_total" THEN COALESCE(c.importe_aplicado, c.valor_pagado, 0) ELSE 0 END),0) AS cartera_conciliada_total, COALESCE(SUM(CASE WHEN COALESCE(c.estado, c.estado_conciliacion) = "conciliado_parcial" THEN COALESCE(c.importe_aplicado, c.valor_pagado, 0) ELSE 0 END),0) AS cartera_conciliada_parcial, COALESCE(SUM(CASE WHEN COALESCE(c.estado, c.estado_conciliacion) = "sin_pago" THEN COALESCE(c.saldo_pendiente_cartera, c.valor_factura, 0) ELSE 0 END),0) AS cartera_sin_pago, COALESCE(SUM(CASE WHEN COALESCE(c.estado, c.estado_conciliacion) = "pago_sin_factura" THEN COALESCE(c.importe_aplicado, c.valor_pagado, 0) ELSE 0 END),0) AS pagos_sin_factura, COALESCE(SUM(CASE WHEN c.id_recaudo_detalle IS NOT NULL THEN COALESCE(c.importe_aplicado, c.valor_pagado, 0) ELSE 0 END),0) AS recaudo_aplicado FROM conciliacion_cartera_recaudo c LEFT JOIN cartera_documentos cd ON cd.id = COALESCE(c.id_cartera_documento, c.cartera_id) LEFT JOIN recaudo_detalle rd ON rd.id = c.id_recaudo_detalle' . $whereConcSql);
    $kpiConcStmt->execute($paramsConc);
    $kpiConc = $kpiConcStmt->fetch(PDO::FETCH_ASSOC) ?: $kpiConc;
} catch (Throwable $e) {
    $warnings[] = build_validation_error(0, 'conciliacion', '', 'No fue posible consultar la tabla de conciliación automáticamente: ' . $e->getMessage());
}
$recaudoNoConciliado = max(0, (float)$recaudoPeriodo - (float)$kpiConc['recaudo_aplicado']);
$porcentajeConciliacion = (float)$kpiConc['cartera_total'] > 0 ? (((float)$kpiConc['cartera_conciliada_total'] + (float)$kpiConc['cartera_conciliada_parcial']) / (float)$kpiConc['cartera_total']) * 100 : 0;
$diasPeriodo = (int)($_GET['dias_periodo'] ?? 30);
$carteraPromedio = ((float)$carteraTotal + (float)$kpiConc['cartera_sin_pago']) / 2;
$rotacionCartera = (float)$recaudoPeriodo > 0 ? ($carteraPromedio / (float)$recaudoPeriodo) * $diasPeriodo : 0;

$uenOpciones = $pdo->query('SELECT DISTINCT COALESCE(NULLIF(TRIM(uens), ""), "") AS valor FROM cartera_documentos ORDER BY valor')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$canalOpciones = $pdo->query('SELECT DISTINCT COALESCE(NULLIF(TRIM(canal), ""), "") AS valor FROM cartera_documentos ORDER BY valor')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$vendedorOpciones = $pdo->query('SELECT DISTINCT COALESCE(NULLIF(TRIM(vendedor), ""), "") AS valor FROM recaudo_detalle ORDER BY valor')->fetchAll(PDO::FETCH_COLUMN) ?: [];

if ($detalleCargaId > 0) {
    $stmt = $pdo->prepare('SELECT d.id, d.nro_recibo, d.fecha_recibo, d.fecha_aplicacion, d.documento_aplicado, d.cliente, d.vendedor, d.importe_aplicado, d.saldo_documento, d.periodo FROM recaudo_detalle d INNER JOIN cargas_recaudo c ON c.id = d.carga_id WHERE d.carga_id = ? AND c.estado = "activa" AND c.activo = 1 ORDER BY d.id ASC LIMIT 300');
    $stmt->execute([$detalleCargaId]);
    $detalleRegistros = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare('SELECT fila, campo, valor, motivo FROM recaudo_validacion_errores WHERE carga_id = ? ORDER BY id ASC LIMIT 300');
    $stmt->execute([$detalleCargaId]);
    $detalleErrores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $detalleDiagnostico = recaudo_diagnostic_load($detalleCargaId);
}

ob_start();
?>
<h2>Carga y conciliación de recaudo</h2>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
<?php if ($periodoDetectado): ?><div class="alert alert-ok">Periodo detectado automáticamente: <strong><?= htmlspecialchars((string)$periodoDetectado) ?></strong></div><?php endif; ?>
<?php if ($warnings): ?><div class="alert alert-info"><ul><?php foreach ($warnings as $warning): ?><li>Fila <?= (int)($warning['fila'] ?? 0) ?> - <?= htmlspecialchars((string)($warning['motivo'] ?? '')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li>Fila <?= (int)($error['fila'] ?? 0) ?> - <?= htmlspecialchars((string)($error['motivo'] ?? '')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($validationResult !== null): ?>
<section class="card">
  <h3>Resultado de validación de recaudo</h3>
  <p><strong>Periodo detectado:</strong> <?= htmlspecialchars((string)($validationResult['periodo_detectado'] ?? 'No detectado')) ?></p>
  <p><strong>Registros válidos:</strong> <?= (int)(($validationResult['summary']['validas'] ?? 0)) ?></p>
  <?php if (!empty($validationResult['errors'])): ?>
    <p><strong>Errores por fila:</strong></p>
    <ul>
      <?php foreach ($validationResult['errors'] as $error): ?>
        <li>Fila <?= (int)($error['fila'] ?? 0) ?> - <?= htmlspecialchars((string)($error['motivo'] ?? '')) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p>No se encontraron errores obligatorios por fila.</p>
  <?php endif; ?>
  <?php if (!empty($validationResult['warnings'])): ?>
    <p><strong>Observaciones por fila:</strong></p>
    <ul>
      <?php foreach ($validationResult['warnings'] as $warning): ?>
        <li>Fila <?= (int)($warning['fila'] ?? 0) ?> - <?= htmlspecialchars((string)($warning['motivo'] ?? '')) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php $diagnosticToShow = $validationResult['diagnostic'] ?? null; ?>
<?php if (is_array($diagnosticToShow)): ?>
<section class="card">
  <h3>Logs de diagnóstico del cruce</h3>
  <?php if (!empty($diagnosticToShow['written_at']) && !empty($_POST['upload_type']) && $_POST['upload_type'] === 'recaudo'): ?>
    <p><strong>Archivo persistido:</strong> <code><?= htmlspecialchars(recaudo_diagnostic_directory()) ?></code> (generado <?= htmlspecialchars((string)$diagnosticToShow['written_at']) ?>)</p>
  <?php endif; ?>
  <div class="row" style="gap:16px;">
    <div><strong>Filas leídas del archivo:</strong> <?= (int)($diagnosticToShow['rows_read'] ?? 0) ?></div>
    <div><strong>Filas no vacías:</strong> <?= (int)($diagnosticToShow['rows_non_empty'] ?? 0) ?></div>
    <div><strong>Filas válidas:</strong> <?= (int)($diagnosticToShow['rows_valid'] ?? 0) ?></div>
    <div><strong>Docs activos en cartera para el período:</strong> <?= (int)($diagnosticToShow['cartera_active_documents_period'] ?? 0) ?></div>
  </div>
  <p><strong>Descartadas por documento vacío:</strong> <?= (int)($diagnosticToShow['discarded_empty_document'] ?? 0) ?> | <strong>Descartadas por tipo vacío:</strong> <?= (int)($diagnosticToShow['discarded_empty_type'] ?? 0) ?> | <strong>Descartadas por otros obligatorios:</strong> <?= (int)($diagnosticToShow['discarded_other_required'] ?? 0) ?></p>
  <?php if (!empty($diagnosticToShow['match_summary'])): ?>
    <p><strong>Resumen de coincidencias exactas:</strong> <?= (int)($diagnosticToShow['match_summary']['coincidencias_exactas'] ?? 0) ?> de <?= (int)($diagnosticToShow['match_summary']['detalles_procesados'] ?? 0) ?> detalles procesados (umbral casi-cero: <?= (int)($diagnosticToShow['match_summary']['umbral_casi_cero'] ?? 0) ?>).</p>
  <?php endif; ?>

  <?php if (!empty($diagnosticToShow['search_filters'])): ?>
    <h4>Filtro efectivo del cruce</h4>
    <p><strong>SQL de referencia:</strong> <code><?= htmlspecialchars((string)($diagnosticToShow['search_filters']['dataset_sql'] ?? '')) ?></code></p>
    <ul>
      <?php foreach (($diagnosticToShow['search_filters']['dataset_conditions'] ?? []) as $condition => $value): ?>
        <li><strong><?= htmlspecialchars((string)$condition) ?>:</strong> <code><?= htmlspecialchars(is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!empty($diagnosticToShow['discard_examples'])): ?>
    <h4>Ejemplos de filas descartadas</h4>
    <ul>
      <?php foreach ($diagnosticToShow['discard_examples'] as $discard): ?>
        <li>
          Fila <?= (int)($discard['fila'] ?? 0) ?> —
          motivo: <?= htmlspecialchars((string)($discard['reason'] ?? '')) ?>,
          documento: <code><?= htmlspecialchars((string)($discard['raw_document'] ?? '')) ?></code>,
          tipo: <code><?= htmlspecialchars((string)($discard['raw_type'] ?? '')) ?></code>,
          preview: <?= htmlspecialchars(implode(' | ', $discard['row_preview'] ?? [])) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!empty($diagnosticToShow['attempts'])): ?>
    <h4>Primeros 5 intentos de cruce</h4>
    <table class="table">
      <tr><th>Fila</th><th>Documento archivo</th><th>Documento normalizado</th><th>Tipo archivo</th><th>Tipo homologado</th><th>Período</th><th>Resultado</th><th>Motivo</th><th>Cartera / Filtro</th></tr>
      <?php foreach ($diagnosticToShow['attempts'] as $attempt): ?>
        <tr>
          <td><?= (int)($attempt['fila'] ?? 0) ?></td>
          <td><code><?= htmlspecialchars((string)($attempt['documento_archivo'] ?? '')) ?></code></td>
          <td><code><?= htmlspecialchars((string)($attempt['documento_normalizado'] ?? '')) ?></code></td>
          <td><?= htmlspecialchars((string)($attempt['tipo_archivo'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($attempt['tipo_normalizado_homologado'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($attempt['periodo_recaudo'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($attempt['resultado_busqueda'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($attempt['motivo'] ?? '')) ?></td>
          <td>
            ID <?= htmlspecialchars((string)($attempt['cartera_documento_id'] ?? 'N/A')) ?><br>
            doc cartera <code><?= htmlspecialchars((string)($attempt['documento_cartera_guardado'] ?? '')) ?></code> → <code><?= htmlspecialchars((string)($attempt['documento_cartera_normalizado'] ?? '')) ?></code><br>
            tipo <?= htmlspecialchars((string)($attempt['tipo_cartera'] ?? '')) ?><br>
            tipo homologado <?= htmlspecialchars((string)($attempt['tipo_cartera_homologado'] ?? '')) ?><br>
            estado <?= htmlspecialchars((string)($attempt['estado_documento_cartera'] ?? '')) ?><br>
            período <?= htmlspecialchars((string)($attempt['periodo_cartera'] ?? '')) ?><br>
            filtro doc/tipo/estado/período:
            <code><?= htmlspecialchars(json_encode(($attempt['filtro_busqueda']['condiciones'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code><br>
            candidatos:
            <code><?= htmlspecialchars(json_encode(($attempt['resumen_candidatos'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if (!empty($diagnosticToShow['results_by_state'])): ?>
    <h4>Resultados por estado</h4>
    <ul>
      <?php foreach ($diagnosticToShow['results_by_state'] as $state => $count): ?>
        <li><strong><?= htmlspecialchars((string)$state) ?>:</strong> <?= (int)$count ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!empty($diagnosticToShow['format_comparison'])): ?>
    <h4>Comparación directa de formato</h4>
    <p>Recaudo recibido: <code><?= htmlspecialchars((string)($diagnosticToShow['format_comparison']['recaudo_original'] ?? '')) ?></code> → normalizado: <code><?= htmlspecialchars((string)($diagnosticToShow['format_comparison']['recaudo_normalizado'] ?? '')) ?></code></p>
    <p>Tipo recaudo: <code><?= htmlspecialchars((string)($diagnosticToShow['format_comparison']['tipo_recaudo_original'] ?? '')) ?></code> → homologado: <code><?= htmlspecialchars((string)($diagnosticToShow['format_comparison']['tipo_recaudo_homologado'] ?? '')) ?></code></p>
    <?php if (!empty($diagnosticToShow['format_comparison']['cartera_candidates'])): ?>
      <ul>
        <?php foreach ($diagnosticToShow['format_comparison']['cartera_candidates'] as $candidate): ?>
          <li>
            Cartera #<?= (int)($candidate['id'] ?? 0) ?>:
            guardado <code><?= htmlspecialchars((string)($candidate['nro_documento_guardado'] ?? '')) ?></code>,
            normalizado <code><?= htmlspecialchars((string)($candidate['nro_documento_normalizado'] ?? '')) ?></code>,
            tipo <?= htmlspecialchars((string)($candidate['tipo'] ?? '')) ?> (<?= htmlspecialchars((string)($candidate['tipo_homologado'] ?? '')) ?>),
            estado <?= htmlspecialchars((string)($candidate['estado_documento'] ?? '')) ?>,
            período <?= htmlspecialchars((string)($candidate['periodo'] ?? '')) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($diagnosticToShow['format_comparison']['similar_in_cartera'])): ?>
      <p><strong>Similares encontrados en cartera:</strong></p>
      <ul>
        <?php foreach ($diagnosticToShow['format_comparison']['similar_in_cartera'] as $candidate): ?>
          <li>
            #<?= (int)($candidate['id'] ?? 0) ?> —
            <code><?= htmlspecialchars((string)($candidate['nro_documento'] ?? '')) ?></code>,
            tipo <?= htmlspecialchars((string)($candidate['tipo'] ?? '')) ?>,
            estado <?= htmlspecialchars((string)($candidate['estado_documento'] ?? '')) ?>,
            período <?= htmlspecialchars((string)($candidate['periodo_documento'] ?? '')) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($diagnosticToShow['notes'])): ?>
    <h4>Notas de diagnóstico</h4>
    <ul>
      <?php foreach ($diagnosticToShow['notes'] as $note): ?>
        <li><?= htmlspecialchars((string)$note) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="gd-kpi-grid">
  <article class="gd-kpi-card"><span>Recaudo acumulado</span><strong>$<?= number_format($recaudoPeriodo, 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>% recuperación de cartera</span><strong><?= number_format($recuperacionPct, 2, ',', '.') ?>%</strong></article>
  <article class="gd-kpi-card"><span>Registros válidos última carga</span><strong><?= (int)$summary['validas'] ?></strong></article>
  <article class="gd-kpi-card"><span>Registros con error última carga</span><strong><?= (int)$summary['con_error'] ?></strong></article>
</section>

<section class="gd-kpi-grid" style="margin-top:12px;">
  <article class="gd-kpi-card"><span>Cartera total</span><strong>$<?= number_format((float)$kpiConc['cartera_total'], 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Conciliada total</span><strong>$<?= number_format((float)$kpiConc['cartera_conciliada_total'], 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Conciliada parcial</span><strong>$<?= number_format((float)$kpiConc['cartera_conciliada_parcial'], 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Cartera sin pago</span><strong>$<?= number_format((float)$kpiConc['cartera_sin_pago'], 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Pagos sin factura</span><strong>$<?= number_format((float)$kpiConc['pagos_sin_factura'], 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Recaudo aplicado</span><strong>$<?= number_format((float)$kpiConc['recaudo_aplicado'], 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Recaudo no conciliado</span><strong>$<?= number_format((float)$recaudoNoConciliado, 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>% conciliación</span><strong><?= number_format((float)$porcentajeConciliacion, 2, ',', '.') ?>%</strong></article>
  <article class="gd-kpi-card"><span>Rotación cartera (días)</span><strong><?= number_format((float)$rotacionCartera, 2, ',', '.') ?></strong></article>
</section>

<section class="gd-grid-2">
  <article class="card">
    <h3>Cargar archivo de recaudo</h3>
    <form id="form-recaudo" method="post" enctype="multipart/form-data" class="form-carga">
      <input type="hidden" name="upload_type" value="recaudo">
      <label>Archivo recaudo (CSV / XLSX / XLS) <input id="archivo-recaudo" type="file" name="archivo_recaudo" accept=".csv,.xlsx,.xls" required></label>
      <button id="btn-cargar-recaudo" class="btn" type="submit">Cargar</button>
    </form>
  </article>
  <article class="card">
    <h3>Cargar presupuesto de recaudo</h3>
    <form class="form-carga" method="post" enctype="multipart/form-data">
      <input type="hidden" name="upload_type" value="presupuesto">
      <label>Archivo presupuesto (periodo,vendedor,valor_presupuesto) <input type="file" name="archivo_presupuesto" accept=".csv,.xlsx,.xls" required></label>
      <button class="btn" type="submit">Cargar presupuesto</button>
    </form>
  </article>
</section>

<section class="card">
  <h3>Control maestro de periodos</h3>
  <form method="get" style="margin-bottom:12px;">
    <label>Periodo para dashboard
      <select name="periodo">
        <option value="todos" <?= $periodoActivoSeleccionado === 'todos' ? 'selected' : '' ?>>Todos</option>
        <?php foreach ($periodosControl as $control): ?>
          <?php $optPeriodo = (string)$control['periodo']; ?>
          <option value="<?= htmlspecialchars($optPeriodo) ?>" <?= $periodoActivoSeleccionado === $optPeriodo ? 'selected' : '' ?>><?= htmlspecialchars($optPeriodo) ?><?= ((int)$control['periodo_activo'] === 1 ? ' (activo)' : '') ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Aplicar filtro</button>
  </form>
  <table class="table">
    <tr><th>Periodo</th><th>Cartera</th><th>Recaudo</th><th>Presupuesto</th><th>Estado</th><th>Semáforo</th></tr>
    <?php foreach ($periodosControl as $control): ?>
      <?php
        $cartera = (int)$control['cartera_cargada'] === 1;
        $recaudo = (int)$control['recaudo_cargado'] === 1;
        $presupuesto = (int)$control['presupuesto_cargado'] === 1;
        $estadoPeriodo = ($cartera && $recaudo && $presupuesto) ? 'completo' : (($recaudo && !$cartera) ? 'inconsistente' : (($cartera && !$recaudo && !$presupuesto) ? 'parcial' : 'incompleto'));
        $semaforo = $estadoPeriodo === 'completo' ? '🟢 verde' : ($estadoPeriodo === 'inconsistente' ? '🔴 rojo' : '🟡 amarillo');
      ?>
      <tr>
        <td><?= htmlspecialchars((string)$control['periodo']) ?><?= ((int)$control['periodo_activo'] === 1 ? ' ⭐' : '') ?></td>
        <td><?= $cartera ? '✓' : '✗' ?></td>
        <td><?= $recaudo ? '✓' : '✗' ?></td>
        <td><?= $presupuesto ? '✓' : '✗' ?></td>
        <td><?= htmlspecialchars($estadoPeriodo) ?></td>
        <td><?= htmlspecialchars($semaforo) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>

<section class="card">
  <h3>Vista operativa de conciliación</h3>
  <form method="get" class="row" style="margin-bottom:12px;">
    <input type="hidden" name="periodo" value="<?= htmlspecialchars((string)$periodoActivoSeleccionado) ?>">
    <input type="text" name="periodo_cartera" placeholder="Periodo cartera (YYYY-MM)" value="<?= htmlspecialchars($periodoCarteraFiltro) ?>">
    <input type="text" name="periodo_recaudo" placeholder="Periodo recaudo (YYYY-MM)" value="<?= htmlspecialchars($periodoRecaudoFiltro) ?>">
    <select name="uen"><option value="">UEN (todas)</option><?php foreach ($uenOpciones as $opt): ?><option value="<?= htmlspecialchars((string)$opt) ?>" <?= $uenFiltro === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars((string)($opt === '' ? 'Sin UEN' : $opt)) ?></option><?php endforeach; ?></select>
    <select name="canal"><option value="">Canal (todos)</option><?php foreach ($canalOpciones as $opt): ?><option value="<?= htmlspecialchars((string)$opt) ?>" <?= $canalFiltro === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars((string)($opt === '' ? 'Sin canal' : $opt)) ?></option><?php endforeach; ?></select>
    <select name="vendedor"><option value="">Vendedor (todos)</option><?php foreach ($vendedorOpciones as $opt): ?><option value="<?= htmlspecialchars((string)$opt) ?>" <?= $vendedorFiltro === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars((string)($opt === '' ? 'Sin vendedor' : $opt)) ?></option><?php endforeach; ?></select>
    <select name="estado_conciliacion">
      <option value="">Estado (todos)</option>
      <?php foreach (['conciliado_total','conciliado_parcial','sin_pago','pago_sin_factura','pago_excedido','periodo_diferente','tipo_no_coincide'] as $estadoOpt): ?>
        <option value="<?= $estadoOpt ?>" <?= $estadoConciliacionFiltro === $estadoOpt ? 'selected' : '' ?>><?= $estadoOpt ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" min="1" max="366" name="dias_periodo" placeholder="Días periodo" value="<?= (int)$diasPeriodo ?>">
    <button class="btn" type="submit">Filtrar</button>
  </form>
  <table class="table">
    <tr><th>Documento</th><th>Cliente</th><th>Valor factura</th><th>Total pagado</th><th>Saldo</th><th>Estado</th><th>Observación</th></tr>
    <?php foreach ($conciliacionRows as $r): ?>
      <tr>
        <td><?= htmlspecialchars((string)$r['numero_documento']) ?></td>
        <td><?= htmlspecialchars((string)$r['cliente']) ?></td>
        <td>$<?= number_format((float)$r['valor_factura'], 2, ',', '.') ?></td>
        <td>$<?= number_format((float)$r['valor_pagado'], 2, ',', '.') ?></td>
        <td>$<?= number_format((float)$r['saldo_resultante'], 2, ',', '.') ?></td>
        <td><?= htmlspecialchars((string)$r['estado_conciliacion']) ?></td>
        <td><?= htmlspecialchars((string)($r['detalle_validacion'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>

<section class="card">
  <h3>Historial de cargas de recaudo</h3>
  <table class="table">
    <tr><th>ID</th><th>Archivo</th><th>Hash SHA-256</th><th>Periodo</th><th>Versión</th><th>Activo</th><th>Registros</th><th>Valor</th><th>Usuario</th><th>Fecha</th><th>Estado</th><th>Acción</th></tr>
    <?php foreach ($historial as $h): ?>
      <tr>
        <td><?= (int)$h['id'] ?></td>
        <td><?= htmlspecialchars((string)$h['archivo']) ?></td>
        <td><code><?= htmlspecialchars((string)$h['hash_sha256']) ?></code></td>
        <td><?= htmlspecialchars((string)$h['periodo']) ?></td>
        <td>v<?= (int)($h['version'] ?? 1) ?></td>
        <td><?= (int)($h['activo'] ?? 0) === 1 ? 'Sí' : 'No' ?></td>
        <td><?= (int)$h['total_registros'] ?></td>
        <td>$<?= number_format((float)$h['total_recaudo'], 2, ',', '.') ?></td>
        <td><?= htmlspecialchars((string)$h['usuario']) ?></td>
        <td><?= htmlspecialchars((string)$h['fecha_carga']) ?></td>
        <td><?= ((int)($h['activo'] ?? 0) === 1 && (string)$h['estado'] === 'activa') ? ui_badge('Activa', 'success') : ui_badge((string)$h['estado'], 'warning') ?></td>
        <td>
          <a href="<?= htmlspecialchars(app_url('recaudos/carga.php?detalle_carga_id=' . (int)$h['id'])) ?>">Ver</a>
          <?php if (current_user()['rol'] === 'admin'): ?>
            <form method="post" class="inline-form" onsubmit="return confirm('Está a punto de eliminar una carga de recaudo.\n\nArchivo: <?= htmlspecialchars((string)$h['archivo'], ENT_QUOTES) ?>\nRegistros: <?= (int)$h['total_registros'] ?>\nValor: $<?= number_format((float)$h['total_recaudo'], 2, ',', '.') ?>\n\n¿Desea continuar?');">
              <input type="hidden" name="action" value="eliminar_carga_recaudo">
              <input type="hidden" name="carga_id" value="<?= (int)$h['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Eliminar carga</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>

<?php if ($detalleCargaId > 0): ?>
<section class="card">
  <h3>Detalle carga #<?= $detalleCargaId ?></h3>
  <?php if ($detalleErrores): ?>
    <h4>Errores de validación</h4>
    <ul>
      <?php foreach ($detalleErrores as $err): ?>
        <li>Fila <?= (int)$err['fila'] ?> - <?= htmlspecialchars((string)$err['campo']) ?>: <?= htmlspecialchars((string)$err['motivo']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <table class="table">
    <tr><th>Recibo</th><th>Fecha recibo</th><th>Fecha aplicación</th><th>Documento</th><th>Cliente</th><th>Vendedor</th><th>Importe</th><th>Saldo doc.</th><th>Periodo</th></tr>
    <?php foreach ($detalleRegistros as $d): ?>
      <tr>
        <td><?= htmlspecialchars((string)$d['nro_recibo']) ?></td>
        <td><?= htmlspecialchars((string)$d['fecha_recibo']) ?></td>
        <td><?= htmlspecialchars((string)$d['fecha_aplicacion']) ?></td>
        <td><?= htmlspecialchars((string)$d['documento_aplicado']) ?></td>
        <td><?= htmlspecialchars((string)$d['cliente']) ?></td>
        <td><?= htmlspecialchars((string)$d['vendedor']) ?></td>
        <td>$<?= number_format((float)$d['importe_aplicado'], 2, ',', '.') ?></td>
        <td>$<?= number_format((float)$d['saldo_documento'], 2, ',', '.') ?></td>
        <td><?= htmlspecialchars((string)$d['periodo']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
<?php endif; ?>

<?php if (is_array($detalleDiagnostico)): ?>
<section class="card">
  <h3>Diagnóstico persistido de la carga #<?= $detalleCargaId ?></h3>
  <p><strong>Filas leídas:</strong> <?= (int)($detalleDiagnostico['rows_read'] ?? 0) ?> | <strong>Filas válidas:</strong> <?= (int)($detalleDiagnostico['rows_valid'] ?? 0) ?> | <strong>Docs activos del período:</strong> <?= (int)($detalleDiagnostico['cartera_active_documents_period'] ?? 0) ?> | <strong>Otros obligatorios faltantes:</strong> <?= (int)($detalleDiagnostico['discarded_other_required'] ?? 0) ?></p>
  <?php if (!empty($detalleDiagnostico['results_by_state'])): ?>
    <ul>
      <?php foreach ($detalleDiagnostico['results_by_state'] as $state => $count): ?>
        <li><strong><?= htmlspecialchars((string)$state) ?>:</strong> <?= (int)$count ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($detalleDiagnostico['attempts'])): ?>
    <table class="table">
      <tr><th>Fila</th><th>Documento</th><th>Normalizado</th><th>Tipo</th><th>Homologado</th><th>Resultado</th><th>Motivo</th><th>Detalle</th></tr>
      <?php foreach ($detalleDiagnostico['attempts'] as $attempt): ?>
        <tr>
          <td><?= (int)($attempt['fila'] ?? 0) ?></td>
          <td><code><?= htmlspecialchars((string)($attempt['documento_archivo'] ?? '')) ?></code></td>
          <td><code><?= htmlspecialchars((string)($attempt['documento_normalizado'] ?? '')) ?></code></td>
          <td><?= htmlspecialchars((string)($attempt['tipo_archivo'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($attempt['tipo_normalizado_homologado'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($attempt['resultado_busqueda'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($attempt['motivo'] ?? '')) ?></td>
          <td><code><?= htmlspecialchars(json_encode(['cartera' => $attempt['documento_cartera_guardado'] ?? null, 'cartera_normalizado' => $attempt['documento_cartera_normalizado'] ?? null, 'filtro' => $attempt['filtro_busqueda']['condiciones'] ?? [], 'candidatos' => $attempt['resumen_candidatos'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if (!empty($detalleDiagnostico['notes'])): ?>
    <ul>
      <?php foreach ($detalleDiagnostico['notes'] as $note): ?>
        <li><?= htmlspecialchars((string)$note) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="gd-grid-2">
  <article class="card"><h3>Recaudo por vendedor</h3><canvas id="vendedorChart" height="160"></canvas></article>
  <article class="card"><h3>Recaudo por UEN</h3><canvas id="uenChart" height="160"></canvas></article>
</section>
<section class="gd-grid-2">
  <article class="card"><h3>Recaudo por bucket</h3><canvas id="bucketChart" height="160"></canvas></article>
  <article class="card"><h3>Tendencia mensual de recaudo</h3><canvas id="trendChart" height="160"></canvas></article>
</section>
<section class="gd-grid-2">
  <article class="card"><h3>Pareto de clientes que más pagan</h3><canvas id="paretoClientesChart" height="160"></canvas></article>
  <article class="card"><h3>Recaudo real vs presupuesto</h3><canvas id="presupuestoChart" height="160"></canvas></article>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  var currency = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  function bar(id, labels, data, color) {
    new Chart(document.getElementById(id), { type: 'bar', data: { labels: labels, datasets: [{ data: data, backgroundColor: color || '#2563eb' }] }, options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return currency.format(ctx.raw || 0); } } } } } });
  }

  bar('vendedorChart', <?= json_encode(array_column($byVendedor, 'categoria'), JSON_UNESCAPED_UNICODE) ?>, <?= json_encode(array_map('floatval', array_column($byVendedor, 'total')), JSON_UNESCAPED_UNICODE) ?>);
  bar('uenChart', <?= json_encode(array_column($byUen, 'categoria'), JSON_UNESCAPED_UNICODE) ?>, <?= json_encode(array_map('floatval', array_column($byUen, 'total')), JSON_UNESCAPED_UNICODE) ?>, '#0891b2');
  bar('bucketChart', <?= json_encode(array_column($byBucket, 'categoria'), JSON_UNESCAPED_UNICODE) ?>, <?= json_encode(array_map('floatval', array_column($byBucket, 'total')), JSON_UNESCAPED_UNICODE) ?>, '#8b5cf6');
  bar('paretoClientesChart', <?= json_encode(array_column($paretoClientes, 'cliente'), JSON_UNESCAPED_UNICODE) ?>, <?= json_encode(array_map('floatval', array_column($paretoClientes, 'total')), JSON_UNESCAPED_UNICODE) ?>, '#f97316');

  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode(array_column($trend, 'periodo'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ label: 'Recaudo', data: <?= json_encode(array_map('floatval', array_column($trend, 'total')), JSON_UNESCAPED_UNICODE) ?>, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.2)', fill: true, tension: .3 }]
    }
  });

  new Chart(document.getElementById('presupuestoChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($vsPresupuesto, 'periodo'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { label: 'Presupuesto', data: <?= json_encode(array_map('floatval', array_column($vsPresupuesto, 'presupuesto')), JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#94a3b8' },
        { label: 'Real', data: <?= json_encode(array_map('floatval', array_column($vsPresupuesto, 'recaudo_real')), JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#22c55e' }
      ]
    }
  });
})();
</script>

<?php
$content = ob_get_clean();
render_layout('Recaudos', $content);
