<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';

$id = (int)($_GET['id_documento'] ?? 0);
$docStmt = $pdo->prepare(
    'SELECT d.*, c.nombre AS cliente, c.nit
     FROM documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE d.id = ?'
);
$docStmt->execute([$id]);
$document = $docStmt->fetch();

$gestionesStmt = $pdo->prepare(
    'SELECT g.*, u.nombre AS usuario
     FROM gestiones g
     INNER JOIN usuarios u ON u.id = g.usuario_id
     WHERE g.documento_id = ?
     ORDER BY g.id DESC'
);
$gestionesStmt->execute([$id]);
$gestiones = $gestionesStmt->fetchAll();

$canManage = in_array(current_user()['rol'], ['admin', 'analista'], true);
ob_start(); ?>
<h1>Detalle de documento</h1>
<div class="card">
  <?php if ($document): ?>
    Cliente: <?= htmlspecialchars($document['cliente']) ?> (<?= htmlspecialchars($document['nit']) ?>)<br>
    Documento: <?= htmlspecialchars($document['tipo_documento']) ?> #<?= htmlspecialchars($document['numero_documento']) ?><br>
    Emisión: <?= htmlspecialchars($document['fecha_emision']) ?> | Vencimiento: <?= htmlspecialchars($document['fecha_vencimiento']) ?><br>
    Saldo: <?= number_format((float)$document['saldo_actual'], 2, ',', '.') ?> |
    Mora: <?= (int)$document['dias_mora'] ?> |
    Estado: <?= htmlspecialchars($document['estado_documento']) ?> |
    Carga origen: #<?= (int)$document['id_carga_origen'] ?>
  <?php else: ?>
    Documento no encontrado.
  <?php endif; ?>
</div>

<?php if ($canManage && $document): ?>
  <a class="btn" href="<?= htmlspecialchars(app_url('gestion/nueva.php?documento_id=' . $id . '&cliente_id=' . (int)$document['cliente_id'])) ?>">Registrar gestión</a>
<?php endif; ?>
<a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/cliente.php?id_cliente=' . (int)($document['cliente_id'] ?? 0))) ?>">Volver al cliente</a>

<table class="table">
  <tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Compromiso</th><th>Estado compromiso</th><th>Anulada</th><th>Usuario</th></tr>
  <?php foreach ($gestiones as $gestion): ?>
    <tr>
      <td><?= htmlspecialchars($gestion['created_at']) ?></td>
      <td><?= htmlspecialchars($gestion['tipo_gestion']) ?></td>
      <td><?= htmlspecialchars($gestion['descripcion']) ?></td>
      <td><?= htmlspecialchars((string)$gestion['fecha_compromiso']) ?> / <?= htmlspecialchars((string)$gestion['valor_compromiso']) ?></td>
      <td>
        <?php
          $estado = strtolower((string)$gestion['estado_compromiso']);
          if ($estado === 'pendiente') {
              echo ui_badge('Pendiente', 'warning');
          } elseif ($estado === 'cumplido') {
              echo ui_badge('Cumplido', 'success');
          } elseif ($estado === 'incumplido') {
              echo ui_badge('Incumplido', 'danger');
          } else {
              echo ui_badge((string)$gestion['estado_compromiso'], 'default');
          }
        ?>
      </td>
      <td><?= (int)$gestion['anulada'] === 1 ? 'Sí' : 'No' ?></td>
      <td><?= htmlspecialchars($gestion['usuario']) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Documento', $content);
