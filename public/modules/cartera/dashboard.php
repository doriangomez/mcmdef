<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

require_role(['admin', 'analista']);

$scope = portfolio_client_scope_sql('c');
$baseWhere = ' WHERE d.estado_documento = "activo"' . $scope['sql'];
$baseParams = $scope['params'];

$kpiStmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_documentos,
        COUNT(DISTINCT d.cliente_id) AS total_clientes,
        COALESCE(SUM(d.saldo_pendiente), 0) AS total_cartera,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS cartera_vencida,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS cartera_critica
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id' . $baseWhere
);
$kpiStmt->execute($baseParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$moraStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN d.dias_vencido <= 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS vigente,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 1 AND 30 THEN d.saldo_pendiente ELSE 0 END), 0) AS b1_30,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 31 AND 60 THEN d.saldo_pendiente ELSE 0 END), 0) AS b31_60,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 61 AND 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS b61_90,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS b90_plus
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id' . $baseWhere
);
$moraStmt->execute($baseParams);
$mora = $moraStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$uenStmt = $pdo->prepare(
    'SELECT
        COALESCE(NULLIF(TRIM(d.uens), ""), "Sin UEN") AS etiqueta,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS total
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY etiqueta
     ORDER BY total DESC
     LIMIT 12'
);
$uenStmt->execute($baseParams);
$uenRows = $uenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$canalStmt = $pdo->prepare(
    'SELECT
        COALESCE(NULLIF(TRIM(COALESCE(NULLIF(d.canal, ""), c.canal)), ""), "Sin canal") AS etiqueta,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS total
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY etiqueta
     ORDER BY total DESC
     LIMIT 12'
);
$canalStmt->execute($baseParams);
$canalRows = $canalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vendedorStmt = $pdo->prepare(
    'SELECT
        COALESCE(NULLIF(TRIM(c.empleado_ventas), ""), "Sin vendedor") AS etiqueta,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS total
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY etiqueta
     ORDER BY total DESC
     LIMIT 12'
);
$vendedorStmt->execute($baseParams);
$vendedorRows = $vendedorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$paretoStmt = $pdo->prepare(
    'SELECT
        c.id,
        c.nombre,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS saldo_vencido
     FROM clientes c
     INNER JOIN cartera_documentos d ON d.cliente_id = c.id
     WHERE d.estado_documento = "activo"' . $scope['sql'] . '
     GROUP BY c.id, c.nombre
     HAVING saldo_vencido > 0
     ORDER BY saldo_vencido DESC
     LIMIT 15'
);
$paretoStmt->execute($scope['params']);
$paretoRows = $paretoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalVencido = (float)($kpi['cartera_vencida'] ?? 0);
$topCliente = $paretoRows[0] ?? null;
$dependenciaMayor = ($topCliente !== null && $totalVencido > 0)
    ? (((float)$topCliente['saldo_vencido'] / $totalVencido) * 100)
    : 0.0;

$totalCartera = (float)($kpi['total_cartera'] ?? 0);
$ratioVencido = $totalCartera > 0 ? min(1, $totalVencido / $totalCartera) : 0.0;
$ratioCritico = $totalCartera > 0 ? min(1, (float)($kpi['cartera_critica'] ?? 0) / $totalCartera) : 0.0;
$ratioConcentracion = min(1, $dependenciaMayor / 100);
$scoreSalud = max(0, min(100, 100 - (($ratioVencido * 45) + ($ratioCritico * 35) + ($ratioConcentracion * 20)) * 100));

$chartEdad = [
    (float)($mora['vigente'] ?? 0),
    (float)($mora['b1_30'] ?? 0),
    (float)($mora['b31_60'] ?? 0),
    (float)($mora['b61_90'] ?? 0),
    (float)($mora['b90_plus'] ?? 0),
];

$uenLabels = array_column($uenRows, 'etiqueta');
$uenValues = array_map('floatval', array_column($uenRows, 'total'));
$canalLabels = array_column($canalRows, 'etiqueta');
$canalValues = array_map('floatval', array_column($canalRows, 'total'));
$vendedorLabels = array_column($vendedorRows, 'etiqueta');
$vendedorValues = array_map('floatval', array_column($vendedorRows, 'total'));

$paretoLabels = [];
$paretoValues = [];
$paretoAcumulado = [];
$acumulado = 0.0;
foreach ($paretoRows as $row) {
    $valor = (float)$row['saldo_vencido'];
    $paretoLabels[] = $row['nombre'];
    $paretoValues[] = $valor;
    $acumulado += $valor;
    $paretoAcumulado[] = $totalVencido > 0 ? round(($acumulado / $totalVencido) * 100, 2) : 0;
}

$docsCount = (int)($kpi['total_documentos'] ?? 0);

