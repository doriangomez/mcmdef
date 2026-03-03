<?php
require_once __DIR__ . '/../config/auth.php';
$user = current_user();
?>
<header class="navbar">
  <div class="brand">
    <img src="/assets/img/logo-mcm.svg" alt="MCM" class="logo">
    <span>Sistema Cartera y Recaudos</span>
  </div>
  <?php if ($user): ?>
    <nav>
      <a href="/index.php">Dashboard</a>
      <a href="/modules/cargas/nueva.php">Cargas</a>
      <a href="/modules/cartera/lista.php">Cartera</a>
      <a href="/modules/gestion/lista.php">Gestión</a>
      <a href="/modules/reportes/index.php">Reportes</a>
      <?php if ($user['rol'] === 'admin'): ?><a href="/modules/admin/usuarios.php">Usuarios</a><?php endif; ?>
      <a href="/logout.php">Salir</a>
    </nav>
  <?php endif; ?>
</header>
