<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_once __DIR__ . '/../../../app/services/ClientService.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

ensure_client_management_schema($pdo);

$id = (int)($_GET['id'] ?? $_GET['id_cliente'] ?? $_POST['id'] ?? 0);
$historyOffset = max(0, (int)($_GET['historial_offset'] ?? 0));
$historyLimit = 20;
$portfolioOffset = max(0, (int)($_GET['cartera_offset'] ?? 0));
$portfolioLimit = 20;
$scope = portfolio_client_scope_sql('c');
$user = current_user() ?? [];
$canEditContact = in_array((string)($user['rol'] ?? ''), ['admin', 'analista'], true);
$isAdmin = (string)($user['rol'] ?? '') === 'admin';
$msg = '';
$error = '';

$clienteStmt = $pdo->prepare(
    'SELECT c.*,
            COALESCE(NULLIF(c.nombre_cliente, ""), c.nombre) AS nombre_mostrar,
            COALESCE(NULLIF(c.nro_identificacion, ""), c.nit) AS identificacion_mostrar
     FROM clientes c
     WHERE c.id = ?' . $scope['sql'] . '
     LIMIT 1'
);
$clienteStmt->execute(array_merge([$id], $scope['params']));
$cliente = $clienteStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$cliente) {
        $error = 'Cliente no encontrado o fuera de tu alcance de cartera.';
    } elseif (!$canEditContact) {
        $error = 'Tu rol actual no tiene permisos para editar datos del cliente.';
    } else {
        try {
            $pdo->beginTransaction();
            $result = client_update_manual_fields($pdo, $id, [
                'direccion' => trim((string)($_POST['direccion'] ?? '')),
                'contacto' => trim((string)($_POST['contacto'] ?? '')),
                'telefono' => trim((string)($_POST['telefono'] ?? '')),
                'estado' => trim((string)($_POST['estado'] ?? (string)($cliente['estado'] ?? 'activo'))),
            ], (int)($user['id'] ?? 0), $isAdmin);

            if (!empty($result['changes'])) {
                foreach ($result['changes'] as $field => $values) {
                    audit_log(
                        $pdo,
                        'clientes',
                        $id,
                        $field,
                        (string)($values['old'] ?? null),
                        (string)($values['new'] ?? null),
                        (int)($user['id'] ?? 0)
                    );
                }
                $msg = 'Datos del cliente actualizados correctamente.';
            } else {
                $msg = 'No se detectaron cambios para guardar.';
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'No fue posible actualizar el cliente: ' . $exception->getMessage();
        }

        $clienteStmt->execute(array_merge([$id], $scope['params']));
        $cliente = $clienteStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$carteraActual = [];
$historial = [];
$resumen = ['saldo_total' => 0, 'documentos' => 0, 'ultima_actividad' => null];
$totalHistorial = 0;
$totalDocumentosActivos = 0;

if ($cliente) {
    $resumenStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN estado_documento = "activo" THEN saldo_pendiente ELSE 0 END), 0) AS saldo_total,
            SUM(CASE WHEN estado_documento = "activo" THEN 1 ELSE 0 END) AS documentos,
            GREATEST(
                COALESCE(MAX(created_at), "1900-01-01 00:00:00"),
                COALESCE(MAX(fecha_contabilizacion), "1900-01-01")
            ) AS ultima_actividad_doc
         FROM cartera_documentos
         WHERE cliente_id = ?'
    );
    $resumenStmt->execute([$id]);
    $resumen = array_merge($resumen, $resumenStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $documentCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM cartera_documentos
         WHERE cliente_id = ? AND estado_documento = "activo"'
    );
    $documentCountStmt->execute([$id]);
    $totalDocumentosActivos = (int)$documentCountStmt->fetchColumn();

    $carteraStmt = $pdo->prepare(
        'SELECT id, nro_documento, tipo, fecha_contabilizacion, fecha_vencimiento, valor_documento, saldo_pendiente, dias_vencido
         FROM cartera_documentos
         WHERE cliente_id = ? AND estado_documento = "activo"
         ORDER BY dias_vencido DESC, saldo_pendiente DESC, fecha_vencimiento ASC
         LIMIT ' . $portfolioLimit . ' OFFSET ' . $portfolioOffset
    );
    $carteraStmt->execute([$id]);
    $carteraActual = $carteraStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalHistorialStmt = $pdo->prepare('SELECT COUNT(*) FROM cliente_historial WHERE cliente_id = ?');
    $totalHistorialStmt->execute([$id]);
    $totalHistorial = (int)$totalHistorialStmt->fetchColumn();

    $histStmt = $pdo->prepare(
        'SELECT h.id, h.fecha_evento, h.tipo_evento, h.valor, h.descripcion, d.nro_documento
         FROM cliente_historial h
         LEFT JOIN cartera_documentos d ON d.id = h.documento_id
         WHERE h.cliente_id = ?
         ORDER BY h.fecha_evento DESC, h.id DESC
         LIMIT ' . $historyLimit . ' OFFSET ' . $historyOffset
    );
    $histStmt->execute([$id]);
    $historial = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$baseDetailQuery = [
    'id' => $id,
    'historial_offset' => $historyOffset,
    'cartera_offset' => $portfolioOffset,
];
$buildDetailUrl = static function (array $overrides) use ($baseDetailQuery): string {
    return app_url('clientes/detalle.php?' . http_build_query(array_merge($baseDetailQuery, $overrides)));
};

ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$cliente): ?>
  <div class="card">Cliente no encontrado o fuera de tu alcance de cartera.</div>
