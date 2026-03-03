<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_role(['admin', 'analista']);

$cargas = $pdo->query(
    'SELECT c.*, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     ORDER BY c.id DESC'
)->fetchAll();

ob_start(); ?>
<h1>Historial de cargas</h1>
<div class="card">
  <a class="btn" href="<?= htmlspecialchars(app_url('cargas/nueva.php')) ?>">Nueva carga</a>
</div>
<table class="table">
  <tr>
    <th>ID</th>
    <th>Archivo</th>
    <th>Hash SHA-256</th>
    <th>Estado</th>
    <th>Errores</th>
    <th>Nuevos</th>
    <th>Actualizados</th>
    <th>Registros</th>
    <th>Usuario</th>
    <th>Fecha</th>
    <th>Detalle</th>
  </tr>
  <?php foreach ($cargas as $c): ?>
    <tr>
      <td><?= (int)$c['id'] ?></td>
      <td><?= htmlspecialchars($c['nombre_archivo']) ?></td>
      <td><code><?= htmlspecialchars($c['hash_archivo']) ?></code></td>
      <td>
        <?php
          if ($c['estado'] === 'procesado') {
              echo ui_badge('Procesado', 'success');
          } elseif ($c['estado'] === 'con_errores') {
              echo ui_badge('Con errores', 'danger');
          } elseif ($c['estado'] === 'revertida') {
              echo ui_badge('Revertida', 'warning');
          } else {
              echo ui_badge((string)$c['estado'], 'default');
          }
        ?>
      </td>
      <td><?= (int)$c['total_errores'] ?></td>
      <td><?= (int)$c['total_nuevos'] ?></td>
      <td><?= (int)$c['total_actualizados'] ?></td>
      <td><?= (int)$c['total_registros'] ?></td>
      <td><?= htmlspecialchars($c['usuario'] ?? '-') ?></td>
      <td><?= htmlspecialchars($c['fecha_carga']) ?></td>
      <td><a href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . (int)$c['id'])) ?>">Ver</a></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Historial cargas', $content);
