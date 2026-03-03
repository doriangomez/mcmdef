<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
$id=(int)($_GET['id_documento']??0);
$st=$pdo->prepare('SELECT d.*,c.nombre cliente,c.nit FROM documentos d JOIN clientes c ON c.id=d.cliente_id WHERE d.id=?'); $st->execute([$id]); $d=$st->fetch();
$ges=$pdo->prepare('SELECT g.*,u.nombre usuario FROM gestiones g JOIN usuarios u ON u.id=g.usuario_id WHERE g.documento_id=? ORDER BY g.id DESC'); $ges->execute([$id]); $grows=$ges->fetchAll();
ob_start(); ?>
<h1>Detalle de documento</h1>
<div class="card"><?php if($d): ?>Cliente: <?= htmlspecialchars($d['cliente']) ?> (<?= htmlspecialchars($d['nit']) ?>) <br> Documento: <?= $d['tipo_documento'] ?> #<?= htmlspecialchars($d['numero_documento']) ?> <br>Saldo <?= $d['saldo_actual'] ?> | Mora <?= $d['dias_mora'] ?> | Carga origen #<?= $d['carga_id'] ?><?php else: ?>Documento no encontrado<?php endif; ?></div>
<a class="btn" href="/modules/gestion/nueva.php?documento_id=<?= $id ?>&cliente_id=<?= (int)($d['cliente_id']??0) ?>">Registrar gestión</a>
<table class="table"><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Estado compromiso</th><th>Usuario</th></tr><?php foreach($grows as $g): ?><tr><td><?= $g['created_at'] ?></td><td><?= htmlspecialchars($g['tipo_gestion']) ?></td><td><?= htmlspecialchars($g['descripcion']) ?></td><td><?= htmlspecialchars((string)$g['estado_compromiso']) ?></td><td><?= htmlspecialchars($g['usuario']) ?></td></tr><?php endforeach; ?></table>
<?php $content=ob_get_clean(); render_layout('Documento',$content);
