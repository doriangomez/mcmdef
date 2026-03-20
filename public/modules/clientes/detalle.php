<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ClientService.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

ensure_client_management_schema($pdo);

$id = (int)($_GET['id'] ?? $_GET['id_cliente'] ?? 0);
$scope = portfolio_client_scope_sql('c');

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

$carteraActual = [];
$historial = [];
$resumen = ['saldo_total' => 0, 'documentos' => 0, 'ultima_actividad' => null];

if ($cliente) {
    $resumenStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN estado_documento = "activo" THEN saldo_pendiente ELSE 0 END), 0) AS saldo_total,
            SUM(CASE WHEN estado_documento = "activo" THEN 1 ELSE 0 END) AS documentos,
            MAX(created_at) AS ultima_actividad_doc
         FROM cartera_documentos
         WHERE cliente_id = ?'
    );
    $resumenStmt->execute([$id]);
    $resumen = array_merge($resumen, $resumenStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $carteraStmt = $pdo->prepare(
        'SELECT id, nro_documento, tipo, saldo_pendiente, estado_documento, fecha_vencimiento
         FROM cartera_documentos
         WHERE cliente_id = ?
         ORDER BY estado_documento ASC, fecha_vencimiento ASC, id DESC'
    );
    $carteraStmt->execute([$id]);
    $carteraActual = $carteraStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $histStmt = $pdo->prepare(
        'SELECT h.fecha_evento, h.tipo_evento, h.valor, h.descripcion, d.nro_documento
         FROM cliente_historial h
         LEFT JOIN cartera_documentos d ON d.id = h.documento_id
         WHERE h.cliente_id = ?
         ORDER BY h.fecha_evento DESC, h.id DESC'
    );
    $histStmt->execute([$id]);
    $historial = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$antiguedadDias = null;
if ($cliente && !empty($cliente['fecha_activacion'])) {
    $desde = new DateTimeImmutable((string)$cliente['fecha_activacion']);
    $hoy = new DateTimeImmutable('today');
    $antiguedadDias = (int)$desde->diff($hoy)->format('%a');
}

ob_start();
?>
<?php if (!$cliente): ?>
  <div class="card">Cliente no encontrado o fuera de tu alcance de cartera.</div>
<?php else: ?>
  <div class="card">
    <div class="card-header">
      <h3>Perfil del cliente</h3>
      <?= ui_badge((string)$cliente['estado'], $cliente['estado'] === 'activo' ? 'success' : 'warning') ?>
    </div>
    <div class="client-grid">
      <p><strong>Nombre</strong><br><?= htmlspecialchars((string)$cliente['nombre_mostrar']) ?></p>
      <p><strong>Identificación</strong><br><?= htmlspecialchars((string)$cliente['identificacion_mostrar']) ?></p>
      <p><strong>Fecha activación</strong><br><?= htmlspecialchars((string)$cliente['fecha_activacion']) ?></p>
      <p><strong>Antigüedad</strong><br><?= $antiguedadDias !== null ? $antiguedadDias . ' días' : '-' ?></p>
      <p><strong>Fecha creación</strong><br><?= htmlspecialchars((string)($cliente['fecha_creacion'] ?? $cliente['created_at'] ?? '-')) ?></p>
      <p><strong>Total cartera actual</strong><br>$<?= number_format((float)$resumen['saldo_total'], 2, ',', '.') ?></p>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Cartera actual</h3></div>
    <table class="table">
      <tr><th>Documento</th><th>Tipo</th><th>Saldo</th><th>Estado</th><th>Vencimiento</th></tr>
      <?php foreach ($carteraActual as $doc): ?>
        <tr>
          <td><?= htmlspecialchars((string)$doc['nro_documento']) ?></td>
          <td><?= htmlspecialchars((string)$doc['tipo']) ?></td>
          <td>$<?= number_format((float)$doc['saldo_pendiente'], 2, ',', '.') ?></td>
          <td><?= htmlspecialchars((string)$doc['estado_documento']) ?></td>
          <td><?= htmlspecialchars((string)$doc['fecha_vencimiento']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <div class="card-header"><h3>Historial del cliente</h3></div>
    <ul class="crm-timeline">
      <?php foreach ($historial as $evento): ?>
        <li>
          <div class="crm-timeline-date"><?= htmlspecialchars((string)$evento['fecha_evento']) ?></div>
          <div class="crm-timeline-body">
            <strong><?= htmlspecialchars(ucfirst((string)$evento['tipo_evento'])) ?></strong>
            <?php if ((string)($evento['nro_documento'] ?? '') !== ''): ?>
              <span class="muted">· Documento <?= htmlspecialchars((string)$evento['nro_documento']) ?></span>
            <?php endif; ?>
            <p><?= htmlspecialchars((string)$evento['descripcion']) ?></p>
            <small>Valor: $<?= number_format((float)$evento['valor'], 2, ',', '.') ?></small>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_layout('Detalle de cliente', $content);
