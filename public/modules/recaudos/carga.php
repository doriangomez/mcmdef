<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/RecaudoImportService.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);

$msg = '';
$errorMsg = '';
$errors = [];
$warnings = [];
$summary = ['total' => 0, 'validas' => 0, 'con_error' => 0, 'total_aplicado' => 0.0];
$periodoDetectado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'eliminar_carga_recaudo') {
    $cargaId = (int)($_POST['carga_id'] ?? 0);
    if ($cargaId <= 0) {
        $errorMsg = 'La carga indicada no es válida.';
    } else {
        try {
            $pdo->beginTransaction();
            $cargaStmt = $pdo->prepare('SELECT id, archivo, total_registros, total_recaudo FROM cargas_recaudo WHERE id = ?');
            $cargaStmt->execute([$cargaId]);
            $carga = $cargaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$carga) {
                throw new RuntimeException('La carga de recaudo no existe.');
            }

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

            if (!empty($errors)) {
                $msg = 'Carga de recaudo rechazada por validaciones obligatorias.';
            } elseif (empty($validRows)) {
                $msg = 'No hay registros válidos para aplicar.';
            } else {
                $pdo->beginTransaction();
                $hash = hash_file('sha256', (string)$file['tmp_name']) ?: hash('sha256', microtime(true) . '|' . random_int(1, PHP_INT_MAX));
                $cargaStmt = $pdo->prepare('INSERT INTO cargas_recaudo (archivo, hash_sha256, periodo, fecha_carga, usuario_id, total_registros, total_recaudo, estado, created_at) VALUES (?, ?, ?, NOW(), ?, ?, ?, "activa", NOW())');
                $cargaStmt->execute([
                    (string)$file['name'],
                    $hash,
                    (string)$periodoDetectado,
                    (int)($_SESSION['user']['id'] ?? 0),
                    (int)$summary['validas'],
                    (float)$summary['total_aplicado'],
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

                recaudo_apply_rows($pdo, $cargaId, $validRows);
                recaudo_build_aggregates($pdo, $cargaId);
                audit_log($pdo, 'cargas_recaudo', $cargaId, 'carga_recaudo_creada', null, 'activa', (int)$_SESSION['user']['id']);
                $pdo->commit();
                $msg = 'Recaudo cargado y conciliado correctamente. Periodo detectado: ' . $periodoDetectado . '. Importe aplicado: $' . number_format((float)$summary['total_aplicado'], 2, ',', '.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = build_validation_error(0, 'proceso', '', $e->getMessage());
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
            for ($i = 1, $len = count($rows); $i < $len; $i++) {
                $r = $rows[$i] ?? [];
                $periodo = trim((string)($r[$map['periodo']] ?? ''));
                $vendedor = trim((string)($r[$map['vendedor']] ?? ''));
                $valor = normalize_decimal_value($r[$map['valor_presupuesto']] ?? null);
                if ($periodo === '' || $vendedor === '' || $valor === null) {
                    continue;
                }
                $stmt->execute([$periodo, $vendedor, $valor]);
            }
            $msg = 'Presupuesto de recaudo cargado correctamente.';
        } catch (Throwable $e) {
            $errors[] = build_validation_error(0, 'archivo_presupuesto', '', $e->getMessage());
        }
    }
}

$latestLoadSql = 'SELECT periodo, MAX(carga_id) AS carga_id FROM recaudo_detalle GROUP BY periodo';

$kpi = $pdo->query('SELECT COALESCE(SUM(d.importe_aplicado),0) recaudo_periodo, COALESCE((SELECT SUM(saldo_pendiente) FROM cartera_documentos),0) cartera_total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id')->fetch(PDO::FETCH_ASSOC) ?: ['recaudo_periodo' => 0, 'cartera_total' => 0];
$recaudoPeriodo = (float)$kpi['recaudo_periodo'];
$carteraTotal = (float)$kpi['cartera_total'];
$recuperacionPct = $carteraTotal > 0 ? ($recaudoPeriodo / $carteraTotal) * 100 : 0;

$byVendedor = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.vendedor), ""), "Sin vendedor") categoria, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id GROUP BY categoria ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byUen = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.uen), ""), "Sin UEN") categoria, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id GROUP BY categoria ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byBucket = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.bucket), ""), "Sin bucket") categoria, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id GROUP BY categoria ORDER BY total DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$trend = $pdo->query('SELECT d.periodo, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id GROUP BY d.periodo ORDER BY d.periodo')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$paretoClientes = $pdo->query('SELECT COALESCE(NULLIF(TRIM(d.cliente), ""), "Sin cliente") cliente, COALESCE(SUM(d.importe_aplicado),0) total FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id GROUP BY cliente ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$vsPresupuesto = $pdo->query('SELECT p.periodo AS periodo, COALESCE(SUM(p.valor_presupuesto),0) AS presupuesto, COALESCE(SUM(t.recaudo_real),0) AS recaudo_real FROM presupuesto_recaudo p LEFT JOIN (SELECT d.periodo AS periodo, d.vendedor AS vendedor, SUM(d.importe_aplicado) AS recaudo_real FROM recaudo_detalle d INNER JOIN (' . $latestLoadSql . ') x ON x.periodo = d.periodo AND x.carga_id = d.carga_id GROUP BY d.periodo, d.vendedor) AS t ON t.periodo = p.periodo AND t.vendedor = p.vendedor GROUP BY p.periodo ORDER BY p.periodo')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$historial = $pdo->query('SELECT c.id, c.archivo, c.hash_sha256, c.periodo, c.total_registros, c.total_recaudo, c.fecha_carga, c.estado, u.nombre AS usuario FROM cargas_recaudo c LEFT JOIN usuarios u ON u.id = c.usuario_id ORDER BY c.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$detalleCargaId = (int)($_GET['detalle_carga_id'] ?? 0);
$detalleRegistros = [];
$detalleErrores = [];
if ($detalleCargaId > 0) {
    $stmt = $pdo->prepare('SELECT id, nro_recibo, fecha_recibo, fecha_aplicacion, documento_aplicado, cliente, vendedor, importe_aplicado, saldo_documento, periodo FROM recaudo_detalle WHERE carga_id = ? ORDER BY id ASC LIMIT 300');
    $stmt->execute([$detalleCargaId]);
    $detalleRegistros = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare('SELECT fila, campo, valor, motivo FROM recaudo_validacion_errores WHERE carga_id = ? ORDER BY id ASC LIMIT 300');
    $stmt->execute([$detalleCargaId]);
    $detalleErrores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

ob_start();
?>
<h2>Carga y conciliación de recaudo</h2>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
<?php if ($periodoDetectado): ?><div class="alert alert-ok">Periodo detectado automáticamente: <strong><?= htmlspecialchars((string)$periodoDetectado) ?></strong></div><?php endif; ?>
<?php if ($warnings): ?><div class="alert alert-info"><ul><?php foreach ($warnings as $warning): ?><li>Fila <?= (int)($warning['fila'] ?? 0) ?> - <?= htmlspecialchars((string)($warning['motivo'] ?? '')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li>Fila <?= (int)($error['fila'] ?? 0) ?> - <?= htmlspecialchars((string)($error['motivo'] ?? '')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="gd-kpi-grid">
  <article class="gd-kpi-card"><span>Recaudo acumulado</span><strong>$<?= number_format($recaudoPeriodo, 2, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>% recuperación de cartera</span><strong><?= number_format($recuperacionPct, 2, ',', '.') ?>%</strong></article>
  <article class="gd-kpi-card"><span>Registros válidos última carga</span><strong><?= (int)$summary['validas'] ?></strong></article>
  <article class="gd-kpi-card"><span>Registros con error última carga</span><strong><?= (int)$summary['con_error'] ?></strong></article>
</section>

<section class="gd-grid-2">
  <article class="card">
    <h3>Cargar archivo de recaudo</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="upload_type" value="recaudo">
      <p>El periodo se detecta automáticamente a partir de <code>fecha_aplicacion</code> o <code>fecha_recibo</code>.</p>
      <label>Archivo recaudo (CSV/XLSX/XLS) <input type="file" name="archivo_recaudo" accept=".csv,.xlsx,.xls" required></label>
      <button class="btn" type="submit">Cargar y conciliar</button>
    </form>
  </article>
  <article class="card">
    <h3>Cargar presupuesto de recaudo</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="upload_type" value="presupuesto">
      <label>Archivo presupuesto (periodo,vendedor,valor_presupuesto) <input type="file" name="archivo_presupuesto" accept=".csv,.xlsx,.xls" required></label>
      <button class="btn" type="submit">Cargar presupuesto</button>
    </form>
  </article>
</section>

<section class="card">
  <h3>Historial de cargas de recaudo</h3>
  <table class="table">
    <tr><th>ID</th><th>Archivo</th><th>Hash SHA-256</th><th>Periodo</th><th>Registros</th><th>Valor</th><th>Usuario</th><th>Fecha</th><th>Estado</th><th>Acción</th></tr>
    <?php foreach ($historial as $h): ?>
      <tr>
        <td><?= (int)$h['id'] ?></td>
        <td><?= htmlspecialchars((string)$h['archivo']) ?></td>
        <td><code><?= htmlspecialchars((string)$h['hash_sha256']) ?></code></td>
        <td><?= htmlspecialchars((string)$h['periodo']) ?></td>
        <td><?= (int)$h['total_registros'] ?></td>
        <td>$<?= number_format((float)$h['total_recaudo'], 2, ',', '.') ?></td>
        <td><?= htmlspecialchars((string)$h['usuario']) ?></td>
        <td><?= htmlspecialchars((string)$h['fecha_carga']) ?></td>
        <td><?= htmlspecialchars((string)$h['estado']) ?></td>
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
