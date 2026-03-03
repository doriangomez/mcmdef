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
      <?php if (in_array($user['rol'], ['admin', 'analista'], true)): ?>
        <a href="/cargas/nueva.php">Cargas</a>
      <?php endif; ?>
      <a href="/cartera/lista.php">Cartera</a>
      <?php if (in_array($user['rol'], ['admin', 'analista'], true)): ?>
        <a href="/gestion/lista.php">Gestión</a>
      <?php endif; ?>
      <a href="/reportes/index.php">Reportes</a>
      <?php if ($user['rol'] === 'admin'): ?>
        <a href="/admin/usuarios.php">Usuarios</a>
        <a href="/admin/auditoria.php">Auditoría</a>
      <?php endif; ?>
      <a href="/logout.php">Salir</a>
    </nav>
  <?php endif; ?>
</header>
