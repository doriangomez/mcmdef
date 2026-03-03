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
            'section' => 'Principal',
            'label' => 'Dashboard',
            'icon' => 'fa-solid fa-gauge-high',
            'url' => 'index.php',
            'match' => ['/index.php'],
            'roles' => ['admin', 'analista', 'visualizador'],
        ],
        [
            'section' => 'Operación',
            'label' => 'Cargas',
            'icon' => 'fa-solid fa-file-arrow-up',
            'url' => 'cargas/nueva.php',
            'match' => ['/cargas', '/modules/cargas'],
            'roles' => ['admin', 'analista'],
        ],
        [
            'section' => 'Operación',
            'label' => 'Cartera',
            'icon' => 'fa-solid fa-wallet',
            'url' => 'cartera/lista.php',
            'match' => ['/cartera', '/modules/cartera'],
            'roles' => ['admin', 'analista', 'visualizador'],
        ],
        [
            'section' => 'Operación',
            'label' => 'Gestión',
            'icon' => 'fa-solid fa-list-check',
            'url' => 'gestion/lista.php',
            'match' => ['/gestion', '/modules/gestion'],
            'roles' => ['admin', 'analista'],
        ],
        [
            'section' => 'Inteligencia',
            'label' => 'Reportes',
            'icon' => 'fa-solid fa-chart-column',
            'url' => 'reportes/index.php',
            'match' => ['/reportes', '/modules/reportes'],
            'roles' => ['admin', 'analista', 'visualizador'],
        ],
        [
            'section' => 'Administración',
            'label' => 'Usuarios',
            'icon' => 'fa-solid fa-users-gear',
            'url' => 'admin/usuarios.php',
            'match' => ['/admin/usuarios.php', '/modules/admin/usuarios.php'],
            'roles' => ['admin'],
        ],
        [
            'section' => 'Administración',
            'label' => 'Auditoría',
            'icon' => 'fa-solid fa-shield-halved',
            'url' => 'admin/auditoria.php',
            'match' => ['/admin/auditoria.php', '/modules/admin/auditoria.php'],
            'roles' => ['admin'],
        ],
        [
            'section' => 'Configuración',
            'label' => 'General del sistema',
            'icon' => 'fa-solid fa-sliders',
            'url' => 'admin/configuracion.php?tab=general',
            'match' => ['/admin/configuracion.php', '/modules/admin/configuracion.php'],
            'roles' => ['admin'],
        ],
        [
            'section' => 'Configuración',
            'label' => 'Parametrización de mora',
            'icon' => 'fa-solid fa-clock-rotate-left',
            'url' => 'admin/configuracion.php?tab=mora',
            'match' => ['/admin/configuracion.php', '/modules/admin/configuracion.php'],
            'roles' => ['admin'],
        ],
        [
            'section' => 'Configuración',
            'label' => 'Parametrización comercial',
            'icon' => 'fa-solid fa-sitemap',
            'url' => 'admin/configuracion.php?tab=comercial',
            'match' => ['/admin/configuracion.php', '/modules/admin/configuracion.php'],
            'roles' => ['admin'],
        ],
        [
            'section' => 'Configuración',
            'label' => 'Parametrización analítica',
            'icon' => 'fa-solid fa-chart-line',
            'url' => 'admin/configuracion.php?tab=analitica',
            'match' => ['/admin/configuracion.php', '/modules/admin/configuracion.php'],
            'roles' => ['admin'],
        ],
        [
            'section' => 'Configuración',
            'label' => 'Gestión avanzada de roles',
            'icon' => 'fa-solid fa-user-shield',
            'url' => 'admin/configuracion.php?tab=roles',
            'match' => ['/admin/configuracion.php', '/modules/admin/configuracion.php'],
            'roles' => ['admin'],
        ],
    ];

    $menuBySection = [];
    foreach ($menu as $item) {
        if (!in_array($role, $item['roles'], true)) {
            continue;
        }
        $section = $item['section'] ?? 'General';
        $menuBySection[$section][] = $item;
    }

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
            <div class="sidebar-brand-subtitle">Plataforma Corporativa de Cartera</div>
          </div>
        </div>
        <nav class="sidebar-nav">
          <?php foreach ($menuBySection as $sectionName => $items): ?>
            <div class="sidebar-section">
              <p class="sidebar-section-label"><?= htmlspecialchars($sectionName) ?></p>
              <?php foreach ($items as $item): ?>
                <?php $active = app_route_is($item['match']); ?>
                <a class="sidebar-link <?= $active ? 'active' : '' ?>" href="<?= htmlspecialchars(app_url($item['url'])) ?>">
                  <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
                  <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
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
            <p class="topbar-greeting">Hola, <?= htmlspecialchars((string)($user['nombre'] ?? 'equipo')) ?></p>
            <h1 class="topbar-title"><?= htmlspecialchars($title) ?></h1>
            <p class="topbar-subtitle">Sistema de Gestión de Cartera y Recaudos</p>
          </div>
          <div class="topbar-user" id="topbarUserMenu">
            <button class="user-menu-trigger" id="userMenuToggle" type="button" aria-label="Abrir menú de usuario" aria-expanded="false">
              <div class="avatar">
                <?= strtoupper(substr((string)($user['nombre'] ?? 'U'), 0, 1)) ?>
              </div>
              <div class="user-meta">
                <strong><?= htmlspecialchars((string)($user['nombre'] ?? 'Usuario')) ?></strong>
                <span><?= htmlspecialchars(ucfirst((string)$role)) ?></span>
              </div>
              <i class="fa-solid fa-chevron-down"></i>
            </button>
            <div class="user-menu-dropdown" id="userMenuDropdown">
              <p class="user-menu-title">Sesión activa</p>
              <p class="user-menu-subtitle"><?= htmlspecialchars((string)($user['email'] ?? '')) ?></p>
              <a class="user-menu-item" href="<?= htmlspecialchars(app_url('logout.php')) ?>">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Cerrar sesión</span>
              </a>
            </div>
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

          var userMenuRoot = document.getElementById('topbarUserMenu');
          var userMenuToggle = document.getElementById('userMenuToggle');
          var userMenuDropdown = document.getElementById('userMenuDropdown');
          if (userMenuRoot && userMenuToggle && userMenuDropdown) {
            userMenuToggle.addEventListener('click', function (event) {
              event.stopPropagation();
              var isOpen = userMenuDropdown.classList.toggle('show');
              userMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', function (event) {
              if (!userMenuRoot.contains(event.target)) {
                userMenuDropdown.classList.remove('show');
                userMenuToggle.setAttribute('aria-expanded', 'false');
              }
            });
          }

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
