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
$clienteId = (int)($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
$ordenDocs = $_GET['orden_docs'] ?? 'mora';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoGestion = trim($_POST['tipo_gestion'] ?? '');
    $observacion = trim($_POST['observacion'] ?? '');
    $compromisoPago = trim($_POST['compromiso_pago'] ?? '');
    $valorCompromiso = trim($_POST['valor_compromiso'] ?? '');
    $estadoCompromiso = trim($_POST['estado_compromiso'] ?? 'pendiente');

    if ($documentoId <= 0 || $tipoGestion === '' || $observacion === '') {
        $error = 'Complete documento, tipo de gestión y observación.';
    } elseif (!in_array($estadoCompromiso, ['pendiente', 'cumplido', 'incumplido'], true)) {
        $error = 'El estado del compromiso no es válido.';
    } else {
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
    }
}

if ($clienteId <= 0 && $documentoId > 0) {
    $clienteLookup = $pdo->prepare('SELECT cliente_id FROM cartera_documentos WHERE id = ? LIMIT 1');
    $clienteLookup->execute([$documentoId]);
    $clienteId = (int)$clienteLookup->fetchColumn();
}

$cliente = null;
$resumen = ['saldo_total' => 0, 'documentos' => 0, 'promedio_mora' => 0];
$documentos = [];
$gestiones = [];

if ($clienteId > 0) {
    $clienteStmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
    $clienteStmt->execute([$clienteId]);
    $cliente = $clienteStmt->fetch() ?: null;

    $resumenStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(saldo_pendiente), 0) AS saldo_total,
            COUNT(*) AS documentos,
            COALESCE(AVG(dias_vencido), 0) AS promedio_mora
         FROM cartera_documentos
         WHERE cliente_id = ? AND estado_documento = "activo"'
    );
    $resumenStmt->execute([$clienteId]);
    $resumen = $resumenStmt->fetch() ?: $resumen;

    $orderSql = $ordenDocs === 'saldo'
        ? 'saldo_pendiente DESC, dias_vencido DESC'
        : 'dias_vencido DESC, saldo_pendiente DESC';

    $docsStmt = $pdo->prepare(
        'SELECT d.id, d.nro_documento, d.tipo, d.saldo_pendiente, d.dias_vencido, d.estado_documento
         FROM cartera_documentos d
         WHERE d.cliente_id = ? AND d.estado_documento = "activo"
         ORDER BY ' . $orderSql
    );
    $docsStmt->execute([$clienteId]);
    $documentos = $docsStmt->fetchAll() ?: [];

    if ($documentoId <= 0 && !empty($documentos)) {
        $documentoId = (int)$documentos[0]['id'];
    }

    $histStmt = $pdo->prepare(
        'SELECT g.*, u.nombre AS usuario, d.nro_documento
         FROM bitacora_gestion g
         INNER JOIN usuarios u ON u.id = g.usuario_id
         INNER JOIN cartera_documentos d ON d.id = g.id_documento
         WHERE d.cliente_id = ?
         ORDER BY g.created_at DESC, g.id DESC
         LIMIT 300'
    );
    $histStmt->execute([$clienteId]);
    $gestiones = $histStmt->fetchAll() ?: [];
}

