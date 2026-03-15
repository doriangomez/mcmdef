<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';
require_once __DIR__ . '/../../../app/services/UenService.php';

require_role(['admin', 'analista']);

$scope = portfolio_client_scope_sql('c');

$allowedUens = uen_user_allowed_values($pdo);
$selectedUens = uen_apply_scope(uen_requested_values('uen'), $allowedUens);
$uenFilter = uen_sql_condition('d.uens', $selectedUens);
$uenSql = $uenFilter['sql'];
$uenParams = $uenFilter['params'];
$uensOptions = $pdo->query("SELECT DISTINCT uens AS uen FROM cartera_documentos WHERE uens IS NOT NULL AND TRIM(uens) <> '' ORDER BY uens")->fetchAll(PDO::FETCH_COLUMN) ?: [];
if (!empty($allowedUens)) {
    $uensOptions = array_values(array_intersect($uensOptions, $allowedUens));
}

$baseWhere = ' WHERE d.estado_documento = "activo"' . $scope['sql'] . $uenSql;

$kpiStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(d.saldo_pendiente), 0) AS total_cartera,
        COUNT(DISTINCT CASE WHEN d.dias_vencido > 0 THEN d.cliente_id END) AS clientes_mora
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id' . $baseWhere
);
$kpiStmt->execute(array_merge($scope['params'], $uenParams));
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$promesasStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "pendiente" THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN g.estado_compromiso = "cumplido" THEN 1 ELSE 0 END) AS cumplidas,
        SUM(CASE WHEN g.estado_compromiso = "incumplido" THEN 1 ELSE 0 END) AS incumplidas,
        COALESCE(SUM(CASE WHEN g.estado_compromiso = "cumplido" AND DATE(g.created_at) = CURDATE() THEN COALESCE(g.valor_compromiso, 0) ELSE 0 END), 0) AS recuperado_hoy,
        COALESCE(SUM(CASE WHEN g.estado_compromiso = "cumplido" AND DATE_FORMAT(g.created_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m") THEN COALESCE(g.valor_compromiso, 0) ELSE 0 END), 0) AS recuperado_mes
     FROM bitacora_gestion g
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE 1=1' . $scope['sql'] . $uenSql
);
$promesasStmt->execute(array_merge($scope['params'], $uenParams));
$promesas = $promesasStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$moraStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 0 AND 30 THEN d.saldo_pendiente ELSE 0 END), 0) AS b0_30,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 31 AND 60 THEN d.saldo_pendiente ELSE 0 END), 0) AS b31_60,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 61 AND 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS b61_90,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS b90_plus
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id' . $baseWhere
);
$moraStmt->execute(array_merge($scope['params'], $uenParams));
$mora = $moraStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$recoveryStmt = $pdo->prepare(
    'SELECT DATE(g.created_at) AS fecha, COALESCE(SUM(g.valor_compromiso), 0) AS total
     FROM bitacora_gestion g
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE g.estado_compromiso = "cumplido"
       AND DATE(g.created_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)' . $scope['sql'] . $uenSql . '
     GROUP BY DATE(g.created_at)
     ORDER BY DATE(g.created_at) ASC'
);
$recoveryStmt->execute(array_merge($scope['params'], $uenParams));
$recoveryRows = $recoveryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$recoveryMap = [];
foreach ($recoveryRows as $row) {
    $recoveryMap[$row['fecha']] = (float)$row['total'];
}

$recoveryLabels = [];
$recoveryValues = [];
for ($i = 29; $i >= 0; $i--) {
    $date = (new DateTimeImmutable('today'))->sub(new DateInterval('P' . $i . 'D'));
    $key = $date->format('Y-m-d');
    $recoveryLabels[] = $date->format('d/m');
    $recoveryValues[] = $recoveryMap[$key] ?? 0;
}

$rankingStmt = $pdo->prepare(
    'SELECT
        u.nombre,
        COUNT(g.id) AS gestiones_realizadas,
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL THEN 1 ELSE 0 END) AS promesas_pago,
        COALESCE(SUM(CASE WHEN g.estado_compromiso = "cumplido" THEN COALESCE(g.valor_compromiso, 0) ELSE 0 END), 0) AS valor_recuperado
     FROM usuarios u
     INNER JOIN bitacora_gestion g ON g.usuario_id = u.id
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE u.estado = "activo"' . $scope['sql'] . $uenSql . '
     GROUP BY u.id, u.nombre
     ORDER BY valor_recuperado DESC, gestiones_realizadas DESC
     LIMIT 10'
);
$rankingStmt->execute(array_merge($scope['params'], $uenParams));
$ranking = $rankingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$criticosStmt = $pdo->prepare(
    'SELECT
        c.id,
        c.nombre,
        c.nit,
        COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total,
        COALESCE(MAX(d.dias_vencido), 0) AS mora_maxima,
        COUNT(*) AS documentos
     FROM clientes c
     INNER JOIN cartera_documentos d ON d.cliente_id = c.id
     WHERE d.estado_documento = "activo"' . $scope['sql'] . $uenSql . '
     GROUP BY c.id, c.nombre, c.nit
     ORDER BY mora_maxima DESC, saldo_total DESC
     LIMIT 12'
);
$criticosStmt->execute(array_merge($scope['params'], $uenParams));
$criticos = $criticosStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$chartMora = [
    (float)($mora['b0_30'] ?? 0),
    (float)($mora['b31_60'] ?? 0),
    (float)($mora['b61_90'] ?? 0),
    (float)($mora['b90_plus'] ?? 0),
];

