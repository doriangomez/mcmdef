<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExportService.php';

$tipo = $_GET['tipo'] ?? 'vigente_vencida';
$periodo = trim($_GET['periodo'] ?? '');
$where=''; $params=[];
if($periodo!==''){ $where=' WHERE d.periodo=? '; $params[]=$periodo; }

$queries=[
'vigente_vencida'=>"SELECT d.estado_documento categoria, SUM(d.saldo_actual) total FROM documentos d {$where} GROUP BY d.estado_documento",
'mora_rangos'=>"SELECT CASE WHEN d.dias_mora BETWEEN 0 AND 30 THEN '0-30' WHEN d.dias_mora BETWEEN 31 AND 60 THEN '31-60' ELSE '61+' END categoria, SUM(d.saldo_actual) total FROM documentos d {$where} GROUP BY categoria",
'canal'=>"SELECT c.canal categoria, SUM(d.saldo_actual) total FROM documentos d JOIN clientes c ON c.id=d.cliente_id {$where} GROUP BY c.canal",
'uen'=>"SELECT c.uen categoria, SUM(d.saldo_actual) total FROM documentos d JOIN clientes c ON c.id=d.cliente_id {$where} GROUP BY c.uen",
'regional'=>"SELECT c.regional categoria, SUM(d.saldo_actual) total FROM documentos d JOIN clientes c ON c.id=d.cliente_id {$where} GROUP BY c.regional",
'asesor'=>"SELECT c.asesor_comercial categoria, SUM(d.saldo_actual) total FROM documentos d JOIN clientes c ON c.id=d.cliente_id {$where} GROUP BY c.asesor_comercial",
'compromisos'=>"SELECT COALESCE(g.estado_compromiso,'sin_estado') categoria, COUNT(*) total FROM gestiones g GROUP BY g.estado_compromiso",
'comparativo_periodo'=>"SELECT d.periodo categoria, SUM(d.saldo_actual) total FROM documentos d GROUP BY d.periodo ORDER BY d.periodo DESC"
];
$q=$queries[$tipo]??$queries['vigente_vencida'];
$st=$pdo->prepare($q); $st->execute($params); $rows=$st->fetchAll();

if(isset($_GET['export']) && in_array(current_user()['rol'],['admin','analista'],true)){ export_csv('reporte_'.$tipo.'.csv',$rows); exit; }

ob_start(); ?>
<h1>Reportes operativos</h1>
<form class="card"><div class="row">
<select name="tipo">
<option value="vigente_vencida" <?= $tipo==='vigente_vencida'?'selected':'' ?>>Cartera vigente y vencida</option>
<option value="mora_rangos" <?= $tipo==='mora_rangos'?'selected':'' ?>>Mora por rangos</option>
<option value="canal" <?= $tipo==='canal'?'selected':'' ?>>Cartera por canal</option>
<option value="uen" <?= $tipo==='uen'?'selected':'' ?>>Cartera por UEN</option>
<option value="regional" <?= $tipo==='regional'?'selected':'' ?>>Cartera por regional</option>
<option value="asesor" <?= $tipo==='asesor'?'selected':'' ?>>Cartera por asesor</option>
<option value="compromisos" <?= $tipo==='compromisos'?'selected':'' ?>>Compromisos y estado</option>
<option value="comparativo_periodo" <?= $tipo==='comparativo_periodo'?'selected':'' ?>>Comparativo por periodo</option>
</select>
<input name="periodo" placeholder="Periodo (opcional)" value="<?= htmlspecialchars($periodo) ?>">
<button class="btn">Ver</button>
<?php if(in_array(current_user()['rol'],['admin','analista'],true)): ?><button class="btn btn-muted" name="export" value="1">Exportar CSV</button><?php endif; ?>
</div></form>
<table class="table"><tr><th>Categoría</th><th>Total</th></tr><?php foreach($rows as $r): ?><tr><td><?= htmlspecialchars((string)$r['categoria']) ?></td><td><?= number_format((float)$r['total'],0,',','.') ?></td></tr><?php endforeach; ?></table>
<?php $content=ob_get_clean(); render_layout('Reportes',$content);
