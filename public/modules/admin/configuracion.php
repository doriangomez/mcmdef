<?php
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';

require_role(['admin']);

$tab = $_GET['tab'] ?? 'general';
$sections = [
    'general' => [
        'title' => 'Configuración general del sistema',
        'description' => 'Define políticas transversales: identidad visual, parámetros de operación y ciclos de actualización.',
        'items' => ['Ambiente y seguridad', 'Identidad y branding MCM', 'Parámetros de notificaciones'],
    ],
    'mora' => [
        'title' => 'Parametrización de mora',
        'description' => 'Controla reglas de segmentación por días de mora, niveles de riesgo y automatizaciones de cobranza.',
        'items' => ['Tramos de mora', 'Matriz de riesgo', 'Reglas de priorización'],
    ],
    'comercial' => [
        'title' => 'Parametrización comercial',
        'description' => 'Administra catálogos comerciales para canal, regional, UEN y marcas de negocio.',
        'items' => ['Canales y subcanales', 'Regional y estructura UEN', 'Portafolio de marcas'],
    ],
    'analitica' => [
        'title' => 'Parametrización analítica',
        'description' => 'Gestiona variables de modelos, umbrales de alertas y definiciones para tableros ejecutivos.',
        'items' => ['Variables de scoring', 'Diccionario de indicadores', 'Alertas y umbrales'],
    ],
    'roles' => [
        'title' => 'Gestión avanzada de roles',
        'description' => 'Orquesta permisos granulares por rol, módulo y acción para fortalecer gobierno y trazabilidad.',
        'items' => ['Matriz de permisos', 'Roles personalizados', 'Delegación y aprobaciones'],
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
    <?= ui_badge('Módulo corporativo', 'info') ?>
  </div>
  <p class="muted"><?= htmlspecialchars($current['description']) ?></p>
</div>

<div class="card">
  <div class="card-header">
    <h3>Capacidades incluidas</h3>
  </div>
  <table class="table">
    <thead>
      <tr>
        <th>Componente</th>
        <th>Estado</th>
        <th>Prioridad</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($current['items'] as $item): ?>
        <tr>
          <td><?= htmlspecialchars($item) ?></td>
          <td><?= ui_badge('Activo', 'success') ?></td>
          <td><?= ui_badge('Alta', 'warning') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_layout('Configuración', $content);
