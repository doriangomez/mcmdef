<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_role(['admin','analista']);
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $stmt=$pdo->prepare('INSERT INTO gestiones (cliente_id,documento_id,tipo_gestion,descripcion,fecha_compromiso,valor_compromiso,estado_compromiso,usuario_id,created_at)
 VALUES (?,?,?,?,?,?,?,?,NOW())');
 $stmt->execute([
   ($_POST['cliente_id']!=='')?(int)$_POST['cliente_id']:null,
   ($_POST['documento_id']!=='')?(int)$_POST['documento_id']:null,
   $_POST['tipo_gestion'],$_POST['descripcion'],
   $_POST['fecha_compromiso']?:null,
   $_POST['valor_compromiso']!==''?$_POST['valor_compromiso']:null,
   $_POST['estado_compromiso']?:null,
   $_SESSION['user']['id']
 ]);
 $msg='Gestión registrada';
}
ob_start(); ?>
<h1>Registrar gestión</h1>
<?php if($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
<form class="card" method="post">
<div class="row">
<input type="number" name="cliente_id" placeholder="Cliente ID" value="<?= htmlspecialchars($_GET['cliente_id'] ?? '') ?>">
<input type="number" name="documento_id" placeholder="Documento ID" value="<?= htmlspecialchars($_GET['documento_id'] ?? '') ?>">
<input name="tipo_gestion" placeholder="Tipo (novedad/compromiso/seguimiento)" required>
</div>
<div class="row"><textarea name="descripcion" placeholder="Descripción" required style="width:100%"></textarea></div>
<div class="row">
<input type="date" name="fecha_compromiso">
<input type="number" step="0.01" name="valor_compromiso" placeholder="Valor compromiso">
<select name="estado_compromiso"><option value="">Sin estado</option><option>Pendiente</option><option>Cumplido</option><option>Incumplido</option></select>
</div>
<button class="btn">Guardar</button>
</form>
<?php $content=ob_get_clean(); render_layout('Nueva gestión',$content);
