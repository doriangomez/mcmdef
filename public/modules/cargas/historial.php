<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_role(['admin', 'analista']);

$msg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'borrar_datos_cargue') {
    if (current_user()['rol'] !== 'admin') {
        $errorMsg = 'Solo el administrador puede borrar los datos del cargue.';
    } else {
        try {
            $pdo->beginTransaction();
            $tablesToClear = ['bitacora_gestion', 'cartera_documentos', 'carga_errores', 'cargas_cartera', 'clientes'];
            $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $existingLookup = array_flip($existingTables);

            foreach ($tablesToClear as $tableName) {
                if (isset($existingLookup[$tableName])) {
                    $pdo->exec('DELETE FROM ' . $tableName);
                }
            }

            if (isset($existingLookup['auditoria_sistema'])) {
                audit_log($pdo, 'cargas_cartera', 0, 'borrado_masivo_temporal', null, 'tablas=' . implode(',', $tablesToClear), (int)current_user()['id']);
            }

            $pdo->commit();
            $msg = 'Se eliminaron temporalmente todos los datos de cargue para pruebas.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = 'No fue posible borrar los datos de cargue: ' . $exception->getMessage();
        }
    }
}

$cargas = $pdo->query(
    'SELECT c.*, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     ORDER BY c.id DESC'
)->fetchAll();

ob_start(); ?>
<h1>Historial de cargas</h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
<div class="card">
  <a class="btn" href="<?= htmlspecialchars(app_url('cargas/nueva.php')) ?>">Nueva carga</a>
  <?php if (current_user()['rol'] === 'admin'): ?>
    <form method="post" class="inline-form" onsubmit="return confirm('¿Seguro que quieres borrar TODOS los datos del cargue? Esta acción es temporal y no se puede deshacer.');">
      <input type="hidden" name="action" value="borrar_datos_cargue">
      <button class="btn btn-danger" type="submit">Borrar datos de cargue (temporal)</button>
    </form>
  <?php endif; ?>
</div>
<table class="table">
  <tr>
    <th>ID</th>
    <th>Archivo</th>
    <th>Hash SHA-256</th>
    <th>Estado</th>
    <th>Total documentos</th>
    <th>Total saldo</th>
    <th>Usuario</th>
    <th>Fecha</th>
    <th>Detalle</th>
  </tr>
  <?php foreach ($cargas as $c): ?>
    <tr>
      <td><?= (int)$c['id'] ?></td>
      <td><?= htmlspecialchars($c['nombre_archivo']) ?></td>
      <td><code><?= htmlspecialchars($c['hash_archivo']) ?></code></td>
      <td><?= $c['estado'] === 'activa' ? ui_badge('Activa', 'success') : ui_badge('Anulada', 'warning') ?></td>
      <td><?= (int)$c['total_documentos'] ?></td>
      <td><?= number_format((float)$c['total_saldo'], 2, ',', '.') ?></td>
      <td><?= htmlspecialchars($c['usuario'] ?? '-') ?></td>
      <td><?= htmlspecialchars($c['fecha_carga']) ?></td>
      <td><a href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . (int)$c['id'])) ?>">Ver</a></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Historial cargas', $content);
