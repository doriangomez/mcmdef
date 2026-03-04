<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';

$id = (int)($_GET['id_cliente'] ?? ($_GET['id'] ?? 0));

$customerStmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$customerStmt->execute([$id]);
$customer = $customerStmt->fetch();

$docsStmt = $pdo->prepare(
    'SELECT d.*,
            d.tipo AS tipo_documento,
            d.nro_documento AS numero_documento,
            d.saldo_pendiente AS saldo_actual,
            d.dias_vencido AS dias_mora,
            d.id_carga AS id_carga_origen,
            DATE_FORMAT(d.fecha_contabilizacion, "%Y-%m") AS periodo
     FROM cartera_documentos d
     WHERE d.cliente_id = ? AND d.estado_documento = "activo"
     ORDER BY d.dias_vencido DESC, d.id DESC'
);
$docsStmt->execute([$id]);
$documents = $docsStmt->fetchAll();

$gestionesStmt = $pdo->prepare(
    'SELECT g.*, u.nombre AS usuario
     FROM gestiones g
     INNER JOIN usuarios u ON u.id = g.usuario_id
     WHERE g.cliente_id = ?
     ORDER BY g.id DESC'
);
$gestionesStmt->execute([$id]);
$gestiones = $gestionesStmt->fetchAll();

$canManage = in_array(current_user()['rol'], ['admin', 'analista'], true);

$view = (string)($_GET['view'] ?? 'expediente');
$showBehavior = $view === 'mora';

$moraSeriesStmt = $pdo->prepare(
    'SELECT DATE_FORMAT(d.fecha_contabilizacion, "%Y-%m") AS periodo,
            AVG(d.dias_vencido) AS promedio_mora,
            SUM(d.saldo_pendiente) AS saldo_total,
            SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END) AS saldo_vencido
     FROM cartera_documentos d
     WHERE d.cliente_id = ? AND d.estado_documento = "activo"
     GROUP BY DATE_FORMAT(d.fecha_contabilizacion, "%Y-%m")
     ORDER BY periodo ASC'
);
$moraSeriesStmt->execute([$id]);
$moraSeries = $moraSeriesStmt->fetchAll() ?: [];

ob_start(); ?>
<h1><?= $showBehavior ? 'Comportamiento de mora' : 'Expediente de cliente' ?></h1>
<div class="card">
  <?php if ($customer): ?>
    <strong><?= htmlspecialchars($customer['nombre']) ?></strong><br>
    NIT: <?= htmlspecialchars($customer['nit']) ?> |
    Canal: <?= htmlspecialchars((string)$customer['canal']) ?> |
    Regional: <?= htmlspecialchars((string)$customer['regional']) ?> |
    UEN: <?= htmlspecialchars((string)$customer['uen']) ?> |
    Marca: <?= htmlspecialchars((string)$customer['marca']) ?>
  <?php else: ?>
    Cliente no encontrado.
  <?php endif; ?>
</div>


<?php if ($showBehavior && $customer): ?>
  <div class="card" style="margin-top:12px;">
    <h3 style="margin-top:0;">Evolución de mora</h3>
    <?php if ($moraSeries): ?>
      <table class="table">
        <tr><th>Periodo</th><th>Promedio mora (días)</th><th>Saldo total</th><th>Saldo vencido</th></tr>
        <?php foreach ($moraSeries as $point): ?>
          <tr>
            <td><?= htmlspecialchars((string)$point['periodo']) ?></td>
            <td><?= number_format((float)$point['promedio_mora'], 1, ',', '.') ?></td>
            <td>$ <?= number_format((float)$point['saldo_total'], 2, ',', '.') ?></td>
            <td>$ <?= number_format((float)$point['saldo_vencido'], 2, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="card" style="margin-top:10px;">
        <strong>Gráfico de comportamiento (ASCII)</strong>
        <pre style="margin:8px 0 0;white-space:pre-wrap;"><?php
          $max = 1.0;
          foreach ($moraSeries as $point) {
              $max = max($max, (float)$point['promedio_mora']);
          }
          foreach ($moraSeries as $point) {
              $days = (float)$point['promedio_mora'];
              $bars = str_repeat('▮', (int)round(($days / $max) * 24));
              echo str_pad((string)$point['periodo'], 8) . ' | ' . str_pad((string)number_format($days, 1, ',', '.'), 6, ' ', STR_PAD_LEFT) . ' días | ' . $bars . PHP_EOL;
          }
        ?></pre>
      </div>
    <?php else: ?>
      <p>No hay información histórica de mora para este cliente.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<h3>Documentos</h3>
<table class="table">
  <tr><th>ID</th><th>Tipo</th><th>Número</th><th>Saldo</th><th>Mora</th><th>Periodo</th><th>Estado</th><th>Carga origen</th><th></th></tr>
  <?php foreach ($documents as $document): ?>
    <tr>
      <td><?= (int)$document['id'] ?></td>
      <td><?= htmlspecialchars($document['tipo_documento']) ?></td>
      <td><?= htmlspecialchars($document['numero_documento']) ?></td>
      <td><?= number_format((float)$document['saldo_actual'], 2, ',', '.') ?></td>
      <td><?= (int)$document['dias_mora'] ?></td>
      <td><?= htmlspecialchars((string)$document['periodo']) ?></td>
      <td><?= htmlspecialchars($document['estado_documento']) ?></td>
      <td>#<?= (int)$document['id_carga_origen'] ?></td>
      <td><a href="<?= htmlspecialchars(app_url('cartera/documento.php?id_documento=' . (int)$document['id'])) ?>">Ver</a></td>
    </tr>
  <?php endforeach; ?>
</table>

<h3>Gestiones</h3>
<?php if ($canManage): ?>
  <a class="btn" href="<?= htmlspecialchars(app_url('gestion/nueva.php?cliente_id=' . $id)) ?>">Nueva gestión</a>
<?php endif; ?>
<table class="table">
  <tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Compromiso</th><th>Estado</th><th>Anulada</th><th>Usuario</th></tr>
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
render_layout('Cliente', $content);
