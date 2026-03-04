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
$responsables = gestion_get_responsables($pdo);
$scope = gestion_scope_condition($responsableId, 'd');

$kpiStmt = $pdo->prepare(
    'SELECT
        COUNT(DISTINCT d.cliente_id) AS clientes_asignados,
        COUNT(*) AS documentos_asignados,
        COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS saldo_vencido,
        COALESCE(AVG(d.dias_vencido), 0) AS promedio_mora
     FROM cartera_documentos d
     WHERE d.estado_documento = "activo"' . $scope['sql']
);
$kpiStmt->execute($scope['params']);
$kpi = $kpiStmt->fetch() ?: [];

$opsStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN DATE(g.created_at) = CURDATE() THEN 1 ELSE 0 END) AS hoy,
        SUM(CASE WHEN YEARWEEK(g.created_at, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS semana,
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL AND COALESCE(g.estado_compromiso, "pendiente") = "pendiente" AND g.compromiso_pago >= CURDATE() THEN 1 ELSE 0 END) AS activos,
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL AND COALESCE(g.estado_compromiso, "pendiente") = "pendiente" AND g.compromiso_pago < CURDATE() THEN 1 ELSE 0 END) AS vencidos
     FROM bitacora_gestion g
     WHERE (? <= 0 OR g.usuario_id = ?)'
);
$opsStmt->execute([$responsableId, $responsableId]);
$ops = $opsStmt->fetch() ?: [];

$distStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN d.dias_vencido BETWEEN 0 AND 30 THEN d.saldo_pendiente ELSE 0 END) AS b0_30,
        SUM(CASE WHEN d.dias_vencido BETWEEN 31 AND 60 THEN d.saldo_pendiente ELSE 0 END) AS b31_60,
        SUM(CASE WHEN d.dias_vencido BETWEEN 61 AND 90 THEN d.saldo_pendiente ELSE 0 END) AS b61_90,
        SUM(CASE WHEN d.dias_vencido BETWEEN 91 AND 180 THEN d.saldo_pendiente ELSE 0 END) AS b91_180,
        SUM(CASE WHEN d.dias_vencido > 180 THEN d.saldo_pendiente ELSE 0 END) AS b180_plus
     FROM cartera_documentos d
     WHERE d.estado_documento = "activo"' . $scope['sql']
);
$distStmt->execute($scope['params']);
$dist = $distStmt->fetch() ?: [];

$topStmt = $pdo->prepare(
    'SELECT d.cliente_id, d.cliente, c.nit, SUM(d.saldo_pendiente) AS saldo
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE d.estado_documento = "activo"' . $scope['sql'] . '
     GROUP BY d.cliente_id, d.cliente, c.nit
     ORDER BY saldo DESC
     LIMIT 10'
);
$topStmt->execute($scope['params']);
$topClientes = $topStmt->fetchAll() ?: [];

$totalSaldo = (float)($kpi['saldo_total'] ?? 0);
$topSaldo = 0;
foreach ($topClientes as $cliente) {
    $topSaldo += (float)$cliente['saldo'];
}
$concentracionTop10 = $totalSaldo > 0 ? ($topSaldo / $totalSaldo) * 100 : 0;

$alertaStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN ult.compromiso_pago IS NOT NULL AND COALESCE(ult.estado_compromiso, "pendiente") = "pendiente" AND ult.compromiso_pago < CURDATE() THEN 1 ELSE 0 END) AS compromisos_vencidos,
        SUM(CASE WHEN d.dias_vencido >= 90 THEN 1 ELSE 0 END) AS mora_critica,
        SUM(CASE WHEN ult.created_at IS NULL OR ult.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS sin_gestion_reciente
     FROM cartera_documentos d
     LEFT JOIN (
        SELECT g1.*
        FROM bitacora_gestion g1
        INNER JOIN (
            SELECT id_documento, MAX(id) AS last_id
            FROM bitacora_gestion
            GROUP BY id_documento
        ) ux ON ux.last_id = g1.id
     ) ult ON ult.id_documento = d.id
     WHERE d.estado_documento = "activo"' . $scope['sql']
);
$alertaStmt->execute($scope['params']);
$alertas = $alertaStmt->fetch() ?: [];

