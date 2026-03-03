<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExcelImportService.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);

$id = (int)($_GET['id'] ?? 0);
$msg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revertir') {
    if (current_user()['rol'] !== 'admin') {
        $errorMsg = 'Solo el administrador puede revertir cargas.';
    } else {
        try {
            $pdo->beginTransaction();
            $result = revert_last_carga($pdo, $id);
            $upd = $pdo->prepare(
                "UPDATE cargas_cartera
                 SET estado = 'revertida',
                     observaciones = CONCAT(IFNULL(observaciones, ''), ' | Revertida por admin'),
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $upd->execute([$id]);
            audit_log($pdo, 'cargas_cartera', $id, 'estado', 'procesado', 'revertida', (int)current_user()['id']);
            $pdo->commit();
            $msg = 'Carga revertida. Documentos restaurados: ' . $result['restored'] . '. Documentos eliminados: ' . $result['removed'] . '.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = $exception->getMessage();
        }
    }
}

$cargaStmt = $pdo->prepare(
    'SELECT c.*, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     WHERE c.id = ?'
);
$cargaStmt->execute([$id]);
$carga = $cargaStmt->fetch();

$snapshotStmt = $pdo->prepare(
    'SELECT nit, nombre_cliente, tipo_documento, numero_documento, saldo_actual, dias_mora
     FROM documentos_snapshot
     WHERE carga_id = ?
     ORDER BY id DESC
     LIMIT 100'
);
$snapshotStmt->execute([$id]);
$rows = $snapshotStmt->fetchAll();

$errorsStmt = $pdo->prepare(
    'SELECT fila_excel, campo, motivo
     FROM carga_errores
     WHERE carga_id = ?
     ORDER BY id ASC
     LIMIT 200'
);
$errorsStmt->execute([$id]);
$errorRows = $errorsStmt->fetchAll();

ob_start(); ?>
<h1>Detalle carga #<?= $id ?></h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

<div class="card">
  <?php if ($carga): ?>
    <p><strong>Archivo:</strong> <?= htmlspecialchars($carga['nombre_archivo']) ?></p>
    <p><strong>Estado:</strong> <?= htmlspecialchars($carga['estado']) ?> | <strong>Usuario:</strong> <?= htmlspecialchars($carga['usuario'] ?? '-') ?> | <strong>Fecha:</strong> <?= htmlspecialchars($carga['fecha_carga']) ?></p>
    <p>
      <strong>Registros:</strong> <?= (int)$carga['total_registros'] ?> |
      <strong>Errores:</strong> <?= (int)$carga['total_errores'] ?> |
      <strong>Nuevos:</strong> <?= (int)$carga['total_nuevos'] ?> |
      <strong>Actualizados:</strong> <?= (int)$carga['total_actualizados'] ?>
    </p>
    <?php if (current_user()['rol'] === 'admin' && $carga['estado'] === 'procesado'): ?>
      <form method="post" onsubmit="return confirm('¿Confirma revertir esta carga? Solo se permite para la última carga procesada.')">
        <input type="hidden" name="action" value="revertir">
        <button class="btn btn-muted" type="submit">Revertir carga</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    No existe la carga solicitada.
  <?php endif; ?>
</div>

<h3>Errores de validación/proceso</h3>
<?php if (empty($errorRows)): ?>
  <div class="card">Sin errores registrados.</div>
<?php else: ?>
  <table class="table">
    <tr><th>Fila Excel</th><th>Campo</th><th>Motivo</th></tr>
    <?php foreach ($errorRows as $error): ?>
      <tr>
        <td><?= (int)$error['fila_excel'] ?></td>
        <td><?= htmlspecialchars($error['campo']) ?></td>
        <td><?= htmlspecialchars($error['motivo']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<h3>Registros de la carga (snapshot)</h3>
<table class="table">
  <tr><th>NIT</th><th>Cliente</th><th>Tipo</th><th>Número</th><th>Saldo</th><th>Días mora</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['nit']) ?></td>
      <td><?= htmlspecialchars($r['nombre_cliente']) ?></td>
      <td><?= htmlspecialchars($r['tipo_documento']) ?></td>
      <td><?= htmlspecialchars($r['numero_documento']) ?></td>
      <td><?= number_format((float)$r['saldo_actual'], 2, ',', '.') ?></td>
      <td><?= (int)$r['dias_mora'] ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Detalle carga', $content);
