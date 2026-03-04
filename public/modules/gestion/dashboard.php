<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

require_role(['admin', 'analista']);

$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$isAdmin = portfolio_is_admin();
$responsableId = $isAdmin ? (int)($_GET['responsable_id'] ?? 0) : $currentUserId;
$trendDays = (int)($_GET['trend_days'] ?? 30);
if (!in_array($trendDays, [7, 30, 90], true)) {
    $trendDays = 30;
}
$trendInterval = $trendDays - 1;
$responsables = gestion_get_responsables($pdo);
$scope = gestion_scope_condition($responsableId, 'd');

$activityStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN DATE(g.created_at) = CURDATE() THEN 1 ELSE 0 END) AS gestiones_hoy,
        SUM(CASE WHEN YEARWEEK(g.created_at, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS gestiones_semana,
        SUM(CASE WHEN DATE_FORMAT(g.created_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m") THEN 1 ELSE 0 END) AS gestiones_mes,
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL THEN 1 ELSE 0 END) AS promesas_registradas,
        SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "cumplido" THEN 1 ELSE 0 END) AS promesas_cumplidas,
        SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "incumplido" THEN 1 ELSE 0 END) AS promesas_incumplidas,
        COALESCE(SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "cumplido" AND DATE(g.created_at) = CURDATE() THEN COALESCE(g.valor_compromiso, 0) ELSE 0 END), 0) AS recuperado_hoy,
        COALESCE(SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "cumplido" AND YEARWEEK(g.created_at, 1) = YEARWEEK(CURDATE(), 1) THEN COALESCE(g.valor_compromiso, 0) ELSE 0 END), 0) AS recuperado_semana,
        COALESCE(SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "cumplido" AND DATE_FORMAT(g.created_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m") THEN COALESCE(g.valor_compromiso, 0) ELSE 0 END), 0) AS recuperado_mes
    FROM bitacora_gestion g
    INNER JOIN cartera_documentos d ON d.id = g.id_documento
    WHERE d.estado_documento = "activo"' . $scope['sql']
);
$activityStmt->execute($scope['params']);
$activity = $activityStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$recentStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN lc.last_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS clientes_con_gestion,
        SUM(CASE WHEN lc.last_created_at IS NULL OR lc.last_created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS clientes_sin_gestion
     FROM clientes c
     INNER JOIN (
        SELECT d.cliente_id
        FROM cartera_documentos d
        WHERE d.estado_documento = "activo"' . $scope['sql'] . '
        GROUP BY d.cliente_id
     ) dc ON dc.cliente_id = c.id
     LEFT JOIN (
        SELECT d.cliente_id, MAX(g.created_at) AS last_created_at
        FROM bitacora_gestion g
        INNER JOIN cartera_documentos d ON d.id = g.id_documento
        WHERE d.estado_documento = "activo"' . $scope['sql'] . '
        GROUP BY d.cliente_id
     ) lc ON lc.cliente_id = c.id'
);
$recentParams = array_merge($scope['params'], $scope['params']);
$recentStmt->execute($recentParams);
$recent = $recentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$perCollectorStmt = $pdo->prepare(
    'SELECT
        u.id,
        u.nombre,
        COUNT(g.id) AS gestiones,
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL THEN 1 ELSE 0 END) AS promesas,
        SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "cumplido" THEN 1 ELSE 0 END) AS promesas_cumplidas,
        SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "incumplido" THEN 1 ELSE 0 END) AS promesas_incumplidas,
        COALESCE(SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "cumplido" THEN COALESCE(g.valor_compromiso, 0) ELSE 0 END), 0) AS recuperado
     FROM bitacora_gestion g
     INNER JOIN usuarios u ON u.id = g.usuario_id
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     WHERE d.estado_documento = "activo"' . $scope['sql'] . '
     GROUP BY u.id, u.nombre
     ORDER BY gestiones DESC, recuperado DESC'
);
$perCollectorStmt->execute($scope['params']);
$collectorRows = $perCollectorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$assignedStmt = $pdo->prepare(
    'SELECT c.responsable_usuario_id AS usuario_id, COUNT(DISTINCT c.id) AS clientes_asignados
     FROM clientes c
     INNER JOIN cartera_documentos d ON d.cliente_id = c.id
     WHERE d.estado_documento = "activo"
       AND c.responsable_usuario_id IS NOT NULL' . $scope['sql'] . '
     GROUP BY c.responsable_usuario_id'
);
$assignedStmt->execute($scope['params']);
$assignedRows = $assignedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$assignedMap = [];
foreach ($assignedRows as $row) {
    $assignedMap[(int)$row['usuario_id']] = (int)$row['clientes_asignados'];
}

