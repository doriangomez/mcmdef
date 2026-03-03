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
    }
}
$users = $pdo->query('SELECT id, nombre, email, rol, estado, created_at FROM usuarios ORDER BY id DESC')->fetchAll();
ob_start(); ?>
<h1>Administración de usuarios</h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card"><h3>Crear usuario</h3>
<form method="post"><input type="hidden" name="action" value="create"><div class="row"><input name="nombre" placeholder="Nombre" required><input type="email" name="email" placeholder="Email" required><input name="password" type="password" placeholder="Password" required><select name="rol"><option value="admin">Administrador</option><option value="analista">Analista</option><option value="visualizador">Visualizador</option></select><select name="estado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select><button class="btn">Crear</button></div></form>
</div>
<table class="table"><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>Creado</th><th>Acciones</th></tr><?php foreach($users as $u): ?><tr><td><?= (int)$u['id'] ?></td><td><?= htmlspecialchars($u['nombre']) ?></td><td><?= htmlspecialchars($u['email']) ?></td><td><?= htmlspecialchars($u['rol']) ?></td><td><?= htmlspecialchars($u['estado']) ?></td><td><?= htmlspecialchars($u['created_at']) ?></td><td><form style="display:inline" method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="estado" value="<?= $u['estado']==='activo'?'inactivo':'activo' ?>"><button class="btn btn-muted">Activar/Inactivar</button></form> <form style="display:inline" method="post"><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn">Reset pass</button></form></td></tr><?php endforeach; ?></table>
<?php
$content = ob_get_clean();
render_layout('Usuarios', $content);