ob_start();
?>
<section class="card cartera-dashboard-head">
  <div>
    <h2>Dashboard de Gestión de Cartera</h2>
    <p class="kpi-subtext">Visualización temporal simplificada: todos los filtros están desactivados y se usa toda la cartera disponible.</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
    <span class="badge" style="background:#f59e0b; color:#111827;">Filtros temporalmente desactivados</span>
    <a class="btn" href="<?= htmlspecialchars(app_url('api/cartera/analisis-export.php')) ?>">Descargar análisis de cartera (Excel XLSX)</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/lista.php')) ?>">Ir a cartera detallada</a>
  </div>
</section>

<?php if ($docsCount === 0): ?>
<section class="card"><p style="margin:0;">No hay registros activos de cartera para visualizar.</p></section>
<?php endif; ?>

<section class="gd-kpi-grid">
  <article class="gd-kpi-card"><span>Total recaudo</span><strong>Pendiente carga de recaudo</strong></article>
  <article class="gd-kpi-card"><span>Total presupuesto</span><strong>Pendiente carga de presupuesto</strong></article>
</section>

<section class="gd-kpi-grid">
  <article class="gd-kpi-card"><span>Total cartera</span><strong>$<?= number_format($totalCartera, 0, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Cartera vencida</span><strong>$<?= number_format($totalVencido, 0, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Dependencia cliente mayor</span><strong><?= number_format($dependenciaMayor, 2, ',', '.') ?>%</strong></article>
  <article class="gd-kpi-card"><span>Score salud cartera</span><strong><?= number_format($scoreSalud, 1, ',', '.') ?>/100</strong></article>
</section>

<section class="gd-grid-2">
  <article class="card gd-chart-card">
    <h3>Distribución por edad de cartera</h3>
    <canvas id="edadChart" height="150"></canvas>
  </article>
  <article class="card gd-chart-card">
    <h3>Pareto de clientes (cartera vencida)</h3>
    <canvas id="paretoChart" height="150"></canvas>
  </article>
</section>

<section class="gd-grid-2">
  <article class="card gd-chart-card">
    <h3>Cartera vencida por UEN</h3>
    <canvas id="uenChart" height="150"></canvas>
  </article>
  <article class="card gd-chart-card">
    <h3>Cartera vencida por canal</h3>
    <canvas id="canalChart" height="150"></canvas>
  </article>
</section>

<section class="card gd-chart-card">
  <h3>Cartera vencida por vendedor</h3>
  <canvas id="vendedorChart" height="110"></canvas>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  var moneyFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

  var axisMoney = { ticks: { callback: function (value) { return moneyFormatter.format(value || 0); } } };

  new Chart(document.getElementById('edadChart'), {
    type: 'doughnut',
    data: {
      labels: ['Vigente', '1-30', '31-60', '61-90', '+90'],
      datasets: [{
        data: <?= json_encode($chartEdad, JSON_UNESCAPED_UNICODE) ?>,
        backgroundColor: ['#22c55e', '#fde047', '#f59e0b', '#f97316', '#ef4444']
      }]
    },
    options: { plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + moneyFormatter.format(ctx.raw || 0); } } } } }
  });

  new Chart(document.getElementById('uenChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($uenLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ label: 'Cartera vencida', data: <?= json_encode($uenValues, JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#2563eb' }]
    },
    options: { indexAxis: 'y', scales: { x: axisMoney }, plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('canalChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($canalLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ label: 'Cartera vencida', data: <?= json_encode($canalValues, JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#7c3aed' }]
    },
    options: { scales: { y: axisMoney }, plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('vendedorChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($vendedorLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ label: 'Cartera vencida', data: <?= json_encode($vendedorValues, JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#0ea5e9' }]
    },
    options: { scales: { y: axisMoney }, plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('paretoChart'), {
    data: {
      labels: <?= json_encode($paretoLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{
        type: 'bar',
        label: 'Saldo vencido',
        data: <?= json_encode($paretoValues, JSON_UNESCAPED_UNICODE) ?>,
        backgroundColor: '#f59e0b',
        yAxisID: 'y'
      }, {
        type: 'line',
        label: '% acumulado',
        data: <?= json_encode($paretoAcumulado, JSON_UNESCAPED_UNICODE) ?>,
        borderColor: '#dc2626',
        backgroundColor: '#dc2626',
        yAxisID: 'y1',
        tension: 0.25
      }]
    },
    options: {
      scales: {
        y: axisMoney,
        y1: {
          position: 'right',
          min: 0,
          max: 100,
          grid: { drawOnChartArea: false },
          ticks: { callback: function (value) { return value + '%'; } }
        }
      }
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard de Gestión de Cartera', $content);
