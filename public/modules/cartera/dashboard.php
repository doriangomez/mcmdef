<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';

require_role(['admin', 'analista']);

$baseWhere = ' WHERE d.estado_documento = "activo"';
$baseParams = [];


$globalKpiStmt = $pdo->query(
    'SELECT
        COUNT(*) AS total_documentos,
        COUNT(DISTINCT d.cliente_id) AS total_clientes,
        COALESCE(SUM(d.saldo_pendiente), 0) AS total_cartera
     FROM cartera_documentos d
     WHERE d.estado_documento = "activo"'
);
$globalKpi = $globalKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$lastLoadStmt = $pdo->query(
    'SELECT fecha_carga, periodo_detectado, nombre_archivo
     FROM cargas_cartera
     WHERE estado = "activa" AND activo = 1
     ORDER BY fecha_carga DESC
     LIMIT 1'
);
$lastLoad = $lastLoadStmt->fetch(PDO::FETCH_ASSOC) ?: null;


$globalKpiStmt = $pdo->query(
    'SELECT
        COUNT(*) AS total_documentos,
        COUNT(DISTINCT d.cliente_id) AS total_clientes,
        COALESCE(SUM(d.saldo_pendiente), 0) AS total_cartera
     FROM cartera_documentos d
     WHERE d.estado_documento = "activo"'
);
$globalKpi = $globalKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assignedClientsCount = null;
if (!$isAdmin) {
    $assignedStmt = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE responsable_usuario_id = ?');
    $assignedStmt->execute([(int)(current_user()['id'] ?? 0)]);
    $assignedClientsCount = (int)$assignedStmt->fetchColumn();
}

$lastLoadStmt = $pdo->query(
    'SELECT fecha_carga, periodo_detectado, nombre_archivo
     FROM cargas_cartera
     WHERE estado = "activa" AND activo = 1
     ORDER BY fecha_carga DESC
     LIMIT 1'
);
$lastLoad = $lastLoadStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$kpiStmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_documentos,
        COUNT(DISTINCT d.cliente_id) AS total_clientes,
        COALESCE(SUM(d.saldo_pendiente), 0) AS total_cartera,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS cartera_vencida,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS cartera_critica,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN 1 ELSE 0 END), 0) AS documentos_vencidos
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere
);
$kpiStmt->execute($baseParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$moraStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN d.dias_vencido <= 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS vigente,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 1 AND 30 THEN d.saldo_pendiente ELSE 0 END), 0) AS b1_30,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 31 AND 60 THEN d.saldo_pendiente ELSE 0 END), 0) AS b31_60,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 61 AND 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS b61_90,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 90 THEN d.saldo_pendiente ELSE 0 END), 0) AS b90_plus
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere
);
$moraStmt->execute($baseParams);
$mora = $moraStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$uenStmt = $pdo->prepare(
    'SELECT
        COALESCE(NULLIF(TRIM(d.uens), ""), "Sin UEN") AS etiqueta,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS total
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY etiqueta
     ORDER BY total DESC
     LIMIT 12'
);
$uenStmt->execute($baseParams);
$uenRows = $uenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$canalStmt = $pdo->prepare(
    'SELECT
        COALESCE(NULLIF(TRIM(COALESCE(NULLIF(d.canal, ""), c.canal)), ""), "Sin canal") AS etiqueta,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS total
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY etiqueta
     ORDER BY total DESC
     LIMIT 12'
);
$canalStmt->execute($baseParams);
$canalRows = $canalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vendedorStmt = $pdo->prepare(
    'SELECT
        COALESCE(NULLIF(TRIM(c.empleado_ventas), ""), "Sin vendedor") AS etiqueta,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS total
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY etiqueta
     ORDER BY total DESC
     LIMIT 12'
);
$vendedorStmt->execute($baseParams);
$vendedorRows = $vendedorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$clienteCarteraStmt = $pdo->prepare(
    'SELECT
        COALESCE(NULLIF(TRIM(c.nombre), ""), NULLIF(TRIM(d.cliente), ""), CONCAT("Cliente #", d.cliente_id)) AS nombre,
        COUNT(*) AS documentos,
        COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS saldo_vencido
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY d.cliente_id, nombre
     ORDER BY saldo_total DESC
     LIMIT 10'
);
$clienteCarteraStmt->execute($baseParams);
$clienteCarteraRows = $clienteCarteraStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$documentoStmt = $pdo->prepare(
    'SELECT
        d.nro_documento,
        COALESCE(NULLIF(TRIM(c.nombre), ""), NULLIF(TRIM(d.cliente), ""), CONCAT("Cliente #", d.cliente_id)) AS cliente,
        d.fecha_vencimiento,
        d.dias_vencido,
        d.saldo_pendiente
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     ORDER BY d.saldo_pendiente DESC
     LIMIT 12'
);
$documentoStmt->execute($baseParams);
$documentoRows = $documentoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$paretoStmt = $pdo->prepare(
    'SELECT
        d.cliente_id AS id,
        COALESCE(NULLIF(TRIM(c.nombre), ""), NULLIF(TRIM(d.cliente), ""), CONCAT("Cliente #", d.cliente_id)) AS nombre,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS saldo_vencido
     FROM cartera_documentos d
     LEFT JOIN clientes c ON c.id = d.cliente_id' . $baseWhere . '
     GROUP BY d.cliente_id, nombre
     HAVING saldo_vencido > 0
     ORDER BY saldo_vencido DESC
     LIMIT 15'
);
$paretoStmt->execute($baseParams);
$paretoRows = $paretoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$compromisosStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN bg.estado_compromiso = "pendiente" THEN 1 ELSE 0 END), 0) AS pendientes,
        COALESCE(SUM(CASE WHEN bg.estado_compromiso = "cumplido" THEN 1 ELSE 0 END), 0) AS cumplidos,
        COALESCE(SUM(CASE WHEN bg.estado_compromiso = "incumplido" THEN 1 ELSE 0 END), 0) AS incumplidos,
        COALESCE(SUM(CASE WHEN bg.estado_compromiso = "pendiente" THEN bg.valor_compromiso ELSE 0 END), 0) AS valor_pendiente
     FROM bitacora_gestion bg
     INNER JOIN cartera_documentos d ON d.id = bg.id_documento
     LEFT JOIN clientes c ON c.id = d.cliente_id
     WHERE d.estado_documento = "activo"