$gestionsTrendStmt = $pdo->prepare(
    'SELECT DATE(g.created_at) AS fecha, COUNT(*) AS total
     FROM bitacora_gestion g
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     WHERE d.estado_documento = "activo"
       AND g.created_at >= DATE_SUB(CURDATE(), INTERVAL ' . (int)$trendInterval . ' DAY)' . $scope['sql'] . '
     GROUP BY DATE(g.created_at)
     ORDER BY DATE(g.created_at) ASC'
);
$gestionsTrendStmt->execute($scope['params']);
$gestionsTrendRows = $gestionsTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recoveryTrendStmt = $pdo->prepare(
    'SELECT DATE(g.created_at) AS fecha, COALESCE(SUM(g.valor_compromiso), 0) AS total
     FROM bitacora_gestion g
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     WHERE d.estado_documento = "activo"
       AND COALESCE(g.estado_compromiso, "pendiente") = "cumplido"
       AND g.created_at >= DATE_SUB(CURDATE(), INTERVAL ' . (int)$trendInterval . ' DAY)' . $scope['sql'] . '
     GROUP BY DATE(g.created_at)
     ORDER BY DATE(g.created_at) ASC'
);
$recoveryTrendStmt->execute($scope['params']);
$recoveryTrendRows = $recoveryTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$commitmentStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL AND COALESCE(g.estado_compromiso, "pendiente") = "pendiente" THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "cumplido" THEN 1 ELSE 0 END) AS cumplidas,
        SUM(CASE WHEN COALESCE(g.estado_compromiso, "pendiente") = "incumplido" THEN 1 ELSE 0 END) AS incumplidas
     FROM bitacora_gestion g
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     WHERE d.estado_documento = "activo"' . $scope['sql']
);
$commitmentStmt->execute($scope['params']);
$commitments = $commitmentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$alertsStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN lc.last_created_at IS NULL OR lc.last_created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS clientes_sin_gestion,
        SUM(CASE WHEN lc.compromiso_pago IS NOT NULL AND COALESCE(lc.estado_compromiso, "pendiente") = "pendiente" AND lc.compromiso_pago < CURDATE() THEN 1 ELSE 0 END) AS promesas_vencidas,
        SUM(CASE WHEN lc.compromiso_pago = CURDATE() AND COALESCE(lc.estado_compromiso, "pendiente") = "pendiente" THEN 1 ELSE 0 END) AS compromisos_hoy,
        SUM(CASE WHEN dmax.max_dias_vencido >= 90 THEN 1 ELSE 0 END) AS mora_critica
     FROM (
        SELECT d.cliente_id, MAX(d.dias_vencido) AS max_dias_vencido
        FROM cartera_documentos d
        WHERE d.estado_documento = "activo"' . $scope['sql'] . '
        GROUP BY d.cliente_id
     ) dmax
     LEFT JOIN (
        SELECT ux.cliente_id, g1.created_at AS last_created_at, g1.compromiso_pago, g1.estado_compromiso
        FROM bitacora_gestion g1
        INNER JOIN (
            SELECT d.cliente_id, MAX(g.id) AS last_id
            FROM bitacora_gestion g
            INNER JOIN cartera_documentos d ON d.id = g.id_documento
            WHERE d.estado_documento = "activo"' . $scope['sql'] . '
            GROUP BY d.cliente_id
        ) ux ON ux.last_id = g1.id
     ) lc ON lc.cliente_id = dmax.cliente_id'
);
$alertsStmt->execute(array_merge($scope['params'], $scope['params']));
$alerts = $alertsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$collectorLabels = [];
$collectorGestionData = [];
$collectorPromiseData = [];
$collectorRecoveryData = [];
foreach ($collectorRows as $row) {
    $collectorLabels[] = (string)$row['nombre'];
    $collectorGestionData[] = (int)$row['gestiones'];
    $collectorPromiseData[] = (int)$row['promesas'];
    $collectorRecoveryData[] = (float)$row['recuperado'];
}

