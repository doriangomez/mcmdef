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
$tipoGestion = trim($_POST['tipo_gestion'] ?? '');
$resultadoGestion = trim($_POST['resultado_gestion'] ?? '');
$canalContacto = trim($_POST['canal_contacto'] ?? '');
$observacionInput = trim($_POST['observacion'] ?? '');
$compromisoPagoInput = trim($_POST['compromiso_pago'] ?? '');
$valorCompromisoInput = trim($_POST['valor_compromiso'] ?? '');
$estadoCompromisoInput = trim($_POST['estado_compromiso'] ?? 'pendiente');

$tiposGestionDisponibles = [
    'cobranza_preventiva' => 'Cobranza preventiva',
    'seguimiento' => 'Seguimiento',
    'negociacion' => 'Negociación',
    'recordatorio_pago' => 'Recordatorio de pago',
    'escalamiento' => 'Escalamiento',
    'novedad' => 'Novedad',
];

$resultadosDisponibles = [
    'no_contesta' => 'No contesta',
    'numero_equivocado' => 'Número equivocado',
    'promesa_pago' => 'Promesa de pago',
    'pago_realizado' => 'Pago realizado',
    'solicita_plazo' => 'Cliente solicita plazo',
    'requiere_revision' => 'Requiere revisión',
    'contacto_exitoso' => 'Contacto exitoso',
    'contacto_fallido' => 'Contacto fallido',
];

$canalesDisponibles = [
    'llamada' => 'Llamada',
    'whatsapp' => 'WhatsApp',
    'correo' => 'Correo',
    'visita' => 'Visita',
    'sms' => 'SMS',
];

