<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
$id=(int)($_GET['id']??0);
$c = $pdo->prepare('SELECT c.*, u.nombre usuario FROM cargas_cartera c LEFT JOIN usuarios u ON u.id=c.usuario_id WHERE c.id=?');
$c->execute([$id]); $carga=$c->fetch();
$docs=$pdo->prepare('SELECT d.id,cl.nit,cl.nombre,d.tipo_documento,d.numero_documento,d.saldo_actual,d.dias_mora FROM documentos d JOIN clientes cl ON cl.id=d.cliente_id WHERE d.carga_id=? LIMIT 50');
$docs->execute([$id]); $rows=$docs->fetchAll();
ob_start(); ?>
<h1>Detalle carga #<?= $id ?></h1>
<div class="card"><?php if($carga): ?>Archivo: <?= htmlspecialchars($carga['nombre_archivo']) ?> | Estado: <?= $carga['estado'] ?> | Errores: <?= $carga['total_errores'] ?> | Usuario: <?= htmlspecialchars($carga['usuario']??'-') ?><?php else: ?>No existe carga.<?php endif; ?></div>
<table class="table"><tr><th>ID Doc</th><th>NIT</th><th>Cliente</th><th>Tipo</th><th>Número</th><th>Saldo</th><th>Días mora</th></tr><?php foreach($rows as $r): ?><tr><td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['nit']) ?></td><td><?= htmlspecialchars($r['nombre']) ?></td><td><?= htmlspecialchars($r['tipo_documento']) ?></td><td><?= htmlspecialchars($r['numero_documento']) ?></td><td><?= $r['saldo_actual'] ?></td><td><?= $r['dias_mora'] ?></td></tr><?php endforeach; ?></table>
<?php $content=ob_get_clean(); render_layout('Detalle carga',$content);
