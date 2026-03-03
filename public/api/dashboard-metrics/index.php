<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado.']);
    exit;
}

function f(string $key): string { return trim((string)($_GET[$key] ?? '')); }
function normalize(string $value): string { return strtolower(trim($value)); }

function sqlWithParams(string $sql, array $params, PDO $pdo): string
{
    $pieces = explode('?', $sql);
    if (count($pieces) === 1) {
        return $sql;
    }

    $built = '';
    foreach ($pieces as $index => $piece) {
        $built .= $piece;
        if ($index < count($params)) {
            $built .= $pdo->quote((string)$params[$index]);
        }
    }
    return $built;
}

$rawFilters = ['periodo' => f('periodo'), 'regional' => f('regional'), 'canal' => f('canal')];

$periodOptions = $pdo->query("SELECT DISTINCT DATE_FORMAT(fecha_contabilizacion, '%Y-%m') p FROM cartera_documentos WHERE estado_documento = 'activo' ORDER BY p DESC")->fetchAll(PDO::FETCH_COLUMN);
$regionalOptions = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(d.regional), ''), NULLIF(TRIM(c.regional), ''), 'Sin dato') v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
$canalOptions = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(d.canal), ''), NULLIF(TRIM(c.canal), ''), 'Sin dato') v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);

$periodSet = array_fill_keys(array_map('strval', $periodOptions), true);
$regionalSet = [];
foreach ($regionalOptions as $regionalValue) { $regionalSet[normalize((string)$regionalValue)] = true; }
$canalSet = [];
foreach ($canalOptions as $canalValue) { $canalSet[normalize((string)$canalValue)] = true; }

$filters = [
    'periodo' => isset($periodSet[$rawFilters['periodo']]) ? $rawFilters['periodo'] : '',
    'regional' => isset($regionalSet[normalize($rawFilters['regional'])]) ? $rawFilters['regional'] : '',
    'canal' => isset($canalSet[normalize($rawFilters['canal'])]) ? $rawFilters['canal'] : '',
];

$regionalExpr = "COALESCE(NULLIF(TRIM(d.regional), ''), NULLIF(TRIM(c.regional), ''), 'Sin dato')";
$canalExpr = "COALESCE(NULLIF(TRIM(d.canal), ''), NULLIF(TRIM(c.canal), ''), 'Sin dato')";
$monthExpr = "DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m')";

$baseWhere = ["d.estado_documento = 'activo'"];
$baseParams = [];
if ($filters['regional'] !== '') {
    $baseWhere[] = "LOWER(TRIM($regionalExpr)) = LOWER(TRIM(?))";
    $baseParams[] = $filters['regional'];
}
if ($filters['canal'] !== '') {
    $baseWhere[] = "LOWER(TRIM($canalExpr)) = LOWER(TRIM(?))";
    $baseParams[] = $filters['canal'];
}

$whereWithPeriod = $baseWhere;
$paramsWithPeriod = $baseParams;
if ($filters['periodo'] !== '') {
    $whereWithPeriod[] = "$monthExpr = ?";
    $paramsWithPeriod[] = $filters['periodo'];
}

$baseWhereSql = ' WHERE ' . implode(' AND ', $baseWhere);
$whereWithPeriodSql = ' WHERE ' . implode(' AND ', $whereWithPeriod);

$debugSql = [];

$kpiSql = 'SELECT
    COALESCE(SUM(d.saldo_pendiente),0) cartera_total,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida,
    COALESCE(AVG(d.dias_vencido),0) aging_promedio,
    COUNT(*) documentos,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN 1 ELSE 0 END),0) docs_vencidos
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id' . $whereWithPeriodSql;
$debugSql['kpis'] = sqlWithParams($kpiSql, $paramsWithPeriod, $pdo);
$stmt = $pdo->prepare($kpiSql);
$stmt->execute($paramsWithPeriod);
$m = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$carteraTotal = (float)($m['cartera_total'] ?? 0);
$carteraVencida = (float)($m['cartera_vencida'] ?? 0);

