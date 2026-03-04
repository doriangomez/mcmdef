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
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL AND g.compromiso_pago >= CURDATE() THEN 1 ELSE 0 END) AS activos,
        SUM(CASE WHEN g.compromiso_pago IS NOT NULL AND g.compromiso_pago < CURDATE() THEN 1 ELSE 0 END) AS vencidos
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
        SUM(CASE WHEN d.dias_vencido > 90 THEN d.saldo_pendiente ELSE 0 END) AS b90_plus
     FROM cartera_documentos d
     WHERE d.estado_documento = "activo"' . $scope['sql']
);
$distStmt->execute($scope['params']);
$dist = $distStmt->fetch() ?: [];

$topStmt = $pdo->prepare(
    'SELECT d.cliente, c.nit, SUM(d.saldo_pendiente) AS saldo
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE d.estado_documento = "activo"' . $scope['sql'] . '
     GROUP BY d.cliente_id, d.cliente, c.nit
     ORDER BY saldo DESC
     LIMIT 5'
);
$topStmt->execute($scope['params']);
$topClientes = $topStmt->fetchAll() ?: [];

ob_start(); ?>
<h1>Dashboard operativo de cartera</h1>
<form class="card" method="get">
  <div class="row">
    <select name="responsable_id">
      <option value="0">Toda la operación</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Aplicar responsable</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/bandeja.php?responsable_id=' . $responsableId)) ?>">Ir a bandeja</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/compromisos.php?responsable_id=' . $responsableId)) ?>">Ver compromisos</a>
  </div>
</form>

<div class="kpi-grid">
  <div class="kpi-card"><p class="kpi-label">Clientes asignados</p><p class="kpi-value"><?= number_format((float)($kpi['clientes_asignados'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Documentos asignados</p><p class="kpi-value"><?= number_format((float)($kpi['documentos_asignados'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Saldo total asignado</p><p class="kpi-value">$<?= number_format((float)($kpi['saldo_total'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Saldo vencido</p><p class="kpi-value">$<?= number_format((float)($kpi['saldo_vencido'] ?? 0), 0, ',', '.') ?></p></div>
</div>

<div class="kpi-grid">
  <div class="kpi-card"><p class="kpi-label">Promedio días mora</p><p class="kpi-value"><?= number_format((float)($kpi['promedio_mora'] ?? 0), 1, ',', '.') ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Gestiones hoy</p><p class="kpi-value"><?= (int)($ops['hoy'] ?? 0) ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Gestiones semana</p><p class="kpi-value"><?= (int)($ops['semana'] ?? 0) ?></p></div>
  <div class="kpi-card"><p class="kpi-label">Compromisos activos / vencidos</p><p class="kpi-value"><?= (int)($ops['activos'] ?? 0) ?> / <?= (int)($ops['vencidos'] ?? 0) ?></p></div>
</div>

<div class="card">
  <div class="card-header"><h3>Distribución de cartera por mora</h3></div>
  <table class="table">
    <tr><th>Bucket</th><th>Saldo</th></tr>
    <tr><td>0 a 30 días</td><td>$<?= number_format((float)($dist['b0_30'] ?? 0), 2, ',', '.') ?></td></tr>
    <tr><td>31 a 60 días</td><td>$<?= number_format((float)($dist['b31_60'] ?? 0), 2, ',', '.') ?></td></tr>
    <tr><td>61 a 90 días</td><td>$<?= number_format((float)($dist['b61_90'] ?? 0), 2, ',', '.') ?></td></tr>
    <tr><td>Mayor a 90 días</td><td>$<?= number_format((float)($dist['b90_plus'] ?? 0), 2, ',', '.') ?></td></tr>
  </table>
</div>

<div class="card">
  <div class="card-header"><h3>Top clientes por saldo (concentración de riesgo)</h3></div>
  <table class="table">
    <tr><th>Cliente</th><th>NIT</th><th>Saldo</th></tr>
    <?php foreach ($topClientes as $cliente): ?>
      <tr>
        <td><?= htmlspecialchars((string)$cliente['cliente']) ?></td>
        <td><?= htmlspecialchars((string)$cliente['nit']) ?></td>
        <td>$<?= number_format((float)$cliente['saldo'], 2, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php
$content = ob_get_clean();
render_layout('Gestión de cartera', $content);