'
);
$compromisosStmt->execute($baseParams);
$compromisos = $compromisosStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$actividadStmt = $pdo->prepare(
    'SELECT
        bg.created_at,
        bg.tipo_gestion,
        bg.estado_compromiso,
        d.nro_documento,
        COALESCE(NULLIF(TRIM(c.nombre), ""), NULLIF(TRIM(d.cliente), ""), CONCAT("Cliente #", d.cliente_id)) AS cliente
     FROM bitacora_gestion bg
     INNER JOIN cartera_documentos d ON d.id = bg.id_documento
     LEFT JOIN clientes c ON c.id = d.cliente_id
     WHERE d.estado_documento = "activo"
     ORDER BY bg.created_at DESC
     LIMIT 10'
);
$actividadStmt->execute($baseParams);
$actividadRows = $actividadStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalVencido = (float)($kpi['cartera_vencida'] ?? 0);
$totalCartera = (float)($kpi['total_cartera'] ?? 0);
$totalDocumentos = (int)($kpi['total_documentos'] ?? 0);
$documentosVencidos = (int)($kpi['documentos_vencidos'] ?? 0);
$carteraCritica = (float)($kpi['cartera_critica'] ?? 0);
$topCliente = $paretoRows[0] ?? null;
$dependenciaMayor = ($topCliente !== null && $totalVencido > 0)
    ? (((float)$topCliente['saldo_vencido'] / $totalVencido) * 100)
    : 0.0;
$severidadMora = $totalVencido > 0 ? ($carteraCritica / $totalVencido) * 100 : 0.0;
$documentosVencidosPct = $totalDocumentos > 0 ? ($documentosVencidos / $totalDocumentos) * 100 : 0.0;
$top5Suma = 0.0;
foreach (array_slice($paretoRows, 0, 5) as $row) {
    $top5Suma += (float)$row['saldo_vencido'];
}
$concentracionTop5 = $totalVencido > 0 ? ($top5Suma / $totalVencido) * 100 : 0.0;
$topClienteParticipacion = $dependenciaMayor;

