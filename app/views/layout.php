<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

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
            'label' => 'Recaudos',
            'icon' => 'fa-solid fa-money-bill-transfer',
            'url' => 'recaudos/carga.php',
            'match' => ['/recaudos', '/modules/recaudos'],
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
            'label' => 'Gestión de cartera',
            'icon' => 'fa-solid fa-list-check',
            'url' => 'gestion/dashboard.php',
            'match' => ['/gestion', '/modules/gestion'],
            'roles' => ['admin', 'analista'],
        ],
        [
            'section' => 'Operación',
            'label' => 'Compromisos',
            'icon' => 'fa-solid fa-handshake',
            'url' => 'gestion/compromisos.php',
            'match' => ['/gestion/compromisos.php', '/modules/gestion/compromisos.php'],
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
      <?php $logoUrl = system_logo_url(); ?>
      <link rel="icon" href="<?= htmlspecialchars($logoUrl) ?>">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
      <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/app.css')) ?>">
    </head>
    <body>
      <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-brand">
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="MCM" class="sidebar-logo">
          <div class="sidebar-brand-text">
            <div class="sidebar-brand-title">MCM</div>
            <div class="sidebar-brand-subtitle">Cartera y Recaudos</div>
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
            <p class="topbar-subtitle">Centro analítico estratégico de cartera y recaudos</p>
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

      <div id="loader-global" aria-live="polite" aria-busy="true" role="status">
        <div class="loader-box">
          <div class="loader-spinner"></div>
          <div id="loader-message">Procesando archivo...</div>
          <div class="loader-progress-container">
            <div id="loader-progress"></div>
          </div>
        </div>
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
            if (window.innerWidth <= 1024) {
              sidebar.classList.toggle('open');
              overlay.classList.toggle('show');
              return;
            }

            document.body.classList.toggle('sidebar-collapsed');
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

          function loaderShow(message) {
            var loader = document.getElementById('loader-global');
            var messageNode = document.getElementById('loader-message');
            if (!loader) return;

            loader.style.display = 'flex';
            if (message && messageNode) {
              messageNode.innerText = message;
            }
          }

          function loaderProgress(percent) {
            var progressNode = document.getElementById('loader-progress');
            if (!progressNode) return;

            var safePercent = Number(percent);
            if (Number.isNaN(safePercent)) {
              safePercent = 0;
            }

            safePercent = Math.max(0, Math.min(100, safePercent));
            progressNode.style.width = safePercent + '%';
          }

          function loaderHide() {
            var loader = document.getElementById('loader-global');
            var progressNode = document.getElementById('loader-progress');
            var messageNode = document.getElementById('loader-message');
            if (!loader) return;

            loader.style.display = 'none';
            if (progressNode) {
              progressNode.style.width = '0%';
            }
            if (messageNode) {
              messageNode.innerText = 'Procesando archivo...';
            }
          }

          window.loaderShow = loaderShow;
          window.loaderProgress = loaderProgress;
          window.loaderHide = loaderHide;

          var flowSteps = [
            { delay: 0, progress: 10, message: 'Validando archivo...' },
            { delay: 250, progress: 40, message: 'Leyendo registros...' },
            { delay: 500, progress: 70, message: 'Procesando conciliación...' },
            { delay: 750, progress: 90, message: 'Guardando información...' },
            { delay: 1000, progress: 100, message: 'Finalizando proceso...' }
          ];

          function lockForm(form) {
            form.querySelectorAll('button, input, select, textarea').forEach(function (field) {
              if (field.type === 'hidden') return;
              field.setAttribute('disabled', 'disabled');
            });
          }

          document.querySelectorAll('.form-carga').forEach(function (form) {
            form.addEventListener('submit', function (event) {
              if (form.dataset.submitting === 'true') {
                return;
              }

              var hasFile = Array.prototype.some.call(form.querySelectorAll('input[type="file"]'), function (fileInput) {
                return fileInput.files && fileInput.files.length > 0;
              });

              if (!hasFile) {
                return;
              }

              event.preventDefault();
              form.dataset.submitting = 'true';
              lockForm(form);

              flowSteps.forEach(function (step) {
                window.setTimeout(function () {
                  loaderShow(step.message);
                  loaderProgress(step.progress);
                }, step.delay);
              });

              window.setTimeout(function () {
                form.submit();
              }, flowSteps[flowSteps.length - 1].delay + 50);
            });
          });

          window.addEventListener('pageshow', loaderHide);
          window.addEventListener('load', loaderHide);
        })();

        (function () {
          var loaderTimer = null;
          var loaderSteps = [
            { progress: 10, message: 'Validando archivo...' },
            { progress: 40, message: 'Leyendo registros...' },
            { progress: 70, message: 'Procesando conciliación...' },
            { progress: 90, message: 'Guardando información...' },
            { progress: 100, message: 'Finalizando proceso...' }
          ];

          window.loaderShow = function (message) {
            var loader = document.getElementById('loader-global');
            var loaderMessage = document.getElementById('loader-message');
            if (!loader || !loaderMessage) return;

            loader.style.display = 'flex';
            if (message) {
              loaderMessage.innerText = message;
            }
          };

          window.loaderProgress = function (percent) {
            var loaderBar = document.getElementById('loader-progress');
            if (!loaderBar) return;
            loaderBar.style.width = percent + '%';
          };

          window.loaderHide = function () {
            var loader = document.getElementById('loader-global');
            if (!loader) return;

            loader.style.display = 'none';
            window.loaderProgress(0);
            if (loaderTimer) {
              window.clearInterval(loaderTimer);
              loaderTimer = null;
            }
          };

          document.querySelectorAll('.form-carga').forEach(function (form) {
            form.addEventListener('submit', function () {
              var submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
              if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
              }

              window.loaderShow(loaderSteps[0].message);
              window.loaderProgress(loaderSteps[0].progress);

              var index = 1;
              if (loaderTimer) {
                window.clearInterval(loaderTimer);
              }
              loaderTimer = window.setInterval(function () {
                if (index >= loaderSteps.length) {
                  window.clearInterval(loaderTimer);
                  loaderTimer = null;
                  return;
                }

                var step = loaderSteps[index];
                window.loaderShow(step.message);
                window.loaderProgress(step.progress);
                index += 1;
              }, 900);
            });
          });

          window.addEventListener('pageshow', function () {
            window.loaderHide();
          });

          window.addEventListener('load', function () {
            window.loaderHide();
          });
        })();
      </script>
    </body>
    </html>
    <?php
}
