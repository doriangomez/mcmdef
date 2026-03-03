<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';

function ui_badge(string $text, string $variant = 'default'): string
{
    $allowed = ['default', 'success', 'warning', 'danger', 'info'];
    if (!in_array($variant, $allowed, true)) {
        $variant = 'default';
    }
    return '<span class="badge badge-' . $variant . '">' . htmlspecialchars($text) . '</span>';
}

function render_layout(string $title, string $content): void
{
    $user = current_user();
    $role = $user['rol'] ?? 'visualizador';
    $menu = [
        [
            'label' => 'Dashboard',
            'icon' => 'fa-solid fa-gauge-high',
            'url' => 'index.php',
            'match' => ['/index.php'],
            'roles' => ['admin', 'analista', 'visualizador'],
        ],
        [
            'label' => 'Cargas',
            'icon' => 'fa-solid fa-file-arrow-up',
            'url' => 'cargas/nueva.php',
            'match' => ['/cargas', '/modules/cargas'],
            'roles' => ['admin', 'analista'],
        ],
        [
            'label' => 'Cartera',
            'icon' => 'fa-solid fa-wallet',
            'url' => 'cartera/lista.php',
            'match' => ['/cartera', '/modules/cartera'],
            'roles' => ['admin', 'analista', 'visualizador'],
        ],
        [
            'label' => 'Gestión',
            'icon' => 'fa-solid fa-list-check',
            'url' => 'gestion/lista.php',
            'match' => ['/gestion', '/modules/gestion'],
            'roles' => ['admin', 'analista'],
        ],
        [
            'label' => 'Reportes',
            'icon' => 'fa-solid fa-chart-column',
            'url' => 'reportes/index.php',
            'match' => ['/reportes', '/modules/reportes'],
            'roles' => ['admin', 'analista', 'visualizador'],
        ],
        [
            'label' => 'Usuarios',
            'icon' => 'fa-solid fa-users-gear',
            'url' => 'admin/usuarios.php',
            'match' => ['/admin/usuarios.php', '/modules/admin/usuarios.php'],
            'roles' => ['admin'],
        ],
        [
            'label' => 'Auditoría',
            'icon' => 'fa-solid fa-shield-halved',
            'url' => 'admin/auditoria.php',
            'match' => ['/admin/auditoria.php', '/modules/admin/auditoria.php'],
            'roles' => ['admin'],
        ],
    ];

    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= htmlspecialchars($title) ?> - MCM Cartera</title>
      <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(app_url('assets/img/logo-mcm.svg')) ?>">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
      <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/app.css')) ?>">
    </head>
    <body>
      <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-brand">
          <img src="<?= htmlspecialchars(app_url('assets/img/logo-mcm.svg')) ?>" alt="MCM" class="sidebar-logo">
          <div>
            <div class="sidebar-brand-title">MCM</div>
            <div class="sidebar-brand-subtitle">Cartera y Recaudos</div>
          </div>
        </div>
        <nav class="sidebar-nav">
          <?php foreach ($menu as $item): ?>
            <?php if (!in_array($role, $item['roles'], true)) { continue; } ?>
            <?php $active = app_route_is($item['match']); ?>
            <a class="sidebar-link <?= $active ? 'active' : '' ?>" href="<?= htmlspecialchars(app_url($item['url'])) ?>">
              <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
              <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
          <?php endforeach; ?>
          <a class="sidebar-link logout-link" href="<?= htmlspecialchars(app_url('logout.php')) ?>">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Salir</span>
          </a>
        </nav>
      </aside>

      <div class="sidebar-overlay" id="sidebarOverlay"></div>

      <div class="app-main">
        <header class="app-topbar">
          <button class="icon-button" id="sidebarToggle" type="button" aria-label="Abrir menú">
            <i class="fa-solid fa-bars"></i>
          </button>
          <div class="topbar-title-wrap">
            <h1 class="topbar-title"><?= htmlspecialchars($title) ?></h1>
            <p class="topbar-subtitle">Sistema de Gestión de Cartera y Recaudos</p>
          </div>
          <div class="topbar-user">
            <div class="avatar">
              <?= strtoupper(substr((string)($user['nombre'] ?? 'U'), 0, 1)) ?>
            </div>
            <div class="user-meta">
              <strong><?= htmlspecialchars((string)($user['nombre'] ?? 'Usuario')) ?></strong>
              <span><?= htmlspecialchars(ucfirst((string)$role)) ?></span>
            </div>
            <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('logout.php')) ?>">Salir</a>
          </div>
        </header>

        <main class="app-content">
          <?= $content ?>
        </main>

        <footer class="app-footer">
          <span><?= date('Y') ?> &copy; MCM - Gestión de Cartera y Recaudos</span>
        </footer>
      </div>

      <script>
        (function () {
          var sidebar = document.getElementById('appSidebar');
          var overlay = document.getElementById('sidebarOverlay');
          var toggle = document.getElementById('sidebarToggle');
          if (!sidebar || !overlay || !toggle) return;

          function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
          }

          toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
          });

          overlay.addEventListener('click', closeSidebar);

          window.addEventListener('resize', function () {
            if (window.innerWidth > 1024) {
              closeSidebar();
            }
          });
        })();
      </script>
    </body>
    </html>
    <?php
}