$gestionsByDate = [];
foreach ($gestionsTrendRows as $row) {
    $gestionsByDate[(string)$row['fecha']] = (int)$row['total'];
}
$recoveryByDate = [];
foreach ($recoveryTrendRows as $row) {
    $recoveryByDate[(string)$row['fecha']] = (float)$row['total'];
}

$trendLabels = [];
$trendGestionesData = [];
$trendRecoveryData = [];
for ($i = $trendInterval; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    $trendLabels[] = date('d M', strtotime($date));
    $trendGestionesData[] = $gestionsByDate[$date] ?? 0;
    $trendRecoveryData[] = $recoveryByDate[$date] ?? 0;
}

$teamRows = [];
foreach ($collectorRows as $row) {
    $uid = (int)$row['id'];
    $teamRows[] = [
        'nombre' => (string)$row['nombre'],
        'clientes_asignados' => $assignedMap[$uid] ?? 0,
        'gestiones' => (int)$row['gestiones'],
        'promesas' => (int)$row['promesas'],
        'promesas_cumplidas' => (int)$row['promesas_cumplidas'],
        'promesas_incumplidas' => (int)$row['promesas_incumplidas'],
        'recuperado' => (float)$row['recuperado'],
    ];
}

$topGestiones = $teamRows;
usort($topGestiones, static fn($a, $b) => $b['gestiones'] <=> $a['gestiones']);
$topPromesas = $teamRows;
usort($topPromesas, static fn($a, $b) => $b['promesas'] <=> $a['promesas']);
$topRecuperado = $teamRows;
usort($topRecuperado, static fn($a, $b) => $b['recuperado'] <=> $a['recuperado']);
$lowActivity = $teamRows;
usort($lowActivity, static function ($a, $b) {
    $scoreA = $a['gestiones'] + $a['promesas'] + ($a['recuperado'] / 1000000);
    $scoreB = $b['gestiones'] + $b['promesas'] + ($b['recuperado'] / 1000000);
    return $scoreA <=> $scoreB;
});

ob_start(); ?>
<h1>Panel operativo del equipo de gestión de cartera</h1>
<form class="card" method="get">
  <div class="row">
    <select name="responsable_id">
      <option value="0">Toda la operación</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="trend_days">
      <option value="7" <?= $trendDays === 7 ? 'selected' : '' ?>>Últimos 7 días</option>
      <option value="30" <?= $trendDays === 30 ? 'selected' : '' ?>>Últimos 30 días</option>
      <option value="90" <?= $trendDays === 90 ? 'selected' : '' ?>>Últimos 90 días</option>
    </select>
    <button class="btn">Filtrar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/dashboard.php')) ?>">Limpiar</a>
  </div>
</form>