$top5Sql = 'SELECT COALESCE(SUM(x.saldo),0) FROM (
    SELECT COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id' . $whereWithPeriodSql . '
    GROUP BY c.id
    ORDER BY saldo DESC
    LIMIT 5
) x';
$debugSql['kpi_top5'] = sqlWithParams($top5Sql, $paramsWithPeriod, $pdo);
$top5 = $pdo->prepare($top5Sql);
$top5->execute($paramsWithPeriod);
$top5Total = (float)$top5->fetchColumn();
$concentracion5 = $carteraTotal > 0 ? ($top5Total / $carteraTotal) * 100 : 0;

$trendPeriodsParams = $baseParams;
$trendPeriodsSql = "SELECT p.periodo
FROM (
    SELECT DISTINCT $monthExpr AS periodo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $baseWhereSql";
if ($filters['periodo'] !== '') {
    $trendPeriodsSql .= " AND $monthExpr <= ?";
    $trendPeriodsParams[] = $filters['periodo'];
}
$trendPeriodsSql .= "
    ORDER BY periodo DESC
    LIMIT 6
) p
ORDER BY p.periodo ASC";
$debugSql['trend_periods'] = sqlWithParams($trendPeriodsSql, $trendPeriodsParams, $pdo);
$trendPeriodsStmt = $pdo->prepare($trendPeriodsSql);
$trendPeriodsStmt->execute($trendPeriodsParams);
$trendPeriods = $trendPeriodsStmt->fetchAll(PDO::FETCH_COLUMN);

$trend = ['categories' => [], 'series' => [['name' => 'Exposición total', 'data' => []], ['name' => 'Cartera en mora', 'data' => []]]];
if ($trendPeriods !== []) {
    $inPlaceholders = implode(', ', array_fill(0, count($trendPeriods), '?'));
    $trendSql = "SELECT $monthExpr AS periodo,
        COALESCE(SUM(d.saldo_pendiente),0) exposure,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) overdue
        FROM cartera_documentos d
        INNER JOIN clientes c ON c.id = d.cliente_id
        $baseWhereSql AND $monthExpr IN ($inPlaceholders)
        GROUP BY periodo
        ORDER BY periodo ASC";
    $trendParams = array_merge($baseParams, $trendPeriods);
    $debugSql['trend'] = sqlWithParams($trendSql, $trendParams, $pdo);
    $trendStmt = $pdo->prepare($trendSql);
    $trendStmt->execute($trendParams);
    $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($trendRows as $row) {
        $map[$row['periodo']] = $row;
    }
    foreach ($trendPeriods as $period) {
        $trend['categories'][] = $period;
        $trend['series'][0]['data'][] = (float)($map[$period]['exposure'] ?? 0);
        $trend['series'][1]['data'][] = (float)($map[$period]['overdue'] ?? 0);
    }
} else {
    $debugSql['trend'] = 'Sin periodos para construir tendencia.';
}

$agingSql = "SELECT
    COALESCE(SUM(d.bucket_actual),0) bucket_actual,
    COALESCE(SUM(d.bucket_1_30),0) bucket_1_30,
    COALESCE(SUM(d.bucket_31_60),0) bucket_31_60,
    COALESCE(SUM(d.bucket_61_90),0) bucket_61_90,
    COALESCE(SUM(d.bucket_91_180),0) bucket_91_180,
    COALESCE(SUM(d.bucket_181_360),0) bucket_181_360,
    COALESCE(SUM(d.bucket_361_plus),0) bucket_361_plus
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id" . $whereWithPeriodSql;
$debugSql['aging'] = sqlWithParams($agingSql, $paramsWithPeriod, $pdo);
$agingStmt = $pdo->prepare($agingSql);
$agingStmt->execute($paramsWithPeriod);
$agingRow = $agingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$agingValues = [
    (float)($agingRow['bucket_actual'] ?? 0),
    (float)($agingRow['bucket_1_30'] ?? 0),
    (float)($agingRow['bucket_31_60'] ?? 0),
    (float)($agingRow['bucket_61_90'] ?? 0),
    (float)($agingRow['bucket_91_180'] ?? 0),
    (float)($agingRow['bucket_181_360'] ?? 0),
    (float)($agingRow['bucket_361_plus'] ?? 0),
];

$paretoSql = "SELECT
    COALESCE(NULLIF(TRIM(c.nombre), ''), c.cuenta, CONCAT('Cliente #', c.id)) cliente,
    COALESCE(SUM(d.saldo_pendiente),0) exposure
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereWithPeriodSql
    GROUP BY c.id, c.nombre, c.cuenta
    ORDER BY exposure DESC
    LIMIT 10";
