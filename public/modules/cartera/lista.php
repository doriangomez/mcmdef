<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExportService.php';

$filters = [
 'nit'=>trim($_GET['nit']??''), 'numero'=>trim($_GET['numero']??''), 'canal'=>trim($_GET['canal']??''), 'regional'=>trim($_GET['regional']??''),
 'asesor'=>trim($_GET['asesor']??''), 'ejecutivo'=>trim($_GET['ejecutivo']??''), 'uen'=>trim($_GET['uen']??''), 'periodo'=>trim($_GET['periodo']??''),
 'tipo'=>trim($_GET['tipo']??''), 'mora_rango'=>trim($_GET['mora_rango']??'')
];
$where=[]; $params=[];
if($filters['nit']!==''){ $where[]='cl.nit LIKE ?'; $params[]="%{$filters['nit']}%"; }
if($filters['numero']!==''){ $where[]='d.numero_documento LIKE ?'; $params[]="%{$filters['numero']}%"; }
if($filters['canal']!==''){ $where[]='cl.canal=?'; $params[]=$filters['canal']; }
if($filters['regional']!==''){ $where[]='cl.regional=?'; $params[]=$filters['regional']; }
if($filters['asesor']!==''){ $where[]='cl.asesor_comercial=?'; $params[]=$filters['asesor']; }
if($filters['ejecutivo']!==''){ $where[]='cl.ejecutivo_cartera=?'; $params[]=$filters['ejecutivo']; }
if($filters['uen']!==''){ $where[]='cl.uen=?'; $params[]=$filters['uen']; }
if($filters['periodo']!==''){ $where[]='d.periodo=?'; $params[]=$filters['periodo']; }
if($filters['tipo']!==''){ $where[]='d.tipo_documento=?'; $params[]=$filters['tipo']; }
if($filters['mora_rango']!==''){ if($filters['mora_rango']==='0-30')$where[]='d.dias_mora BETWEEN 0 AND 30'; if($filters['mora_rango']==='31-60')$where[]='d.dias_mora BETWEEN 31 AND 60'; if($filters['mora_rango']==='61+')$where[]='d.dias_mora >= 61'; }

$sqlBase = ' FROM documentos d JOIN clientes cl ON cl.id=d.cliente_id';
if($where){ $sqlBase .= ' WHERE ' . implode(' AND ',$where); }

if(isset($_GET['export']) && in_array(current_user()['rol'], ['admin','analista'], true)){
 $st=$pdo->prepare('SELECT cl.nit,cl.nombre,d.tipo_documento,d.numero_documento,d.saldo_actual,d.dias_mora,d.periodo,cl.canal,cl.regional'.$sqlBase.' ORDER BY d.id DESC');
 $st->execute($params); export_csv('cartera_filtrada.csv',$st->fetchAll()); exit;
}

$page=max(1,(int)($_GET['page']??1)); $size=20; $offset=($page-1)*$size;
$totalSt=$pdo->prepare('SELECT COUNT(*)'.$sqlBase); $totalSt->execute($params); $total=(int)$totalSt->fetchColumn();
$dataSt=$pdo->prepare('SELECT d.id,cl.id cliente_id,cl.nit,cl.nombre,d.tipo_documento,d.numero_documento,d.saldo_actual,d.dias_mora,d.estado_documento,d.periodo'.$sqlBase.' ORDER BY d.id DESC LIMIT '.$size.' OFFSET '.$offset);
$dataSt->execute($params); $rows=$dataSt->fetchAll();

ob_start(); ?>
<h1>Consulta de cartera</h1>
<form class="card" method="get"><div class="row">
<input name="nit" placeholder="NIT" value="<?= htmlspecialchars($filters['nit']) ?>">
<input name="numero" placeholder="Número doc" value="<?= htmlspecialchars($filters['numero']) ?>">
<input name="canal" placeholder="Canal" value="<?= htmlspecialchars($filters['canal']) ?>">
<input name="regional" placeholder="Regional" value="<?= htmlspecialchars($filters['regional']) ?>">
<input name="asesor" placeholder="Asesor" value="<?= htmlspecialchars($filters['asesor']) ?>">
<input name="ejecutivo" placeholder="Ejecutivo" value="<?= htmlspecialchars($filters['ejecutivo']) ?>">
<input name="uen" placeholder="UEN" value="<?= htmlspecialchars($filters['uen']) ?>">
<input name="periodo" placeholder="Periodo" value="<?= htmlspecialchars($filters['periodo']) ?>">
<select name="tipo"><option value="">Tipo</option><option <?= $filters['tipo']==='Factura'?'selected':'' ?>>Factura</option><option <?= $filters['tipo']==='NC'?'selected':'' ?>>NC</option></select>
<select name="mora_rango"><option value="">Mora</option><option value="0-30" <?= $filters['mora_rango']==='0-30'?'selected':'' ?>>0-30</option><option value="31-60" <?= $filters['mora_rango']==='31-60'?'selected':'' ?>>31-60</option><option value="61+" <?= $filters['mora_rango']==='61+'?'selected':'' ?>>61+</option></select>
<button class="btn">Filtrar</button>
<?php if(in_array(current_user()['rol'],['admin','analista'],true)): ?><button class="btn btn-muted" name="export" value="1">Exportar CSV</button><?php endif; ?>
</div></form>
<table class="table"><tr><th>ID</th><th>NIT</th><th>Cliente</th><th>Tipo</th><th>Número</th><th>Saldo</th><th>Días mora</th><th>Estado</th><th>Detalle</th></tr>
<?php foreach($rows as $r): ?><tr><td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['nit']) ?></td><td><?= htmlspecialchars($r['nombre']) ?></td><td><?= $r['tipo_documento'] ?></td><td><?= htmlspecialchars($r['numero_documento']) ?></td><td><?= number_format((float)$r['saldo_actual'],0,',','.') ?></td><td><?= $r['dias_mora'] ?></td><td><?= $r['estado_documento'] ?></td><td><a href="/modules/cartera/documento.php?id_documento=<?= $r['id'] ?>">Documento</a> | <a href="/modules/cartera/cliente.php?id_cliente=<?= $r['cliente_id'] ?>">Cliente</a></td></tr><?php endforeach; ?>
</table>
<p>Total: <?= $total ?> | Página <?= $page ?></p>
<?php $content=ob_get_clean(); render_layout('Cartera',$content);
