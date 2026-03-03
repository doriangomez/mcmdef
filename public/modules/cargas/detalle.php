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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anular_lote') {
    if (current_user()['rol'] !== 'admin') {
        $errorMsg = 'Solo el administrador puede anular lotes.';
    } else {
        try {
            $pdo->beginTransaction();
            $result = revert_last_carga($pdo, $id);
            $upd = $pdo->prepare("UPDATE cargas_cartera SET estado = 'anulada' WHERE id = ? AND estado = 'activa'");
            $upd->execute([$id]);
            audit_log($pdo, 'cargas_cartera', $id, 'anulacion_lote', 'activa', 'anulada', (int)current_user()['id']);
            audit_log($pdo, 'cartera_documentos', $id, 'cambio_estado_documentos', 'activo', 'inactivo', (int)current_user()['id']);
            $pdo->commit();
            $msg = 'Lote anulado. Documentos marcados inactivos: ' . $result['removed'] . '.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = $exception->getMessage();
        }
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'errores') {
    require_once __DIR__ . '/../../../app/services/ExportService.php';
    $expStmt = $pdo->prepare(
        'SELECT fila_excel AS fila, campo, motivo
         FROM carga_errores
         WHERE carga_id = ?
         ORDER BY id ASC'
    );
    $expStmt->execute([$id]);
    $exportRows = $expStmt->fetchAll();
    if (empty($exportRows)) {
        $exportRows = [['fila' => '', 'campo' => '', 'motivo' => 'Sin errores registrados']];
    }
    export_csv('errores_carga_' . $id . '.csv', $exportRows);
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
    'SELECT c.nit, d.cliente AS nombre_cliente, d.tipo AS tipo_documento, d.nro_documento AS numero_documento, d.saldo_pendiente AS saldo_actual, d.dias_vencido AS dias_mora
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE d.id_carga = ?
     ORDER BY d.id DESC
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
    <p><strong>Hash SHA-256:</strong> <code><?= htmlspecialchars($carga['hash_archivo']) ?></code></p>
    <p><strong>Estado:</strong> <?= htmlspecialchars($carga['estado']) ?> | <strong>Usuario:</strong> <?= htmlspecialchars($carga['usuario'] ?? '-') ?> | <strong>Fecha:</strong> <?= htmlspecialchars($carga['fecha_carga']) ?></p>
    <p><strong>Total documentos:</strong> <?= (int)$carga['total_documentos'] ?> | <strong>Total saldo:</strong> <?= number_format((float)$carga['total_saldo'], 2, ',', '.') ?></p>
    <?php if (current_user()['rol'] === 'admin' && $carga['estado'] === 'activa'): ?>
      <form method="post" onsubmit="return confirm('¿Confirma anular este lote?')">
        <input type="hidden" name="action" value="anular_lote">
        <button class="btn btn-muted" type="submit">Anular lote</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    No existe la carga solicitada.
  <?php endif; ?>
</div>

<h3>Errores de validación/proceso</h3>
<p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . $id . '&export=errores')) ?>">Descargar reporte de errores (CSV)</a></p>
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

<h3>Registros de la carga</h3>
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