$chartEdad = [
    (float)($mora['vigente'] ?? 0),
    (float)($mora['b1_30'] ?? 0),
    (float)($mora['b31_60'] ?? 0),
    (float)($mora['b61_90'] ?? 0),
    (float)($mora['b90_plus'] ?? 0),
];

$uenLabels = array_column($uenRows, 'etiqueta');
$uenValues = array_map('floatval', array_column($uenRows, 'total'));
$canalLabels = array_column($canalRows, 'etiqueta');
$canalValues = array_map('floatval', array_column($canalRows, 'total'));
$vendedorLabels = array_column($vendedorRows, 'etiqueta');
$vendedorValues = array_map('floatval', array_column($vendedorRows, 'total'));

$paretoLabels = [];
$paretoValues = [];
$paretoAcumulado = [];
$acumulado = 0.0;
foreach ($paretoRows as $row) {
    $valor = (float)$row['saldo_vencido'];
    $paretoLabels[] = $row['nombre'];
    $paretoValues[] = $valor;
    $acumulado += $valor;
    $paretoAcumulado[] = $totalVencido > 0 ? round(($acumulado / $totalVencido) * 100, 2) : 0;
}

$globalDocs = (int)($globalKpi['total_documentos'] ?? 0);
$globalSaldo = (float)($globalKpi['total_cartera'] ?? 0);
$globalClientes = (int)($globalKpi['total_clientes'] ?? 0);
$sinCarteraActiva = $globalDocs === 0;

ob_start();
?>
<section class="card cartera-dashboard-head">
  <div>
    <h2>Dashboard de Gestión de Cartera</h2>
    <p class="kpi-subtext">Rediseño funcional: el tablero inicia con cartera total cargada. Recaudo y presupuesto quedan como capas adicionales.</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
    <span class="badge" style="background:#f59e0b; color:#111827;">Vista total de cartera (sin filtros)</span>
    <a class="btn" href="<?= htmlspecialchars(app_url('api/cartera/analisis-export.php')) ?>">Descargar análisis de cartera (Excel XLSX)</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/lista.php')) ?>">Ir a cartera detallada</a>
  </div>
</section>

<div class="gd-view-switch" role="tablist" aria-label="Vistas del dashboard">
  <button class="gd-view-btn is-active" data-view="ejecutivo" role="tab" aria-selected="true">Vista ejecutiva</button>
  <button class="gd-view-btn" data-view="operativo" role="tab" aria-selected="false">Vista operativa</button>
</div>

<section class="card" style="margin-bottom:16px;">
  <h3 style="margin-top:0;">Estado de datos cargados</h3>
  <p style="margin:0 0 8px; color:#334155;">Documentos activos (global): <strong><?= number_format($globalDocs, 0, ',', '.') ?></strong> · Clientes con cartera (global): <strong><?= number_format($globalClientes, 0, ',', '.') ?></strong> · Saldo global: <strong>$<?= number_format($globalSaldo, 0, ',', '.') ?></strong>.</p>
  <p style="margin:0; color:#334155;">Datos visibles en dashboard: <strong><?= number_format($totalDocumentos, 0, ',', '.') ?></strong> documentos activos.</p>
  <?php if ($lastLoad): ?>
    <p style="margin:8px 0 0; color:#334155;">Última carga activa: <strong><?= htmlspecialchars((string)$lastLoad['fecha_carga']) ?></strong><?php if (!empty($lastLoad['periodo_detectado'])): ?> · Periodo: <strong><?= htmlspecialchars((string)$lastLoad['periodo_detectado']) ?></strong><?php endif; ?> · Archivo: <strong><?= htmlspecialchars((string)$lastLoad['nombre_archivo']) ?></strong>.</p>
  <?php endif; ?>
  <?php if ($sinCarteraActiva): ?>
    <p style="margin:10px 0 0; color:#b45309; font-weight:600;">No hay cartera activa disponible. Se necesita una carga de cartera activa para poblar el dashboard.</p>
  <?php endif; ?>
</section>

