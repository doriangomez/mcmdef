<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);

$msg = '';
$error = '';
$documentoId = (int)($_POST['documento_id'] ?? $_GET['documento_id'] ?? 0);
$tipoGestion = trim($_POST['tipo_gestion'] ?? '');
$observacion = trim($_POST['observacion'] ?? '');
$compromisoPago = trim($_POST['compromiso_pago'] ?? '');
$valorCompromiso = trim($_POST['valor_compromiso'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($documentoId <= 0) {
        $error = 'Debe asociar la gestión a un documento.';
    }

    if ($error === '') {
        $docLookup = $pdo->prepare('SELECT id FROM cartera_documentos WHERE id = ? LIMIT 1');
        $docLookup->execute([$documentoId]);
        if (!$docLookup->fetchColumn()) {
            $error = 'El documento indicado no existe.';
        }
    }

    if ($tipoGestion === '') {
        $error = 'El tipo de gestión es obligatorio.';
    }
    if ($observacion === '') {
        $error = 'La observación es obligatoria.';
    }

    if ($error === '') {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO bitacora_gestion
                 (id_documento, usuario_id, tipo_gestion, observacion, compromiso_pago, valor_compromiso, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $insert->execute([
                $documentoId,
                (int)$_SESSION['user']['id'],
                $tipoGestion,
                $observacion,
                $compromisoPago !== '' ? $compromisoPago : null,
                $valorCompromiso !== '' ? $valorCompromiso : null,
            ]);
            $gestionId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'bitacora_gestion', $gestionId, 'gestion_creada', null, 'ok', (int)$_SESSION['user']['id']);
            $msg = 'Gestión registrada correctamente.';
            $tipoGestion = '';
            $observacion = '';
            $compromisoPago = '';
            $valorCompromiso = '';
        } catch (Throwable $exception) {
            $error = 'No se pudo registrar la gestión: ' . $exception->getMessage();
        }
    }
}

ob_start(); ?>
<h1>Registrar gestión</h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form class="card" method="post">
<div class="row">
<input type="number" min="1" name="documento_id" placeholder="Documento ID" value="<?= $documentoId > 0 ? (int)$documentoId : '' ?>" required>
<select name="tipo_gestion" required>
  <option value="">Tipo de gestión</option>
  <option value="novedad" <?= $tipoGestion === 'novedad' ? 'selected' : '' ?>>Novedad</option>
  <option value="compromiso" <?= $tipoGestion === 'compromiso' ? 'selected' : '' ?>>Compromiso</option>
  <option value="seguimiento" <?= $tipoGestion === 'seguimiento' ? 'selected' : '' ?>>Seguimiento</option>
  <option value="otro" <?= $tipoGestion === 'otro' ? 'selected' : '' ?>>Otro</option>
</select>
</div>
<div class="row"><textarea name="observacion" placeholder="Observación" required style="width:100%"><?= htmlspecialchars($observacion) ?></textarea></div>
<div class="row">
<input type="date" name="compromiso_pago" value="<?= htmlspecialchars($compromisoPago) ?>">
<input type="number" step="0.01" min="0" name="valor_compromiso" placeholder="Valor compromiso" value="<?= htmlspecialchars($valorCompromiso) ?>">
</div>
<button class="btn">Guardar</button>
<a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/lista.php')) ?>">Ver historial</a>
</form>
<?php
$content = ob_get_clean();
render_layout('Nueva gestión', $content);
