<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/middlewares/require_auth.php';
require_once __DIR__ . '/../app/views/layout.php';

$kpis = [
    'vigente' => (float)$pdo->query("SELECT COALESCE(SUM(saldo_actual),0) FROM documentos WHERE estado_documento='vigente'")->fetchColumn(),
    'vencida' => (float)$pdo->query("SELECT COALESCE(SUM(saldo_actual),0) FROM documentos WHERE estado_documento='vencido'")->fetchColumn(),
    'saldo' => (float)$pdo->query("SELECT COALESCE(SUM(saldo_actual),0) FROM documentos")->fetchColumn(),
    'docs_vencidos' => (int)$pdo->query("SELECT COUNT(*) FROM documentos WHERE estado_documento='vencido'")->fetchColumn(),
    'compromisos' => (int)$pdo->query("SELECT COUNT(*) FROM gestiones WHERE estado_compromiso='pendiente'")->fetchColumn(),
];
$cargas = $pdo->query("SELECT c.*, u.nombre as usuario FROM cargas_cartera c LEFT JOIN usuarios u ON u.id=c.usuario_id ORDER BY c.id DESC LIMIT 5")->fetchAll();

ob_start();
?>
<h1>Dashboard</h1>
<div class="grid">
    <div class="kpi">Cartera vigente<br><strong>$<?= number_format($kpis['vigente'],0,',','.') ?></strong></div>
    <div class="kpi">Cartera vencida<br><strong>$<?= number_format($kpis['vencida'],0,',','.') ?></strong></div>
    <div class="kpi">Total saldo<br><strong>$<?= number_format($kpis['saldo'],0,',','.') ?></strong></div>
    <div class="kpi">Documentos vencidos<br><strong><?= $kpis['docs_vencidos'] ?></strong></div>
    <div class="kpi">Compromisos pendientes<br><strong><?= $kpis['compromisos'] ?></strong></div>
</div>
<div class="card">
<h3>Últimas cargas</h3>
<table class="table"><tr><th>ID</th><th>Archivo</th><th>Estado</th><th>Registros</th><th>Usuario</th><th>Fecha</th></tr>
<?php foreach($cargas as $c): ?>
<tr><td><?= $c['id'] ?></td><td><?= htmlspecialchars($c['nombre_archivo']) ?></td><td><?= $c['estado'] ?></td><td><?= $c['total_registros'] ?></td><td><?= htmlspecialchars($c['usuario'] ?? '-') ?></td><td><?= $c['fecha_carga'] ?></td></tr>
<?php endforeach; ?></table>
</div>
<div class="card">
  <a class="btn" href="/modules/cargas/nueva.php">Cargar Cartera</a>
  <a class="btn" href="/modules/cartera/lista.php">Consultar</a>
  <a class="btn" href="/modules/reportes/index.php">Reportes</a>
</div>
<?php
$content = ob_get_clean();
render_layout('Dashboard',$content);
