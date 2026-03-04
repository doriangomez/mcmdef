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

$compromisosSql = 'SELECT
    g.id,
    g.id_documento,
    g.compromiso_pago,
    g.valor_compromiso,
    g.estado_compromiso,
    g.observacion,
    g.created_at,
    u.nombre AS responsable,
    d.cliente,
    d.cliente_id,
    d.nro_documento,
    d.cuenta
 FROM bitacora_gestion g
 INNER JOIN (
    SELECT id_documento, MAX(id) AS last_id
    FROM bitacora_gestion
    WHERE compromiso_pago IS NOT NULL
    GROUP BY id_documento
 ) ult ON ult.last_id = g.id
 INNER JOIN usuarios u ON u.id = g.usuario_id
 INNER JOIN cartera_documentos d ON d.id = g.id_documento
 WHERE 1 = 1';

$params = [];
if ($responsableId > 0) {
    $compromisosSql .= ' AND g.usuario_id = ?';
    $params[] = $responsableId;
}

$compromisosSql .= ' ORDER BY
    CASE
        WHEN LOWER(COALESCE(g.estado_compromiso, "pendiente")) = "cumplido" THEN 4
        WHEN LOWER(COALESCE(g.estado_compromiso, "pendiente")) = "incumplido" THEN 5
        WHEN DATE(g.compromiso_pago) < CURDATE() THEN 1
        WHEN DATE(g.compromiso_pago) = CURDATE() THEN 2
        ELSE 3
    END,
    g.compromiso_pago ASC,
    g.id DESC
 LIMIT 500';

