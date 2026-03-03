<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_role(['admin']);
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(($_POST['action']??'')==='create'){
  $hash=password_hash($_POST['password'], PASSWORD_BCRYPT);
  $pdo->prepare('INSERT INTO usuarios (nombre,email,password_hash,rol,estado,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())')->execute([$_POST['nombre'],$_POST['email'],$hash,$_POST['rol'],$_POST['estado']]);
  $msg='Usuario creado';
 }
 if(($_POST['action']??'')==='reset'){
  $uid=(int)$_POST['id']; $temp='Temp1234!';
  $pdo->prepare('UPDATE usuarios SET password_hash=?,updated_at=NOW() WHERE id=?')->execute([password_hash($temp,PASSWORD_BCRYPT),$uid]);
  audit_log($pdo,'usuarios',$uid,'password_hash','***','***',$_SESSION['user']['id']);
  $msg='Contraseña temporal reasignada: '.$temp;
 }
 if(($_POST['action']??'')==='toggle'){
  $uid=(int)$_POST['id']; $new=$_POST['estado'];
  $pdo->prepare('UPDATE usuarios SET estado=?,updated_at=NOW() WHERE id=?')->execute([$new,$uid]);
  audit_log($pdo,'usuarios',$uid,'estado',null,$new,$_SESSION['user']['id']);
  $msg='Estado actualizado';
 }
}
$users=$pdo->query('SELECT id,nombre,email,rol,estado,created_at FROM usuarios ORDER BY id DESC')->fetchAll();
ob_start(); ?>
<h1>Administración de usuarios</h1>
<?php if($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<div class="card"><h3>Crear usuario</h3>
<form method="post"><input type="hidden" name="action" value="create"><div class="row"><input name="nombre" placeholder="Nombre" required><input type="email" name="email" placeholder="Email" required><input name="password" type="password" placeholder="Password" required><select name="rol"><option value="admin">Administrador</option><option value="analista">Analista</option><option value="visualizador">Visualizador</option></select><select name="estado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select><button class="btn">Crear</button></div></form>
</div>
<table class="table"><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr><?php foreach($users as $u): ?><tr><td><?= $u['id'] ?></td><td><?= htmlspecialchars($u['nombre']) ?></td><td><?= htmlspecialchars($u['email']) ?></td><td><?= $u['rol'] ?></td><td><?= $u['estado'] ?></td><td><form style="display:inline" method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="estado" value="<?= $u['estado']==='activo'?'inactivo':'activo' ?>"><button class="btn btn-muted">Activar/Inactivar</button></form> <form style="display:inline" method="post"><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn">Reset pass</button></form></td></tr><?php endforeach; ?></table>
<?php $content=ob_get_clean(); render_layout('Usuarios',$content);
