<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExportService.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

function cartera_mora_sql(string $range): ?string
{
    if ($range === '0-30') {
        return 'd.dias_vencido BETWEEN 0 AND 30';
    }
    if ($range === '31-60') {
        return 'd.dias_vencido BETWEEN 31 AND 60';
    }
    if ($range === '61-90') {
        return 'd.dias_vencido BETWEEN 61 AND 90';
    }
    if ($range === '91-180') {
        return 'd.dias_vencido BETWEEN 91 AND 180';
    }
    if ($range === '181-360') {
        return 'd.dias_vencido BETWEEN 181 AND 360';
    }
    if ($range === '360+') {
        return 'd.dias_vencido > 360';
    }

    return null;
}

function cartera_mora_badge(int $dias): string
{
    if ($dias <= 30) {
        return ui_badge((string)$dias, 'success');
    }
    if ($dias <= 60) {
        return ui_badge((string)$dias, 'warning');
    }
    if ($dias <= 90) {
        return '<span class="badge badge-mora-orange">' . $dias . '</span>';
    }

    return ui_badge((string)$dias, 'danger');
}

function cartera_is_critical_client(array $row): bool
{
    return (int)($row['total_documentos_vencidos'] ?? 0) >= 5
        || (float)($row['saldo_vencido'] ?? 0) >= 50000000
        || (float)($row['promedio_mora'] ?? 0) >= 90;
}

$filters = [
    'cliente' => trim($_GET['cliente'] ?? ''),
    'cliente_id' => (int)($_GET['cliente_id'] ?? 0),
    'numero' => trim($_GET['numero'] ?? ''),
    'tipo' => trim($_GET['tipo'] ?? ''),
    'canal' => trim($_GET['canal'] ?? ''),
    'regional' => trim($_GET['regional'] ?? ''),
    'mora_rango' => trim($_GET['mora_rango'] ?? ''),
    'estado' => trim($_GET['estado'] ?? 'activo'),
    'vista' => trim($_GET['vista'] ?? 'documento'),
    'export_mode' => trim($_GET['export_mode'] ?? ''),
    'responsable_id' => (int)($_GET['responsable_id'] ?? 0),
];