$debugSql['pareto'] = sqlWithParams($paretoSql, $paramsWithPeriod, $pdo);
$paretoStmt = $pdo->prepare($paretoSql);
$paretoStmt->execute($paramsWithPeriod);
$paretoRows = $paretoStmt->fetchAll(PDO::FETCH_ASSOC);
$paretoTotal = array_reduce($paretoRows, static fn(float $acc, array $row): float => $acc + (float)$row['exposure'], 0.0);
$paretoExposure = [];
$paretoCategories = [];
$paretoCumulative = [];
$runningExposure = 0.0;
foreach ($paretoRows as $row) {
    $value = (float)$row['exposure'];
    $runningExposure += $value;
    $paretoCategories[] = (string)$row['cliente'];
    $paretoExposure[] = $value;
    $paretoCumulative[] = $paretoTotal > 0 ? ($runningExposure / $paretoTotal) * 100 : 0;
}

$heatmapSql = "SELECT
    $regionalExpr AS regional,
    $canalExpr AS canal,
    COALESCE(SUM(d.saldo_pendiente),0) exposure
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereWithPeriodSql
    GROUP BY regional, canal
    ORDER BY regional, canal";
$debugSql['heatmap'] = sqlWithParams($heatmapSql, $paramsWithPeriod, $pdo);
$heatmapStmt = $pdo->prepare($heatmapSql);
$heatmapStmt->execute($paramsWithPeriod);
$heatmapRows = $heatmapStmt->fetchAll(PDO::FETCH_ASSOC);
$heatmapSeriesMap = [];
$regionalLabels = [];
$channelLabels = [];
foreach ($heatmapRows as $row) {
    $regional = (string)$row['regional'];
    $canal = (string)$row['canal'];
    $value = (float)$row['exposure'];

    if (!isset($heatmapSeriesMap[$regional])) {
        $heatmapSeriesMap[$regional] = [];
        $regionalLabels[] = $regional;
    }
    $heatmapSeriesMap[$regional][$canal] = $value;
    if (!in_array($canal, $channelLabels, true)) {
        $channelLabels[] = $canal;
    }
}
$heatmapSeries = [];
foreach ($regionalLabels as $regionalLabel) {
    $points = [];
    foreach ($channelLabels as $channelLabel) {
        $points[] = ['x' => $channelLabel, 'y' => (float)($heatmapSeriesMap[$regionalLabel][$channelLabel] ?? 0)];
    }
    $heatmapSeries[] = ['name' => $regionalLabel, 'data' => $points];
}

$topExposureSql = "SELECT
    COALESCE(NULLIF(TRIM(c.nombre), ''), c.cuenta, CONCAT('Cliente #', c.id)) cliente,
    COALESCE(SUM(d.saldo_pendiente),0) valor
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereWithPeriodSql
    GROUP BY c.id, c.nombre, c.cuenta
    ORDER BY valor DESC
    LIMIT 10";
$debugSql['top_exposure'] = sqlWithParams($topExposureSql, $paramsWithPeriod, $pdo);
$topExposureStmt = $pdo->prepare($topExposureSql);
$topExposureStmt->execute($paramsWithPeriod);
$topExposureRows = $topExposureStmt->fetchAll(PDO::FETCH_ASSOC);

$topMoraSql = "SELECT
    COALESCE(NULLIF(TRIM(c.nombre), ''), c.cuenta, CONCAT('Cliente #', c.id)) cliente,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) valor,
    COALESCE(AVG(CASE WHEN d.dias_vencido > 0 THEN d.dias_vencido END),0) dias_promedio
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereWithPeriodSql
    GROUP BY c.id, c.nombre, c.cuenta
    HAVING valor > 0
    ORDER BY valor DESC
    LIMIT 10";
$debugSql['top_mora'] = sqlWithParams($topMoraSql, $paramsWithPeriod, $pdo);
$topMoraStmt = $pdo->prepare($topMoraSql);
$topMoraStmt->execute($paramsWithPeriod);
$topMoraRows = $topMoraStmt->fetchAll(PDO::FETCH_ASSOC);

