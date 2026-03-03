<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';

$id = (int)($_GET['id_cliente'] ?? 0);

$customerStmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$customerStmt->execute([$id]);
$customer = $customerStmt->fetch();

$docsStmt = $pdo->prepare(
    'SELECT *
     FROM documentos
     WHERE cliente_id = ?
     ORDER BY dias_mora DESC, id DESC'
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
ob_start(); ?>
<h1>Expediente de cliente</h1>
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
      <td><a href="/cartera/documento.php?id_documento=<?= (int)$document['id'] ?>">Ver</a></td>
    </tr>
  <?php endforeach; ?>
</table>

<h3>Gestiones</h3>
<?php if ($canManage): ?>
  <a class="btn" href="/gestion/nueva.php?cliente_id=<?= $id ?>">Nueva gestión</a>
<?php endif; ?>
<table class="table">
  <tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Compromiso</th><th>Estado</th><th>Anulada</th><th>Usuario</th></tr>
  <?php foreach ($gestiones as $gestion): ?>
    <tr>
      <td><?= htmlspecialchars($gestion['created_at']) ?></td>
      <td><?= htmlspecialchars($gestion['tipo_gestion']) ?></td>
      <td><?= htmlspecialchars($gestion['descripcion']) ?></td>
      <td><?= htmlspecialchars((string)$gestion['fecha_compromiso']) ?> / <?= htmlspecialchars((string)$gestion['valor_compromiso']) ?></td>
      <td><?= htmlspecialchars((string)$gestion['estado_compromiso']) ?></td>
      <td><?= (int)$gestion['anulada'] === 1 ? 'Sí' : 'No' ?></td>
      <td><?= htmlspecialchars($gestion['usuario']) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Cliente', $content);
