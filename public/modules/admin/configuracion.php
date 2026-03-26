<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/SystemSettingsService.php';

require_role(['admin']);

$tab = $_GET['tab'] ?? 'general';
$sections = [
    'general' => [
        'title' => 'Configuración general',
        'description' => 'Administra parámetros base de la plataforma corporativa.',
    ],
    'mora' => [
        'title' => 'Parametrización de rangos de mora',
        'description' => 'Edición de buckets y reglas de envejecimiento para analítica de riesgo.',
    ],
    'comercial' => [
        'title' => 'Gestión comercial',
        'description' => 'Catálogos maestros para segmentación estratégica.',
    ],
    'roles' => [
        'title' => 'Gestión avanzada de roles',
        'description' => 'Permisos granulares por módulo y acción.',
    ],
];

if (!isset($sections[$tab])) {
    $tab = 'general';
}

$msg = '';
$error = '';
$maxLogoSizeBytes = 2 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'general') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_logo') {
        $logoFile = $_FILES['institutional_logo'] ?? null;

        if (!is_array($logoFile) || (int)($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Debes seleccionar un archivo PNG o SVG para continuar.';
        } elseif ((int)$logoFile['error'] !== UPLOAD_ERR_OK) {
            $error = 'No fue posible cargar el archivo. Intenta nuevamente.';
        } elseif ((int)$logoFile['size'] > $maxLogoSizeBytes) {
            $error = 'El logo supera el tamaño máximo permitido (2 MB).';
        } else {
            $originalName = (string)($logoFile['name'] ?? 'logo');
            $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['png', 'svg'];

            if (!in_array($extension, $allowedExtensions, true)) {
                $error = 'Formato no permitido. Solo se aceptan archivos PNG o SVG.';
            } else {
                $mimeType = (string)mime_content_type((string)$logoFile['tmp_name']);
                $allowedMimes = ['image/png', 'image/svg+xml', 'text/plain'];
                if (!in_array($mimeType, $allowedMimes, true)) {
                    $error = 'Tipo de archivo inválido. Debes cargar un PNG o SVG válido.';
                } else {
                    $uploadDirRelative = 'uploads/branding';
                    $uploadDirAbsolute = dirname(__DIR__, 3) . '/public/' . $uploadDirRelative;
                    if (!is_dir($uploadDirAbsolute)) {
                        mkdir($uploadDirAbsolute, 0775, true);
                    }

                    $newFilename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
                    $newPathRelative = $uploadDirRelative . '/' . $newFilename;
                    $newPathAbsolute = $uploadDirAbsolute . '/' . $newFilename;

                    $currentLogo = system_setting_get($pdo, 'institutional_logo_path');
                    $currentLogoResolved = system_logo_public_path($currentLogo);

                    if (!move_uploaded_file((string)$logoFile['tmp_name'], $newPathAbsolute)) {
                        $error = 'No fue posible almacenar el logo en el servidor.';
                    } else {
                        system_setting_set($pdo, 'institutional_logo_path', $newPathRelative);

                        if ($currentLogoResolved !== null && strpos($currentLogoResolved, 'uploads/branding/') === 0) {
                            $oldAbsolute = dirname(__DIR__, 3) . '/public/' . $currentLogoResolved;
                            if (is_file($oldAbsolute)) {
                                @unlink($oldAbsolute);
                            }
                        }

                        $msg = 'Logo institucional actualizado correctamente.';
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'mora') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_mora_params') {
        $severidadBaseInput = trim((string)($_POST['mora_severidad_base_dias'] ?? '90'));
        if ($severidadBaseInput === '' || !ctype_digit($severidadBaseInput)) {
            $error = 'La referencia base de severidad debe ser un número entero positivo.';
        } else {
            $severidadBase = (int)$severidadBaseInput;
            if ($severidadBase < 1 || $severidadBase > 720) {
                $error = 'La referencia base de severidad debe estar entre 1 y 720 días.';
            } else {
                system_setting_set($pdo, 'mora_severidad_base_dias', (string)$severidadBase);
                $msg = 'Parámetros de mora actualizados correctamente.';
            }
        }
    }
}

$current = $sections[$tab];
$logoPath = system_setting_get($pdo, 'institutional_logo_path');
$logoRelative = system_logo_public_path($logoPath);
$logoPreviewUrl = app_url($logoRelative ?? system_logo_default_path());
$moraSeveridadBaseDias = system_setting_get($pdo, 'mora_severidad_base_dias', '90') ?? '90';

ob_start();
?>
<div class="card">
  <div class="card-header">
    <h3><?= htmlspecialchars($current['title']) ?></h3>
    <?= ui_badge('Configuración corporativa', 'info') ?>
  </div>
  <p class="muted"><?= htmlspecialchars($current['description']) ?></p>
</div>

<?php if ($tab === 'general'): ?>
  <?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-header">
      <h3>Logo institucional</h3>
      <?= ui_badge('Editable', 'success') ?>
    </div>
    <p class="muted">Carga un archivo PNG o SVG para actualizar la marca principal del sidebar. Tamaño máximo: 2 MB.</p>

    <div style="display:grid; gap:16px; grid-template-columns:minmax(220px, 320px) 1fr; align-items:start;">
      <div style="border:1px solid #D8E0EC; border-radius:16px; padding:16px; background:#F8FBFF;">
        <p style="margin:0 0 8px; font-size:12px; color:#6B7280; font-weight:600;">Vista previa actual</p>
        <img src="<?= htmlspecialchars($logoPreviewUrl) ?>" alt="Logo institucional" style="width:100%; max-height:180px; object-fit:contain; display:block;">
      </div>

      <form method="post" enctype="multipart/form-data" style="display:grid; gap:10px; align-content:start;">
        <input type="hidden" name="action" value="update_logo">
        <label for="institutional_logo" style="font-size:13px; font-weight:600; color:#334155;">Seleccionar nueva imagen</label>
        <input id="institutional_logo" name="institutional_logo" type="file" accept=".png,.svg,image/png,image/svg+xml" required>
        <small class="muted">Se reemplazará automáticamente el logo actual en todo el sistema.</small>
        <div>
          <button class="btn" type="submit">Guardar logo institucional</button>
        </div>
      </form>
    </div>
  </div>
<?php elseif ($tab === 'mora'): ?>
  <?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="card">
    <div class="card-header">
      <h3>Parámetros de severidad</h3>
      <?= ui_badge('Configurable', 'success') ?>
    </div>
    <p class="muted">Define el valor de referencia (en días) para el cálculo del índice de severidad de mora del dashboard ejecutivo.</p>
    <form method="post" style="display:grid; gap:10px; max-width:420px;">
      <input type="hidden" name="action" value="update_mora_params">
      <label for="mora_severidad_base_dias" style="font-size:13px; font-weight:600; color:#334155;">Referencia base de severidad (días)</label>
      <input
        id="mora_severidad_base_dias"
        name="mora_severidad_base_dias"
        type="number"
        min="1"
        max="720"
        step="1"
        value="<?= htmlspecialchars($moraSeveridadBaseDias) ?>"
        required
      >
      <small class="muted">Valor recomendado por defecto: 90 días.</small>
      <div>
        <button class="btn" type="submit">Guardar parámetros de mora</button>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <table class="table">
      <thead><tr><th>Componente</th><th>Estado</th></tr></thead>
      <tbody>
        <tr><td>Módulo en construcción</td><td><?= ui_badge('Próximamente', 'warning') ?></td></tr>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_layout('Configuración', $content);