ob_start();
?>
<section class="card cartera-dashboard-head">
  <div>
    <h2>Dashboard de Gestión de Cartera</h2>
    <p class="kpi-subtext">Vista ejecutiva para administradores y gestores. Los gestores solo visualizan su cartera asignada.</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
    <form method="get" style="display:flex; gap:8px; align-items:flex-end;">
      <label>UEN (obligatorio)
        <select name="uen[]" multiple size="3" required>
          <?php foreach ($uensOptions as $uenOption): ?>
            <option value="<?= htmlspecialchars((string)$uenOption) ?>" <?= in_array((string)$uenOption, $selectedUens, true) ? 'selected' : '' ?>><?= htmlspecialchars((string)$uenOption) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit">Aplicar UEN</button>
    </form>
    <a class="btn" href="<?= htmlspecialchars(app_url('api/cartera/analisis-export.php?' . http_build_query($_GET))) ?>">Descargar análisis de cartera (Excel XLSX)</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/lista.php')) ?>">Ir a cartera detallada</a>
  </div>
</section>

<section class="gd-kpi-grid">
  <article class="gd-kpi-card"><span>Total cartera</span><strong>$<?= number_format((float)($kpi['total_cartera'] ?? 0), 0, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Clientes en mora</span><strong><?= number_format((int)($kpi['clientes_mora'] ?? 0), 0, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Promesas pendientes</span><strong><?= number_format((int)($promesas['pendientes'] ?? 0), 0, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Promesas incumplidas</span><strong><?= number_format((int)($promesas['incumplidas'] ?? 0), 0, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Recuperado hoy</span><strong>$<?= number_format((float)($promesas['recuperado_hoy'] ?? 0), 0, ',', '.') ?></strong></article>
  <article class="gd-kpi-card"><span>Recuperado mes</span><strong>$<?= number_format((float)($promesas['recuperado_mes'] ?? 0), 0, ',', '.') ?></strong></article>
</section>

<section class="gd-grid-2">
  <article class="card gd-chart-card">
    <h3>Distribución de mora</h3>
    <canvas id="moraChart" height="150"></canvas>
  </article>
  <article class="card gd-chart-card">
    <h3>Recuperación diaria (últimos 30 días)</h3>
    <canvas id="recoveryChart" height="150"></canvas>
  </article>
</section>

<section class="gd-grid-2">
  <article class="card gd-chart-card">
    <h3>Panel de promesas de pago</h3>
    <div class="gd-promise-panel">
      <div><span>Pendientes</span><strong><?= (int)($promesas['pendientes'] ?? 0) ?></strong></div>
      <div><span>Cumplidas</span><strong><?= (int)($promesas['cumplidas'] ?? 0) ?></strong></div>
      <div><span>Incumplidas</span><strong><?= (int)($promesas['incumplidas'] ?? 0) ?></strong></div>
    </div>
  </article>

  <article class="card">
    <h3>Ranking de gestores</h3>
    <div class="table-responsive">
      <table class="table">
        <tr><th>Gestor</th><th>Gestiones realizadas</th><th>Promesas de pago</th><th>Valor recuperado</th></tr>
        <?php foreach ($ranking as $row): ?>
          <tr>
            <td><?= htmlspecialchars((string)$row['nombre']) ?></td>
            <td><?= number_format((int)$row['gestiones_realizadas'], 0, ',', '.') ?></td>
            <td><?= number_format((int)$row['promesas_pago'], 0, ',', '.') ?></td>
            <td>$<?= number_format((float)$row['valor_recuperado'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($ranking)): ?><tr><td colspan="4">Sin información para el alcance seleccionado.</td></tr><?php endif; ?>
      </table>
    </div>
  </article>
</section>

<section class="card">
  <h3>Clientes críticos (mora/saldo)</h3>
  <div class="table-responsive">
    <table class="table">
      <tr><th>Cliente</th><th>NIT</th><th>Saldo total</th><th>Mora máxima (días)</th><th>Documentos</th></tr>
      <?php foreach ($criticos as $cliente): ?>
        <tr>
          <td><a href="<?= htmlspecialchars(app_url('cartera/cliente.php?id_cliente=' . (int)$cliente['id'])) ?>"><?= htmlspecialchars((string)$cliente['nombre']) ?></a></td>
          <td><?= htmlspecialchars((string)$cliente['nit']) ?></td>
          <td>$<?= number_format((float)$cliente['saldo_total'], 0, ',', '.') ?></td>
          <td><?= (int)$cliente['mora_maxima'] ?></td>
          <td><?= (int)$cliente['documentos'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($criticos)): ?><tr><td colspan="5">No se encontraron clientes críticos.</td></tr><?php endif; ?>
    </table>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  if (!window.Chart) return;

  var moneyFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

  new Chart(document.getElementById('moraChart'), {
    type: 'doughnut',
    data: {
      labels: ['0-30', '31-60', '61-90', '+90'],
      datasets: [{
        data: <?= json_encode($chartMora, JSON_UNESCAPED_UNICODE) ?>,
        backgroundColor: ['#22c55e', '#f59e0b', '#f97316', '#ef4444']
      }]
    },
    options: {
      plugins: {
        legend: { position: 'bottom' },
        tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + moneyFormatter.format(ctx.raw || 0); } } }
      }
    }
  });

  new Chart(document.getElementById('recoveryChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($recoveryLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{
        label: 'Recuperado',
        data: <?= json_encode($recoveryValues, JSON_UNESCAPED_UNICODE) ?>,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37, 99, 235, 0.2)',
        fill: true,
        tension: 0.35,
        pointRadius: 2
      }]
    },
    options: {
      scales: {
        y: { ticks: { callback: function (value) { return moneyFormatter.format(value); } } }
      },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function (ctx) { return moneyFormatter.format(ctx.raw || 0); } } }
      }
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard de Gestión de Cartera', $content);