$top3Exposure = array_slice($topExposureRows, 0, 3);
$top3Sum = array_reduce($top3Exposure, static fn(float $acc, array $row): float => $acc + (float)$row['valor'], 0.0);
$top3Concentration = $carteraTotal > 0 ? ($top3Sum / $carteraTotal) * 100 : 0;

$agingLabels = ['Actual', '1-30', '31-60', '61-90', '91-180', '181-360', '361+'];
$dominantIndex = 0;
$dominantValue = -1;
foreach ($agingValues as $index => $agingValue) {
    if ($agingValue > $dominantValue) {
        $dominantValue = $agingValue;
        $dominantIndex = $index;
    }
}
$dominantAgingBucket = $agingLabels[$dominantIndex] ?? 'Actual';

$overdueRatio = $carteraTotal > 0 ? ($carteraVencida / $carteraTotal) * 100 : 0;
$riskLevel = 'Controlado';
if ($overdueRatio >= 40 || $top3Concentration >= 75) {
    $riskLevel = 'Alto';
} elseif ($overdueRatio >= 20 || $top3Concentration >= 55) {
    $riskLevel = 'Moderado';
}

echo json_encode([
    'ok' => true,
    'meta' => [
        'generated_at_human' => date('d/m/Y H:i:s'),
        'selected_filters' => $filters,
        'current_period' => $filters['periodo'],
        'previous_period' => '',
    ],
    'filter_options' => ['periodo' => $periodOptions, 'regional' => $regionalOptions, 'canal' => $canalOptions],
    'kpis' => [
        ['title' => 'Cartera Total', 'value' => $carteraTotal, 'unit' => 'currency', 'icon' => 'fa-solid fa-sack-dollar', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Saldo pendiente total'],
        ['title' => 'Cartera Vencida', 'value' => $carteraVencida, 'unit' => 'currency', 'icon' => 'fa-solid fa-triangle-exclamation', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Dias vencido > 0'],
        ['title' => '% Cartera Vencida', 'value' => $overdueRatio, 'unit' => 'percent', 'icon' => 'fa-solid fa-percent', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Participación vencida'],
        ['title' => 'Aging Promedio', 'value' => (float)($m['aging_promedio'] ?? 0), 'unit' => 'days', 'icon' => 'fa-solid fa-calendar-days', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Días vencido promedio'],
        ['title' => 'Concentración Top 5', 'value' => $concentracion5, 'unit' => 'percent', 'icon' => 'fa-solid fa-users', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Peso top 5 clientes'],
        ['title' => 'Documentos Vencidos', 'value' => (int)($m['docs_vencidos'] ?? 0), 'unit' => 'number', 'icon' => 'fa-solid fa-file-circle-exclamation', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Cantidad de documentos'],
    ],
    'charts' => [
        'trend' => $trend,
        'aging' => ['labels' => $agingLabels, 'values' => $agingValues],
        'pareto' => ['categories' => $paretoCategories, 'exposure' => $paretoExposure, 'cumulative_pct' => $paretoCumulative],
        'heatmap' => ['regional_labels' => $regionalLabels, 'channel_labels' => $channelLabels, 'series' => $heatmapSeries],
        'top_exposure' => [
            'categories' => array_map(static fn(array $row): string => (string)$row['cliente'], $topExposureRows),
            'values' => array_map(static fn(array $row): float => (float)$row['valor'], $topExposureRows),
        ],
        'top_mora' => [
            'categories' => array_map(static fn(array $row): string => (string)$row['cliente'], $topMoraRows),
            'values' => array_map(static fn(array $row): float => (float)$row['valor'], $topMoraRows),
        ],
    ],
    'tables' => [
        'top_exposure' => array_map(static fn(array $row): array => ['cliente' => (string)$row['cliente'], 'valor' => (float)$row['valor']], $topExposureRows),
        'top_mora' => array_map(static fn(array $row): array => ['cliente' => (string)$row['cliente'], 'valor' => (float)$row['valor'], 'dias_promedio' => (float)$row['dias_promedio']], $topMoraRows),
    ],
    'insights' => [
        'risk_level' => $riskLevel,
        'dominant_aging_bucket' => $dominantAgingBucket,
        'overdue_ratio' => $overdueRatio,
        'top3_concentration' => $top3Concentration,
        'narrative' => 'Métricas generadas desde cartera activa con filtros dinámicos por periodo, regional y canal.',
    ],
    'debug_sql' => $debugSql,
]);
