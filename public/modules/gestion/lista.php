<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);

$msg = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anular') {
    $gestionId = (int)($_POST['gestion_id'] ?? 0);
    $motivo = trim($_POST['motivo_anulacion'] ?? '');
    if ($gestionId <= 0) {
        $error = 'Gestión inválida.';
    } elseif ($motivo === '') {
        $error = 'Debe indicar motivo de anulación.';
    } else {
        $update = $pdo->prepare(
            "UPDATE gestiones
             SET anulada = 1,
                 motivo_anulacion = ?,
                 estado_compromiso = CASE WHEN estado_compromiso = 'pendiente' THEN 'incumplido' ELSE estado_compromiso END
             WHERE id = ? AND anulada = 0"
        );
        $update->execute([$motivo, $gestionId]);
        if ($update->rowCount() > 0) {
            audit_log($pdo, 'gestiones', $gestionId, 'anulada', '0', '1', (int)$_SESSION['user']['id']);
            $msg = 'Gestión anulada correctamente.';
        } else {
            $error = 'No se pudo anular la gestión (puede estar anulada previamente).';
        }
    }
}

$tipo = trim($_GET['tipo'] ?? '');
$estado = trim($_GET['estado'] ?? '');
$clienteFiltro = trim($_GET['cliente'] ?? '');
$anulada = trim($_GET['anulada'] ?? '');

$where = [];
$params = [];
if ($tipo !== '') {
    $where[] = 'g.tipo_gestion = ?';
    $params[] = $tipo;
}
if ($estado !== '') {
    $where[] = 'g.estado_compromiso = ?';
    $params[] = $estado;
}
if ($clienteFiltro !== '') {
    $where[] = '(c.nombre LIKE ? OR c.nit LIKE ?)';
    $params[] = '%' . $clienteFiltro . '%';
    $params[] = '%' . $clienteFiltro . '%';
}
if ($anulada === '0' || $anulada === '1') {
    $where[] = 'g.anulada = ?';
    $params[] = (int)$anulada;
}

$sql = 'SELECT g.*, u.nombre AS usuario, c.nombre AS cliente, c.nit, d.numero_documento
        FROM gestiones g
        INNER JOIN usuarios u ON u.id = g.usuario_id
        LEFT JOIN clientes c ON c.id = g.cliente_id
        LEFT JOIN documentos d ON d.id = g.documento_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY g.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

ob_start(); ?>
<h1>Historial de gestiones</h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form class="card">
  <div class="row">
    <input name="tipo" placeholder="Tipo (novedad, compromiso...)" value="<?= htmlspecialchars($tipo) ?>">
    <select name="estado">
      <option value="">Estado compromiso</option>
      <option value="pendiente" <?= $estado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
      <option value="cumplido" <?= $estado === 'cumplido' ? 'selected' : '' ?>>Cumplido</option>
      <option value="incumplido" <?= $estado === 'incumplido' ? 'selected' : '' ?>>Incumplido</option>
    </select>
    <input name="cliente" placeholder="Cliente / NIT" value="<?= htmlspecialchars($clienteFiltro) ?>">
    <select name="anulada">
      <option value="">Anulada (todas)</option>
      <option value="0" <?= $anulada === '0' ? 'selected' : '' ?>>No</option>
      <option value="1" <?= $anulada === '1' ? 'selected' : '' ?>>Sí</option>
    </select>
    <button class="btn">Filtrar</button>
    <a class="btn btn-muted" href="/gestion/lista.php">Limpiar</a>
    <a class="btn" href="/gestion/nueva.php">Nueva gestión</a>
  </div>
</form>

<table class="table">
  <tr><th>ID</th><th>Fecha</th><th>Cliente</th><th>Documento</th><th>Tipo</th><th>Descripción</th><th>Compromiso</th><th>Estado</th><th>Anulada</th><th>Usuario</th><th>Acción</th></tr>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int)$row['id'] ?></td>
      <td><?= htmlspecialchars($row['created_at']) ?></td>
      <td><?= htmlspecialchars((string)$row['cliente']) ?> (<?= htmlspecialchars((string)$row['nit']) ?>)</td>
      <td><?= htmlspecialchars((string)$row['numero_documento']) ?></td>
      <td><?= htmlspecialchars($row['tipo_gestion']) ?></td>
      <td><?= htmlspecialchars($row['descripcion']) ?></td>
      <td><?= htmlspecialchars((string)$row['fecha_compromiso']) ?> / <?= htmlspecialchars((string)$row['valor_compromiso']) ?></td>
      <td><?= htmlspecialchars((string)$row['estado_compromiso']) ?></td>
      <td><?= (int)$row['anulada'] === 1 ? 'Sí' : 'No' ?></td>
      <td><?= htmlspecialchars($row['usuario']) ?></td>
      <td>
        <?php if ((int)$row['anulada'] === 0): ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="anular">
            <input type="hidden" name="gestion_id" value="<?= (int)$row['id'] ?>">
            <input type="text" name="motivo_anulacion" placeholder="Motivo anulación" required>
            <button class="btn btn-muted" type="submit">Anular</button>
          </form>
        <?php else: ?>
          <?= htmlspecialchars((string)$row['motivo_anulacion']) ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Gestiones', $content);
