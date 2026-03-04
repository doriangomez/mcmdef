<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

require_role(['admin', 'analista']);

$msg = '';
$error = '';
$documentoId = (int)($_POST['documento_id'] ?? $_GET['documento_id'] ?? 0);
$tipoGestion = trim($_POST['tipo_gestion'] ?? '');
$observacion = trim($_POST['observacion'] ?? '');
$compromisoPago = trim($_POST['compromiso_pago'] ?? '');
$valorCompromiso = trim($_POST['valor_compromiso'] ?? '');
$estadoCompromiso = trim($_POST['estado_compromiso'] ?? 'pendiente');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($documentoId <= 0) {
        $error = 'Debe asociar la gestión a un documento.';
    }

    if ($error === '') {
        $docScope = portfolio_document_scope_sql('cartera_documentos');
        $docLookup = $pdo->prepare('SELECT id FROM cartera_documentos WHERE id = ?' . $docScope['sql'] . ' LIMIT 1');
        $docLookup->execute(array_merge([$documentoId], $docScope['params']));
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
    if (!in_array($estadoCompromiso, ['pendiente', 'cumplido', 'incumplido'], true)) {
        $error = 'El estado del compromiso no es válido.';
    }

    if ($error === '') {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO bitacora_gestion
                 (id_documento, usuario_id, tipo_gestion, observacion, compromiso_pago, valor_compromiso, estado_compromiso, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $insert->execute([
                $documentoId,
                (int)$_SESSION['user']['id'],
                $tipoGestion,
                $observacion,
                $compromisoPago !== '' ? $compromisoPago : null,
                $valorCompromiso !== '' ? $valorCompromiso : null,
                $compromisoPago !== '' ? $estadoCompromiso : null,
            ]);
            $gestionId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'bitacora_gestion', $gestionId, 'gestion_creada', null, 'ok', (int)$_SESSION['user']['id']);
            $msg = 'Gestión registrada correctamente.';
            $tipoGestion = '';
            $observacion = '';
            $compromisoPago = '';
            $valorCompromiso = '';
            $estadoCompromiso = 'pendiente';
        } catch (Throwable $exception) {
            $error = 'No se pudo registrar la gestión: ' . $exception->getMessage();
        }
    }
}

$scope = portfolio_document_scope_sql('d');
$docsStmt = $pdo->prepare(
    'SELECT d.id, d.cliente, d.nro_documento
     FROM cartera_documentos d
     WHERE d.estado_documento = "activo"' . $scope['sql'] . '
     ORDER BY d.dias_vencido DESC, d.saldo_pendiente DESC
     LIMIT 500'
);
$docsStmt->execute($scope['params']);
$documentos = $docsStmt->fetchAll() ?: [];

ob_start(); ?>
<h1>Formulario de nueva gestión</h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form class="card" method="post">
<div class="row">
<select name="documento_id" required>
  <option value="">Cliente / Documento</option>
  <?php foreach ($documentos as $documento): ?>
    <option value="<?= (int)$documento['id'] ?>" <?= (int)$documento['id'] === $documentoId ? 'selected' : '' ?>>
      <?= htmlspecialchars((string)$documento['cliente']) ?> · <?= htmlspecialchars((string)$documento['nro_documento']) ?>
    </option>
  <?php endforeach; ?>
</select>
<select name="tipo_gestion" required>
  <option value="">Tipo de gestión</option>
  <option value="llamada" <?= $tipoGestion === 'llamada' ? 'selected' : '' ?>>Llamada telefónica</option>
  <option value="correo" <?= $tipoGestion === 'correo' ? 'selected' : '' ?>>Correo electrónico</option>
  <option value="whatsapp" <?= $tipoGestion === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
  <option value="visita" <?= $tipoGestion === 'visita' ? 'selected' : '' ?>>Visita</option>
  <option value="compromiso_pago" <?= $tipoGestion === 'compromiso_pago' ? 'selected' : '' ?>>Compromiso de pago</option>
  <option value="promesa_pago" <?= $tipoGestion === 'promesa_pago' ? 'selected' : '' ?>>Promesa de pago</option>
  <option value="novedad" <?= $tipoGestion === 'novedad' ? 'selected' : '' ?>>Novedad</option>
</select>
<select name="estado_compromiso">
  <option value="pendiente" <?= $estadoCompromiso === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
  <option value="cumplido" <?= $estadoCompromiso === 'cumplido' ? 'selected' : '' ?>>Cumplido</option>
  <option value="incumplido" <?= $estadoCompromiso === 'incumplido' ? 'selected' : '' ?>>Incumplido</option>
</select>
</div>
<div class="row"><textarea name="observacion" placeholder="Observación" required style="width:100%"><?= htmlspecialchars($observacion) ?></textarea></div>
<div class="row">
<input type="date" name="compromiso_pago" value="<?= htmlspecialchars($compromisoPago) ?>">
<input type="number" step="0.01" min="0" name="valor_compromiso" placeholder="Valor comprometido" value="<?= htmlspecialchars($valorCompromiso) ?>">
</div>
<button class="btn">Guardar</button>
<a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('gestion/dashboard.php')) ?>">Dashboard de gestión</a>
</form>
<?php
$content = ob_get_clean();
render_layout('Nueva gestión', $content);
