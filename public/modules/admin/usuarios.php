<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin']);

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol = $_POST['rol'] ?? 'visualizador';
        $estado = $_POST['estado'] ?? 'activo';
        if ($nombre === '' || $email === '' || $password === '') {
            $error = 'Nombre, email y contraseña son obligatorios.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $insert = $pdo->prepare(
                    'INSERT INTO usuarios (nombre, email, password_hash, rol, estado, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $insert->execute([$nombre, $email, $hash, $rol, $estado]);
                $uid = (int)$pdo->lastInsertId();
                audit_log($pdo, 'usuarios', $uid, 'creacion', null, 'nuevo usuario', (int)$_SESSION['user']['id']);
                $msg = 'Usuario creado.';
            } catch (Throwable $exception) {
                $error = 'No fue posible crear usuario: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'reset') {
        $uid = (int)($_POST['id'] ?? 0);
        $temp = 'Temp1234!';
        if ($uid <= 0) {
            $error = 'Usuario inválido.';
        } else {
            $update = $pdo->prepare('UPDATE usuarios SET password_hash = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([password_hash($temp, PASSWORD_BCRYPT), $uid]);
            audit_log($pdo, 'usuarios', $uid, 'password_hash', '***', '***', (int)$_SESSION['user']['id']);
            $msg = 'Contraseña temporal reasignada: ' . $temp;
        }
    } elseif ($action === 'toggle') {
        $uid = (int)($_POST['id'] ?? 0);
        $new = $_POST['estado'] ?? 'inactivo';
        if ($uid <= 0) {
            $error = 'Usuario inválido.';
        } elseif ($uid === (int)$_SESSION['user']['id'] && $new === 'inactivo') {
            $error = 'No puedes desactivar tu propio usuario.';
        } else {
            $update = $pdo->prepare('UPDATE usuarios SET estado = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([$new, $uid]);
            audit_log($pdo, 'usuarios', $uid, 'estado', null, $new, (int)$_SESSION['user']['id']);
            $msg = 'Estado actualizado.';
        }
    } elseif ($action === 'assign_clients') {
        $uid = (int)($_POST['usuario_id'] ?? 0);
        $rawClientIds = $_POST['cliente_ids'] ?? [];
        $clientIds = array_values(array_unique(array_filter(array_map('intval', is_array($rawClientIds) ? $rawClientIds : []), static fn($v) => $v > 0)));
        if ($uid <= 0) {
            $error = 'Debe seleccionar un usuario.';
        } elseif (empty($clientIds)) {
            $error = 'Debe seleccionar al menos un cliente.';
        } else {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $params = array_merge([$uid], $clientIds);
            $assign = $pdo->prepare('UPDATE clientes SET responsable_usuario_id = ? WHERE id IN (' . $placeholders . ')');
            $assign->execute($params);
            audit_log($pdo, 'clientes', $uid, 'asignacion_masiva', null, 'clientes:' . implode(',', $clientIds), (int)$_SESSION['user']['id']);
            $msg = 'Clientes asignados correctamente al responsable seleccionado.';
        }
    }
}

$users = $pdo->query('SELECT id, nombre, email, rol, estado, created_at FROM usuarios ORDER BY nombre ASC')->fetchAll();
$assignmentUsers = $pdo->query("SELECT id, nombre FROM usuarios WHERE estado = 'activo' AND rol IN ('admin', 'analista') ORDER BY nombre ASC")->fetchAll();
$clientOptions = $pdo->query('SELECT id, nombre, nit FROM clientes ORDER BY nombre ASC LIMIT 3000')->fetchAll();

$assignmentStatsStmt = $pdo->query(
    'SELECT u.id,
            COUNT(DISTINCT c.id) AS clientes_asignados,
            COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total_asignado,
            COUNT(DISTINCT g.id) AS gestiones_realizadas,
            SUM(CASE WHEN g.compromiso_pago IS NOT NULL AND g.compromiso_pago >= CURDATE() THEN 1 ELSE 0 END) AS compromisos_cumplidos,
            SUM(CASE WHEN g.compromiso_pago IS NOT NULL AND g.compromiso_pago < CURDATE() AND d.saldo_pendiente > 0 THEN 1 ELSE 0 END) AS compromisos_incumplidos
     FROM usuarios u
     LEFT JOIN clientes c ON c.responsable_usuario_id = u.id
     LEFT JOIN cartera_documentos d ON d.cliente_id = c.id AND d.estado_documento = "activo"
     LEFT JOIN bitacora_gestion g ON g.id_documento = d.id AND g.usuario_id = u.id
     GROUP BY u.id'
);
$assignmentStats = [];
foreach ($assignmentStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $stat) {
    $assignmentStats[(int)$stat['id']] = $stat;
}

ob_start(); ?>
<h1>Administración de usuarios</h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card"><h3>Crear usuario</h3>
<form method="post"><input type="hidden" name="action" value="create"><div class="row"><input name="nombre" placeholder="Nombre" required><input type="email" name="email" placeholder="Email" required><input name="password" type="password" placeholder="Password" required><select name="rol"><option value="admin">Administrador</option><option value="analista">Analista</option><option value="visualizador">Visualizador</option></select><select name="estado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select><button class="btn">Crear</button></div></form>
</div>

<div class="card">
  <h3>Clientes asignados (asignación masiva)</h3>
  <form method="post">
    <input type="hidden" name="action" value="assign_clients">
    <div class="row">
      <select name="usuario_id" required>
        <option value="">Responsable</option>
        <?php foreach ($assignmentUsers as $user): ?>
          <option value="<?= (int)$user['id'] ?>"><?= htmlspecialchars((string)$user['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" id="clientFilter" placeholder="Buscar cliente por nombre o NIT">
    </div>
    <div class="row" style="margin-top:8px;">
      <select name="cliente_ids[]" id="clientAssignSelect" multiple size="12" style="min-height:220px" required>
        <?php foreach ($clientOptions as $client): ?>
          <option value="<?= (int)$client['id'] ?>" data-search="<?= htmlspecialchars(mb_strtolower((string)$client['nombre'] . ' ' . (string)$client['nit'])) ?>">
            <?= htmlspecialchars((string)$client['nombre']) ?> · <?= htmlspecialchars((string)$client['nit']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="muted">Use Ctrl/Cmd + click para selección múltiple y realice reasignación masiva en un solo paso.</p>
    <button class="btn">Asignar clientes seleccionados</button>
  </form>
</div>

<table class="table"><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>Clientes asignados</th><th>Saldo asignado</th><th>Gestiones</th><th>Compromisos C/IC</th><th>Creado</th><th>Acciones</th></tr><?php foreach($users as $u): $stats = $assignmentStats[(int)$u['id']] ?? ['clientes_asignados' => 0, 'saldo_total_asignado' => 0, 'gestiones_realizadas' => 0, 'compromisos_cumplidos' => 0, 'compromisos_incumplidos' => 0]; ?><tr><td><?= (int)$u['id'] ?></td><td><?= htmlspecialchars($u['nombre']) ?></td><td><?= htmlspecialchars($u['email']) ?></td><td><?php if ($u['rol']==='admin') { echo ui_badge('Administrador', 'info'); } elseif ($u['rol']==='analista') { echo ui_badge('Analista', 'default'); } else { echo ui_badge('Visualizador', 'warning'); } ?></td><td><?= $u['estado']==='activo' ? ui_badge('Activo', 'success') : ui_badge('Inactivo', 'danger') ?></td><td><?= (int)$stats['clientes_asignados'] ?></td><td>$<?= number_format((float)$stats['saldo_total_asignado'], 2, ',', '.') ?></td><td><?= (int)$stats['gestiones_realizadas'] ?></td><td><?= (int)$stats['compromisos_cumplidos'] ?> / <?= (int)$stats['compromisos_incumplidos'] ?></td><td><?= htmlspecialchars($u['created_at']) ?></td><td><form style="display:inline" method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="estado" value="<?= $u['estado']==='activo'?'inactivo':'activo' ?>"><button class="btn btn-muted">Activar/Inactivar</button></form> <form style="display:inline" method="post"><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn">Reset pass</button></form></td></tr><?php endforeach; ?></table>

<script>
(function () {
  var filter = document.getElementById('clientFilter');
  var select = document.getElementById('clientAssignSelect');
  if (!filter || !select) return;
  filter.addEventListener('input', function () {
    var term = (filter.value || '').toLowerCase().trim();
    Array.prototype.forEach.call(select.options, function (option) {
      var haystack = option.getAttribute('data-search') || option.text.toLowerCase();
      option.style.display = term === '' || haystack.indexOf(term) !== -1 ? '' : 'none';
    });
  });
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Usuarios', $content);
