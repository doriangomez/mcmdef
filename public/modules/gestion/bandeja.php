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
    'mora' => 'd.dias_vencido DESC, d.saldo_pendiente DESC',
    'saldo' => 'd.saldo_pendiente DESC, d.dias_vencido DESC',
    'compromisos_vencidos' => 'compromiso_orden DESC, d.dias_vencido DESC',
];
$orderSql = $orderMap[$orden] ?? $orderMap['mora'];
$scope = gestion_scope_condition($responsableId, 'd');

$sql = 'SELECT
            d.id,
            d.cliente_id,
            d.cliente,
            c.nit,
            d.nro_documento,
            d.saldo_pendiente,
            d.dias_vencido,
            ult.tipo_gestion AS ultima_gestion,
            ult.created_at AS fecha_ultima_gestion,
            ult.compromiso_pago,
            ult.estado_compromiso,
            ur.nombre AS responsable,
            CASE
                WHEN ult.compromiso_pago IS NOT NULL AND COALESCE(ult.estado_compromiso, "pendiente") = "pendiente" AND ult.compromiso_pago < CURDATE() THEN 2
                WHEN ult.compromiso_pago IS NOT NULL THEN 1
                ELSE 0
            END AS compromiso_orden
        FROM cartera_documentos d
        INNER JOIN clientes c ON c.id = d.cliente_id
        LEFT JOIN (
            SELECT g1.*
            FROM bitacora_gestion g1
            INNER JOIN (
                SELECT id_documento, MAX(id) AS last_id
                FROM bitacora_gestion
                GROUP BY id_documento
            ) lu ON lu.last_id = g1.id
        ) ult ON ult.id_documento = d.id
        LEFT JOIN usuarios ur ON ur.id = ult.usuario_id
        WHERE d.estado_documento = "activo"' . $scope['sql'] . '
        ORDER BY ' . $orderSql . '
        LIMIT 400';
$stmt = $pdo->prepare($sql);
$stmt->execute($scope['params']);
$rows = $stmt->fetchAll() ?: [];

ob_start(); ?>
<h1>Bandeja de trabajo del gestor</h1>
<form class="card" method="get">
  <div class="row">
    <select name="responsable_id">
      <option value="0">Toda la operación</option>
      <?php foreach ($responsables as $responsable): ?>
        <option value="<?= (int)$responsable['id'] ?>" <?= (int)$responsable['id'] === $responsableId ? 'selected' : '' ?>><?= htmlspecialchars((string)$responsable['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="orden">
      <option value="mora" <?= $orden === 'mora' ? 'selected' : '' ?>>Priorizar por mayor mora</option>
      <option value="saldo" <?= $orden === 'saldo' ? 'selected' : '' ?>>Priorizar por mayor saldo</option>
      <option value="compromisos_vencidos" <?= $orden === 'compromisos_vencidos' ? 'selected' : '' ?>>Priorizar por compromisos vencidos</option>
    </select>
    <button class="btn">Aplicar prioridad</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/dashboard.php?responsable_id=' . $responsableId)) ?>">Dashboard</a>
  </div>
</form>

<table class="table">
  <tr><th>Cliente</th><th>Documento</th><th>Saldo</th><th>Días de mora</th><th>Última gestión</th><th>Fecha última gestión</th><th>Compromiso activo</th><th>Responsable</th><th>Acción</th></tr>
  <?php foreach ($rows as $row): ?>
    <?php [$estadoTexto, $estadoColor] = gestion_compromiso_estado((string)($row['estado_compromiso'] ?? ''), $row['compromiso_pago'] ?? null); ?>
    <?php $mora = (int)$row['dias_vencido']; ?>
    <tr class="<?= htmlspecialchars(gestion_priority_class($mora)) ?>">
      <td>
        <strong><?= htmlspecialchars((string)$row['cliente']) ?></strong><br>
        <small><?= htmlspecialchars((string)$row['nit']) ?></small>
      </td>
      <td><?= htmlspecialchars((string)$row['nro_documento']) ?></td>
      <td>$<?= number_format((float)$row['saldo_pendiente'], 2, ',', '.') ?></td>
      <td><?= ui_badge((string)$mora . ' días', gestion_mora_badge_variant($mora)) ?></td>
      <td><?= htmlspecialchars((string)($row['ultima_gestion'] ?? 'Sin gestión')) ?></td>
      <td><?= htmlspecialchars((string)($row['fecha_ultima_gestion'] ?? '-')) ?></td>
      <td><?= ui_badge($estadoTexto, $estadoColor) ?></td>
      <td><?= htmlspecialchars((string)($row['responsable'] ?? 'Sin responsable')) ?></td>
      <td>
        <a class="btn btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . (int)$row['cliente_id'] . '&documento_id=' . (int)$row['id'] . '#registro-gestion')) ?>">Gestionar</a>
      </td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Bandeja de trabajo', $content);