$bucketValues = [
    '0-30' => (float)($dist['b0_30'] ?? 0),
    '31-60' => (float)($dist['b31_60'] ?? 0),
    '61-90' => (float)($dist['b61_90'] ?? 0),
    '91-180' => (float)($dist['b91_180'] ?? 0),
    '180+' => (float)($dist['b180_plus'] ?? 0),
];
$maxBucket = max($bucketValues ?: [0]);

ob_start(); ?>
<h1>Dashboard profesional de gestión de cartera</h1>
<form class="card" method="get">
  <div class="row">
    <select name="responsable_id">
      <option value="0">Toda la operación</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Aplicar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/bandeja.php?responsable_id=' . $responsableId)) ?>">Bandeja operativa</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/compromisos.php?responsable_id=' . $responsableId)) ?>">Compromisos</a>
  </div>
</form>

<h2 class="section-title">Indicadores principales</h2>
<div class="kpi-grid">
  <div class="kpi-card"><p class="kpi-label">Clientes asignados</p><p class="kpi-value"><?= number_format((float)($kpi['clientes_asignados'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Documentos asignados</p><p class="kpi-value"><?= number_format((float)($kpi['documentos_asignados'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Saldo total asignado</p><p class="kpi-value">$<?= number_format($totalSaldo, 0, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Saldo vencido</p><p class="kpi-value">$<?= number_format((float)($kpi['saldo_vencido'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Promedio días de mora</p><p class="kpi-value"><?= number_format((float)($kpi['promedio_mora'] ?? 0), 1, ',', '.') ?></p></div>
</div>

<h2 class="section-title">Indicadores de gestión</h2>
<div class="kpi-grid kpi-grid-compact">
  <div class="kpi-card"><p class="kpi-label">Gestiones realizadas hoy</p><p class="kpi-value"><?= (int)($ops['hoy'] ?? 0) ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Gestiones realizadas esta semana</p><p class="kpi-value"><?= (int)($ops['semana'] ?? 0) ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Compromisos activos</p><p class="kpi-value"><?= (int)($ops['activos'] ?? 0) ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Compromisos vencidos</p><p class="kpi-value"><?= (int)($ops['vencidos'] ?? 0) ?></p></div>
</div>

<div class="dashboard-two-cols">
  <div class="card">
    <div class="card-header"><h3>Distribución de cartera por mora</h3></div>
    <div class="mora-bars">
      <?php foreach ($bucketValues as $bucket => $value): ?>
        <?php $width = $maxBucket > 0 ? (($value / $maxBucket) * 100) : 0; ?>
        <div class="mora-row">
          <span class="mora-bucket"><?= htmlspecialchars($bucket) ?></span>
          <div class="mora-track"><span class="mora-fill" style="width: <?= number_format($width, 2, '.', '') ?>%"></span></div>
          <span class="mora-value">$<?= number_format($value, 0, ',', '.') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Alertas automáticas</h3></div>
    <ul class="alert-list">
      <li><strong><?= (int)($alertas['compromisos_vencidos'] ?? 0) ?></strong> compromisos vencidos por gestionar.</li>
      <li><strong><?= (int)($alertas['mora_critica'] ?? 0) ?></strong> clientes/documentos con mora crítica (90+ días).</li>
      <li><strong><?= (int)($alertas['sin_gestion_reciente'] ?? 0) ?></strong> documentos sin gestión reciente (7+ días).</li>
    </ul>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Concentración de cartera (Top 10 clientes)</h3></div>
  <p class="kpi-subtext">Participación top 10: <strong><?= number_format($concentracionTop10, 2, ',', '.') ?>%</strong> del saldo total asignado.</p>
  <table class="table">
    <tr><th>Cliente</th><th>NIT</th><th>Saldo total</th><th>% participación</th></tr>
    <?php foreach ($topClientes as $cliente): ?>
      <?php $share = $totalSaldo > 0 ? ((float)$cliente['saldo'] / $totalSaldo) * 100 : 0; ?>
      <tr>
        <td><a href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . (int)$cliente['cliente_id'])) ?>"><?= htmlspecialchars((string)$cliente['cliente']) ?></a></td>
        <td><?= htmlspecialchars((string)$cliente['nit']) ?></td>
        <td>$<?= number_format((float)$cliente['saldo'], 2, ',', '.') ?></td>
        <td><?= number_format($share, 2, ',', '.') ?>%</td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php
$content = ob_get_clean();
render_layout('Gestión de cartera', $content);