$respuestasRapidas = [
    'Cliente promete pagar mañana.',
    'Cliente no contesta en los intentos realizados.',
    'Cliente solicita plazo adicional para ponerse al día.',
    'Cliente confirma que pagará esta semana.',
    'Número incorrecto, se debe validar dato de contacto.',
    'Se solicita enviar estado de cuenta actualizado.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoGestion = $tipoGestion !== '' ? $tipoGestion : 'seguimiento';
    $resultadoGestion = trim($_POST['resultado_gestion'] ?? '');
    $canalContacto = trim($_POST['canal_contacto'] ?? '');
    $observacion = trim($_POST['observacion'] ?? '');
    $compromisoPago = trim($_POST['compromiso_pago'] ?? '');
    $valorCompromiso = trim($_POST['valor_compromiso'] ?? '');
    $estadoCompromiso = trim($_POST['estado_compromiso'] ?? 'pendiente');
    $hayPromesa = $compromisoPago !== '' || $valorCompromiso !== '';

    if ($hayPromesa && $resultadoGestion === '') {
        $resultadoGestion = 'promesa_pago';
    }

    if (!array_key_exists($tipoGestion, $tiposGestionDisponibles)) {
        $error = 'Seleccione un tipo de gestión válido.';
    } elseif ($resultadoGestion !== '' && !array_key_exists($resultadoGestion, $resultadosDisponibles)) {
        $error = 'Seleccione un resultado de gestión válido.';
    } elseif ($canalContacto !== '' && !array_key_exists($canalContacto, $canalesDisponibles)) {
        $error = 'Seleccione un canal de contacto válido.';
    }

    if ($documentoId <= 0 || $observacion === '') {
        $error = 'Complete documento, tipo de gestión y observación.';
    } elseif (!in_array($estadoCompromiso, ['pendiente', 'cumplido', 'incumplido'], true)) {
        $error = 'El estado del compromiso no es válido.';
    } else {
        $meta = [];
        if ($canalContacto !== '') {
            $meta[] = 'Canal: ' . ($canalesDisponibles[$canalContacto] ?? $canalContacto);
        }
        if ($resultadoGestion !== '') {
            $meta[] = 'Resultado: ' . ($resultadosDisponibles[$resultadoGestion] ?? $resultadoGestion);
        }
        $observacionNormalizada = $meta ? '[' . implode(' | ', $meta) . '] ' . $observacion : $observacion;

        $insert = $pdo->prepare(
            'INSERT INTO bitacora_gestion
             (id_documento, usuario_id, tipo_gestion, observacion, compromiso_pago, valor_compromiso, estado_compromiso, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $insert->execute([
            $documentoId,
            (int)$_SESSION['user']['id'],
            $tipoGestion,
            $observacionNormalizada,
            $compromisoPago !== '' ? $compromisoPago : null,
            $valorCompromiso !== '' ? $valorCompromiso : null,
            $hayPromesa ? $estadoCompromiso : null,
        ]);
        $gestionId = (int)$pdo->lastInsertId();
        audit_log($pdo, 'bitacora_gestion', $gestionId, 'gestion_creada', null, 'ok', (int)$_SESSION['user']['id']);
        $msg = 'Gestión registrada correctamente.';
        $tipoGestion = '';
        $resultadoGestion = '';
        $canalContacto = '';
        $observacionInput = '';
        $compromisoPagoInput = '';
        $valorCompromisoInput = '';
        $estadoCompromisoInput = 'pendiente';
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
$documentoSeleccionado = null;

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
        'SELECT d.id, d.cuenta, d.nro_documento, d.tipo, d.saldo_pendiente, d.dias_vencido, d.estado_documento
         FROM cartera_documentos d
         WHERE d.cliente_id = ? AND d.estado_documento = "activo"
         ORDER BY ' . $orderSql
    );
    $docsStmt->execute([$clienteId]);
    $documentos = $docsStmt->fetchAll() ?: [];

    if ($documentoId <= 0 && !empty($documentos)) {
        $documentoId = (int)$documentos[0]['id'];
    }

    foreach ($documentos as $doc) {
        if ((int)$doc['id'] === $documentoId) {
            $documentoSeleccionado = $doc;
            break;
        }
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
    <?php
      $ultimaGestion = $gestiones[0] ?? null;
      $ultimaGestionTexto = $ultimaGestion ? ((string)$ultimaGestion['created_at']) . ' · ' . (string)$ultimaGestion['tipo_gestion'] : 'Sin gestión registrada';
    ?>
    <style>
      .gestion-documento-panel { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:14px; background:linear-gradient(180deg,#f8fbff 0%,#ffffff 100%); border:1px solid #dbe4f1; border-radius:14px; padding:14px; }
      .gestion-documento-panel p { margin:0; color:#334155; }
      .gestion-documento-panel strong { display:block; font-size:12px; letter-spacing:.03em; text-transform:uppercase; color:#64748b; margin-bottom:4px; }
      .gestion-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px,1fr)); gap:10px; }
      .gestion-subcard { margin-top:14px; border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#fcfdff; }
      .gestion-subcard h4 { margin:0 0 10px; font-size:14px; color:#1e3a8a; }
      .quick-replies { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 10px; }
      .quick-replies .btn { padding:7px 10px; font-size:12px; }
      textarea[name="observacion"] { min-height:150px; }
      .timeline { list-style:none; margin:0; padding:0; }
      .timeline li { border-left:3px solid #dbeafe; padding:0 0 10px 12px; margin-left:8px; position:relative; }
      .timeline li::before { content:''; width:10px; height:10px; border-radius:50%; background:#2563eb; position:absolute; left:-7px; top:5px; }
      .timeline-meta { color:#64748b; font-size:12px; margin-bottom:4px; }
    </style>
    <div class="gestion-documento-panel">
      <p><strong>Cliente</strong><?= htmlspecialchars((string)$cliente['nombre']) ?></p>
      <p><strong>Cuenta</strong><?= htmlspecialchars((string)($documentoSeleccionado['cuenta'] ?? $cliente['cuenta'] ?? '-')) ?></p>
      <p><strong>Documento</strong><?= htmlspecialchars((string)($documentoSeleccionado['nro_documento'] ?? '-')) ?></p>
      <p><strong>Valor deuda</strong>$<?= number_format((float)($documentoSeleccionado['saldo_pendiente'] ?? 0), 0, ',', '.') ?></p>
      <p><strong>Días de mora</strong><?= (int)($documentoSeleccionado['dias_vencido'] ?? 0) ?> días</p>
      <p><strong>Teléfono</strong><?= htmlspecialchars((string)($cliente['telefono'] ?? '-')) ?></p>
      <p><strong>Última gestión</strong><?= htmlspecialchars($ultimaGestionTexto) ?></p>
    </div>
    <div class="gestion-grid">
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
        <?php foreach ($tiposGestionDisponibles as $tipoId => $tipoLabel): ?>
          <option value="<?= htmlspecialchars($tipoId) ?>" <?= $tipoGestion === $tipoId ? 'selected' : '' ?>><?= htmlspecialchars($tipoLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="resultado_gestion">
        <option value="">Resultado de gestión</option>
        <?php foreach ($resultadosDisponibles as $resultadoId => $resultadoLabel): ?>
          <option value="<?= htmlspecialchars($resultadoId) ?>" <?= $resultadoGestion === $resultadoId ? 'selected' : '' ?>><?= htmlspecialchars($resultadoLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="canal_contacto">
        <option value="">Canal de contacto</option>
        <?php foreach ($canalesDisponibles as $canalId => $canalLabel): ?>
          <option value="<?= htmlspecialchars($canalId) ?>" <?= $canalContacto === $canalId ? 'selected' : '' ?>><?= htmlspecialchars($canalLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    </div>
    <div class="gestion-subcard">
      <h4>Acuerdo de pago</h4>
      <div class="row">
        <input type="date" name="compromiso_pago" placeholder="Fecha compromiso" value="<?= htmlspecialchars($compromisoPagoInput) ?>">
        <input type="number" step="0.01" min="0" name="valor_compromiso" placeholder="Valor comprometido" value="<?= htmlspecialchars($valorCompromisoInput) ?>">
        <select name="estado_compromiso">
          <option value="pendiente" <?= $estadoCompromisoInput === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
          <option value="cumplido" <?= $estadoCompromisoInput === 'cumplido' ? 'selected' : '' ?>>Cumplido</option>
          <option value="incumplido" <?= $estadoCompromisoInput === 'incumplido' ? 'selected' : '' ?>>Incumplido</option>
        </select>
      </div>
      <small style="color:#64748b;">Si registra fecha o valor, se considerará automáticamente como promesa de pago.</small>
    </div>
    <div class="gestion-subcard">
      <h4>Respuestas rápidas</h4>
      <div class="quick-replies">
        <?php foreach ($respuestasRapidas as $respuesta): ?>
          <button type="button" class="btn btn-secondary btn-sm quick-reply" data-text="<?= htmlspecialchars($respuesta) ?>"><?= htmlspecialchars($respuesta) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="row"><textarea name="observacion" id="observacionGestion" placeholder="Observación de la gestión" rows="6" required><?= htmlspecialchars($observacionInput) ?></textarea></div>
      <div class="row"><button class="btn">Guardar gestión</button></div>
    </div>
    <script>
      document.querySelectorAll('.quick-reply').forEach((button) => {
        button.addEventListener('click', () => {
          const textarea = document.getElementById('observacionGestion');
          const text = button.dataset.text || '';
          if (!textarea) return;
          textarea.value = textarea.value.trim() ? `${textarea.value.trim()}\n${text}` : text;
          textarea.focus();
        });
      });
    </script>
  </form>

  <div class="card">
    <div class="card-header"><h3>Historial de gestiones (timeline)</h3></div>
    <ul class="timeline">
      <?php foreach ($gestiones as $gestion): ?>
        <?php [$estadoTexto, $estadoColor] = gestion_compromiso_estado((string)($gestion['estado_compromiso'] ?? ''), $gestion['compromiso_pago'] ?? null); ?>
        <li>
          <div class="timeline-meta"><?= htmlspecialchars((string)$gestion['created_at']) ?> · <?= htmlspecialchars((string)$gestion['usuario']) ?> · <?= htmlspecialchars((string)$gestion['tipo_gestion']) ?></div>
          <div><strong>Observación:</strong> <?= nl2br(htmlspecialchars((string)$gestion['observacion'])) ?></div>
          <div><strong>Compromiso:</strong> <?= ui_badge($estadoTexto, $estadoColor) ?> · <?= htmlspecialchars((string)($gestion['compromiso_pago'] ?? '-')) ?> · $<?= number_format((float)($gestion['valor_compromiso'] ?? 0), 0, ',', '.') ?></div>
        </li>
      <?php endforeach; ?>
      <?php if (empty($gestiones)): ?>
        <li><div class="timeline-meta">Sin gestiones registradas para este cliente.</div></li>
      <?php endif; ?>
    </ul>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_layout('Detalle de cliente', $content);