<section class="gd-view-panel is-active" data-view="ejecutivo">
  <section class="gd-kpi-grid gd-kpi-grid-wide">
    <article class="gd-kpi-card"><span>Total recaudo</span><strong>Pendiente carga de recaudo</strong></article>
    <article class="gd-kpi-card"><span>Total presupuesto</span><strong>Pendiente carga de presupuesto</strong></article>
    <article class="gd-kpi-card"><span>Cartera total</span><strong>$<?= number_format($totalCartera, 0, ',', '.') ?></strong></article>
    <article class="gd-kpi-card"><span>Cartera crítica (&gt;90 días)</span><strong>$<?= number_format($carteraCritica, 0, ',', '.') ?></strong></article>
    <article class="gd-kpi-card"><span>Índice severidad de mora</span><strong><?= number_format($severidadMora, 2, ',', '.') ?>%</strong></article>
    <article class="gd-kpi-card"><span>% documentos vencidos</span><strong><?= number_format($documentosVencidosPct, 2, ',', '.') ?>%</strong></article>
    <article class="gd-kpi-card"><span>Concentración Top 5 clientes</span><strong><?= number_format($concentracionTop5, 2, ',', '.') ?>%</strong></article>
    <article class="gd-kpi-card"><span>Dependencia cliente mayor</span><strong><?= number_format($dependenciaMayor, 2, ',', '.') ?>%</strong></article>
    <article class="gd-kpi-card"><span>Cliente mayor participación</span><strong><?= htmlspecialchars($topCliente['nombre'] ?? 'Sin datos') ?> (<?= number_format($topClienteParticipacion, 2, ',', '.') ?>%)</strong></article>
  </section>

  <section class="gd-grid-2">
    <article class="card gd-chart-card">
      <h3>Distribución por edad de cartera</h3>
      <canvas id="edadChart" height="150"></canvas>
    </article>
    <article class="card gd-chart-card">
      <h3>Pareto de clientes (cartera vencida)</h3>
      <canvas id="paretoChart" height="150"></canvas>
    </article>
  </section>
</section>

