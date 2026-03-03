<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';

require_role(['admin', 'analista']);

$tipo = trim($_GET['tipo'] ?? '');
$clienteFiltro = trim($_GET['cliente'] ?? '');

$where = [];
$params = [];
if ($tipo !== '') {
    $where[] = 'g.tipo_gestion = ?';
    $params[] = $tipo;
}
if ($clienteFiltro !== '') {
    $where[] = '(d.cliente LIKE ? OR c.nit LIKE ?)';
    $params[] = '%' . $clienteFiltro . '%';
    $params[] = '%' . $clienteFiltro . '%';
}

$sql = 'SELECT g.*, u.nombre AS usuario, d.cliente, c.nit, d.nro_documento
        FROM bitacora_gestion g
        INNER JOIN usuarios u ON u.id = g.usuario_id
        INNER JOIN cartera_documentos d ON d.id = g.id_documento
        INNER JOIN clientes c ON c.id = d.cliente_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY g.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

ob_start(); ?>
<h1>Historial de gestiones</h1>

<form class="card">
  <div class="row">
    <input name="tipo" placeholder="Tipo (novedad, compromiso...)" value="<?= htmlspecialchars($tipo) ?>">
    <input name="cliente" placeholder="Cliente / NIT" value="<?= htmlspecialchars($clienteFiltro) ?>">
    <button class="btn">Filtrar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/lista.php')) ?>">Limpiar</a>
    <a class="btn" href="<?= htmlspecialchars(app_url('gestion/nueva.php')) ?>">Nueva gestión</a>
  </div>
</form>

<table class="table">
  <tr><th>ID</th><th>Fecha</th><th>Cliente</th><th>Documento</th><th>Tipo</th><th>Observación</th><th>Compromiso</th><th>Usuario</th></tr>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int)$row['id'] ?></td>
      <td><?= htmlspecialchars($row['created_at']) ?></td>
      <td><?= htmlspecialchars((string)$row['cliente']) ?> (<?= htmlspecialchars((string)$row['nit']) ?>)</td>
      <td><?= htmlspecialchars((string)$row['nro_documento']) ?></td>
      <td><?= htmlspecialchars($row['tipo_gestion']) ?></td>
      <td><?= htmlspecialchars($row['observacion']) ?></td>
      <td><?= htmlspecialchars((string)$row['compromiso_pago']) ?> / <?= htmlspecialchars((string)$row['valor_compromiso']) ?></td>
      <td><?= htmlspecialchars($row['usuario']) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Gestiones', $content);
