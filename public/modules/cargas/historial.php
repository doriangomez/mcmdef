<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_once __DIR__ . '/../../../app/services/CargaDeletionService.php';
require_role(['admin', 'analista']);

$msg = '';
$errorMsg = '';

if (isset($_SESSION['flash_carga_delete']) && is_array($_SESSION['flash_carga_delete'])) {
    $flashDelete = $_SESSION['flash_carga_delete'];
    if (($flashDelete['type'] ?? '') === 'ok') {
        $msg = (string)($flashDelete['message'] ?? 'El cargue fue eliminado correctamente.');
    } elseif (($flashDelete['type'] ?? '') === 'error') {
        $errorMsg = (string)($flashDelete['message'] ?? 'No fue posible eliminar el cargue.');
    }
    unset($_SESSION['flash_carga_delete']);
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anular_carga') {
    $cargaId = (int)($_POST['carga_id'] ?? 0);
    if ($cargaId > 0) {
        $stmt = $pdo->prepare("UPDATE cargas_cartera SET estado = 'anulada', activo = 0 WHERE id = ? AND estado = 'activa'");
        $stmt->execute([$cargaId]);
        audit_log($pdo, 'cargas_cartera', $cargaId, 'carga_anulada', 'activa', 'anulada', (int)current_user()['id']);
        $msg = 'Carga anulada correctamente.';
    }
}

$cargas = $pdo->query(
    'SELECT c.*, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     ORDER BY c.id DESC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
    <th>Periodo</th>
    <th>Total documentos</th>
    <th>Total saldo</th>
    <th>Estado</th>
    <th>Activo</th>
    <th>Usuario</th>
    <th>Fecha</th>
    <th>Acciones</th>
  </tr>
  <?php foreach ($cargas as $c): ?>
    <tr>
      <td><?= (int)$c['id'] ?></td>
      <td><?= htmlspecialchars($c['nombre_archivo']) ?></td>
      <td><code><?= htmlspecialchars($c['hash_archivo']) ?></code></td>
      <td><?= htmlspecialchars((string)($c['periodo_detectado'] ?? 'N/A')) ?></td>
      <td><?= (int)$c['total_documentos'] ?></td>
      <td><?= number_format((float)$c['total_saldo'], 2, ',', '.') ?></td>
      <td><?= (($c['estado'] ?? '') === 'activa') ? ui_badge('Activa', 'success') : ui_badge('Anulada', 'warning') ?></td>
      <td><?= (int)($c['activo'] ?? 0) === 1 ? ui_badge('Sí', 'success') : ui_badge('No', 'warning') ?></td>
      <td><?= htmlspecialchars($c['usuario'] ?? '-') ?></td>
      <td><?= htmlspecialchars($c['fecha_carga']) ?></td>
      <td>
        <a href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . (int)$c['id'])) ?>">Ver detalle</a>
        <form method="post" class="inline-form" onsubmit="return confirm('¿Anular carga?');">
          <input type="hidden" name="action" value="anular_carga">
          <input type="hidden" name="carga_id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-secondary btn-sm" type="submit">Anular carga</button>
        </form>
        <?php if (current_user()['rol'] === 'admin'): ?>
          <form method="post" action="<?= htmlspecialchars(app_url('cargas/eliminar.php')) ?>" class="inline-form" onsubmit="return confirm('¿Eliminar este cargue? Esta acción no se puede deshacer.');">
            <input type="hidden" name="carga_id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Eliminar cargue</button>
          </form>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(app_url('cargas/nueva.php')) ?>" class="btn btn-sm">Reprocesar</a>
      </td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Historial cargas', $content);
