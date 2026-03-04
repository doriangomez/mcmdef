<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

require_role(['admin', 'analista']);

$documentoId = (int)($_GET['documento_id'] ?? $_POST['documento_id'] ?? 0);
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoGestion = trim($_POST['tipo_gestion'] ?? '');
    $observacion = trim($_POST['observacion'] ?? '');
    $compromisoPago = trim($_POST['compromiso_pago'] ?? '');
    $valorCompromiso = trim($_POST['valor_compromiso'] ?? '');

    if ($documentoId <= 0 || $tipoGestion === '' || $observacion === '') {
        $error = 'Complete documento, tipo de gestión y observación.';
    } else {
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
    }
}

$docScope = portfolio_client_scope_sql('c');
$docStmt = $pdo->prepare(
    'SELECT d.*, c.nombre AS cliente_nombre, c.nit, c.telefono, c.contacto, c.canal, c.regional
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE d.id = ? AND d.estado_documento = "activo"' . $docScope['sql'] . '
     LIMIT 1'
);
$docStmt->execute(array_merge([$documentoId], $docScope['params']));
$documento = $docStmt->fetch();

$docsCliente = [];
$gestiones = [];
if ($documento) {
    $docsStmt = $pdo->prepare(
        'SELECT id, nro_documento, tipo, saldo_pendiente, dias_vencido
         FROM cartera_documentos
         WHERE cliente_id = ? AND estado_documento = "activo"
         ORDER BY saldo_pendiente DESC
         LIMIT 20'
    );
    $docsStmt->execute([(int)$documento['cliente_id']]);
    $docsCliente = $docsStmt->fetchAll() ?: [];

    $histStmt = $pdo->prepare(
        'SELECT g.*, u.nombre AS usuario
         FROM bitacora_gestion g
         INNER JOIN usuarios u ON u.id = g.usuario_id
         WHERE g.id_documento = ?
         ORDER BY g.id DESC
         LIMIT 100'
    );
    $histStmt->execute([$documentoId]);
    $gestiones = $histStmt->fetchAll() ?: [];
}

ob_start(); ?>
<h1>Detalle de cliente y gestión</h1>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$documento): ?>
  <div class="card">Documento no encontrado. <a href="<?= htmlspecialchars(app_url('gestion/bandeja.php')) ?>">Volver a bandeja</a></div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><h3>Información del cliente</h3></div>
    <p><strong>Cliente:</strong> <?= htmlspecialchars((string)$documento['cliente_nombre']) ?> (<?= htmlspecialchars((string)$documento['nit']) ?>)</p>
    <p><strong>Contacto:</strong> <?= htmlspecialchars((string)($documento['contacto'] ?? '-')) ?> · <?= htmlspecialchars((string)($documento['telefono'] ?? '-')) ?></p>
    <p><strong>Canal / Regional:</strong> <?= htmlspecialchars((string)($documento['canal'] ?? '-')) ?> / <?= htmlspecialchars((string)($documento['regional'] ?? '-')) ?></p>
    <p><strong>Saldo total cliente:</strong> $<?= number_format((float)array_sum(array_map(static fn($d) => (float)$d['saldo_pendiente'], $docsCliente)), 2, ',', '.') ?></p>
  </div>

  <div class="card">
    <div class="card-header"><h3>Documentos asociados e historial de mora</h3></div>
    <table class="table">
      <tr><th>Documento</th><th>Tipo</th><th>Saldo</th><th>Días mora</th><th></th></tr>
      <?php foreach ($docsCliente as $doc): ?>
        <tr>
          <td><?= htmlspecialchars((string)$doc['nro_documento']) ?></td>
          <td><?= htmlspecialchars((string)$doc['tipo']) ?></td>
          <td>$<?= number_format((float)$doc['saldo_pendiente'], 2, ',', '.') ?></td>
          <td><?= (int)$doc['dias_vencido'] ?></td>
          <td><a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars(app_url('gestion/detalle.php?documento_id=' . (int)$doc['id'])) ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <form class="card" method="post">
    <input type="hidden" name="documento_id" value="<?= (int)$documentoId ?>">
    <div class="card-header"><h3>Registrar nueva gestión</h3></div>
    <div class="row">
      <input value="<?= htmlspecialchars((string)$documento['cliente_nombre']) ?>" disabled>
      <input value="<?= htmlspecialchars((string)$documento['nro_documento']) ?>" disabled>
      <select name="tipo_gestion" required>
        <option value="">Tipo de gestión</option>
        <option value="llamada">Llamada telefónica</option>
        <option value="correo">Correo electrónico</option>
        <option value="whatsapp">WhatsApp</option>
        <option value="visita">Visita</option>
        <option value="compromiso_pago">Compromiso de pago</option>
        <option value="promesa_pago">Promesa de pago</option>
        <option value="novedad">Novedad</option>
      </select>
    </div>
    <div class="row"><textarea name="observacion" placeholder="Observación de la gestión" required></textarea></div>
    <div class="row">
      <input type="date" name="compromiso_pago">
      <input type="number" step="0.01" min="0" name="valor_compromiso" placeholder="Valor comprometido">
      <button class="btn">Guardar gestión</button>
    </div>
  </form>

  <div class="card">
    <div class="card-header"><h3>Historial de gestiones y compromisos</h3></div>
    <table class="table">
      <tr><th>Fecha</th><th>Tipo</th><th>Observación</th><th>Valor comprometido</th><th>Fecha compromiso</th><th>Estado</th><th>Responsable</th></tr>
      <?php foreach ($gestiones as $gestion): ?>
        <?php [$estadoTexto, $estadoColor] = gestion_commitment_status($gestion['compromiso_pago'] ?? null, (float)$documento['saldo_pendiente']); ?>
        <tr>
          <td><?= htmlspecialchars((string)$gestion['created_at']) ?></td>
          <td><?= htmlspecialchars((string)$gestion['tipo_gestion']) ?></td>
          <td><?= htmlspecialchars((string)$gestion['observacion']) ?></td>
          <td>$<?= number_format((float)($gestion['valor_compromiso'] ?? 0), 2, ',', '.') ?></td>
          <td><?= htmlspecialchars((string)($gestion['compromiso_pago'] ?? '-')) ?></td>
          <td><?= ui_badge($estadoTexto, $estadoColor) ?></td>
          <td><?= htmlspecialchars((string)$gestion['usuario']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_layout('Detalle de gestión', $content);
