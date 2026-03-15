<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';
require_once __DIR__ . '/../../../app/services/UenService.php';

require_role(['admin', 'analista']);

$tipo = trim($_GET['tipo'] ?? '');
$clienteFiltro = trim($_GET['cliente'] ?? '');
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$isAdmin = portfolio_is_admin();
$responsableId = $isAdmin ? (int)($_GET['responsable_id'] ?? 0) : $currentUserId;
$responsables = gestion_get_responsables($pdo);
$uens = uen_apply_scope(uen_requested_values('uen'), uen_user_allowed_values($pdo));
$uensOptions = $pdo->query("SELECT DISTINCT uens AS uen FROM cartera_documentos WHERE uens IS NOT NULL AND TRIM(uens) <> '' ORDER BY uens")->fetchAll(PDO::FETCH_COLUMN) ?: [];

$where = [];
$params = [];
if ($tipo !== '') {
    $where[] = 'g.tipo_gestion = ?';
    $params[] = $tipo;
}
if ($clienteFiltro !== '') {
    $where[] = '(d.cliente LIKE ? OR c.nit LIKE ? OR d.nro_documento LIKE ?)';
    $params[] = '%' . $clienteFiltro . '%';
    $params[] = '%' . $clienteFiltro . '%';
    $params[] = '%' . $clienteFiltro . '%';
}
$uenScope = uen_sql_condition('d.uens', $uens);
if ($uenScope['sql'] !== '') {
    $where[] = ltrim($uenScope['sql'], ' AND');
    $params = array_merge($params, $uenScope['params']);
}
if ($responsableId > 0) {
    $where[] = 'c.responsable_usuario_id = ?';
    $params[] = $responsableId;
}

$sql = 'SELECT g.*, u.nombre AS usuario, d.cliente, c.nit, d.nro_documento, d.dias_vencido, d.saldo_pendiente
        FROM bitacora_gestion g
        INNER JOIN usuarios u ON u.id = g.usuario_id
        INNER JOIN cartera_documentos d ON d.id = g.id_documento
        INNER JOIN clientes c ON c.id = d.cliente_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY g.created_at DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

ob_start(); ?>
<h1>Historial analítico de gestiones</h1>

<form class="card" method="get">
  <div class="row">
    <input name="tipo" placeholder="Tipo de gestión" value="<?= htmlspecialchars($tipo) ?>">
    <input name="cliente" placeholder="Cliente / NIT / Documento" value="<?= htmlspecialchars($clienteFiltro) ?>">
    <select name="responsable_id">
      <option value="0">Todos los responsables</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="uen[]" multiple size="3" required>
      <?php foreach ($uensOptions as $u): ?>
        <option value="<?= htmlspecialchars((string)$u) ?>" <?= in_array((string)$u, $uens, true) ? 'selected' : '' ?>><?= htmlspecialchars((string)$u) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Filtrar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/lista.php')) ?>">Limpiar</a>
    <a class="btn" href="<?= htmlspecialchars(app_url('gestion/bandeja.php')) ?>">Ir a gestión unificada</a>
  </div>
</form>

<table class="table">
  <tr><th>Fecha gestión</th><th>Cliente</th><th>Documento</th><th>Días mora</th><th>Tipo</th><th>Observación</th><th>Valor comprometido</th><th>Fecha compromiso</th><th>Responsable</th><th>Estado compromiso</th></tr>
  <?php foreach ($rows as $row): ?>
    <?php [$estadoTexto, $estadoColor] = gestion_compromiso_estado((string)($row['estado_compromiso'] ?? ""), $row['compromiso_pago'] ?? null); ?>
    <tr>
      <td><?= htmlspecialchars((string)$row['created_at']) ?></td>
      <td><?= htmlspecialchars((string)$row['cliente']) ?><br><small><?= htmlspecialchars((string)$row['nit']) ?></small></td>
      <td><a href="<?= htmlspecialchars(app_url('gestion/detalle.php?documento_id=' . (int)$row['id_documento'])) ?>"><?= htmlspecialchars((string)$row['nro_documento']) ?></a></td>
      <td><?= (int)$row['dias_vencido'] ?></td>
      <td><?= htmlspecialchars((string)$row['tipo_gestion']) ?></td>
      <td><?= htmlspecialchars((string)$row['observacion']) ?></td>
      <td>$<?= number_format((float)($row['valor_compromiso'] ?? 0), 2, ',', '.') ?></td>
      <td><?= htmlspecialchars((string)($row['compromiso_pago'] ?? '-')) ?></td>
      <td><?= htmlspecialchars((string)$row['usuario']) ?></td>
      <td><?= ui_badge($estadoTexto, $estadoColor) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Historial de gestiones', $content);
