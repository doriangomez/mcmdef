<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/helpers.php';

require_role(['admin', 'analista']);

$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$responsableId = (int)($_GET['responsable_id'] ?? $currentUserId);
$orden = $_GET['orden'] ?? 'mora';
$responsables = gestion_get_responsables($pdo);

$orderMap = [
    'saldo' => 'd.saldo_pendiente DESC',
    'mora' => 'd.dias_vencido DESC',
    'compromisos_vencidos' => 'estado_compromiso_orden DESC, d.dias_vencido DESC',
    'criticos' => 'cliente_critico DESC, d.saldo_pendiente DESC',
];
$orderSql = $orderMap[$orden] ?? $orderMap['mora'];

$scope = gestion_scope_condition($responsableId, 'd');

$sql = 'SELECT
            d.id,
            d.cliente,
            c.nit,
            d.nro_documento,
            d.saldo_pendiente,
            d.dias_vencido,
            ult.tipo_gestion AS ultima_gestion,
            ult.created_at AS fecha_ultima_gestion,
            ult.compromiso_pago,
            CASE
                WHEN ult.compromiso_pago IS NULL THEN 0
                WHEN ult.compromiso_pago < CURDATE() THEN 3
                WHEN ult.compromiso_pago <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 2
                ELSE 1
            END AS estado_compromiso_orden,
            CASE
                WHEN d.saldo_pendiente >= 10000000 OR d.dias_vencido >= 120 THEN 1
                ELSE 0
            END AS cliente_critico
        FROM cartera_documentos d
        INNER JOIN clientes c ON c.id = d.cliente_id
        LEFT JOIN (
            SELECT g1.*
            FROM bitacora_gestion g1
            INNER JOIN (
                SELECT id_documento, MAX(id) AS last_id
                FROM bitacora_gestion
                GROUP BY id_documento
            ) last ON last.last_id = g1.id
        ) ult ON ult.id_documento = d.id
        WHERE d.estado_documento = "activo"' . $scope['sql'] . '
        ORDER BY ' . $orderSql . '
        LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($scope['params']);
$rows = $stmt->fetchAll() ?: [];

ob_start(); ?>
<h1>Bandeja operativa del gestor</h1>
<form class="card" method="get">
  <div class="row">
    <select name="responsable_id">
      <option value="0">Toda la operación</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="orden">
      <option value="saldo" <?= $orden === 'saldo' ? 'selected' : '' ?>>Mayor saldo</option>
      <option value="mora" <?= $orden === 'mora' ? 'selected' : '' ?>>Mayor mora</option>
      <option value="compromisos_vencidos" <?= $orden === 'compromisos_vencidos' ? 'selected' : '' ?>>Compromisos vencidos</option>
      <option value="criticos" <?= $orden === 'criticos' ? 'selected' : '' ?>>Clientes críticos</option>
    </select>
    <button class="btn">Priorizar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/dashboard.php?responsable_id=' . $responsableId)) ?>">Dashboard</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/lista.php?responsable_id=' . $responsableId)) ?>">Historial</a>
  </div>
</form>

<table class="table">
  <tr><th>Cliente</th><th>Documento</th><th>Saldo</th><th>Días mora</th><th>Última gestión</th><th>Fecha última gestión</th><th>Estado compromiso</th><th>Acción</th></tr>
  <?php foreach ($rows as $row): ?>
    <?php [$estadoTexto, $estadoColor] = gestion_commitment_status($row['compromiso_pago'] ?? null, (float)$row['saldo_pendiente']); ?>
    <tr class="<?= htmlspecialchars(gestion_priority_class((int)$row['dias_vencido'])) ?>">
      <td>
        <?= htmlspecialchars((string)$row['cliente']) ?><br>
        <small><?= htmlspecialchars((string)$row['nit']) ?></small>
      </td>
      <td><?= htmlspecialchars((string)$row['nro_documento']) ?></td>
      <td>$<?= number_format((float)$row['saldo_pendiente'], 2, ',', '.') ?></td>
      <td><?= (int)$row['dias_vencido'] ?></td>
      <td><?= htmlspecialchars((string)($row['ultima_gestion'] ?? 'Sin gestión')) ?></td>
      <td><?= htmlspecialchars((string)($row['fecha_ultima_gestion'] ?? '-')) ?></td>
      <td><?= ui_badge($estadoTexto, $estadoColor) ?></td>
      <td><a class="btn btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?documento_id=' . (int)$row['id'])) ?>">Gestionar</a></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Bandeja de trabajo', $content);
