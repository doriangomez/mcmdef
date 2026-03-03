<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);

$msg = '';
$error = '';
$clienteId = (int)($_POST['cliente_id'] ?? $_GET['cliente_id'] ?? 0);
$documentoId = (int)($_POST['documento_id'] ?? $_GET['documento_id'] ?? 0);
$tipoGestion = trim($_POST['tipo_gestion'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$fechaCompromiso = trim($_POST['fecha_compromiso'] ?? '');
$valorCompromiso = trim($_POST['valor_compromiso'] ?? '');
$estadoCompromiso = strtolower(trim($_POST['estado_compromiso'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($clienteId <= 0 && $documentoId <= 0) {
        $error = 'Debe asociar la gestión a un cliente o documento.';
    }

    if ($documentoId > 0 && $clienteId <= 0) {
        $docLookup = $pdo->prepare('SELECT cliente_id FROM documentos WHERE id = ? LIMIT 1');
        $docLookup->execute([$documentoId]);
        $clienteId = (int)$docLookup->fetchColumn();
        if ($clienteId <= 0) {
            $error = 'El documento indicado no existe.';
        }
    }

    if ($tipoGestion === '') {
        $error = 'El tipo de gestión es obligatorio.';
    }
    if ($descripcion === '') {
        $error = 'La descripción es obligatoria.';
    }

    $tiposPermitidos = ['novedad', 'compromiso', 'seguimiento', 'ajuste', 'otro'];
    if (!in_array($tipoGestion, $tiposPermitidos, true)) {
        $error = 'Tipo de gestión inválido.';
    }

    if ($estadoCompromiso !== '' && !in_array($estadoCompromiso, ['pendiente', 'cumplido', 'incumplido'], true)) {
        $error = 'Estado de compromiso inválido.';
    }

    if ($tipoGestion === 'compromiso' && $estadoCompromiso === '') {
        $estadoCompromiso = 'pendiente';
    }

    if ($error === '') {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO gestiones
                 (cliente_id, documento_id, tipo_gestion, descripcion, fecha_compromiso, valor_compromiso, estado_compromiso, usuario_id, created_at, anulada)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)'
            );
            $insert->execute([
                $clienteId > 0 ? $clienteId : null,
                $documentoId > 0 ? $documentoId : null,
                $tipoGestion,
                $descripcion,
                $fechaCompromiso !== '' ? $fechaCompromiso : null,
                $valorCompromiso !== '' ? $valorCompromiso : null,
                $estadoCompromiso !== '' ? $estadoCompromiso : null,
                (int)$_SESSION['user']['id'],
            ]);
            $gestionId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'gestiones', $gestionId, 'creacion', null, 'nueva gestión', (int)$_SESSION['user']['id']);
            $msg = 'Gestión registrada correctamente.';
            $tipoGestion = '';
            $descripcion = '';
            $fechaCompromiso = '';
            $valorCompromiso = '';
            $estadoCompromiso = '';
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
<input type="number" min="1" name="cliente_id" placeholder="Cliente ID" value="<?= $clienteId > 0 ? (int)$clienteId : '' ?>">
<input type="number" min="1" name="documento_id" placeholder="Documento ID" value="<?= $documentoId > 0 ? (int)$documentoId : '' ?>">
<select name="tipo_gestion" required>
  <option value="">Tipo de gestión</option>
  <option value="novedad" <?= $tipoGestion === 'novedad' ? 'selected' : '' ?>>Novedad</option>
  <option value="compromiso" <?= $tipoGestion === 'compromiso' ? 'selected' : '' ?>>Compromiso</option>
  <option value="seguimiento" <?= $tipoGestion === 'seguimiento' ? 'selected' : '' ?>>Seguimiento</option>
  <option value="ajuste" <?= $tipoGestion === 'ajuste' ? 'selected' : '' ?>>Ajuste</option>
  <option value="otro" <?= $tipoGestion === 'otro' ? 'selected' : '' ?>>Otro</option>
</select>
</div>
<div class="row"><textarea name="descripcion" placeholder="Descripción" required style="width:100%"><?= htmlspecialchars($descripcion) ?></textarea></div>
<div class="row">
<input type="date" name="fecha_compromiso" value="<?= htmlspecialchars($fechaCompromiso) ?>">
<input type="number" step="0.01" min="0" name="valor_compromiso" placeholder="Valor compromiso" value="<?= htmlspecialchars($valorCompromiso) ?>">
<select name="estado_compromiso">
  <option value="">Sin estado</option>
  <option value="pendiente" <?= $estadoCompromiso === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
  <option value="cumplido" <?= $estadoCompromiso === 'cumplido' ? 'selected' : '' ?>>Cumplido</option>
  <option value="incumplido" <?= $estadoCompromiso === 'incumplido' ? 'selected' : '' ?>>Incumplido</option>
</select>
</div>
<button class="btn">Guardar</button>
<a class="btn btn-muted" href="/gestion/lista.php">Ver historial</a>
</form>
<?php
$content = ob_get_clean();
render_layout('Nueva gestión', $content);
