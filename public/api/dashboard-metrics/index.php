<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';
require_once __DIR__ . '/../../../app/services/UenService.php';
require_once __DIR__ . '/../../../app/services/SystemSettingsService.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado.']);
    exit;
}

$user = current_user();
$moraCriticaBaseRaw = system_setting_get($pdo, 'mora_critica_base_dias', '90');
$moraCriticaBaseDias = is_numeric($moraCriticaBaseRaw) ? (int)$moraCriticaBaseRaw : 90;
if ($moraCriticaBaseDias < 1) {
    $moraCriticaBaseDias = 90;
}

function qf(string $key): string
{
    return trim((string)($_GET[$key] ?? ''));
}

function normalize(string $value): string
{
    return mb_strtolower(trim($value));
}

function valid_date_ymd(string $value): bool
{
    if ($value === '') {
        return false;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $value;
}

function valid_period_ym(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $d = DateTimeImmutable::createFromFormat('Y-m', $value);
    return $d instanceof DateTimeImmutable && $d->format('Y-m') === $value;
}

$rawFilters = [
    'periodo' => qf('periodo'),
    'fecha_desde' => qf('fecha_desde'),
    'fecha_hasta' => qf('fecha_hasta'),
    'comparar_anterior' => qf('comparar_anterior') === '1',
    'regional' => qf('regional'),
    'canal' => qf('canal'),
    'empleado_ventas' => qf('empleado_ventas'),
    'cliente' => qf('cliente'),
    'vista' => qf('vista') === 'operativo' ? 'operativo' : 'ejecutivo',
];

$allowedUens = uen_user_allowed_values($pdo);
$selectedUens = uen_apply_scope(uen_requested_values('uen'), $allowedUens);

$regionalExpr = "COALESCE(NULLIF(TRIM(d.regional), ''), NULLIF(TRIM(c.regional), ''), 'Sin dato')";
$canalExpr = "COALESCE(NULLIF(TRIM(d.canal), ''), NULLIF(TRIM(c.canal), ''), 'Sin dato')";
$empleadoExpr = "COALESCE(NULLIF(TRIM(c.empleado_ventas), ''), 'Sin dato')";
$clienteExpr = "COALESCE(NULLIF(TRIM(c.nombre), ''), NULLIF(TRIM(d.cliente), ''), c.cuenta, CONCAT('Cliente #', c.id))";
$fechaExpr = 'DATE(COALESCE(d.fecha_contabilizacion, d.created_at))';
$monthExpr = "DATE_FORMAT($fechaExpr, '%Y-%m')";

$periodOptions = $pdo->query("SELECT DISTINCT DATE_FORMAT($fechaExpr, '%Y-%m') AS periodo FROM cartera_documentos d WHERE d.estado_documento = 'activo' AND $fechaExpr IS NOT NULL ORDER BY periodo DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$defaultPeriod = $periodOptions[0] ?? '';
$selectedPeriod = valid_period_ym($rawFilters['periodo']) ? $rawFilters['periodo'] : '';

$periodStart = '';
$periodEnd = '';
if ($selectedPeriod !== '') {
    $periodDate = DateTimeImmutable::createFromFormat('Y-m-d', $selectedPeriod . '-01');
    if ($periodDate instanceof DateTimeImmutable) {
        $periodStart = $periodDate->format('Y-m-01');
        $periodEnd = $periodDate->modify('last day of this month')->format('Y-m-d');
    }
}

$dateBoundsSql = "SELECT MIN($fechaExpr) AS min_fecha, MAX($fechaExpr) AS max_fecha
    FROM cartera_documentos d
    WHERE d.estado_documento = 'activo'";
$dateBoundsParams = [];
if ($selectedPeriod !== '') {
    $dateBoundsSql .= " AND DATE_FORMAT($fechaExpr, '%Y-%m') = ?";
    $dateBoundsParams[] = $selectedPeriod;
}
$dateBoundsStmt = $pdo->prepare($dateBoundsSql);
$dateBoundsStmt->execute($dateBoundsParams);
$dateBounds = $dateBoundsStmt->fetch(PDO::FETCH_ASSOC) ?: ['min_fecha' => null, 'max_fecha' => null];
$defaultFrom = (string)($dateBounds['min_fecha'] ?? '');
$defaultTo = (string)($dateBounds['max_fecha'] ?? '');

$optionBase = " FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    WHERE d.estado_documento = 'activo'";
$optionParams = [];
if ($selectedPeriod !== '') {
    $optionBase .= " AND DATE_FORMAT($fechaExpr, '%Y-%m') = ?";
    $optionParams[] = $selectedPeriod;
}
$regionalStmt = $pdo->prepare("SELECT DISTINCT $regionalExpr v" . $optionBase . ' ORDER BY v');
$regionalStmt->execute($optionParams);
$regionalOptions = $regionalStmt->fetchAll(PDO::FETCH_COLUMN);
$canalStmt = $pdo->prepare("SELECT DISTINCT $canalExpr v" . $optionBase . ' ORDER BY v');
$canalStmt->execute($optionParams);
$canalOptions = $canalStmt->fetchAll(PDO::FETCH_COLUMN);
$empleadoStmt = $pdo->prepare("SELECT DISTINCT $empleadoExpr v" . $optionBase . ' ORDER BY v');
$empleadoStmt->execute($optionParams);
$empleadoOptions = $empleadoStmt->fetchAll(PDO::FETCH_COLUMN);
$clienteStmt = $pdo->prepare("SELECT DISTINCT $clienteExpr v" . $optionBase . ' ORDER BY v');
$clienteStmt->execute($optionParams);
$clienteOptions = $clienteStmt->fetchAll(PDO::FETCH_COLUMN);
$uenOptionsSql = "SELECT DISTINCT d.uens AS uen FROM cartera_documentos d WHERE d.estado_documento = 'activo' AND d.uens IS NOT NULL AND TRIM(d.uens) <> ''";
$uenOptionsParams = [];
if ($selectedPeriod !== '') {
    $uenOptionsSql .= " AND DATE_FORMAT($fechaExpr, '%Y-%m') = ?";
    $uenOptionsParams[] = $selectedPeriod;
}
$uenOptionsSql .= ' ORDER BY d.uens';
$uenOptionsStmt = $pdo->prepare($uenOptionsSql);
$uenOptionsStmt->execute($uenOptionsParams);
$uenOptions = $uenOptionsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

if (!empty($allowedUens)) {
    $uenOptions = array_values(array_intersect($uenOptions, $allowedUens));
}

$selectedUens = array_values(array_intersect($selectedUens, $uenOptions));

$regionalSet = [];
foreach ($regionalOptions as $value) { $regionalSet[normalize((string)$value)] = true; }
$canalSet = [];
foreach ($canalOptions as $value) { $canalSet[normalize((string)$value)] = true; }
$empleadoSet = [];
foreach ($empleadoOptions as $value) { $empleadoSet[normalize((string)$value)] = true; }
$clienteSet = [];
foreach ($clienteOptions as $value) { $clienteSet[normalize((string)$value)] = true; }

$fechaDesde = $periodStart !== '' ? $periodStart : (valid_date_ymd($rawFilters['fecha_desde']) ? $rawFilters['fecha_desde'] : $defaultFrom);
$fechaHasta = $periodEnd !== '' ? $periodEnd : (valid_date_ymd($rawFilters['fecha_hasta']) ? $rawFilters['fecha_hasta'] : $defaultTo);
if ($fechaDesde !== '' && $fechaHasta !== '' && $fechaDesde > $fechaHasta) {
    [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
}

$selectedRegional = '';
if ($rawFilters['regional'] !== '' && isset($regionalSet[normalize($rawFilters['regional'])])) {
    $selectedRegional = $rawFilters['regional'];
}

$selectedCanal = '';
if ($rawFilters['canal'] !== '' && isset($canalSet[normalize($rawFilters['canal'])])) {
    $selectedCanal = $rawFilters['canal'];
}

$selectedEmpleado = '';
if ($rawFilters['empleado_ventas'] !== '' && isset($empleadoSet[normalize($rawFilters['empleado_ventas'])])) {
    $selectedEmpleado = $rawFilters['empleado_ventas'];
}

$selectedCliente = '';
if ($rawFilters['cliente'] !== '' && isset($clienteSet[normalize($rawFilters['cliente'])])) {
    $selectedCliente = $rawFilters['cliente'];
}

$filters = [
    'periodo' => $selectedPeriod,
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'comparar_anterior' => $rawFilters['comparar_anterior'],
    'regional' => $selectedRegional,
    'canal' => $selectedCanal,
    'empleado_ventas' => $selectedEmpleado,
    'cliente' => $selectedCliente,
    'uen' => $selectedUens,
    'vista' => $rawFilters['vista'],
];

$scope = user_portfolio_scope($pdo, $user ?? null, 'd', 'c');
$where = ["d.estado_documento = 'activo'"];
$params = $scope['params'];
if ($scope['sql'] !== '') { $where[] = ltrim($scope['sql'], ' AND'); }
$uenScope = uen_sql_condition('d.uens', $selectedUens);
if ($uenScope['sql'] !== '') {
    $where[] = ltrim($uenScope['sql'], ' AND');
    $params = array_merge($params, $uenScope['params']);
}
if ($filters['periodo'] !== '') {
    $where[] = "$monthExpr = ?";
    $params[] = $filters['periodo'];
}
if ($filters['fecha_desde'] !== '') {
    $where[] = "$fechaExpr >= ?";
    $params[] = $filters['fecha_desde'];
}
if ($filters['fecha_hasta'] !== '') {
    $where[] = "$fechaExpr <= ?";
    $params[] = $filters['fecha_hasta'];
}
if ($filters['regional'] !== '') {
    $where[] = "$regionalExpr = ?";
    $params[] = $filters['regional'];
}
if ($filters['canal'] !== '') {
    $where[] = "$canalExpr = ?";
    $params[] = $filters['canal'];
}
if ($filters['empleado_ventas'] !== '') {
    $where[] = "$empleadoExpr = ?";
    $params[] = $filters['empleado_ventas'];
}
if ($filters['cliente'] !== '') {
    $where[] = "$clienteExpr = ?";
    $params[] = $filters['cliente'];
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$kpiSql = "SELECT
    COALESCE(SUM(d.saldo_pendiente),0) cartera_total,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida,
    COALESCE(SUM(d.bucket_31_60),0) saldo_31_60,
    COALESCE(SUM(d.bucket_61_90),0) saldo_61_90,
    COALESCE(SUM(d.bucket_91_180),0) saldo_91_180,
    COALESCE(SUM(d.bucket_181_360),0) saldo_181_360,
    COALESCE(SUM(d.bucket_361_plus),0) saldo_361_plus,
    COALESCE(SUM(CASE WHEN d.dias_vencido > ? THEN d.saldo_pendiente ELSE 0 END),0) saldo_critico,
    COALESCE(SUM(CASE WHEN d.saldo_pendiente < 0 THEN d.saldo_pendiente ELSE 0 END),0) saldo_negativo,
    COUNT(*) total_docs,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN 1 ELSE 0 END),0) docs_vencidos
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $whereSql";
$stmt = $pdo->prepare($kpiSql);
$stmt->execute(array_merge([$moraCriticaBaseDias], $params));
$m = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$carteraTotal = (float)($m['cartera_total'] ?? 0);
$carteraVencida = (float)($m['cartera_vencida'] ?? 0);
$porcVencida = $carteraTotal > 0 ? ($carteraVencida / $carteraTotal) * 100 : 0;
$porcCritica = $carteraTotal > 0 ? ((float)($m['saldo_critico'] ?? 0) / $carteraTotal) * 100 : 0;
$totalDocs = (int)($m['total_docs'] ?? 0);
$docsVencidos = (int)($m['docs_vencidos'] ?? 0);
$docsVencidosPct = $totalDocs > 0 ? ($docsVencidos / $totalDocs) * 100 : 0;
$saldoNegativoPct = $carteraTotal != 0 ? (abs((float)($m['saldo_negativo'] ?? 0)) / abs($carteraTotal)) * 100 : 0;

$indiceSeveridad = $carteraTotal > 0
    ? (((float)($m['saldo_31_60'] ?? 0) * 0.5)
        + ((float)($m['saldo_61_90'] ?? 0) * 1)
        + ((float)($m['saldo_91_180'] ?? 0) * 2)
        + ((float)($m['saldo_181_360'] ?? 0) * 3)
        + ((float)($m['saldo_361_plus'] ?? 0) * 4)) / $carteraTotal
    : 0;

$topClientSql = "SELECT $clienteExpr cliente, COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY c.id, cliente
    ORDER BY saldo DESC";
$topStmt = $pdo->prepare($topClientSql);
$topStmt->execute($params);
$allTopRows = $topStmt->fetchAll(PDO::FETCH_ASSOC);
$top5Rows = array_slice($allTopRows, 0, 5);
$saldoTop5 = array_reduce($top5Rows, static fn(float $acc, array $r): float => $acc + (float)$r['saldo'], 0.0);
$top5ConcentrationPct = $carteraTotal > 0 ? ($saldoTop5 / $carteraTotal) * 100 : 0;
$maxClientRow = $allTopRows[0] ?? ['cliente' => 'Sin dato', 'saldo' => 0];
$dependenciaMayorPct = $carteraTotal > 0 ? (((float)$maxClientRow['saldo']) / $carteraTotal) * 100 : 0;

$paretoRunning = 0.0;
$paretoData = [];
foreach (array_slice($allTopRows, 0, 10) as $row) {
    $saldo = (float)$row['saldo'];
    $pct = $carteraTotal > 0 ? ($saldo / $carteraTotal) * 100 : 0;
    $paretoRunning += $pct;
    $paretoData[] = ['cliente' => (string)$row['cliente'], 'saldo' => $saldo, 'pct' => $pct, 'cum_pct' => $paretoRunning];
}

$agingSql = "SELECT
    COALESCE(SUM(d.bucket_actual),0) actual,
    COALESCE(SUM(d.bucket_1_30),0) b1_30,
    COALESCE(SUM(d.bucket_31_60),0) b31_60,
    COALESCE(SUM(d.bucket_61_90),0) b61_90,
    COALESCE(SUM(d.bucket_91_180),0) b91_180,
    COALESCE(SUM(d.bucket_181_360),0) b181_360,
    COALESCE(SUM(d.bucket_361_plus),0) b361_plus
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $whereSql";
$agingStmt = $pdo->prepare($agingSql);
$agingStmt->execute($params);
$agingRaw = $agingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$agingDefs = [
    ['label' => 'Actual', 'key' => 'actual'],
    ['label' => '1-30', 'key' => 'b1_30'],
    ['label' => '31-60', 'key' => 'b31_60'],
    ['label' => '61-90', 'key' => 'b61_90'],
    ['label' => '91-180', 'key' => 'b91_180'],
    ['label' => '181-360', 'key' => 'b181_360'],
    ['label' => '361+', 'key' => 'b361_plus'],
];
$aging = [];
foreach ($agingDefs as $def) {
    $value = (float)($agingRaw[$def['key']] ?? 0);
    $aging[] = ['bucket' => $def['label'], 'value' => $value, 'pct' => $carteraTotal > 0 ? ($value / $carteraTotal) * 100 : 0];
}
$negativeAgingValue = abs((float)($m['saldo_negativo'] ?? 0));
$negativeAgingPct = $carteraTotal > 0 ? ($negativeAgingValue / abs($carteraTotal)) * 100 : 0;

$trendWhere = ["d.estado_documento = 'activo'"];
$trendParams = $scope['params'];
if ($scope['sql'] !== '') { $trendWhere[] = ltrim($scope['sql'], ' AND'); }
if ($uenScope['sql'] !== '') {
    $trendWhere[] = ltrim($uenScope['sql'], ' AND');
    $trendParams = array_merge($trendParams, $uenScope['params']);
}
$trendWhereSql = ' WHERE ' . implode(' AND ', $trendWhere);

$trendSql = "SELECT $monthExpr AS periodo, COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $trendWhereSql
    GROUP BY periodo
    ORDER BY periodo ASC";
$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute($trendParams);
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
$trend = [];
$prev = null;
foreach ($trendRows as $row) {
    $saldo = (float)$row['saldo'];
    $variation = ($prev !== null && $prev > 0) ? (($saldo - $prev) / $prev) * 100 : 0;
    $trend[] = ['periodo' => (string)$row['periodo'], 'saldo' => $saldo, 'variation_pct' => $variation];
    $prev = $saldo;
}

$canalSql = "SELECT $canalExpr canal,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida,
    COALESCE(SUM(d.saldo_pendiente),0) cartera_total
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY canal
    ORDER BY cartera_vencida DESC";
$stmtCanal = $pdo->prepare($canalSql);
$stmtCanal->execute($params);
$canalRows = $stmtCanal->fetchAll(PDO::FETCH_ASSOC);

$empleadoSql = "SELECT $empleadoExpr empleado,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida,
    COALESCE(SUM(d.saldo_pendiente),0) cartera_total
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY empleado
    ORDER BY cartera_vencida DESC";
$stmtEmp = $pdo->prepare($empleadoSql);
$stmtEmp->execute($params);
$empRows = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

$uenMoraSql = "SELECT COALESCE(NULLIF(TRIM(d.uens),''),'Sin UEN') uen,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY uen
    ORDER BY cartera_vencida DESC";
$stmtUen = $pdo->prepare($uenMoraSql);
$stmtUen->execute($params);
$uenMoraRows = $stmtUen->fetchAll(PDO::FETCH_ASSOC);

$negSql = "SELECT COALESCE(NULLIF(TRIM(d.tipo_documento_financiero),''),'factura') tipo,
    COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    $whereSql AND d.saldo_pendiente < 0
    GROUP BY tipo
    ORDER BY saldo ASC";
$negStmt = $pdo->prepare($negSql);
$negStmt->execute($params);
$negativeBreakdown = $negStmt->fetchAll(PDO::FETCH_ASSOC);

$recaudoSql = "SELECT
    COALESCE(SUM(d.importe_aplicado),0) recaudo_periodo,
    d.periodo periodo
    FROM recaudo_detalle d
    INNER JOIN (SELECT c.periodo, MAX(c.id) AS carga_id FROM cargas_recaudo c WHERE c.estado = 'activa' AND c.activo = 1 GROUP BY c.periodo) x ON x.periodo = d.periodo AND x.carga_id = d.carga_id
    WHERE d.periodo = ?
    GROUP BY periodo
    ORDER BY periodo DESC";
$recaudoRows = [];
$recaudoTotal = 0.0;
$recuperacionPct = 0.0;
$recaudoRealMes = 0.0;

$recaudoState = [
    'loaded' => false,
    'integrated' => false,
    'message' => 'Pendiente carga de recaudo',
];

$budgetMonth = $filters['periodo'] !== '' ? $filters['periodo'] : date('Y-m');

try {
    if ($filters['periodo'] !== '') {
        $recaudoStmt = $pdo->prepare($recaudoSql);
        $recaudoStmt->execute([$filters['periodo']]);
        $recaudoRows = $recaudoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recaudoStatusStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM recaudo_detalle WHERE periodo = ?');
        $recaudoStatusStmt->execute([$filters['periodo']]);
        $recaudoRowsCount = (int)$recaudoStatusStmt->fetchColumn();
        $recaudoState['loaded'] = $recaudoRowsCount > 0;
        $recaudoState['integrated'] = $recaudoState['loaded'];
        $recaudoState['message'] = $recaudoState['loaded'] ? '' : 'Pendiente carga de recaudo';
    }

    $recaudoMonthStmt = $pdo->prepare("SELECT COALESCE(SUM(d.importe_aplicado),0) AS recaudo_real FROM recaudo_detalle d INNER JOIN (SELECT c.periodo, MAX(c.id) AS carga_id FROM cargas_recaudo c WHERE c.estado = 'activa' AND c.activo = 1 GROUP BY c.periodo) x ON x.periodo = d.periodo AND x.carga_id = d.carga_id WHERE d.periodo = ?");
    $recaudoMonthStmt->execute([$budgetMonth]);
    $recaudoRealMes = (float)(($recaudoMonthStmt->fetch(PDO::FETCH_ASSOC) ?: ['recaudo_real' => 0])['recaudo_real'] ?? 0);
} catch (Throwable $e) {
    $recaudoRows = [];
    $recaudoTotal = 0.0;
    $recuperacionPct = 0.0;
    $recaudoRealMes = 0.0;
    $recaudoState = [
        'loaded' => false,
        'integrated' => false,
        'message' => 'Pendiente procesamiento de recaudo',
    ];
}

$recaudoTotal = array_reduce($recaudoRows, static fn(float $acc, array $row): float => $acc + (float)$row['recaudo_periodo'], 0.0);
$recuperacionPct = $carteraTotal > 0 ? ($recaudoTotal / $carteraTotal) * 100 : 0;

$budgetStmt = $pdo->prepare('SELECT COALESCE(SUM(valor_presupuesto),0) AS presupuesto FROM presupuesto_recaudo WHERE periodo = ?');
$budgetStmt->execute([$budgetMonth]);
$budget = (float)(($budgetStmt->fetch(PDO::FETCH_ASSOC) ?: ['presupuesto' => 0])['presupuesto'] ?? 0);
$hasBudget = $budget > 0;

$hasRecaudoData = $recaudoState['loaded'] && $recaudoState['integrated'];
$rotationDays = $hasRecaudoData && $recaudoTotal > 0 ? ($carteraTotal / $recaudoTotal) * 30 : null;

$comparison = null;
if ($filters['comparar_anterior'] && $filters['fecha_desde'] !== '' && $filters['fecha_hasta'] !== '') {
    $start = new DateTimeImmutable($filters['fecha_desde']);
    $end = new DateTimeImmutable($filters['fecha_hasta']);
    $days = max(1, (int)$end->diff($start)->days + 1);
    $prevStart = $start->sub(new DateInterval('P' . $days . 'D'));
    $prevEnd = $start->sub(new DateInterval('P1D'));

    $cmpSql = "SELECT
      COALESCE(SUM(d.saldo_pendiente),0) cartera_total,
      COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida,
      COALESCE(SUM(CASE WHEN d.dias_vencido > ? THEN d.saldo_pendiente ELSE 0 END),0) cartera_critica
      FROM cartera_documentos d
      LEFT JOIN clientes c ON c.id = d.cliente_id
      WHERE d.estado_documento = 'activo'" . $scope['sql'] . ($uenScope['sql'] ?? '') . ($filters['periodo'] !== '' ? " AND $monthExpr = ?" : '') . " AND $fechaExpr BETWEEN ? AND ?";
    $cmpParams = array_merge([$moraCriticaBaseDias], $scope['params'], $uenScope['params'] ?? [], $filters['periodo'] !== '' ? [$filters['periodo']] : [], [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')]);
    $cmpStmt = $pdo->prepare($cmpSql);
    $cmpStmt->execute($cmpParams);
    $cmp = $cmpStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $comparison = [
        'periodo_anterior' => ['desde' => $prevStart->format('Y-m-d'), 'hasta' => $prevEnd->format('Y-m-d')],
        'variacion_cartera_pct' => ((float)($cmp['cartera_total'] ?? 0)) > 0 ? (($carteraTotal - (float)$cmp['cartera_total']) / (float)$cmp['cartera_total']) * 100 : 0,
        'variacion_mora_pct' => ((float)($cmp['cartera_vencida'] ?? 0)) > 0 ? (($carteraVencida - (float)$cmp['cartera_vencida']) / (float)$cmp['cartera_vencida']) * 100 : 0,
        'variacion_exposicion_pct' => ((float)($cmp['cartera_critica'] ?? 0)) > 0 ? ((((float)($m['saldo_critico'] ?? 0)) - (float)$cmp['cartera_critica']) / (float)$cmp['cartera_critica']) * 100 : 0,
    ];
}

$scorePenalty = ($indiceSeveridad * 15) + ($top5ConcentrationPct * 0.30) + ($porcCritica * 0.30) + ($docsVencidosPct * 0.25);
$score = max(0, min(100, 100 - $scorePenalty));
$scoreLabel = $score >= 80 ? 'saludable' : ($score >= 60 ? 'riesgo medio' : 'deterioro alto');

$scoreDrivers = [
    [
        'key' => 'critical',
        'kind' => 'risk',
        'label' => 'Cartera crítica >' . $moraCriticaBaseDias . ' días representa ' . number_format($porcCritica, 1, '.', '') . '%',
        'value' => $porcCritica,
        'direction' => 'lower_better',
    ],
    [
        'key' => 'top5',
        'kind' => 'risk',
        'label' => 'Concentración en top 5 clientes: ' . number_format($top5ConcentrationPct, 1, '.', '') . '%',
        'value' => $top5ConcentrationPct,
        'direction' => 'lower_better',
    ],
    [
        'key' => 'docs_overdue',
        'kind' => 'risk',
        'label' => number_format($docsVencidosPct, 1, '.', '') . '% de documentos están vencidos',
        'value' => $docsVencidosPct,
        'direction' => 'lower_better',
    ],
    [
        'key' => 'severity',
        'kind' => 'risk',
        'label' => 'Índice de severidad de mora: ' . number_format($indiceSeveridad, 2, '.', ''),
        'value' => $indiceSeveridad,
        'direction' => 'lower_better',
    ],
];

if ($score > 80) {
    usort($scoreDrivers, static fn(array $a, array $b): int => $a['value'] <=> $b['value']);
    $scoreDrivers = array_slice($scoreDrivers, 0, 3);
    foreach ($scoreDrivers as &$driver) {
        $driver['kind'] = 'strength';
    }
    unset($driver);
} else {
    usort($scoreDrivers, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
    $scoreDrivers = array_slice($scoreDrivers, 0, 3);
}

$recaudoMissingTooltip = 'No hay recaudos cargados para el período y filtros seleccionados.';
$recaudoMissingCardMeta = [
    'status' => 'empty',
    'empty_state' => true,
    'empty_tooltip' => $recaudoMissingTooltip,
];

$kpis = [
    ['title' => 'Cartera Total', 'value' => $carteraTotal, 'unit' => 'currency', 'icon' => 'fa-solid fa-sack-dollar', 'tooltip' => 'Suma del saldo pendiente para los filtros aplicados.'],
    ['title' => '% Cartera Crítica (>' . $moraCriticaBaseDias . ' días)', 'value' => $porcCritica, 'unit' => 'percent', 'icon' => 'fa-solid fa-circle-exclamation', 'status' => $porcCritica > 20 ? 'critical' : 'good', 'tooltip' => 'Porcentaje de cartera en mora superior a ' . $moraCriticaBaseDias . ' días.'],
    ['title' => 'Índice de Severidad de Mora', 'value' => $indiceSeveridad, 'unit' => 'ratio', 'icon' => 'fa-solid fa-gauge-high', 'status' => $indiceSeveridad > 1.6 ? 'critical' : 'warning', 'tooltip' => 'Pondera los buckets superiores a 90 días con mayor peso.'],
    array_merge([
        'title' => 'Rotación de Cartera',
        'value' => $rotationDays,
        'unit' => 'days',
        'icon' => 'fa-solid fa-rotate',
        'tooltip' => 'Días promedio que tarda en recuperarse la cartera. Se calcula como (saldo total / recaudo del período) × 30. Requiere recaudo cargado para calcularse.',
        'empty_value_label' => 'Sin recaudo en período',
        'message' => $hasRecaudoData ? '' : 'Sin recaudo en período',
    ], !$hasRecaudoData ? $recaudoMissingCardMeta : []),
    ['title' => '% Concentración Top 5 Clientes', 'value' => $top5ConcentrationPct, 'unit' => 'percent', 'icon' => 'fa-solid fa-users-viewfinder', 'tooltip' => 'Participación de los 5 clientes más expuestos.'],
    ['title' => '% Dependencia Cliente Mayor', 'value' => $dependenciaMayorPct, 'unit' => 'percent', 'icon' => 'fa-solid fa-user-large', 'tooltip' => 'Participación del cliente con mayor saldo.'],
    ['title' => '% Documentos Vencidos', 'value' => $docsVencidosPct, 'unit' => 'percent', 'icon' => 'fa-solid fa-file-circle-xmark', 'tooltip' => 'Proporción de documentos vencidos sobre el total.'],
    array_merge([
        'title' => 'Recaudo del período',
        'value' => $recaudoTotal,
        'unit' => 'currency',
        'icon' => 'fa-solid fa-money-bill-trend-up',
        'tooltip' => 'Valor total recaudado en el rango seleccionado.',
        'empty_value_label' => 'Sin datos',
        'message' => !$hasRecaudoData ? 'Sin datos del período' : '',
    ], !$hasRecaudoData ? $recaudoMissingCardMeta : []),
    array_merge([
        'title' => '% Recuperación del período',
        'value' => $recuperacionPct,
        'unit' => 'percent',
        'icon' => 'fa-solid fa-hand-holding-dollar',
        'tooltip' => 'Recaudo del período frente a cartera del período.',
        'empty_value_label' => 'Sin datos',
        'message' => !$hasRecaudoData ? 'Sin datos del período' : '',
    ], !$hasRecaudoData ? $recaudoMissingCardMeta : []),
    array_merge([
        'title' => 'Presupuesto de recaudo (' . $budgetMonth . ')',
        'value' => $budget,
        'unit' => 'currency',
        'icon' => 'fa-solid fa-bullseye',
        'tooltip' => 'Meta de recaudo configurada para el mes.',
        'empty_value_label' => 'Sin datos',
        'message' => !$hasRecaudoData ? 'Sin datos del período' : ($hasBudget ? '' : 'Pendiente carga de presupuesto'),
    ], !$hasRecaudoData ? $recaudoMissingCardMeta : []),
    array_merge([
        'title' => 'Recaudo vs meta (' . $budgetMonth . ')',
        'value' => $budget > 0 ? ($recaudoRealMes / $budget) * 100 : 0,
        'unit' => 'percent',
        'icon' => 'fa-solid fa-chart-column',
        'tooltip' => 'Cumplimiento de presupuesto de recaudo mensual.',
        'empty_value_label' => 'Sin datos',
        'message' => !$hasRecaudoData ? 'Sin datos del período' : ($hasBudget ? '' : 'Pendiente carga de presupuesto'),
    ], !$hasRecaudoData ? $recaudoMissingCardMeta : []),
    array_merge([
        'title' => '% Saldo Negativo',
        'value' => $saldoNegativoPct,
        'unit' => 'percent',
        'icon' => 'fa-solid fa-arrow-trend-down',
        'tooltip' => 'Proporción de saldos negativos en cartera.',
        'empty_value_label' => 'Sin datos',
        'message' => !$hasRecaudoData ? 'Sin datos del período' : '',
    ], !$hasRecaudoData ? $recaudoMissingCardMeta : []),
];

echo json_encode([
    'ok' => true,
    'meta' => [
        'generated_at_human' => date('d/m/Y H:i:s'),
        'selected_filters' => $filters,
        'degraded_to_global' => false,
        'degraded_filters' => [],
    ],
    'filter_options' => [
        'periodo' => $periodOptions,
        'fecha_desde' => $defaultFrom,
        'fecha_hasta' => $defaultTo,
        'regional' => $regionalOptions,
        'canal' => $canalOptions,
        'empleado_ventas' => $empleadoOptions,
        'cliente' => $clienteOptions,
        'uen' => $uenOptions,
    ],
    'kpis' => $kpis,
    'comparison' => $comparison,
    'charts' => [
        'aging' => $aging,
        'trend' => $trend,
        'top_clients' => array_map(static fn(array $r): array => ['cliente' => (string)$r['cliente'], 'saldo' => (float)$r['saldo'], 'pct' => $carteraTotal > 0 ? ((float)$r['saldo'] / $carteraTotal) * 100 : 0], array_slice($allTopRows, 0, 10)),
        'vencida_canal' => array_map(static fn(array $r): array => ['canal' => (string)$r['canal'], 'cartera_vencida' => (float)$r['cartera_vencida'], 'mora_pct' => ((float)$r['cartera_total']) > 0 ? ((float)$r['cartera_vencida'] / (float)$r['cartera_total']) * 100 : 0], $canalRows),
        'vencida_empleado' => array_map(static fn(array $r): array => ['empleado' => (string)$r['empleado'], 'cartera_vencida' => (float)$r['cartera_vencida'], 'mora_pct' => ((float)$r['cartera_total']) > 0 ? ((float)$r['cartera_vencida'] / (float)$r['cartera_total']) * 100 : 0], $empRows),
        'mora_uen' => array_map(static fn(array $r): array => ['uen' => (string)$r['uen'], 'cartera_vencida' => (float)$r['cartera_vencida']], $uenMoraRows),
        'pareto_top10' => ['rows' => $paretoData],
        'negative_breakdown' => $negativeBreakdown,
        'recaudo_periodos' => $recaudoRows,
        'recaudo_state' => $recaudoState,
        'dependencia_mayor' => ['cliente' => (string)$maxClientRow['cliente'], 'pct' => $dependenciaMayorPct, 'saldo' => (float)$maxClientRow['saldo']],
        'score' => [
            'value' => $score,
            'label' => $scoreLabel,
            'tooltip' => 'Indicador compuesto basado en severidad, concentración, cartera crítica y proporción de documentos vencidos.',
            'drivers' => $scoreDrivers,
        ],
        'aging_negative' => [
            'bucket' => 'Saldo negativo',
            'value' => $negativeAgingValue,
            'pct' => $negativeAgingPct,
        ],
    ],
    'empty' => $totalDocs === 0,
    'empty_message' => 'Sin datos para los filtros seleccionados',
]);