ob_start(); ?>
<h1>Vista operativa integral del cliente</h1>
<?php if ($documentoId > 0): ?><p class="kpi-subtext">Documento seleccionado para gestionar: <strong>#<?= (int)$documentoId ?></strong></p><?php endif; ?>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$cliente): ?>
  <div class="card">Cliente no encontrado. <a href="<?= htmlspecialchars(app_url('gestion/bandeja.php')) ?>">Volver a bandeja</a></div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><h3>Información del cliente</h3></div>
    <div class="client-grid">
      <p><strong>Cliente:</strong><br><?= htmlspecialchars((string)$cliente['nombre']) ?></p>
      <p><strong>NIT:</strong><br><?= htmlspecialchars((string)$cliente['nit']) ?></p>
      <p><strong>Regional:</strong><br><?= htmlspecialchars((string)($cliente['regional'] ?? '-')) ?></p>
      <p><strong>Canal:</strong><br><?= htmlspecialchars((string)($cliente['canal'] ?? '-')) ?></p>
      <p><strong>Saldo total del cliente:</strong><br>$<?= number_format((float)$resumen['saldo_total'], 2, ',', '.') ?></p>
      <p><strong>Número de documentos:</strong><br><?= (int)$resumen['documentos'] ?></p>
      <p><strong>Promedio mora:</strong><br><?= number_format((float)$resumen['promedio_mora'], 1, ',', '.') ?> días</p>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Documentos del cliente</h3>
      <div>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . $clienteId . '&orden_docs=mora')) ?>">Ordenar por mora</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . $clienteId . '&orden_docs=saldo')) ?>">Ordenar por saldo</a>
      </div>
    </div>
    <table class="table">
      <tr><th>Documento</th><th>Tipo</th><th>Saldo</th><th>Días de mora</th><th>Estado</th><th>Acción</th></tr>
      <?php foreach ($documentos as $doc): ?>
        <tr class="<?= (int)$doc['id'] === $documentoId ? 'selected-document-row' : '' ?>">
          <td><?= htmlspecialchars((string)$doc['nro_documento']) ?></td>
          <td><?= htmlspecialchars((string)$doc['tipo']) ?></td>
          <td>$<?= number_format((float)$doc['saldo_pendiente'], 2, ',', '.') ?></td>
          <td><?= ui_badge((string)((int)$doc['dias_vencido']) . ' días', gestion_mora_badge_variant((int)$doc['dias_vencido'])) ?></td>
          <td><?= htmlspecialchars((string)$doc['estado_documento']) ?></td>
          <td><a class="btn btn-sm" href="<?= htmlspecialchars(app_url('gestion/detalle.php?cliente_id=' . $clienteId . '&documento_id=' . (int)$doc['id'] . '#registro-gestion')) ?>">Gestionar</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <form id="registro-gestion" class="card" method="post">
    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
    <div class="card-header"><h3>Registrar gestión</h3></div>
    <div class="row">
      <select name="documento_id" required>
        <?php foreach ($documentos as $doc): ?>
          <option value="<?= (int)$doc['id'] ?>" <?= (int)$doc['id'] === $documentoId ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$doc['nro_documento']) ?> · $<?= number_format((float)$doc['saldo_pendiente'], 0, ',', '.') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="tipo_gestion" required>
        <option value="">Tipo de gestión</option>
        <option value="llamada">Llamada</option>
        <option value="correo">Correo</option>
        <option value="whatsapp">WhatsApp</option>
        <option value="visita">Visita</option>
        <option value="compromiso_pago">Compromiso de pago</option>
        <option value="promesa_pago">Promesa de pago</option>
        <option value="novedad">Novedad</option>
      </select>
      <select name="estado_compromiso">
        <option value="pendiente">Pendiente</option>
        <option value="cumplido">Cumplido</option>
        <option value="incumplido">Incumplido</option>
      </select>
    </div>
    <div class="row"><textarea name="observacion" placeholder="Observación de la gestión" required></textarea></div>
    <div class="row">
      <input type="date" name="compromiso_pago" placeholder="Fecha compromiso">
      <input type="number" step="0.01" min="0" name="valor_compromiso" placeholder="Valor comprometido">
      <button class="btn">Guardar gestión</button>
    </div>
  </form>

  <div class="card">
    <div class="card-header"><h3>Historial completo de gestiones</h3></div>
    <table class="table">
      <tr><th>Fecha</th><th>Documento</th><th>Tipo gestión</th><th>Observación</th><th>Valor comprometido</th><th>Fecha compromiso</th><th>Estado compromiso</th><th>Responsable</th></tr>
      <?php foreach ($gestiones as $gestion): ?>
        <?php [$estadoTexto, $estadoColor] = gestion_compromiso_estado((string)($gestion['estado_compromiso'] ?? ''), $gestion['compromiso_pago'] ?? null); ?>
        <tr>
          <td><?= htmlspecialchars((string)$gestion['created_at']) ?></td>
          <td><?= htmlspecialchars((string)$gestion['nro_documento']) ?></td>
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
render_layout('Detalle de cliente', $content);
