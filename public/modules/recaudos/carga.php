<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/RecaudoImportService.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);

$msg = '';
$errors = [];
$summary = ['total' => 0, 'validas' => 0, 'con_error' => 0, 'total_aplicado' => 0.0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_type']) && $_POST['upload_type'] === 'recaudo') {
    $periodoCarga = trim((string)($_POST['periodo_carga'] ?? date('Y-m')));
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
            $validation = recaudo_validate_and_prepare($pdo, $rows, $periodoCarga);
            $errors = $validation['errors'] ?? [];
            $summary = $validation['summary'] ?? $summary;
            $validRows = $validation['valid_rows'] ?? [];

            $criticalErrors = array_filter($errors, static function (array $err): bool {
                return stripos((string)($err['motivo'] ?? ''), 'recomendada') === false;
            });

            if (!empty($criticalErrors)) {
                $msg = 'Carga de recaudo rechazada por validaciones obligatorias.';
            } elseif (empty($validRows)) {
                $msg = 'No hay registros válidos para aplicar.';
            } else {
                $pdo->beginTransaction();
                $hash = hash('sha256', hash_file('sha256', (string)$file['tmp_name']) . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
                $cargaStmt = $pdo->prepare('INSERT INTO cargas_recaudo (fecha_carga, usuario_id, nombre_archivo, periodo_carga, total_registros, total_aplicado, hash_archivo, estado, created_at) VALUES (NOW(), ?, ?, ?, ?, ?, ?, "activa", NOW())');
                $cargaStmt->execute([
                    (int)$_SESSION['user']['id'],
                    (string)$file['name'],
                    $periodoCarga,
                    (int)$summary['validas'],
                    (float)$summary['total_aplicado'],
                    $hash,
                ]);
                $cargaId = (int)$pdo->lastInsertId();

                recaudo_apply_rows($pdo, $cargaId, $validRows);
                audit_log($pdo, 'cargas_recaudo', $cargaId, 'carga_recaudo_creada', null, 'activa', (int)$_SESSION['user']['id']);
                $pdo->commit();
                $msg = 'Recaudo cargado y conciliado correctamente. Importe aplicado: $' . number_format((float)$summary['total_aplicado'], 2, ',', '.');
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

$kpi = $pdo->query('SELECT COALESCE(SUM(ra.importe_aplicado),0) recaudo_periodo, COALESCE((SELECT SUM(saldo_pendiente) FROM cartera_documentos),0) cartera_total FROM recaudo_aplicacion ra')->fetch(PDO::FETCH_ASSOC) ?: ['recaudo_periodo' => 0, 'cartera_total' => 0];
$recaudoPeriodo = (float)$kpi['recaudo_periodo'];
$carteraTotal = (float)$kpi['cartera_total'];
$recuperacionPct = $carteraTotal > 0 ? ($recaudoPeriodo / $carteraTotal) * 100 : 0;

$byVendedor = $pdo->query('SELECT COALESCE(r.vendedor, "Sin vendedor") categoria, COALESCE(SUM(ra.importe_aplicado),0) total FROM recaudo_aplicacion ra INNER JOIN recaudos r ON r.id = ra.recaudo_id GROUP BY categoria ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byUen = $pdo->query('SELECT COALESCE(NULLIF(ra.uen, ""), "Sin UEN") categoria, COALESCE(SUM(ra.importe_aplicado),0) total FROM recaudo_aplicacion ra GROUP BY categoria ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byBucket = $pdo->query('SELECT COALESCE(NULLIF(ra.bucket, ""), "Sin bucket") categoria, COALESCE(SUM(ra.importe_aplicado),0) total FROM recaudo_aplicacion ra GROUP BY categoria ORDER BY total DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$trend = $pdo->query('SELECT DATE_FORMAT(COALESCE(ra.fecha_aplicacion, r.fecha_recibo), "%Y-%m") periodo, COALESCE(SUM(ra.importe_aplicado),0) total FROM recaudo_aplicacion ra INNER JOIN recaudos r ON r.id = ra.recaudo_id GROUP BY periodo ORDER BY periodo')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$paretoClientes = $pdo->query('SELECT COALESCE(r.cliente, "Sin cliente") cliente, COALESCE(SUM(ra.importe_aplicado),0) total FROM recaudo_aplicacion ra INNER JOIN recaudos r ON r.id = ra.recaudo_id GROUP BY cliente ORDER BY total DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$vsPresupuesto = $pdo->query('SELECT p.periodo, COALESCE(SUM(p.valor_presupuesto),0) presupuesto, COALESCE(SUM(t.real),0) real FROM presupuesto_recaudo p LEFT JOIN (SELECT DATE_FORMAT(COALESCE(ra.fecha_aplicacion, r.fecha_recibo), "%Y-%m") periodo, r.vendedor, SUM(ra.importe_aplicado) real FROM recaudo_aplicacion ra INNER JOIN recaudos r ON r.id = ra.recaudo_id GROUP BY periodo, r.vendedor) t ON t.periodo = p.periodo AND t.vendedor = p.vendedor GROUP BY p.periodo ORDER BY p.periodo')->fetchAll(PDO::FETCH_ASSOC) ?: [];

ob_start();
?>
<h2>Carga y conciliación de recaudo</h2>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
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
      <label>Periodo de carga <input type="month" name="periodo_carga" value="<?= htmlspecialchars(date('Y-m')) ?>" required></label>
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
        { label: 'Real', data: <?= json_encode(array_map('floatval', array_column($vsPresupuesto, 'real')), JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#22c55e' }
      ]
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Recaudos', $content);
