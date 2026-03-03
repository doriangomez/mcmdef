<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
$id=(int)($_GET['id_cliente']??0);
$st=$pdo->prepare('SELECT * FROM clientes WHERE id=?'); $st->execute([$id]); $c=$st->fetch();
$docs=$pdo->prepare('SELECT * FROM documentos WHERE cliente_id=? ORDER BY id DESC'); $docs->execute([$id]); $rows=$docs->fetchAll();
$ges=$pdo->prepare('SELECT g.*,u.nombre usuario FROM gestiones g JOIN usuarios u ON u.id=g.usuario_id WHERE g.cliente_id=? ORDER BY g.id DESC'); $ges->execute([$id]); $gestiones=$ges->fetchAll();
ob_start(); ?>
<h1>Expediente de cliente</h1>
<div class="card"><?php if($c): ?><strong><?= htmlspecialchars($c['nombre']) ?></strong> - NIT <?= htmlspecialchars($c['nit']) ?> | Canal <?= htmlspecialchars($c['canal']) ?> | Regional <?= htmlspecialchars($c['regional']) ?><?php else: ?>Cliente no encontrado<?php endif; ?></div>
<h3>Documentos</h3><table class="table"><tr><th>ID</th><th>Tipo</th><th>Número</th><th>Saldo</th><th>Mora</th><th></th></tr><?php foreach($rows as $r): ?><tr><td><?= $r['id'] ?></td><td><?= $r['tipo_documento'] ?></td><td><?= htmlspecialchars($r['numero_documento']) ?></td><td><?= $r['saldo_actual'] ?></td><td><?= $r['dias_mora'] ?></td><td><a href="/modules/cartera/documento.php?id_documento=<?= $r['id'] ?>">Ver</a></td></tr><?php endforeach; ?></table>
<h3>Gestiones</h3><a class="btn" href="/modules/gestion/nueva.php?cliente_id=<?= $id ?>">Nueva gestión</a><table class="table"><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Compromiso</th><th>Estado</th><th>Usuario</th></tr><?php foreach($gestiones as $g): ?><tr><td><?= $g['created_at'] ?></td><td><?= htmlspecialchars($g['tipo_gestion']) ?></td><td><?= htmlspecialchars($g['descripcion']) ?></td><td><?= htmlspecialchars((string)$g['fecha_compromiso']) ?> / <?= htmlspecialchars((string)$g['valor_compromiso']) ?></td><td><?= htmlspecialchars((string)$g['estado_compromiso']) ?></td><td><?= htmlspecialchars($g['usuario']) ?></td></tr><?php endforeach; ?></table>
<?php $content=ob_get_clean(); render_layout('Cliente',$content);