<section class="gm-section">
  <h2 class="section-title">Indicadores principales del equipo</h2>

  <div class="gm-kpi-category">
    <h3>Actividad del equipo</h3>
    <div class="gm-kpi-grid">
      <article class="gm-kpi-card"><p class="kpi-label">Gestiones hoy</p><p class="kpi-value"><?= number_format((int)($activity['gestiones_hoy'] ?? 0), 0, ',', '.') ?></p></article>
      <article class="gm-kpi-card"><p class="kpi-label">Gestiones semana</p><p class="kpi-value"><?= number_format((int)($activity['gestiones_semana'] ?? 0), 0, ',', '.') ?></p></article>
      <article class="gm-kpi-card"><p class="kpi-label">Gestiones mes</p><p class="kpi-value"><?= number_format((int)($activity['gestiones_mes'] ?? 0), 0, ',', '.') ?></p></article>
    </div>
  </div>

  <div class="gm-kpi-category">
    <h3>Negociación</h3>
    <div class="gm-kpi-grid">
      <article class="gm-kpi-card"><p class="kpi-label">Promesas registradas</p><p class="kpi-value"><?= number_format((int)($activity['promesas_registradas'] ?? 0), 0, ',', '.') ?></p></article>
      <article class="gm-kpi-card"><p class="kpi-label">Promesas cumplidas</p><p class="kpi-value"><?= number_format((int)($activity['promesas_cumplidas'] ?? 0), 0, ',', '.') ?></p></article>
      <article class="gm-kpi-card gm-kpi-card-danger"><p class="kpi-label">Promesas incumplidas</p><p class="kpi-value"><?= number_format((int)($activity['promesas_incumplidas'] ?? 0), 0, ',', '.') ?></p></article>
    </div>
  </div>

  <div class="gm-kpi-category">
    <h3>Recuperación</h3>
    <div class="gm-kpi-grid">
      <article class="gm-kpi-card gm-kpi-card-emphasis"><p class="kpi-label">Valor recuperado hoy</p><p class="kpi-value">$<?= number_format((float)($activity['recuperado_hoy'] ?? 0), 0, ',', '.') ?></p></article>
      <article class="gm-kpi-card gm-kpi-card-emphasis"><p class="kpi-label">Valor recuperado semana</p><p class="kpi-value">$<?= number_format((float)($activity['recuperado_semana'] ?? 0), 0, ',', '.') ?></p></article>
      <article class="gm-kpi-card gm-kpi-card-emphasis"><p class="kpi-label">Valor recuperado mes</p><p class="kpi-value">$<?= number_format((float)($activity['recuperado_mes'] ?? 0), 0, ',', '.') ?></p></article>
    </div>
  </div>

  <div class="gm-kpi-category">
    <h3>Control de cartera</h3>
    <div class="gm-kpi-grid">
      <article class="gm-kpi-card"><p class="kpi-label">Clientes con gestión reciente</p><p class="kpi-value"><?= number_format((int)($recent['clientes_con_gestion'] ?? 0), 0, ',', '.') ?></p></article>
      <article class="gm-kpi-card gm-kpi-card-danger"><p class="kpi-label">Clientes sin gestión reciente (+7 días)</p><p class="kpi-value"><?= number_format((int)($recent['clientes_sin_gestion'] ?? 0), 0, ',', '.') ?></p></article>
    </div>
  </div>
</section>

<section class="gm-section">
  <h2 class="section-title">Productividad y efectividad por cobrador</h2>
  <div class="gm-main-charts gm-main-charts-3 gm-productivity-charts">
    <article class="card gm-chart-card"><div class="card-header"><h3>Gestiones realizadas por cobrador</h3></div><canvas id="gestionesPorCobradorChart" height="240"></canvas></article>
    <article class="card gm-chart-card"><div class="card-header"><h3>Promesas de pago por cobrador</h3></div><canvas id="promesasPorCobradorChart" height="240"></canvas></article>
    <article class="card gm-chart-card"><div class="card-header"><h3>Valor recuperado por cobrador</h3></div><canvas id="recuperadoPorCobradorChart" height="240"></canvas></article>
  </div>
</section>

<section class="gm-section">
  <h2 class="section-title">Control de compromisos y alertas operativas</h2>
  <div class="gm-analysis-grid gm-analysis-grid-priority">
    <article class="card gm-alerts-priority-card">
      <div class="card-header"><h3>Alertas operativas</h3></div>
      <div class="gm-alert-grid">
        <div class="gm-alert-card gm-alert-warning"><strong><?= (int)($alerts['clientes_sin_gestion'] ?? 0) ?></strong><span>Clientes sin gestión reciente (+7 días)</span></div>
        <div class="gm-alert-card gm-alert-critical"><strong><?= (int)($alerts['promesas_vencidas'] ?? 0) ?></strong><span>Promesas de pago vencidas</span></div>
        <div class="gm-alert-card gm-alert-warning"><strong><?= (int)($alerts['compromisos_hoy'] ?? 0) ?></strong><span>Compromisos que vencen hoy</span></div>
        <div class="gm-alert-card gm-alert-critical"><strong><?= (int)($alerts['mora_critica'] ?? 0) ?></strong><span>Clientes con mora crítica (+90 días)</span></div>
      </div>
    </article>

    <article class="card gm-commitment-card-compact">
      <div class="card-header"><h3>Compromisos de pago</h3></div>
      <div class="gm-mini-kpis">
        <div class="gm-mini-kpi"><span>Promesas pendientes</span><strong><?= (int)($commitments['pendientes'] ?? 0) ?></strong></div>
        <div class="gm-mini-kpi"><span>Promesas cumplidas</span><strong><?= (int)($commitments['cumplidas'] ?? 0) ?></strong></div>
        <div class="gm-mini-kpi"><span>Promesas incumplidas</span><strong><?= (int)($commitments['incumplidas'] ?? 0) ?></strong></div>
      </div>
      <div class="gm-compact-donut-wrap">
        <canvas id="estadoCompromisosChart" height="130"></canvas>
      </div>
    </article>
  </div>