<?php else: ?>
  <div class="card">
    <div class="card-header">
      <h3>Perfil del cliente</h3>
      <?= ui_badge((string)$cliente['estado'], $cliente['estado'] === 'activo' ? 'success' : 'warning') ?>
    </div>
    <p class="muted">Los campos provenientes del archivo SAP son de solo lectura y se actualizan automáticamente en cada carga de cartera.</p>
    <div class="client-grid client-grid-compact">
      <p><strong>Código cuenta SAP</strong><br><?= htmlspecialchars((string)$cliente['cuenta']) ?></p>
      <p><strong>Nombre</strong><br><?= htmlspecialchars((string)$cliente['nombre_mostrar']) ?></p>
      <p><strong>NIT</strong><br><?= htmlspecialchars((string)$cliente['identificacion_mostrar']) ?></p>
      <p><strong>Canal</strong><br><?= htmlspecialchars((string)($cliente['canal'] ?? '-')) ?></p>
      <p><strong>UEN</strong><br><?= htmlspecialchars((string)($cliente['uen'] ?? '-')) ?></p>
      <p><strong>Regional</strong><br><?= htmlspecialchars((string)($cliente['regional'] ?? '-')) ?></p>
      <p><strong>Empleado de ventas</strong><br><?= htmlspecialchars((string)($cliente['empleado_ventas'] ?? '-')) ?></p>
      <p><strong>Fecha de activación</strong><br><?= htmlspecialchars((string)($cliente['fecha_activacion'] ?? '-')) ?></p>
      <p><strong>Antigüedad</strong><br><?= htmlspecialchars(client_antiquity_label((string)($cliente['fecha_activacion'] ?? ''))) ?></p>
      <p><strong>Fecha de creación</strong><br><?= htmlspecialchars((string)($cliente['fecha_creacion'] ?? $cliente['created_at'] ?? '-')) ?></p>
      <p><strong>Saldo cartera activa</strong><br>$<?= number_format((float)$resumen['saldo_total'], 2, ',', '.') ?></p>
      <p><strong>Documentos activos</strong><br><?= (int)$resumen['documentos'] ?></p>
    </div>
  </div>

  <form class="card" method="post">
    <input type="hidden" name="id" value="<?= (int)$cliente['id'] ?>">
    <div class="card-header">
      <h3>Editar datos de contacto</h3>
      <?php if ($canEditContact): ?>
        <button class="btn btn-sm" type="submit">Guardar cambios</button>
      <?php endif; ?>
    </div>
    <div class="form-grid-two">
      <label>
        Dirección
        <input type="text" name="direccion" value="<?= htmlspecialchars((string)($cliente['direccion'] ?? '')) ?>" <?= $canEditContact ? '' : 'disabled' ?>>
      </label>
      <label>
        Persona de contacto
        <input type="text" name="contacto" value="<?= htmlspecialchars((string)($cliente['contacto'] ?? '')) ?>" <?= $canEditContact ? '' : 'disabled' ?>>
      </label>
      <label>
        Teléfono
        <input type="text" name="telefono" value="<?= htmlspecialchars((string)($cliente['telefono'] ?? '')) ?>" <?= $canEditContact ? '' : 'disabled' ?>>
      </label>
      <label>
        Estado
        <?php if ($isAdmin): ?>
          <select name="estado">
            <option value="activo" <?= (string)$cliente['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option>
            <option value="inactivo" <?= (string)$cliente['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
          </select>
        <?php else: ?>
          <input type="text" value="<?= htmlspecialchars((string)$cliente['estado']) ?>" disabled>
        <?php endif; ?>
      </label>
    </div>
    <?php if (!$canEditContact): ?>
      <p class="muted">Tu rol es solo lectura para la información de contacto.</p>
    <?php elseif (!$isAdmin): ?>
      <p class="muted">Solo el administrador puede cambiar el estado del cliente.</p>
    <?php endif; ?>
  </form>

  <div class="card table-responsive">
    <div class="card-header">
      <h3>Cartera actual</h3>
      <span class="muted">Mostrando <?= count($carteraActual) ?> de <?= $totalDocumentosActivos ?> documento(s) activos</span>
    </div>
    <table class="table">
      <tr><th>Número</th><th>Tipo</th><th>Fecha contabilización</th><th>Fecha vencimiento</th><th>Valor original</th><th>Saldo pendiente</th><th>Días vencido</th></tr>
      <?php foreach ($carteraActual as $doc): ?>
        <tr>
          <td><?= htmlspecialchars((string)$doc['nro_documento']) ?></td>
          <td><?= htmlspecialchars((string)$doc['tipo']) ?></td>
          <td><?= htmlspecialchars((string)$doc['fecha_contabilizacion']) ?></td>
          <td><?= htmlspecialchars((string)$doc['fecha_vencimiento']) ?></td>
          <td>$<?= number_format((float)$doc['valor_documento'], 2, ',', '.') ?></td>
          <td>$<?= number_format((float)$doc['saldo_pendiente'], 2, ',', '.') ?></td>
          <td><?= (int)$doc['dias_vencido'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($carteraActual)): ?>
        <tr><td colspan="7">No hay documentos activos para este cliente.</td></tr>
      <?php endif; ?>
      <tr>
        <td colspan="5"><strong>Total</strong></td>
        <td><strong>$<?= number_format((float)$resumen['saldo_total'], 2, ',', '.') ?></strong></td>
        <td><strong><?= (int)$resumen['documentos'] ?> doc(s)</strong></td>
      </tr>
    </table>
    <?php if (($portfolioOffset + $portfolioLimit) < $totalDocumentosActivos): ?>
      <div style="margin-top:14px;">
        <a class="btn btn-secondary" href="<?= htmlspecialchars($buildDetailUrl(['cartera_offset' => $portfolioOffset + $portfolioLimit])) ?>">Cargar 20 más documentos</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Historial del cliente</h3>
      <?= ui_badge((string)$totalHistorial . ' eventos', 'info') ?>
    </div>
    <ul class="crm-timeline">
      <?php foreach ($historial as $evento): ?>
        <li>
          <div class="crm-timeline-date"><?= htmlspecialchars((string)$evento['fecha_evento']) ?></div>
          <div class="crm-timeline-body">
            <strong><i class="<?= htmlspecialchars(client_history_icon((string)$evento['tipo_evento'])) ?>"></i> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$evento['tipo_evento']))) ?></strong>
            <?php if ((string)($evento['nro_documento'] ?? '') !== ''): ?>
              <span class="muted">· Documento <?= htmlspecialchars((string)$evento['nro_documento']) ?></span>
            <?php endif; ?>
            <p><?= htmlspecialchars((string)$evento['descripcion']) ?></p>
            <?php if ((float)$evento['valor'] !== 0.0): ?>
              <small>Valor: $<?= number_format((float)$evento['valor'], 2, ',', '.') ?></small>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if (empty($historial)): ?>
        <li><div class="crm-timeline-body"><p>No hay eventos registrados para este cliente.</p></div></li>
      <?php endif; ?>
    </ul>
    <?php if (($historyOffset + $historyLimit) < $totalHistorial): ?>
      <div style="margin-top:14px;">
        <a class="btn btn-secondary" href="<?= htmlspecialchars($buildDetailUrl(['historial_offset' => $historyOffset + $historyLimit])) ?>">Cargar 20 más</a>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_layout('Detalle de cliente', $content);