<section class="gd-view-panel" data-view="operativo">
  <section class="gd-grid-2">
    <article class="card gd-chart-card">
      <h3>Cartera por canal</h3>
      <canvas id="canalChart" height="150"></canvas>
    </article>
    <article class="card gd-chart-card">
      <h3>Cartera por UEN</h3>
      <canvas id="uenChart" height="150"></canvas>
    </article>
  </section>

  <section class="card gd-chart-card" style="margin-bottom:16px;">
    <h3>Cartera por vendedor</h3>
    <canvas id="vendedorChart" height="110"></canvas>
  </section>

  <section class="gd-grid-2">
    <article class="card gd-table-card">
      <h3>Cartera por cliente</h3>
      <table class="gd-table">
        <thead><tr><th>Cliente</th><th>Docs</th><th>Saldo total</th><th>Saldo vencido</th></tr></thead>
        <tbody>
        <?php foreach ($clienteCarteraRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['nombre']) ?></td>
            <td><?= (int)$row['documentos'] ?></td>
            <td>$<?= number_format((float)$row['saldo_total'], 0, ',', '.') ?></td>
            <td>$<?= number_format((float)$row['saldo_vencido'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($clienteCarteraRows)): ?>
          <tr><td colspan="4">Sin datos de cartera por cliente.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </article>
    <article class="card gd-table-card">
      <h3>Cartera por documento</h3>
      <table class="gd-table">
        <thead><tr><th>Documento</th><th>Cliente</th><th>Días vencido</th><th>Saldo</th></tr></thead>
        <tbody>
        <?php foreach ($documentoRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['nro_documento']) ?></td>
            <td><?= htmlspecialchars($row['cliente']) ?></td>
            <td><?= (int)$row['dias_vencido'] ?></td>
            <td>$<?= number_format((float)$row['saldo_pendiente'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($documentoRows)): ?>
          <tr><td colspan="4">Sin documentos para mostrar.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </article>
  </section>

  <section class="gd-grid-2">
    <article class="card">
      <h3>Compromisos</h3>
      <div class="gd-promise-panel">
        <div><span>Pendientes</span><strong><?= (int)($compromisos['pendientes'] ?? 0) ?></strong></div>
        <div><span>Cumplidos</span><strong><?= (int)($compromisos['cumplidos'] ?? 0) ?></strong></div>
        <div><span>Incumplidos</span><strong><?= (int)($compromisos['incumplidos'] ?? 0) ?></strong></div>
      </div>
      <p style="margin:10px 0 0; color:#334155;">Valor pendiente de compromiso: <strong>$<?= number_format((float)($compromisos['valor_pendiente'] ?? 0), 0, ',', '.') ?></strong></p>
      <p style="margin:10px 0 0;"><a href="<?= htmlspecialchars(app_url('gestion/compromisos.php')) ?>">Ir a gestión de compromisos</a></p>
    </article>
    <article class="card gd-table-card">
      <h3>Seguimiento y detalle reciente</h3>
      <table class="gd-table">
        <thead><tr><th>Fecha</th><th>Cliente</th><th>Documento</th><th>Gestión</th></tr></thead>
        <tbody>
        <?php foreach ($actividadRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars((string)$row['created_at']) ?></td>
            <td><?= htmlspecialchars($row['cliente']) ?></td>
            <td><?= htmlspecialchars($row['nro_documento']) ?></td>
            <td><?= htmlspecialchars($row['tipo_gestion']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($actividadRows)): ?>
          <tr><td colspan="4">Sin actividad registrada.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      <p style="margin:10px 0 0;"><a href="<?= htmlspecialchars(app_url('gestion/lista.php')) ?>">Ver seguimiento completo</a></p>
    </article>
  </section>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  var moneyFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  var axisMoney = { ticks: { callback: function (value) { return moneyFormatter.format(value || 0); } } };

  var viewButtons = document.querySelectorAll('.gd-view-btn');
  var panels = document.querySelectorAll('.gd-view-panel');
  viewButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.getAttribute('data-view');
      viewButtons.forEach(function (item) {
        var active = item === btn;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-view') === target);
      });
    });
  });

  new Chart(document.getElementById('edadChart'), {
    type: 'doughnut',
    data: {
      labels: ['Vigente', '1-30', '31-60', '61-90', '+90'],
      datasets: [{
        data: <?= json_encode($chartEdad, JSON_UNESCAPED_UNICODE) ?>,
        backgroundColor: ['#22c55e', '#fde047', '#f59e0b', '#f97316', '#ef4444']
      }]
    },
    options: { plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + moneyFormatter.format(ctx.raw || 0); } } } } }
  });

  new Chart(document.getElementById('uenChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($uenLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ label: 'Cartera vencida', data: <?= json_encode($uenValues, JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#2563eb' }]
    },
    options: { indexAxis: 'y', scales: { x: axisMoney }, plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('canalChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($canalLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ label: 'Cartera vencida', data: <?= json_encode($canalValues, JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#7c3aed' }]
    },
    options: { scales: { y: axisMoney }, plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('vendedorChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($vendedorLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ label: 'Cartera vencida', data: <?= json_encode($vendedorValues, JSON_UNESCAPED_UNICODE) ?>, backgroundColor: '#0ea5e9' }]
    },
    options: { scales: { y: axisMoney }, plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('paretoChart'), {
    data: {
      labels: <?= json_encode($paretoLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{
        type: 'bar',
        label: 'Saldo vencido',
        data: <?= json_encode($paretoValues, JSON_UNESCAPED_UNICODE) ?>,
        backgroundColor: '#f59e0b',
        yAxisID: 'y'
      }, {
        type: 'line',
        label: '% acumulado',
        data: <?= json_encode($paretoAcumulado, JSON_UNESCAPED_UNICODE) ?>,
        borderColor: '#dc2626',
        backgroundColor: '#dc2626',
        yAxisID: 'y1',
        tension: 0.25
      }]
    },
    options: {
      scales: {
        y: axisMoney,
        y1: {
          position: 'right',
          min: 0,
          max: 100,
          grid: { drawOnChartArea: false },
          ticks: { callback: function (value) { return value + '%'; } }
        }
      }
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard de Gestión de Cartera', $content);
