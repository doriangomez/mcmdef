<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/middlewares/require_auth.php';
require_once __DIR__ . '/../app/views/layout.php';

$kpis = [
    'vigente' => (float)$pdo->query("SELECT COALESCE(SUM(saldo_actual),0) FROM documentos WHERE estado_documento='vigente'")->fetchColumn(),
    'vencida' => (float)$pdo->query("SELECT COALESCE(SUM(saldo_actual),0) FROM documentos WHERE estado_documento='vencido'")->fetchColumn(),
    'saldo' => (float)$pdo->query("SELECT COALESCE(SUM(saldo_actual),0) FROM documentos")->fetchColumn(),
    'docs_vencidos' => (int)$pdo->query("SELECT COUNT(*) FROM documentos WHERE estado_documento='vencido'")->fetchColumn(),
    'compromisos' => (int)$pdo->query("SELECT COUNT(*) FROM gestiones WHERE estado_compromiso='pendiente' AND anulada=0")->fetchColumn(),
];
$cargas = $pdo->query("SELECT c.*, u.nombre as usuario FROM cargas_cartera c LEFT JOIN usuarios u ON u.id=c.usuario_id ORDER BY c.id DESC LIMIT 5")->fetchAll();

ob_start();
?>
<div class="kpi-grid">
    <section class="kpi-card">
      <p class="kpi-label">Cartera vigente</p>
      <p class="kpi-value">$<?= number_format($kpis['vigente'], 0, ',', '.') ?></p>
      <p class="kpi-subtext">Documentos al día</p>
    </section>
    <section class="kpi-card">
      <p class="kpi-label">Cartera vencida</p>
      <p class="kpi-value">$<?= number_format($kpis['vencida'], 0, ',', '.') ?></p>
      <p class="kpi-subtext">Exposición en mora</p>
    </section>
    <section class="kpi-card">
      <p class="kpi-label">Total saldo</p>
      <p class="kpi-value">$<?= number_format($kpis['saldo'], 0, ',', '.') ?></p>
      <p class="kpi-subtext">Saldo consolidado</p>
    </section>
    <section class="kpi-card">
      <p class="kpi-label">Documentos vencidos</p>
      <p class="kpi-value"><?= number_format($kpis['docs_vencidos'], 0, ',', '.') ?></p>
      <p class="kpi-subtext">Compromisos pendientes: <?= number_format($kpis['compromisos'], 0, ',', '.') ?></p>
    </section>
</div>

<div class="card">
  <div class="card-header">
    <h3>Últimas cargas</h3>
    <?php if (in_array(current_user()['rol'], ['admin', 'analista'], true)): ?>
      <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('cargas/historial.php')) ?>">Ver historial</a>
    <?php endif; ?>
  </div>
  <table class="table">
    <tr><th>ID</th><th>Archivo</th><th>Estado</th><th>Registros</th><th>Usuario</th><th>Fecha</th></tr>
    <?php foreach ($cargas as $c): ?>
      <?php
        $statusBadge = ui_badge($c['estado'], 'info');
        if ($c['estado'] === 'procesado') {
            $statusBadge = ui_badge('Procesado', 'success');
        } elseif ($c['estado'] === 'con_errores') {
            $statusBadge = ui_badge('Con errores', 'danger');
        } elseif ($c['estado'] === 'revertida') {
            $statusBadge = ui_badge('Revertida', 'warning');
        }
      ?>
      <tr>
        <td><?= (int)$c['id'] ?></td>
        <td><?= htmlspecialchars($c['nombre_archivo']) ?></td>
        <td><?= $statusBadge ?></td>
        <td><?= number_format((int)$c['total_registros'], 0, ',', '.') ?></td>
        <td><?= htmlspecialchars($c['usuario'] ?? '-') ?></td>
        <td><?= htmlspecialchars($c['fecha_carga']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <?php if (in_array(current_user()['rol'], ['admin', 'analista'], true)): ?>
    <a class="btn" href="<?= htmlspecialchars(app_url('cargas/nueva.php')) ?>">Cargar cartera</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/historial.php')) ?>">Historial de cargas</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/lista.php')) ?>">Gestión</a>
  <?php endif; ?>
  <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/lista.php')) ?>">Consultar cartera</a>
  <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('reportes/index.php')) ?>">Reportes</a>
</div>
<?php
$content = ob_get_clean();
render_layout('Dashboard',$content);