$stmt = $pdo->prepare($compromisosSql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

$stats = [
    'vencidos' => 0,
    'hoy' => 0,
    'proximos' => 0,
    'cumplidos' => 0,
    'incumplidos' => 0,
    'pendientes' => 0,
];

$sections = [
    'vencidos' => [],
    'hoy' => [],
    'proximos' => [],
    'cumplidos' => [],
    'incumplidos' => [],
];

foreach ($rows as $row) {
    $estadoRaw = strtolower(trim((string)($row['estado_compromiso'] ?? '')));
    $fechaCompromiso = (string)($row['compromiso_pago'] ?? '');

    $bucket = 'proximos';
    if ($estadoRaw === 'cumplido') {
        $bucket = 'cumplidos';
    } elseif ($estadoRaw === 'incumplido') {
        $bucket = 'incumplidos';
    } else {
        if ($fechaCompromiso !== '') {
            $fechaIso = substr($fechaCompromiso, 0, 10);
            $hoy = date('Y-m-d');
            if ($fechaIso < $hoy) {
                $bucket = 'vencidos';
            } elseif ($fechaIso === $hoy) {
                $bucket = 'hoy';
            } else {
                $bucket = 'proximos';
            }
        }
    }

    $stats[$bucket]++;
    if (in_array($bucket, ['vencidos', 'hoy', 'proximos'], true)) {
        $stats['pendientes']++;
    }
    $sections[$bucket][] = $row;
}

$clientesSinGestion = 0;
$sinGestionSql = 'SELECT COUNT(*)
    FROM clientes c
    WHERE NOT EXISTS (
        SELECT 1
        FROM bitacora_gestion g
        INNER JOIN cartera_documentos d2 ON d2.id = g.id_documento
        WHERE d2.cliente_id = c.id
          AND g.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
$sinGestionParams = [];
if ($responsableId > 0) {
    $sinGestionSql .= ' AND g.usuario_id = ?';
    $sinGestionParams[] = $responsableId;
}
$sinGestionSql .= '    )';

$sinGestionStmt = $pdo->prepare($sinGestionSql);
$sinGestionStmt->execute($sinGestionParams);
$clientesSinGestion = (int)$sinGestionStmt->fetchColumn();

$legend = [
    'vencidos' => ['title' => '🔴 Compromisos vencidos', 'empty' => 'No hay compromisos vencidos.'],
    'hoy' => ['title' => '🟡 Compromisos de hoy', 'empty' => 'No hay compromisos para hoy.'],
    'proximos' => ['title' => '🟢 Compromisos próximos', 'empty' => 'No hay compromisos próximos.'],
    'cumplidos' => ['title' => '🔵 Compromisos cumplidos', 'empty' => 'No hay compromisos cumplidos registrados.'],
    'incumplidos' => ['title' => '⚫ Compromisos incumplidos', 'empty' => 'No hay compromisos incumplidos registrados.'],
];

ob_start();
?>
<style>
  .agenda-resumen { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin:12px 0; }
  .agenda-kpi { border:1px solid #e2e8f0; border-radius:12px; padding:10px; background:#fff; }
  .agenda-kpi h4 { margin:0; font-size:12px; color:#64748b; text-transform:uppercase; }
  .agenda-kpi p { margin:6px 0 0; font-size:24px; font-weight:700; }
  .agenda-kpi.alert p { color:#b91c1c; }
  .agenda-kpi.warn p { color:#b45309; }
  .agenda-kpi.ok p { color:#047857; }
  .agenda-kpi.info p { color:#1d4ed8; }
  .agenda-kpi.dark p { color:#334155; }
  .agenda-seccion { margin-top:16px; }
  .agenda-seccion h3 { margin-bottom:8px; }
  .agenda-empty { color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; padding:10px; }
  .agenda-row td { vertical-align:top; }
  .agenda-obs { color:#334155; font-size:12px; max-width:280px; }
  .agenda-actions { display:flex; flex-wrap:wrap; gap:6px; }
  .agenda-actions .btn { padding:5px 8px; font-size:12px; }
</style>

<h1>Agenda de compromisos de pago</h1>
<form class="card" method="get">
  <div class="row">
    <select name="responsable_id">
      <option value="0">Todos los responsables</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Filtrar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/dashboard.php?responsable_id=' . $responsableId)) ?>">Dashboard</a>
  </div>
</form>

<div class="agenda-resumen">
  <div class="agenda-kpi alert"><h4>Compromisos vencidos</h4><p><?= $stats['vencidos'] ?></p></div>
  <div class="agenda-kpi warn"><h4>Compromisos de hoy</h4><p><?= $stats['hoy'] ?></p></div>
  <div class="agenda-kpi ok"><h4>Compromisos pendientes</h4><p><?= $stats['pendientes'] ?></p></div>
  <div class="agenda-kpi info"><h4>Compromisos cumplidos</h4><p><?= $stats['cumplidos'] ?></p></div>
  <div class="agenda-kpi dark"><h4>Clientes sin gestión reciente</h4><p><?= $clientesSinGestion ?></p></div>
</div>

<div class="card">
  <div class="card-header"><h3>Tareas de hoy</h3></div>
  <div class="row">
    <div><?= $stats['vencidos'] ?> compromisos vencidos</div>
    <div><?= $stats['hoy'] ?> compromisos que vencen hoy</div>
    <div><?= $clientesSinGestion ?> clientes sin gestión en 7 días</div>
  </div>
</div>

<?php
$renderSection = static function (string $key, array $items, array $legend): void {
    ?>
    <section class="agenda-seccion card">
      <div class="card-header"><h3><?= htmlspecialchars($legend[$key]['title']) ?></h3></div>
      <?php if (!$items): ?>
        <div class="agenda-empty"><?= htmlspecialchars($legend[$key]['empty']) ?></div>
      <?php else: ?>
        <table class="table">
          <tr>
            <th>Cliente</th>
            <th>Cuenta / Documento</th>
            <th>Valor compromiso</th>
            <th>Fecha compromiso</th>
            <th>Estado</th>
            <th>Usuario</th>
            <th>Última observación</th>
            <th>Acciones</th>
          </tr>
          <?php foreach ($items as $row): ?>
            <?php [$estadoTexto, $estadoColor] = gestion_compromiso_estado((string)($row['estado_compromiso'] ?? ''), $row['compromiso_pago'] ?? null); ?>
            <tr class="agenda-row">
              <td><?= htmlspecialchars((string)$row['cliente']) ?></td>
              <td>
                <div><?= htmlspecialchars((string)($row['cuenta'] ?? '-')) ?></div>
                <small><?= htmlspecialchars((string)$row['nro_documento']) ?></small>
              </td>
              <td>$<?= number_format((float)($row['valor_compromiso'] ?? 0), 2, ',', '.') ?></td>
              <td><?= htmlspecialchars(substr((string)$row['compromiso_pago'], 0, 10)) ?></td>
              <td><?= ui_badge($estadoTexto, $estadoColor) ?></td>
              <td><?= htmlspecialchars((string)$row['responsable']) ?></td>
              <td class="agenda-obs"><?= htmlspecialchars((string)($row['observacion'] ?? 'Sin observación')) ?></td>
              <td>
                <div class="agenda-actions">
                  <a class="btn btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . (int)$row['cliente_id'] . '&documento_id=' . (int)$row['id_documento'] . '#registro-gestion')) ?>">Abrir gestión</a>
                  <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . (int)$row['cliente_id'] . '&documento_id=' . (int)$row['id_documento'] . '#registro-gestion')) ?>">Registrar pago</a>
                  <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . (int)$row['cliente_id'] . '&documento_id=' . (int)$row['id_documento'] . '#registro-gestion')) ?>">Marcar cumplido</a>
                  <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . (int)$row['cliente_id'] . '&documento_id=' . (int)$row['id_documento'] . '#registro-gestion')) ?>">Marcar incumplido</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </section>
    <?php
};

$renderSection('vencidos', $sections['vencidos'], $legend);
$renderSection('hoy', $sections['hoy'], $legend);
$renderSection('proximos', $sections['proximos'], $legend);
$renderSection('cumplidos', $sections['cumplidos'], $legend);
$renderSection('incumplidos', $sections['incumplidos'], $legend);

$content = ob_get_clean();
render_layout('Compromisos', $content);
