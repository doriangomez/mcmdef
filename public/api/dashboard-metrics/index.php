<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado.']);
    exit;
}

function qf(string $key): string
{
    return trim((string)($_GET[$key] ?? ''));
}

function normalize(string $value): string
{
    return mb_strtolower(trim($value));
}

$rawFilters = [
    'periodo' => qf('periodo'),
    'regional' => qf('regional'),
    'canal' => qf('canal'),
    'empleado_ventas' => qf('empleado_ventas'),
    'cliente' => qf('cliente'),
];

$regionalExpr = "COALESCE(NULLIF(TRIM(d.regional), ''), NULLIF(TRIM(c.regional), ''), 'Sin dato')";
$canalExpr = "COALESCE(NULLIF(TRIM(d.canal), ''), NULLIF(TRIM(c.canal), ''), 'Sin dato')";
$empleadoExpr = "COALESCE(NULLIF(TRIM(c.empleado_ventas), ''), 'Sin dato')";
$clienteExpr = "COALESCE(NULLIF(TRIM(c.nombre), ''), NULLIF(TRIM(d.cliente), ''), c.cuenta, CONCAT('Cliente #', c.id))";
$monthExpr = "DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m')";

$periodOptions = $pdo->query("SELECT DISTINCT DATE_FORMAT(fecha_contabilizacion, '%Y-%m') p FROM cartera_documentos WHERE estado_documento = 'activo' ORDER BY p DESC")->fetchAll(PDO::FETCH_COLUMN);
$regionalOptions = $pdo->query("SELECT DISTINCT $regionalExpr v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
$canalOptions = $pdo->query("SELECT DISTINCT $canalExpr v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
$empleadoOptions = $pdo->query("SELECT DISTINCT $empleadoExpr v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
$clienteOptions = $pdo->query("SELECT DISTINCT $clienteExpr v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);

$periodSet = array_fill_keys(array_map('strval', $periodOptions), true);
$regionalSet = [];
foreach ($regionalOptions as $value) { $regionalSet[normalize((string)$value)] = true; }
$canalSet = [];
foreach ($canalOptions as $value) { $canalSet[normalize((string)$value)] = true; }
$empleadoSet = [];
foreach ($empleadoOptions as $value) { $empleadoSet[normalize((string)$value)] = true; }
$clienteSet = [];
foreach ($clienteOptions as $value) { $clienteSet[normalize((string)$value)] = true; }

$filters = [
    'periodo' => isset($periodSet[$rawFilters['periodo']]) ? $rawFilters['periodo'] : '',
    'regional' => isset($regionalSet[normalize($rawFilters['regional'])]) ? $rawFilters['regional'] : '',
    'canal' => isset($canalSet[normalize($rawFilters['canal'])]) ? $rawFilters['canal'] : '',
    'empleado_ventas' => isset($empleadoSet[normalize($rawFilters['empleado_ventas'])]) ? $rawFilters['empleado_ventas'] : '',
    'cliente' => isset($clienteSet[normalize($rawFilters['cliente'])]) ? $rawFilters['cliente'] : '',
];

$where = ["d.estado_documento = 'activo'"];
$scope = portfolio_client_scope_sql('c');
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
}
$params = $scope['params'];
if ($filters['periodo'] !== '') { $where[] = "$monthExpr = ?"; $params[] = $filters['periodo']; }
if ($filters['regional'] !== '') { $where[] = "LOWER(TRIM($regionalExpr)) = LOWER(TRIM(?))"; $params[] = $filters['regional']; }
if ($filters['canal'] !== '') { $where[] = "LOWER(TRIM($canalExpr)) = LOWER(TRIM(?))"; $params[] = $filters['canal']; }
if ($filters['empleado_ventas'] !== '') { $where[] = "LOWER(TRIM($empleadoExpr)) = LOWER(TRIM(?))"; $params[] = $filters['empleado_ventas']; }
if ($filters['cliente'] !== '') { $where[] = "LOWER(TRIM($clienteExpr)) = LOWER(TRIM(?))"; $params[] = $filters['cliente']; }
$whereSql = ' WHERE ' . implode(' AND ', $where);

$kpiSql = "SELECT
    COALESCE(SUM(d.saldo_pendiente),0) cartera_total,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida,
    COALESCE(SUM(d.saldo_pendiente * GREATEST(d.dias_vencido,0)) / NULLIF(SUM(d.saldo_pendiente),0),0) dias_prom_ponderados,
    COALESCE(AVG(d.saldo_pendiente),0) ticket_promedio,
    COALESCE(SUM(d.bucket_31_60),0) saldo_31_60,
    COALESCE(SUM(d.bucket_61_90),0) saldo_61_90,
    COALESCE(SUM(d.bucket_91_180 + d.bucket_181_360 + d.bucket_361_plus),0) saldo_91_plus,
    COALESCE(SUM(d.bucket_361_plus),0) saldo_361_plus,
    COALESCE(SUM(CASE WHEN d.saldo_pendiente < 0 THEN d.saldo_pendiente ELSE 0 END),0) saldo_negativo,
    COALESCE(AVG(DATEDIFF(CURDATE(), d.fecha_contabilizacion)),0) antiguedad_promedio,
    COUNT(*) total_docs,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN 1 ELSE 0 END),0) docs_vencidos
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql";
$stmt = $pdo->prepare($kpiSql);
$stmt->execute($params);
$m = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$carteraTotal = (float)($m['cartera_total'] ?? 0);
$carteraVencida = (float)($m['cartera_vencida'] ?? 0);
$porcVencida = $carteraTotal > 0 ? ($carteraVencida / $carteraTotal) * 100 : 0;
$porc91Plus = $carteraTotal > 0 ? ((float)($m['saldo_91_plus'] ?? 0) / $carteraTotal) * 100 : 0;
$porc361Plus = $carteraTotal > 0 ? ((float)($m['saldo_361_plus'] ?? 0) / $carteraTotal) * 100 : 0;
$totalDocs = (int)($m['total_docs'] ?? 0);
$docsVencidos = (int)($m['docs_vencidos'] ?? 0);
$ratioSaldoVencido = $docsVencidos > 0 ? ($carteraVencida / $docsVencidos) : 0;
$indiceSeveridad = $carteraVencida > 0
    ? ((((float)($m['saldo_91_plus'] ?? 0)) * 2) + (((float)($m['saldo_61_90'] ?? 0)) * 1.5) + (((float)($m['saldo_31_60'] ?? 0)) * 1.2)) / $carteraVencida
    : 0;
$docsVencidosPct = $totalDocs > 0 ? ($docsVencidos / $totalDocs) * 100 : 0;
$saldoNegativoPct = $carteraTotal != 0 ? (abs((float)($m['saldo_negativo'] ?? 0)) / abs($carteraTotal)) * 100 : 0;

$topClientSql = "SELECT $clienteExpr cliente,
    COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY c.id, cliente
    ORDER BY saldo DESC";
$topStmt = $pdo->prepare($topClientSql);
$topStmt->execute($params);
$allTopRows = $topStmt->fetchAll(PDO::FETCH_ASSOC);
$topClients = array_map(static fn(array $r): array => [
    'cliente' => (string)$r['cliente'],
    'saldo' => (float)$r['saldo'],
    'pct' => $carteraTotal > 0 ? ((float)$r['saldo'] / $carteraTotal) * 100 : 0,
], array_slice($allTopRows, 0, 10));
$top5Rows = array_slice($allTopRows, 0, 5);
$saldoTop5 = array_reduce($top5Rows, static fn(float $acc, array $r): float => $acc + (float)$r['saldo'], 0.0);
$top5ConcentrationPct = $carteraTotal > 0 ? ($saldoTop5 / $carteraTotal) * 100 : 0;
$maxClientSaldo = isset($allTopRows[0]) ? (float)$allTopRows[0]['saldo'] : 0.0;
$dependenciaMayorPct = $carteraTotal > 0 ? ($maxClientSaldo / $carteraTotal) * 100 : 0;

$paretoRows = array_slice($allTopRows, 0, 10);
$paretoRunning = 0.0;
$paretoData = [];
foreach ($paretoRows as $row) {
    $saldo = (float)$row['saldo'];
    $pct = $carteraTotal > 0 ? ($saldo / $carteraTotal) * 100 : 0;
    $paretoRunning += $pct;
    $paretoData[] = ['cliente' => (string)$row['cliente'], 'saldo' => $saldo, 'pct' => $pct, 'cum_pct' => $paretoRunning];
}

$avgDaysRegionalSql = "SELECT $regionalExpr regional, COALESCE(AVG(d.dias_vencido),0) avg_dias
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY regional
    ORDER BY avg_dias DESC";
$stmtAvgRegional = $pdo->prepare($avgDaysRegionalSql);
$stmtAvgRegional->execute($params);
$avgDaysRegional = $stmtAvgRegional->fetchAll(PDO::FETCH_ASSOC);

$avgDaysCanalSql = "SELECT $canalExpr canal, COALESCE(AVG(d.dias_vencido),0) avg_dias
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY canal
    ORDER BY avg_dias DESC";
$stmtAvgCanal = $pdo->prepare($avgDaysCanalSql);
$stmtAvgCanal->execute($params);
$avgDaysCanal = $stmtAvgCanal->fetchAll(PDO::FETCH_ASSOC);

$avgDaysEmpleadoSql = "SELECT $empleadoExpr empleado, COALESCE(AVG(d.dias_vencido),0) avg_dias
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY empleado
    ORDER BY avg_dias DESC";
$stmtAvgEmpleado = $pdo->prepare($avgDaysEmpleadoSql);
$stmtAvgEmpleado->execute($params);
$avgDaysEmpleado = $stmtAvgEmpleado->fetchAll(PDO::FETCH_ASSOC);

$agingSql = "SELECT
    COALESCE(SUM(d.bucket_actual),0) actual,
    COALESCE(SUM(d.bucket_1_30),0) b1_30,
    COALESCE(SUM(d.bucket_31_60),0) b31_60,
    COALESCE(SUM(d.bucket_61_90),0) b61_90,
    COALESCE(SUM(d.bucket_91_180),0) b91_180,
    COALESCE(SUM(d.bucket_181_360),0) b181_360,
    COALESCE(SUM(d.bucket_361_plus),0) b361_plus
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql";
$agingStmt = $pdo->prepare($agingSql);
$agingStmt->execute($params);
$agingRaw = $agingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$agingDefs = [
    ['label' => 'Actual', 'key' => 'actual', 'avg' => 0],
    ['label' => '1-30', 'key' => 'b1_30', 'avg' => 15],
    ['label' => '31-60', 'key' => 'b31_60', 'avg' => 45],
    ['label' => '61-90', 'key' => 'b61_90', 'avg' => 75],
    ['label' => '91-180', 'key' => 'b91_180', 'avg' => 135],
    ['label' => '181-360', 'key' => 'b181_360', 'avg' => 270],
    ['label' => '361+', 'key' => 'b361_plus', 'avg' => 450],
];
$aging = [];
foreach ($agingDefs as $def) {
    $value = (float)($agingRaw[$def['key']] ?? 0);
    $aging[] = ['bucket' => $def['label'], 'value' => $value, 'pct' => $carteraTotal > 0 ? ($value / $carteraTotal) * 100 : 0, 'avg_days' => $def['avg']];
}

$trendSql = "SELECT $monthExpr AS periodo, COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    " . str_replace("$monthExpr = ? AND ", '', $whereSql) . "
    GROUP BY periodo
    ORDER BY periodo ASC";
$trendParams = $params;
if ($filters['periodo'] !== '') { array_shift($trendParams); }
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

$daysComponent = min(((float)($m['dias_prom_ponderados'] ?? 0) / 180) * 100, 100);
$scorePenalty = ($porcVencida * 0.35) + ($daysComponent * 0.25) + ($top5ConcentrationPct * 0.20) + ($porc91Plus * 0.20);
$score = max(0, min(100, 100 - $scorePenalty));
$severidadStatus = $indiceSeveridad < 1.2 ? 'good' : ($indiceSeveridad < 1.6 ? 'warning' : 'critical');

echo json_encode([
    'ok' => true,
    'meta' => [
        'generated_at_human' => date('d/m/Y H:i:s'),
        'selected_filters' => $filters,
    ],
    'filter_options' => [
        'periodo' => $periodOptions,
        'regional' => $regionalOptions,
        'canal' => $canalOptions,
        'empleado_ventas' => $empleadoOptions,
        'cliente' => $clienteOptions,
    ],
    'kpis' => [
        ['title' => 'Cartera Total', 'value' => $carteraTotal, 'unit' => 'currency', 'icon' => 'fa-solid fa-sack-dollar', 'tooltip' => 'Suma total del saldo pendiente de todos los documentos según filtros aplicados.'],
        ['title' => '% Cartera Crítica (91+ días)', 'value' => $porc91Plus, 'unit' => 'percent', 'icon' => 'fa-solid fa-circle-exclamation', 'status' => $porc91Plus > 20 ? 'critical' : 'good', 'tooltip' => 'Proporción del saldo total que se encuentra en tramos de mora superior a 90 días.'],
        ['title' => '% Cartera 361+ días', 'value' => $porc361Plus, 'unit' => 'percent', 'icon' => 'fa-solid fa-triangle-exclamation', 'status' => 'critical', 'tooltip' => 'Porcentaje de cartera con mora extrema superior a 361 días.'],
        ['title' => 'Índice de Severidad de Mora', 'value' => $indiceSeveridad, 'unit' => 'ratio', 'icon' => 'fa-solid fa-gauge-high', 'status' => $severidadStatus, 'tooltip' => 'Indicador ponderado que mide profundidad del deterioro de la cartera vencida.'],
        ['title' => 'Ratio Saldo Vencido / Documentos Vencidos', 'value' => $ratioSaldoVencido, 'unit' => 'currency', 'icon' => 'fa-solid fa-file-invoice-dollar', 'tooltip' => 'Promedio de saldo por documento vencido.'],
        ['title' => '% Concentración Top 5 Clientes', 'value' => $top5ConcentrationPct, 'unit' => 'percent', 'icon' => 'fa-solid fa-users-viewfinder', 'tooltip' => 'Proporción del saldo total concentrado en los cinco principales clientes.'],
        ['title' => 'Índice de Dependencia del Cliente Mayor', 'value' => $dependenciaMayorPct, 'unit' => 'percent', 'icon' => 'fa-solid fa-user-large', 'tooltip' => 'Dependencia del portafolio respecto al cliente con mayor exposición.'],
        ['title' => '% Documentos Vencidos / Total', 'value' => $docsVencidosPct, 'unit' => 'percent', 'icon' => 'fa-solid fa-file-circle-xmark', 'tooltip' => 'Proporción de documentos que presentan mora frente al total.'],
        ['title' => 'Antigüedad Promedio de Cartera', 'value' => (float)($m['antiguedad_promedio'] ?? 0), 'unit' => 'days', 'icon' => 'fa-solid fa-hourglass-half', 'tooltip' => 'Edad promedio estructural de la cartera.'],
        ['title' => '% Saldo Negativo', 'value' => $saldoNegativoPct, 'unit' => 'percent', 'icon' => 'fa-solid fa-arrow-trend-down', 'tooltip' => 'Proporción de saldo negativo asociado a notas crédito o ajustes.'],
    ],
    'charts' => [
        'aging' => $aging,
        'trend' => $trend,
        'top_clients' => $topClients,
        'avg_days_regional' => array_map(static fn(array $r): array => ['regional' => (string)$r['regional'], 'avg_dias' => (float)$r['avg_dias']], $avgDaysRegional),
        'avg_days_canal' => array_map(static fn(array $r): array => ['canal' => (string)$r['canal'], 'avg_dias' => (float)$r['avg_dias']], $avgDaysCanal),
        'avg_days_empleado' => array_map(static fn(array $r): array => ['empleado' => (string)$r['empleado'], 'avg_dias' => (float)$r['avg_dias']], $avgDaysEmpleado),
        'pareto_top5' => ['concentration_pct' => $top5ConcentrationPct, 'rows' => $paretoData],
        'score' => [
            'value' => $score,
            'tooltip' => 'Indicador compuesto que refleja el nivel general de riesgo de la cartera bajo los filtros actuales.',
        ],
    ],
    'empty' => $totalDocs === 0,
    'empty_message' => 'No hay datos para los filtros seleccionados. Ajusta los filtros para continuar.',
]);
