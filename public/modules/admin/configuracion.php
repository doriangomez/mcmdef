<?php
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';

require_role(['admin']);

$tab = $_GET['tab'] ?? 'general';
$sections = [
    'general' => [
        'title' => 'Configuración general',
        'description' => 'Administra parámetros base de la plataforma corporativa.',
        'items' => ['Nombre sistema', 'Logo editable', 'Año fiscal', 'Moneda'],
    ],
    'mora' => [
        'title' => 'Parametrización de rangos de mora',
        'description' => 'Edición de buckets y reglas de envejecimiento para analítica de riesgo.',
        'items' => ['Bucket Actual', 'Bucket 1-30', 'Bucket 31-60', 'Bucket 61-90', 'Bucket 91-180', 'Bucket 181-360', 'Bucket 361+'],
    ],
    'comercial' => [
        'title' => 'Gestión comercial',
        'description' => 'Catálogos maestros para segmentación estratégica.',
        'items' => ['Gestión de canales', 'Gestión de regionales', 'Gestión de empleados de ventas'],
    ],
    'roles' => [
        'title' => 'Gestión avanzada de roles',
        'description' => 'Permisos granulares por módulo y acción.',
        'items' => ['Matriz de permisos', 'Roles personalizados', 'Aprobaciones y delegaciones'],
    ],
];

if (!isset($sections[$tab])) {
    $tab = 'general';
}
$current = $sections[$tab];

ob_start();
?>
<div class="card">
  <div class="card-header">
    <h3><?= htmlspecialchars($current['title']) ?></h3>
    <?= ui_badge('Configuración corporativa', 'info') ?>
  </div>
  <p class="muted"><?= htmlspecialchars($current['description']) ?></p>
</div>

<div class="card">
  <table class="table">
    <thead><tr><th>Componente</th><th>Estado</th></tr></thead>
    <tbody>
      <?php foreach ($current['items'] as $item): ?>
        <tr>
          <td><?= htmlspecialchars($item) ?></td>
          <td><?= ui_badge('Editable', 'success') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_layout('Configuración', $content);
