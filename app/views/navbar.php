<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';
$user = current_user();
?>
<header class="navbar">
  <div class="brand">
    <img src="<?= htmlspecialchars(system_logo_url()) ?>" alt="MCM" class="logo">
    <span>Sistema Cartera y Recaudos</span>
  </div>
  <?php if ($user): ?>
    <nav>
      <a href="<?= htmlspecialchars(app_url('index.php')) ?>">Dashboard</a>
      <?php if (in_array($user['rol'], ['admin', 'analista'], true)): ?>
        <a href="<?= htmlspecialchars(app_url('cargas/nueva.php')) ?>">Cargas</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(app_url('cartera/lista.php')) ?>">Cartera</a>
      <?php if (in_array($user['rol'], ['admin', 'analista'], true)): ?>
        <a href="<?= htmlspecialchars(app_url('gestion/lista.php')) ?>">Gestión</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(app_url('reportes/index.php')) ?>">Reportes</a>
      <?php if ($user['rol'] === 'admin'): ?>
        <a href="<?= htmlspecialchars(app_url('admin/usuarios.php')) ?>">Usuarios</a>
        <a href="<?= htmlspecialchars(app_url('admin/auditoria.php')) ?>">Auditoría</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(app_url('logout.php')) ?>">Salir</a>
    </nav>
  <?php endif; ?>
</header>