if (!in_array($filters['vista'], ['documento', 'cliente'], true)) {
    $filters['vista'] = 'documento';
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'clientes') {
    $q = trim($_GET['q'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    if ($q === '') {
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $scope = portfolio_client_scope_sql('c');
    $stmt = $pdo->prepare(
        'SELECT c.id, c.nombre, c.nit
         FROM clientes c
         WHERE (c.nombre LIKE ? OR c.nit LIKE ?)' . $scope['sql'] . '
         ORDER BY c.nombre ASC
         LIMIT 12'
    );
    $term = '%' . $q . '%';
    $stmt->execute(array_merge([$term, $term], $scope['params']));
    echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

$where = [];
$params = [];
$scope = portfolio_client_scope_sql('c');
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
    $params = array_merge($params, $scope['params']);
} elseif ($filters['responsable_id'] > 0) {
    $where[] = 'c.responsable_usuario_id = ?';
    $params[] = $filters['responsable_id'];
}
if ($filters['cliente_id'] > 0) {
    $where[] = 'd.cliente_id = ?';
    $params[] = $filters['cliente_id'];
} elseif ($filters['cliente'] !== '') {
    $where[] = '(d.cliente LIKE ? OR c.nit LIKE ?)';
    $params[] = '%' . $filters['cliente'] . '%';
    $params[] = '%' . $filters['cliente'] . '%';
}
if ($filters['numero'] !== '') {
    $where[] = 'd.nro_documento LIKE ?';
    $params[] = '%' . $filters['numero'] . '%';
}
if ($filters['tipo'] !== '') {
    $where[] = 'd.tipo = ?';
    $params[] = $filters['tipo'];
}
if ($filters['canal'] !== '') {
    $where[] = 'd.canal = ?';
    $params[] = $filters['canal'];
}
if ($filters['regional'] !== '') {
    $where[] = 'd.regional = ?';
    $params[] = $filters['regional'];
}
if ($filters['estado'] !== '') {
    $where[] = 'd.estado_documento = ?';
    $params[] = $filters['estado'];
}
$moraSql = cartera_mora_sql($filters['mora_rango']);
if ($moraSql !== null) {
    $where[] = $moraSql;
}

$sqlBase = ' FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id';
if (!empty($where)) {
    $sqlBase .= ' WHERE ' . implode(' AND ', $where);
}

$estadoWhere = $filters['estado'] === '' ? '' : ' AND d.estado_documento = ' . $pdo->quote($filters['estado']);
$baseScopeSql = ' FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE 1=1' . $scope['sql'] . $estadoWhere;
$tipoStmt = $pdo->prepare('SELECT DISTINCT d.tipo ' . $baseScopeSql . ' ORDER BY d.tipo');
$tipoStmt->execute($scope['params']);
$tipoOptions = $tipoStmt->fetchAll(PDO::FETCH_COLUMN);
$canalStmt = $pdo->prepare('SELECT DISTINCT d.canal ' . $baseScopeSql . ' ORDER BY d.canal');
$canalStmt->execute($scope['params']);
$canalOptions = $canalStmt->fetchAll(PDO::FETCH_COLUMN);
$regionalStmt = $pdo->prepare('SELECT DISTINCT d.regional ' . $baseScopeSql . ' ORDER BY d.regional');
$regionalStmt->execute($scope['params']);
$regionalOptions = $regionalStmt->fetchAll(PDO::FETCH_COLUMN);

$kpiStmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_documentos,
        COUNT(DISTINCT d.cliente_id) AS total_clientes,
        COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total,
        COALESCE(AVG(d.dias_vencido), 0) AS promedio_mora
    ' . $sqlBase
);
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$saldoTotal = (float)($kpi['saldo_total'] ?? 0);

$riskStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN d.dias_vencido <= 30 THEN d.saldo_pendiente ELSE 0 END),0) AS corriente,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 31 AND 60 THEN d.saldo_pendiente ELSE 0 END),0) AS mora_30,
        COALESCE(SUM(CASE WHEN d.dias_vencido BETWEEN 61 AND 90 THEN d.saldo_pendiente ELSE 0 END),0) AS mora_60,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 90 THEN d.saldo_pendiente ELSE 0 END),0) AS mora_90_plus
    ' . $sqlBase
);
$riskStmt->execute($params);
$riskDistribution = $riskStmt->fetch(PDO::FETCH_ASSOC) ?: ['corriente' => 0, 'mora_30' => 0, 'mora_60' => 0, 'mora_90_plus' => 0];

$topExposureStmt = $pdo->prepare(
    'SELECT d.cliente_id, d.cliente, c.nit, COALESCE(SUM(d.saldo_pendiente),0) AS saldo_total
     ' . $sqlBase . '
     GROUP BY d.cliente_id, d.cliente, c.nit
     ORDER BY saldo_total DESC
     LIMIT 10'
);
$topExposureStmt->execute($params);
$topExposure = $topExposureStmt->fetchAll(PDO::FETCH_ASSOC);
$top10Exposure = 0.0;
foreach ($topExposure as $expRow) {
    $top10Exposure += (float)$expRow['saldo_total'];
}
$concentrationPct = $saldoTotal > 0 ? ($top10Exposure / $saldoTotal) * 100 : 0;

$allowedSortDocumento = [
    'id' => 'd.id',
    'nit' => 'c.nit',
    'cliente' => 'd.cliente',
    'tipo' => 'd.tipo',
    'numero' => 'd.nro_documento',
    'saldo' => 'd.saldo_pendiente',
    'mora' => 'd.dias_vencido',
    'estado' => 'd.estado_documento',
    'vencimiento' => 'd.fecha_vencimiento',
];
$allowedSortCliente = [
    'cliente' => 'cliente',
    'saldo' => 'saldo_total',
    'mora' => 'promedio_mora',
    'documentos' => 'total_documentos',
    'vencidos' => 'total_documentos_vencidos',
];

$allowedSort = $filters['vista'] === 'cliente' ? $allowedSortCliente : $allowedSortDocumento;
$sort = $_GET['sort'] ?? ($filters['vista'] === 'cliente' ? 'saldo' : 'id');
$dir = strtolower($_GET['dir'] ?? 'desc');
if (!isset($allowedSort[$sort])) {
    $sort = $filters['vista'] === 'cliente' ? 'saldo' : 'id';
}
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}
$orderBy = $allowedSort[$sort] . ' ' . strtoupper($dir);

