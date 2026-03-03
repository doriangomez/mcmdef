<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
$tipo=trim($_GET['tipo']??''); $estado=trim($_GET['estado']??'');
$where=[]; $params=[];
if($tipo!==''){ $where[]='g.tipo_gestion=?'; $params[]=$tipo; }
if($estado!==''){ $where[]='g.estado_compromiso=?'; $params[]=$estado; }
$sql='SELECT g.*,u.nombre usuario,c.nombre cliente,d.numero_documento FROM gestiones g JOIN usuarios u ON u.id=g.usuario_id LEFT JOIN clientes c ON c.id=g.cliente_id LEFT JOIN documentos d ON d.id=g.documento_id';
if($where){ $sql.=' WHERE '.implode(' AND ',$where); }
$sql.=' ORDER BY g.id DESC';
$st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll();
ob_start(); ?>
<h1>Historial de gestiones</h1>
<form class="card"><div class="row"><input name="tipo" placeholder="Tipo" value="<?= htmlspecialchars($tipo) ?>"><select name="estado"><option value="">Estado compromiso</option><option <?= $estado==='Pendiente'?'selected':'' ?>>Pendiente</option><option <?= $estado==='Cumplido'?'selected':'' ?>>Cumplido</option><option <?= $estado==='Incumplido'?'selected':'' ?>>Incumplido</option></select><button class="btn">Filtrar</button><a class="btn" href="/modules/gestion/nueva.php">Nueva gestión</a></div></form>
<table class="table"><tr><th>ID</th><th>Fecha</th><th>Cliente</th><th>Documento</th><th>Tipo</th><th>Descripción</th><th>Compromiso</th><th>Estado</th><th>Usuario</th></tr><?php foreach($rows as $r): ?><tr><td><?= $r['id'] ?></td><td><?= $r['created_at'] ?></td><td><?= htmlspecialchars((string)$r['cliente']) ?></td><td><?= htmlspecialchars((string)$r['numero_documento']) ?></td><td><?= htmlspecialchars($r['tipo_gestion']) ?></td><td><?= htmlspecialchars($r['descripcion']) ?></td><td><?= htmlspecialchars((string)$r['fecha_compromiso']) ?> / <?= htmlspecialchars((string)$r['valor_compromiso']) ?></td><td><?= htmlspecialchars((string)$r['estado_compromiso']) ?></td><td><?= htmlspecialchars($r['usuario']) ?></td></tr><?php endforeach; ?></table>
<?php $content=ob_get_clean(); render_layout('Gestiones',$content);