</section>

<section class="gm-section">
  <div class="card-header card-header-inline">
    <h2 class="section-title">Tendencias operativas</h2>
    <small>Rango activo: últimos <?= (int)$trendDays ?> días</small>
  </div>
  <div class="gm-main-charts">
    <article class="card gm-chart-card gm-chart-card-compact"><div class="card-header"><h3>Gestiones diarias</h3></div><canvas id="gestionesTiempoChart" height="210"></canvas></article>
    <article class="card gm-chart-card gm-chart-card-compact"><div class="card-header"><h3>Recuperación diaria</h3></div><canvas id="recuperacionTiempoChart" height="210"></canvas></article>
  </div>
</section>

<section class="gm-section">
  <h2 class="section-title">Desempeño del equipo</h2>
  <div class="card">
    <div class="table-responsive">
      <table class="table gm-top-table" id="teamPerformanceTable">
        <thead>
          <tr>
            <th data-type="text">Usuario</th><th data-type="number">Clientes asignados</th><th data-type="number">Gestiones realizadas</th><th data-type="number">Promesas registradas</th><th data-type="number">Promesas cumplidas</th><th data-type="number">Promesas incumplidas</th><th data-type="currency">Valor recuperado</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($teamRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['nombre']) ?></td>
            <td data-sort="<?= (int)$row['clientes_asignados'] ?>"><?= number_format($row['clientes_asignados'], 0, ',', '.') ?></td>
            <td data-sort="<?= (int)$row['gestiones'] ?>"><?= number_format($row['gestiones'], 0, ',', '.') ?></td>
            <td data-sort="<?= (int)$row['promesas'] ?>"><?= number_format($row['promesas'], 0, ',', '.') ?></td>
            <td data-sort="<?= (int)$row['promesas_cumplidas'] ?>"><?= number_format($row['promesas_cumplidas'], 0, ',', '.') ?></td>
            <td data-sort="<?= (int)$row['promesas_incumplidas'] ?>"><?= number_format($row['promesas_incumplidas'], 0, ',', '.') ?></td>
            <td data-sort="<?= (float)$row['recuperado'] ?>">$<?= number_format($row['recuperado'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="gm-section">
  <h2 class="section-title">Ranking automático del equipo</h2>
  <div class="gm-analysis-grid">
    <article class="card">
      <div class="card-header"><h3>Top cobradores del periodo</h3></div>
      <ul class="gm-ranking-list">
        <li><span>Mayor número de gestiones</span><strong><?= htmlspecialchars($topGestiones[0]['nombre'] ?? 'Sin datos') ?></strong><small><?= (int)($topGestiones[0]['gestiones'] ?? 0) ?> gestiones</small></li>
        <li><span>Mayor número de promesas</span><strong><?= htmlspecialchars($topPromesas[0]['nombre'] ?? 'Sin datos') ?></strong><small><?= (int)($topPromesas[0]['promesas'] ?? 0) ?> promesas</small></li>
        <li><span>Mayor valor recuperado</span><strong><?= htmlspecialchars($topRecuperado[0]['nombre'] ?? 'Sin datos') ?></strong><small>$<?= number_format((float)($topRecuperado[0]['recuperado'] ?? 0), 0, ',', '.') ?></small></li>
      </ul>
    </article>
    <article class="card">
      <div class="card-header"><h3>Cobradores con baja actividad</h3></div>
      <ul class="gm-ranking-list">
        <?php foreach (array_slice($lowActivity, 0, 3) as $row): ?>
          <li><span><?= htmlspecialchars($row['nombre']) ?></span><small><?= (int)$row['gestiones'] ?> gestiones · <?= (int)$row['promesas'] ?> promesas · $<?= number_format((float)$row['recuperado'], 0, ',', '.') ?></small></li>
        <?php endforeach; ?>
      </ul>
    </article>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  if (!window.Chart) return;

  var moneyFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

  function barChart(id, label, data, color, currency) {
    var el = document.getElementById(id);
    if (!el) return;

    new Chart(el, {
      type: 'bar',
      data: {
        labels: <?= json_encode($collectorLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: label,
          data: data,
          backgroundColor: color,
          borderRadius: 8,
          maxBarThickness: 34
        }]
      },
      options: {
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                if (currency) return moneyFormatter.format(ctx.raw || 0);
                return (ctx.raw || 0) + ' registros';
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function (v) {
                return currency ? moneyFormatter.format(v) : v;
              }
            }
          }
        }
      }
    });
  }

  barChart('gestionesPorCobradorChart', 'Gestiones', <?= json_encode($collectorGestionData, JSON_UNESCAPED_UNICODE) ?>, '#2563eb', false);
  barChart('promesasPorCobradorChart', 'Promesas', <?= json_encode($collectorPromiseData, JSON_UNESCAPED_UNICODE) ?>, '#7c3aed', false);
  barChart('recuperadoPorCobradorChart', 'Recuperado', <?= json_encode($collectorRecoveryData, JSON_UNESCAPED_UNICODE) ?>, '#16a34a', true);

  new Chart(document.getElementById('gestionesTiempoChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{
        label: 'Gestiones',
        data: <?= json_encode($trendGestionesData, JSON_UNESCAPED_UNICODE) ?>,
        borderColor: '#1d4ed8',
        backgroundColor: 'rgba(37, 99, 235, 0.20)',
        fill: true,
        tension: 0.25,
        pointRadius: 2
      }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });

  new Chart(document.getElementById('recuperacionTiempoChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{
        label: 'Recuperación',
        data: <?= json_encode($trendRecoveryData, JSON_UNESCAPED_UNICODE) ?>,
        borderColor: '#0f766e',
        backgroundColor: 'rgba(13, 148, 136, 0.18)',
        fill: true,
        tension: 0.25,
        pointRadius: 2
      }]
    },
    options: {
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return moneyFormatter.format(ctx.raw || 0); } } } },
      scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return moneyFormatter.format(v); } } } }
    }
  });

  new Chart(document.getElementById('estadoCompromisosChart'), {
    type: 'doughnut',
    data: {
      labels: ['Pendientes', 'Cumplidas', 'Incumplidas'],
      datasets: [{
        data: [<?= (int)($commitments['pendientes'] ?? 0) ?>, <?= (int)($commitments['cumplidas'] ?? 0) ?>, <?= (int)($commitments['incumplidas'] ?? 0) ?>],
        backgroundColor: ['#f59e0b', '#10b981', '#ef4444'],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: { cutout: '65%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } } }
  });

  var teamTable = document.getElementById('teamPerformanceTable');
  if (teamTable) {
    var headers = teamTable.querySelectorAll('thead th');
    var tbody = teamTable.querySelector('tbody');
    headers.forEach(function (header, index) {
      header.classList.add('gm-sortable');
      header.setAttribute('role', 'button');
      header.dataset.order = 'desc';
      header.addEventListener('click', function () {
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var order = header.dataset.order === 'asc' ? 'desc' : 'asc';
        header.dataset.order = order;
        rows.sort(function (a, b) {
          var aCell = a.children[index];
          var bCell = b.children[index];
          var aValue = aCell.dataset.sort || aCell.textContent.trim();
          var bValue = bCell.dataset.sort || bCell.textContent.trim();
          var aNum = parseFloat(aValue);
          var bNum = parseFloat(bValue);
          if (!Number.isNaN(aNum) && !Number.isNaN(bNum)) {
            return order === 'asc' ? aNum - bNum : bNum - aNum;
          }
          return order === 'asc' ? aValue.localeCompare(bValue, 'es') : bValue.localeCompare(aValue, 'es');
        });
        rows.forEach(function (row) { tbody.appendChild(row); });
      });
    });
  }
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Gestión de cartera', $content);
