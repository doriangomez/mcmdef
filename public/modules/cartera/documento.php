<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';

$id = (int)($_GET['id_documento'] ?? 0);
$docStmt = $pdo->prepare(
    'SELECT d.*, c.nombre AS cliente_nombre, c.nit,
            d.tipo AS tipo_documento,
            d.nro_documento AS numero_documento,
            d.fecha_contabilizacion AS fecha_emision,
            d.saldo_pendiente AS saldo_actual,
            d.dias_vencido AS dias_mora,
            d.id_carga AS id_carga_origen
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE d.id = ? AND d.estado_documento = "activo"'
);
$docStmt->execute([$id]);
$document = $docStmt->fetch();

$gestionesStmt = $pdo->prepare(
    'SELECT g.id, g.tipo_gestion, g.observacion, g.compromiso_pago, g.valor_compromiso, g.created_at, u.nombre AS usuario
     FROM bitacora_gestion g
     INNER JOIN usuarios u ON u.id = g.usuario_id
     WHERE g.id_documento = ?
     ORDER BY g.id DESC'
);
$gestionesStmt->execute([$id]);
$gestiones = $gestionesStmt->fetchAll();

$canManage = in_array(current_user()['rol'], ['admin', 'analista'], true);
ob_start(); ?>
<h1>Detalle de documento</h1>
<div class="card">
  <?php if ($document): ?>
    Cliente: <?= htmlspecialchars($document['cliente_nombre']) ?> (<?= htmlspecialchars($document['nit']) ?>)<br>
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
  <tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Compromiso</th><th>Estado compromiso</th><th>Usuario</th></tr>
  <?php foreach ($gestiones as $gestion): ?>
    <tr>
      <td><?= htmlspecialchars($gestion['created_at']) ?></td>
      <td><?= htmlspecialchars($gestion['tipo_gestion']) ?></td>
      <td><?= htmlspecialchars($gestion['observacion']) ?></td>
      <td><?= htmlspecialchars((string)$gestion['compromiso_pago']) ?> / <?= htmlspecialchars((string)$gestion['valor_compromiso']) ?></td>
      <td>
        <?php
          if (!empty($gestion['compromiso_pago'])) {
              $fechaCompromiso = strtotime((string)$gestion['compromiso_pago']);
              $estadoCompromiso = ($fechaCompromiso !== false && $fechaCompromiso < strtotime(date('Y-m-d'))) ? 'Vencido' : 'Pendiente';
              $badgeType = $estadoCompromiso === 'Vencido' ? 'danger' : 'warning';
              echo ui_badge($estadoCompromiso, $badgeType);
          } else {
              echo ui_badge('Sin compromiso', 'default');
          }
        ?>
      </td>
      <td><?= htmlspecialchars($gestion['usuario']) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Documento', $content);
