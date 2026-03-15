<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';

$tipo = trim((string)($_GET['tipo'] ?? 'cartera_regional'));
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));

$api = app_url('api/gestion/reportes.php?' . http_build_query(['tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta]));
$data = @file_get_contents($api);
$rows = [];
if ($data !== false) {
    $decoded = json_decode($data, true);
    if (is_array($decoded) && !empty($decoded['rows']) && is_array($decoded['rows'])) {
        $rows = $decoded['rows'];
    }
}

ob_start(); ?>
<h1>Reportes de cartera</h1>
<form class="card" method="get">
  <div class="row">
    <select name="tipo">
      <option value="cartera_regional" <?= $tipo === 'cartera_regional' ? 'selected' : '' ?>>Cartera por regional</option>
      <option value="cartera_canal" <?= $tipo === 'cartera_canal' ? 'selected' : '' ?>>Cartera por canal</option>
      <option value="cartera_gestor" <?= $tipo === 'cartera_gestor' ? 'selected' : '' ?>>Cartera por gestor</option>
      <option value="promesas_pendientes" <?= $tipo === 'promesas_pendientes' ? 'selected' : '' ?>>Promesas pendientes</option>
      <option value="promesas_incumplidas" <?= $tipo === 'promesas_incumplidas' ? 'selected' : '' ?>>Promesas incumplidas</option>
      <option value="gestiones_gestor" <?= $tipo === 'gestiones_gestor' ? 'selected' : '' ?>>Gestiones por gestor</option>
      <option value="analisis_vencimiento" <?= $tipo === 'analisis_vencimiento' ? 'selected' : '' ?>>Análisis de vencimiento</option>
    </select>
    <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
    <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
    <button class="btn">Consultar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('api/gestion/reportes.php?' . http_build_query(['tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'format' => 'csv']))) ?>">Exportar a Excel (CSV)</a>
  </div>
</form>

<?php if ($tipo === 'analisis_vencimiento'): ?>
  <table class="table">
    <tr>
      <th>Cliente</th><th>Documento</th><th>Saldo</th><th>Dias vencido</th><th>Actual</th><th>1-30</th><th>31-60</th><th>61-90</th><th>91-180</th><th>181-360</th><th>361+</th><th>Canal</th><th>Regional</th><th>Asesor</th><th>UENS</th>
    </tr>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= htmlspecialchars((string)($row['cliente'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['documento'] ?? '')) ?></td>
        <td><?= number_format((float)($row['saldo'] ?? 0), 2, ',', '.') ?></td>
        <td><?= (int)($row['dias_vencido'] ?? 0) ?></td>
        <td><?= number_format((float)($row['actual'] ?? 0), 2, ',', '.') ?></td>
        <td><?= number_format((float)($row['bucket_1_30'] ?? 0), 2, ',', '.') ?></td>
        <td><?= number_format((float)($row['bucket_31_60'] ?? 0), 2, ',', '.') ?></td>
        <td><?= number_format((float)($row['bucket_61_90'] ?? 0), 2, ',', '.') ?></td>
        <td><?= number_format((float)($row['bucket_91_180'] ?? 0), 2, ',', '.') ?></td>
        <td><?= number_format((float)($row['bucket_181_360'] ?? 0), 2, ',', '.') ?></td>
        <td><?= number_format((float)($row['bucket_361_plus'] ?? 0), 2, ',', '.') ?></td>
        <td><?= htmlspecialchars((string)($row['canal'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['regional'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['asesor'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['uens'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php else: ?>
  <table class="table">
    <tr><th>Categoría</th><th>Total</th></tr>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= htmlspecialchars((string)($row['categoria'] ?? '')) ?></td>
        <td><?= number_format((float)($row['total'] ?? 0), 2, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_layout('Reportes', $content);
