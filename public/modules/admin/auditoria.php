<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExportService.php';

require_role(['admin']);

$tabla = trim($_GET['tabla'] ?? '');
$usuarioId = (int)($_GET['usuario_id'] ?? 0);
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

$where = [];
$params = [];
if ($tabla !== '') {
    $where[] = 'a.tabla = ?';
    $params[] = $tabla;
}
if ($usuarioId > 0) {
    $where[] = 'a.usuario_id = ?';
    $params[] = $usuarioId;
}
if ($desde !== '') {
    $where[] = 'DATE(a.created_at) >= ?';
    $params[] = $desde;
}
if ($hasta !== '') {
    $where[] = 'DATE(a.created_at) <= ?';
    $params[] = $hasta;
}

$sql = 'SELECT a.id, a.tabla, a.registro_id, a.campo, a.valor_anterior, a.valor_nuevo, a.created_at, u.nombre AS usuario
        FROM auditoria_log a
        INNER JOIN usuarios u ON u.id = a.usuario_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY a.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (isset($_GET['export'])) {
    export_csv('auditoria_log.csv', $rows);
    exit;
}

$users = $pdo->query('SELECT id, nombre FROM usuarios ORDER BY nombre')->fetchAll();

ob_start(); ?>
<h1>Auditoría y trazabilidad</h1>
<form class="card">
  <div class="row">
    <input name="tabla" placeholder="Tabla (usuarios, cargas_cartera...)" value="<?= htmlspecialchars($tabla) ?>">
    <select name="usuario_id">
      <option value="0">Usuario (todos)</option>
      <?php foreach ($users as $user): ?>
        <option value="<?= (int)$user['id'] ?>" <?= $usuarioId === (int)$user['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($user['nombre']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label>Desde <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></label>
    <button class="btn" type="submit">Filtrar</button>
    <a class="btn btn-muted" href="<?= htmlspecialchars(app_url('admin/auditoria.php')) ?>">Limpiar</a>
    <button class="btn btn-muted" name="export" value="1" type="submit">Exportar CSV</button>
  </div>
</form>

<table class="table">
  <tr><th>ID</th><th>Fecha</th><th>Usuario</th><th>Tabla</th><th>Registro</th><th>Campo</th><th>Anterior</th><th>Nuevo</th></tr>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int)$row['id'] ?></td>
      <td><?= htmlspecialchars($row['created_at']) ?></td>
      <td><?= htmlspecialchars($row['usuario']) ?></td>
      <td><?= htmlspecialchars($row['tabla']) ?></td>
      <td><?= (int)$row['registro_id'] ?></td>
      <td><?= htmlspecialchars($row['campo']) ?></td>
      <td><?= htmlspecialchars((string)$row['valor_anterior']) ?></td>
      <td><?= htmlspecialchars((string)$row['valor_nuevo']) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Auditoría', $content);