$page = max(1, (int)($_GET['page'] ?? 1));
$size = (int)($_GET['size'] ?? 100);
$size = max(1, min(200, $size));
$offset = ($page - 1) * $size;

if ($filters['vista'] === 'cliente') {
    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT d.cliente_id ' . $sqlBase . ' GROUP BY d.cliente_id) z');
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
} else {
    $totalStmt = $pdo->prepare('SELECT COUNT(*)' . $sqlBase);
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
}

$totalPages = max(1, (int)ceil($total / $size));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $size;
}

$rows = [];
$docsByClient = [];
if ($filters['vista'] === 'cliente') {
    $dataStmt = $pdo->prepare(
        'SELECT
            d.cliente_id,
            d.cliente AS cliente,
            c.nit,
            COUNT(*) AS total_documentos,
            SUM(CASE WHEN d.dias_vencido > 0 THEN 1 ELSE 0 END) AS total_documentos_vencidos,
            COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total,
            COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END), 0) AS saldo_vencido,
            COALESCE(AVG(d.dias_vencido), 0) AS promedio_mora
        ' . $sqlBase . '
        GROUP BY d.cliente_id, d.cliente, c.nit
        ORDER BY ' . $orderBy . '
        LIMIT ' . $size . ' OFFSET ' . $offset
    );
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $clientIds = array_map(static fn($r) => (int)$r['cliente_id'], $rows);
    if (!empty($clientIds)) {
        $ph = implode(',', array_fill(0, count($clientIds), '?'));
        $docPreviewStmt = $pdo->prepare(
            'SELECT d.id, d.cliente_id, d.tipo, d.nro_documento, d.saldo_pendiente, d.dias_vencido
             FROM cartera_documentos d
             WHERE d.cliente_id IN (' . $ph . ') AND d.estado_documento = ?
             ORDER BY d.dias_vencido DESC, d.saldo_pendiente DESC
             LIMIT 3000'
        );
        $docParams = $clientIds;
        $docParams[] = $filters['estado'] === '' ? 'activo' : $filters['estado'];
        $docPreviewStmt->execute($docParams);
        foreach ($docPreviewStmt->fetchAll(PDO::FETCH_ASSOC) as $docRaw) {
            $cid = (int)$docRaw['cliente_id'];
            if (!isset($docsByClient[$cid])) {
                $docsByClient[$cid] = [];
            }
            if (count($docsByClient[$cid]) < 8) {
                $docsByClient[$cid][] = $docRaw;
            }
        }
    }
} else {
    $dataStmt = $pdo->prepare(
        'SELECT d.id, d.cliente_id, c.nit, d.cliente AS nombre, d.tipo, d.nro_documento, d.saldo_pendiente, d.dias_vencido, d.estado_documento, d.fecha_vencimiento'
        . $sqlBase
        . ' ORDER BY ' . $orderBy
        . ' LIMIT ' . $size . ' OFFSET ' . $offset
    );
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['export']) && in_array(current_user()['rol'], ['admin', 'analista'], true)) {
    $mode = in_array($filters['export_mode'], ['cliente', 'documento'], true) ? $filters['export_mode'] : 'documento';
    if ($mode === 'cliente') {
        $exportStmt = $pdo->prepare(
            'SELECT c.nit, d.cliente, COUNT(*) AS documentos, COALESCE(SUM(d.saldo_pendiente),0) AS saldo_total, COALESCE(AVG(d.dias_vencido),0) AS promedio_mora, COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) AS saldo_vencido'
            . $sqlBase
            . ' GROUP BY c.nit, d.cliente ORDER BY saldo_total DESC'
        );
        $exportStmt->execute($params);
        export_csv('cartera_resumen_clientes.csv', $exportStmt->fetchAll());
    } else {
        $exportStmt = $pdo->prepare(
            'SELECT c.nit, d.cliente, d.tipo, d.nro_documento, d.fecha_vencimiento, d.saldo_pendiente, d.dias_vencido, d.estado_documento, d.canal, d.regional'
            . $sqlBase
            . ' ORDER BY d.id DESC'
        );
        $exportStmt->execute($params);
        export_csv('cartera_detalle_documentos.csv', $exportStmt->fetchAll());
    }
    exit;
}

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page'], $queryWithoutPage['export'], $queryWithoutPage['export_mode']);
$buildSortUrl = static function (string $column) use ($queryWithoutPage, $sort, $dir): string {
    $nextDir = 'asc';
    if ($sort === $column && $dir === 'asc') {
        $nextDir = 'desc';
    }
    $params = array_merge($queryWithoutPage, ['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
    return app_url('cartera/lista.php?' . http_build_query($params));
};


$responsablesFiltro = $pdo->query("SELECT id, nombre FROM usuarios WHERE estado = 'activo' AND rol IN ('admin', 'analista') ORDER BY nombre")->fetchAll();
ob_start(); ?>
<style>
  .cartera-filter-card { padding: 16px; border:1px solid #dbe4f1; border-radius:16px; background:linear-gradient(180deg,#f8fbff 0%,#fff 100%); margin-bottom:14px; }
  .cartera-filter-title { margin:0 0 10px; font-size:13px; letter-spacing:.04em; text-transform:uppercase; color:#64748b; font-weight:700; }
  .cartera-filter-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(170px,1fr)); gap:10px; align-items:end; }
  .cartera-filter-grid .wide { grid-column: span 2; }
  .cartera-metrics { display:grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 12px; margin-bottom: 14px; }
  .metric-card { background:#fff; border:1px solid #dbe4f1; border-radius:14px; padding:12px 14px; box-shadow: var(--shadow-xs); }
  .metric-label { margin:0; color:#64748b; font-size:12px; font-weight:600; text-transform: uppercase; letter-spacing:.04em; }
  .metric-value { margin:4px 0 0; font-size:22px; font-weight:700; color:#0f2a50; }
  .analytics-grid { display:grid; grid-template-columns: 1.2fr 1fr; gap:12px; margin-bottom:14px; }
  .analytics-card { background:#fff; border:1px solid #dbe4f1; border-radius:14px; padding:12px 14px; }
  .analytics-title { margin:0 0 10px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
  .risk-bars { display:grid; gap:8px; }
  .risk-row { display:grid; grid-template-columns: 120px 1fr 90px; gap:10px; align-items:center; font-size:12px; }
  .risk-track { background:#eef2fa; border-radius:999px; height:10px; overflow:hidden; }
  .risk-fill { height:100%; border-radius:999px; }
  .risk-green{background:#16a34a;} .risk-yellow{background:#eab308;} .risk-orange{background:#f97316;} .risk-red{background:#dc2626;}
  .top-list { margin:0; padding-left:16px; display:grid; gap:7px; }
  .top-list li { font-size:13px; }
  .top-list small { color:#64748b; }
  .table-responsive { overflow-x:auto; }
  .action-links { display:flex; flex-wrap:wrap; gap:6px; }
  .action-links a { font-size:12px; }
  .action-links .btn { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 10px; }
  .badge-mora-orange { background: rgba(249, 115, 22, 0.18); color:#c2410c; border:1px solid rgba(194, 65, 12, 0.22); display:inline-flex; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:600; }
  .critical-row td { background:rgba(239, 68, 68, 0.06); }
  .doc-mini { margin-top:8px; border-top:1px dashed #dbe4f1; padding-top:8px; }
  .doc-mini table { width:100%; font-size:12px; }
  .doc-mini td, .doc-mini th { padding:4px 6px; border-bottom:1px solid #eef2fa; }
  .auto-wrap { position:relative; }
  .autocomplete-list { position:absolute; z-index:15; left:0; right:0; top:calc(100% + 4px); background:#fff; border:1px solid #d0dcec; border-radius:12px; box-shadow: var(--shadow-soft); max-height:240px; overflow:auto; display:none; }
  .autocomplete-item { padding:8px 10px; cursor:pointer; border-bottom:1px solid #edf2fa; }
  .autocomplete-item:last-child { border-bottom:none; }
  .autocomplete-item:hover { background:#f4f8ff; }
  .autocomplete-name { font-weight:600; color:#0f172a; }
  .autocomplete-sub { color:#64748b; font-size:12px; }
</style>

<h1>Consulta de cartera</h1>
<form class="card cartera-filter-card" method="get" id="filtrosCartera">
  <p class="cartera-filter-title">Panel analítico de filtros</p>
  <div class="cartera-filter-grid">
    <div class="auto-wrap wide">
      <label>Cliente / NIT</label>
      <input name="cliente" id="clienteInput" autocomplete="off" placeholder="Buscar por cliente o NIT" value="<?= htmlspecialchars($filters['cliente']) ?>">
      <input type="hidden" name="cliente_id" id="clienteIdInput" value="<?= (int)$filters['cliente_id'] ?>">
      <div class="autocomplete-list" id="clienteSuggest"></div>
    </div>
    <div>
      <label>Número documento</label>
      <input name="numero" placeholder="Ej: 12968" value="<?= htmlspecialchars($filters['numero']) ?>">
    </div>
    <div><label>Tipo</label><select name="tipo"><option value="">Todos</option><?php foreach ($tipoOptions as $option): if (trim((string)$option) === '') { continue; } ?><option value="<?= htmlspecialchars($option) ?>" <?= $filters['tipo'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select></div>
    <div><label>Canal</label><select name="canal"><option value="">Todos</option><?php foreach ($canalOptions as $option): if (trim((string)$option) === '') { continue; } ?><option value="<?= htmlspecialchars($option) ?>" <?= $filters['canal'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select></div>
    <div><label>Regional</label><select name="regional"><option value="">Todas</option><?php foreach ($regionalOptions as $option): if (trim((string)$option) === '') { continue; } ?><option value="<?= htmlspecialchars($option) ?>" <?= $filters['regional'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select></div>
    <div>
      <label>Días mora</label>
      <select name="mora_rango">
        <option value="">Todos</option><option value="0-30" <?= $filters['mora_rango'] === '0-30' ? 'selected' : '' ?>>0 - 30</option><option value="31-60" <?= $filters['mora_rango'] === '31-60' ? 'selected' : '' ?>>31 - 60</option><option value="61-90" <?= $filters['mora_rango'] === '61-90' ? 'selected' : '' ?>>61 - 90</option><option value="91-180" <?= $filters['mora_rango'] === '91-180' ? 'selected' : '' ?>>91 - 180</option><option value="181-360" <?= $filters['mora_rango'] === '181-360' ? 'selected' : '' ?>>181 - 360</option><option value="360+" <?= $filters['mora_rango'] === '360+' ? 'selected' : '' ?>>360+</option>
      </select>
    </div>
    <div><label>Vista</label><select name="vista"><option value="documento" <?= $filters['vista'] === 'documento' ? 'selected' : '' ?>>Por documento</option><option value="cliente" <?= $filters['vista'] === 'cliente' ? 'selected' : '' ?>>Agrupada por cliente</option></select></div>
    <div><label>Estado</label><select name="estado"><option value="activo" <?= $filters['estado'] === 'activo' ? 'selected' : '' ?>>Activos</option><option value="inactivo" <?= $filters['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivos</option><option value="" <?= $filters['estado'] === '' ? 'selected' : '' ?>>Todos</option></select></div>
    <div><label>Tamaño de página</label><select name="size"><?php foreach ([50, 100, 150, 200] as $pageSize): ?><option value="<?= $pageSize ?>" <?= $size === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option><?php endforeach; ?></select></div>
  </div>
</form>

<div class="cartera-metrics">
  <div class="metric-card"><p class="metric-label">Total clientes</p><p class="metric-value"><?= number_format((int)($kpi['total_clientes'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="metric-card"><p class="metric-label">Total documentos</p><p class="metric-value"><?= number_format((int)($kpi['total_documentos'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="metric-card"><p class="metric-label">Saldo total cartera</p><p class="metric-value">$ <?= number_format($saldoTotal, 0, ',', '.') ?></p></div>
  <div class="metric-card"><p class="metric-label">Promedio días de mora</p><p class="metric-value"><?= number_format((float)($kpi['promedio_mora'] ?? 0), 1, ',', '.') ?></p></div>
</div>

<div class="analytics-grid">
  <article class="analytics-card">
    <h3 class="analytics-title">Distribución por riesgo de mora</h3>
    <?php $riskLabels = ['corriente' => ['Cartera corriente','risk-green'], 'mora_30' => ['31 - 60 días','risk-yellow'], 'mora_60' => ['61 - 90 días','risk-orange'], 'mora_90_plus' => ['90+ días','risk-red']]; ?>
    <div class="risk-bars">
      <?php foreach ($riskLabels as $key => [$label, $colorClass]): $value = (float)($riskDistribution[$key] ?? 0); $pct = $saldoTotal > 0 ? ($value / $saldoTotal) * 100 : 0; ?>
      <div class="risk-row"><span><?= htmlspecialchars($label) ?></span><div class="risk-track"><div class="risk-fill <?= $colorClass ?>" style="width: <?= max(2, min(100, $pct)) ?>%"></div></div><strong><?= number_format($pct, 1, ',', '.') ?>%</strong></div>
      <?php endforeach; ?>
    </div>
  </article>
  <article class="analytics-card">
    <h3 class="analytics-title">Concentración y exposición</h3>
    <p style="margin:0 0 8px;">Top 10 clientes representan <strong><?= number_format($concentrationPct, 1, ',', '.') ?>%</strong> de la cartera total.</p>
    <ol class="top-list">
      <?php foreach ($topExposure as $idx => $top): ?>
        <li><strong><?= htmlspecialchars((string)$top['cliente']) ?></strong> · $ <?= number_format((float)$top['saldo_total'], 0, ',', '.') ?><br><small><?= htmlspecialchars((string)$top['nit']) ?></small></li>
      <?php endforeach; ?>
      <?php if (empty($topExposure)): ?><li>Sin resultados para los filtros seleccionados.</li><?php endif; ?>
    </ol>
  </article>
</div>

<div style="display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;">
  <a class="btn" href="<?= htmlspecialchars(app_url('cartera/dashboard.php')) ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard de Gestión de Cartera</a>
  <?php $baseExport = array_merge($_GET, ['export' => 1, 'page' => 1]); ?>
  <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/lista.php?' . http_build_query(array_merge($baseExport, ['export_mode' => 'documento'])))) ?>">Exportar detalle documentos</a>
  <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/lista.php?' . http_build_query(array_merge($baseExport, ['export_mode' => 'cliente'])))) ?>">Exportar resumen por cliente</a>
</div>

<div class="table-responsive">
<table class="table">
<?php if ($filters['vista'] === 'cliente'): ?>
<tr><th><a href="<?= htmlspecialchars($buildSortUrl('cliente')) ?>">Cliente</a></th><th>NIT</th><th><a href="<?= htmlspecialchars($buildSortUrl('saldo')) ?>">Total cartera</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('documentos')) ?>"># Documentos</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('vencidos')) ?>">Docs vencidos</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('mora')) ?>">Prom. mora</a></th><th>Acciones</th></tr>
<?php foreach ($rows as $r): ?>
  <?php $clienteId = (int)$r['cliente_id']; $isCritical = cartera_is_critical_client($r); ?>
  <tr class="<?= $isCritical ? 'critical-row' : '' ?>">
    <td><?= htmlspecialchars($r['cliente']) ?> <?= $isCritical ? ui_badge('Crítico', 'danger') : '' ?></td>
    <td><?= htmlspecialchars((string)$r['nit']) ?></td>
    <td>$ <?= number_format((float)$r['saldo_total'], 2, ',', '.') ?></td>
    <td><?= (int)$r['total_documentos'] ?></td>
    <td><?= (int)$r['total_documentos_vencidos'] ?></td>
    <td><?= cartera_mora_badge((int)round((float)$r['promedio_mora'])) ?></td>
    <td><div class="action-links"><a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('cartera/cliente.php?id_cliente=' . $clienteId)) ?>"><i class="fa-solid fa-address-card"></i> Ver historial</a><a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('cartera/lista.php?vista=documento&cliente_id=' . $clienteId . '&cliente=' . urlencode((string)$r['cliente']))) ?>"><i class="fa-solid fa-file-lines"></i> Ver documentos</a></div></td>
  </tr>
  <tr><td colspan="7"><div class="doc-mini"><table><tr><th>Tipo</th><th>Número</th><th>Saldo</th><th>Mora</th><th>Detalle</th></tr><?php foreach (($docsByClient[$clienteId] ?? []) as $doc): ?><tr><td><?= htmlspecialchars((string)$doc['tipo']) ?></td><td><?= htmlspecialchars((string)$doc['nro_documento']) ?></td><td>$ <?= number_format((float)$doc['saldo_pendiente'], 0, ',', '.') ?></td><td><?= cartera_mora_badge((int)$doc['dias_vencido']) ?></td><td><a href="<?= htmlspecialchars(app_url('cartera/documento.php?id_documento=' . (int)$doc['id'])) ?>">Abrir</a></td></tr><?php endforeach; ?></table></div></td></tr>
<?php endforeach; ?>
<?php else: ?>
<tr><th><a href="<?= htmlspecialchars($buildSortUrl('id')) ?>">ID</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('nit')) ?>">NIT</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('cliente')) ?>">Cliente</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('tipo')) ?>">Tipo</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('numero')) ?>">Número</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('saldo')) ?>">Saldo</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('mora')) ?>">Días mora</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('estado')) ?>">Estado</a></th><th>Acciones</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= (int)$r['id'] ?></td><td><?= htmlspecialchars($r['nit']) ?></td><td><?= htmlspecialchars($r['nombre']) ?></td><td><?= htmlspecialchars($r['tipo']) ?></td><td><?= htmlspecialchars($r['nro_documento']) ?></td><td>$ <?= number_format((float)$r['saldo_pendiente'],2,',','.') ?></td><td><?= cartera_mora_badge((int)$r['dias_vencido']) ?></td><td><?= htmlspecialchars($r['estado_documento']) ?></td>
  <td><div class="action-links"><a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('cartera/documento.php?id_documento=' . (int)$r['id'])) ?>"><i class="fa-solid fa-magnifying-glass"></i> Ver detalle</a><a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('cartera/cliente.php?id_cliente=' . (int)$r['cliente_id'] . '&view=mora')) ?>"><i class="fa-solid fa-chart-line"></i> Ver comportamiento de mora</a></div></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</table>
</div>
<div class="pagination"><span>Total registros: <?= $total ?> | Página <?= $page ?> de <?= $totalPages ?> | Tamaño página: <?= $size ?></span></div>

<script>
(function () {
  var form = document.getElementById('filtrosCartera');
  if (!form) return;
  var debounceTimer = null;
  var clientInput = document.getElementById('clienteInput');
  var clientIdInput = document.getElementById('clienteIdInput');
  var suggest = document.getElementById('clienteSuggest');

  function submitDebounced(delay) {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(function () { form.submit(); }, delay || 260);
  }

  form.querySelectorAll('select').forEach(function (el) {
    el.addEventListener('change', function () { submitDebounced(60); });
  });

  form.querySelectorAll('input[name="numero"]').forEach(function (el) {
    el.addEventListener('input', function () { submitDebounced(420); });
  });

  function closeSuggestions() {
    suggest.style.display = 'none';
    suggest.innerHTML = '';
  }

  function renderSuggestions(items) {
    if (!items.length) {
      closeSuggestions();
      return;
    }
    suggest.innerHTML = '';
    items.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'autocomplete-item';
      row.innerHTML = '<div class="autocomplete-name">' + item.nombre + '</div><div class="autocomplete-sub">NIT: ' + item.nit + '</div>';
      row.addEventListener('click', function () {
        clientInput.value = item.nombre + ' - ' + item.nit;
        clientIdInput.value = item.id;
        closeSuggestions();
        form.submit();
      });
      suggest.appendChild(row);
    });
    suggest.style.display = 'block';
  }

  var suggestTimer = null;
  clientInput.addEventListener('input', function () {
    var q = clientInput.value.trim();
    clientIdInput.value = '';
    if (q.length < 2) {
      closeSuggestions();
      submitDebounced(450);
      return;
    }

    window.clearTimeout(suggestTimer);
    suggestTimer = window.setTimeout(function () {
      fetch('<?= htmlspecialchars(app_url('cartera/lista.php')) ?>?ajax=clientes&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (payload) { renderSuggestions(payload.items || []); });
    }, 180);

    submitDebounced(700);
  });

  document.addEventListener('click', function (event) {
    if (!event.target.closest('.auto-wrap')) {
      closeSuggestions();
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Cartera', $content);
