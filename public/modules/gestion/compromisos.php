<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/helpers.php';

require_role(['admin', 'analista']);

$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$responsableId = (int)($_GET['responsable_id'] ?? $currentUserId);
$responsables = gestion_get_responsables($pdo);

$stmt = $pdo->prepare(
    'SELECT
        g.id,
        g.id_documento,
        g.compromiso_pago,
        g.valor_compromiso,
        g.created_at,
        u.nombre AS responsable,
        d.cliente,
        d.nro_documento,
        d.saldo_pendiente
     FROM bitacora_gestion g
     INNER JOIN usuarios u ON u.id = g.usuario_id
     INNER JOIN cartera_documentos d ON d.id = g.id_documento
     WHERE g.compromiso_pago IS NOT NULL
       AND (? <= 0 OR g.usuario_id = ?)
     ORDER BY g.compromiso_pago ASC, g.id DESC
     LIMIT 300'
);
$stmt->execute([$responsableId, $responsableId]);
$rows = $stmt->fetchAll() ?: [];

ob_start(); ?>
<h1>Panel de compromisos de pago</h1>
<form class="card" method="get">
  <div class="row">
    <select name="responsable_id">
      <option value="0">Todos los responsables</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Filtrar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/bandeja.php?responsable_id=' . $responsableId)) ?>">Bandeja</a>
  </div>
</form>

<table class="table">
  <tr><th>Cliente</th><th>Documento</th><th>Valor comprometido</th><th>Fecha compromiso</th><th>Responsable</th><th>Estado</th><th></th></tr>
  <?php foreach ($rows as $row): ?>
    <?php [$estadoTexto, $estadoColor] = gestion_commitment_status($row['compromiso_pago'] ?? null, (float)$row['saldo_pendiente']); ?>
    <tr>
      <td><?= htmlspecialchars((string)$row['cliente']) ?></td>
      <td><?= htmlspecialchars((string)$row['nro_documento']) ?></td>
      <td>$<?= number_format((float)($row['valor_compromiso'] ?? 0), 2, ',', '.') ?></td>
      <td><?= htmlspecialchars((string)$row['compromiso_pago']) ?></td>
      <td><?= htmlspecialchars((string)$row['responsable']) ?></td>
      <td><?= ui_badge($estadoTexto, $estadoColor) ?></td>
      <td><a class="btn btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?documento_id=' . (int)$row['id_documento'])) ?>">Gestionar</a></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Compromisos', $content);
