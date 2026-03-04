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
    </select>
    <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
    <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
    <button class="btn">Consultar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('api/gestion/reportes.php?' . http_build_query(['tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'format' => 'csv']))) ?>">Exportar a Excel (CSV)</a>
  </div>
</form>

<table class="table">
  <tr><th>Categoría</th><th>Total</th></tr>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= htmlspecialchars((string)($row['categoria'] ?? '')) ?></td>
      <td><?= number_format((float)($row['total'] ?? 0), 2, ',', '.') ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php
$content = ob_get_clean();
render_layout('Reportes', $content);
