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

function qf(string $key): string
{
    return trim((string)($_GET[$key] ?? ''));
}

function normalize(string $value): string
{
    return mb_strtolower(trim($value));
}

$rawFilters = ['periodo' => qf('periodo'), 'regional' => qf('regional'), 'canal' => qf('canal')];

$periodOptions = $pdo->query("SELECT DISTINCT DATE_FORMAT(fecha_contabilizacion, '%Y-%m') p FROM cartera_documentos WHERE estado_documento = 'activo' ORDER BY p DESC")->fetchAll(PDO::FETCH_COLUMN);
$regionalOptions = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(d.regional), ''), NULLIF(TRIM(c.regional), ''), 'Sin dato') v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
$canalOptions = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(d.canal), ''), NULLIF(TRIM(c.canal), ''), 'Sin dato') v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);

$periodSet = array_fill_keys(array_map('strval', $periodOptions), true);
$regionalSet = [];
foreach ($regionalOptions as $value) {
    $regionalSet[normalize((string)$value)] = true;
}
$canalSet = [];
foreach ($canalOptions as $value) {
    $canalSet[normalize((string)$value)] = true;
}

$filters = [
    'periodo' => isset($periodSet[$rawFilters['periodo']]) ? $rawFilters['periodo'] : '',
    'regional' => isset($regionalSet[normalize($rawFilters['regional'])]) ? $rawFilters['regional'] : '',
    'canal' => isset($canalSet[normalize($rawFilters['canal'])]) ? $rawFilters['canal'] : '',
];

$regionalExpr = "COALESCE(NULLIF(TRIM(d.regional), ''), NULLIF(TRIM(c.regional), ''), 'Sin dato')";
$canalExpr = "COALESCE(NULLIF(TRIM(d.canal), ''), NULLIF(TRIM(c.canal), ''), 'Sin dato')";
$monthExpr = "DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m')";

$where = ["d.estado_documento = 'activo'"];
$params = [];
if ($filters['periodo'] !== '') {
    $where[] = "$monthExpr = ?";
    $params[] = $filters['periodo'];
}
if ($filters['regional'] !== '') {
    $where[] = "LOWER(TRIM($regionalExpr)) = LOWER(TRIM(?))";
    $params[] = $filters['regional'];
}
if ($filters['canal'] !== '') {
    $where[] = "LOWER(TRIM($canalExpr)) = LOWER(TRIM(?))";
    $params[] = $filters['canal'];
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$kpiSql = "SELECT
    COALESCE(SUM(d.saldo_pendiente),0) cartera_total,
    COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida,
    COALESCE(SUM(d.saldo_pendiente * GREATEST(d.dias_vencido,0)) / NULLIF(SUM(d.saldo_pendiente),0),0) dias_prom_ponderados,
    COALESCE(AVG(d.saldo_pendiente),0) ticket_promedio,
    COALESCE(SUM(d.bucket_91_180 + d.bucket_181_360 + d.bucket_361_plus),0) saldo_91_plus,
    COUNT(*) total_docs
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
    $aging[] = [
        'bucket' => $def['label'],
        'value' => $value,
        'pct' => $carteraTotal > 0 ? ($value / $carteraTotal) * 100 : 0,
        'avg_days' => $def['avg'],
    ];
}

$trendSql = "SELECT $monthExpr AS periodo, COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    " . str_replace("$monthExpr = ? AND ", '', $whereSql) . "
    GROUP BY periodo
    ORDER BY periodo ASC";
$trendParams = $params;
if ($filters['periodo'] !== '') {
    array_shift($trendParams);
}
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

$topClientSql = "SELECT COALESCE(NULLIF(TRIM(c.nombre), ''), c.cuenta, CONCAT('Cliente #', c.id)) cliente,
    COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY c.id, c.nombre, c.cuenta
    ORDER BY saldo DESC
    LIMIT 10";
$topStmt = $pdo->prepare($topClientSql);
$topStmt->execute($params);
$topRows = $topStmt->fetchAll(PDO::FETCH_ASSOC);
$topClients = array_map(static fn(array $r): array => [
    'cliente' => (string)$r['cliente'],
    'saldo' => (float)$r['saldo'],
    'pct' => $carteraTotal > 0 ? ((float)$r['saldo'] / $carteraTotal) * 100 : 0,
], $topRows);
$top10Pct = array_reduce($topClients, static fn(float $acc, array $r): float => $acc + $r['pct'], 0.0);

$regionalSql = "SELECT $regionalExpr regional, COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY regional
    ORDER BY saldo DESC";
$regionalStmt = $pdo->prepare($regionalSql);
$regionalStmt->execute($params);
$regionalRows = $regionalStmt->fetchAll(PDO::FETCH_ASSOC);
$regionalData = array_map(static fn(array $r): array => [
    'regional' => (string)$r['regional'],
    'saldo' => (float)$r['saldo'],
    'pct' => $carteraTotal > 0 ? ((float)$r['saldo'] / $carteraTotal) * 100 : 0,
], $regionalRows);

$canalSql = "SELECT $canalExpr canal, COALESCE(SUM(d.saldo_pendiente),0) saldo
    FROM cartera_documentos d
    INNER JOIN clientes c ON c.id = d.cliente_id
    $whereSql
    GROUP BY canal
    ORDER BY saldo DESC";
$canalStmt = $pdo->prepare($canalSql);
$canalStmt->execute($params);
$canalRows = $canalStmt->fetchAll(PDO::FETCH_ASSOC);
$canalData = array_map(static fn(array $r): array => [
    'canal' => (string)$r['canal'],
    'saldo' => (float)$r['saldo'],
    'pct' => $carteraTotal > 0 ? ((float)$r['saldo'] / $carteraTotal) * 100 : 0,
], $canalRows);

$heatmapSql = "SELECT regional, bucket, SUM(valor) saldo
FROM (
    SELECT $regionalExpr regional, 'Actual' bucket, d.bucket_actual valor FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id $whereSql
    UNION ALL
    SELECT $regionalExpr regional, '1-30' bucket, d.bucket_1_30 valor FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id $whereSql
    UNION ALL
    SELECT $regionalExpr regional, '31-60' bucket, d.bucket_31_60 valor FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id $whereSql
    UNION ALL
    SELECT $regionalExpr regional, '61-90' bucket, d.bucket_61_90 valor FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id $whereSql
    UNION ALL
    SELECT $regionalExpr regional, '91-180' bucket, d.bucket_91_180 valor FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id $whereSql
    UNION ALL
    SELECT $regionalExpr regional, '181-360' bucket, d.bucket_181_360 valor FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id $whereSql
    UNION ALL
    SELECT $regionalExpr regional, '361+' bucket, d.bucket_361_plus valor FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id $whereSql
) x
GROUP BY regional, bucket
ORDER BY regional, bucket";
$heatParams = array_merge($params, $params, $params, $params, $params, $params, $params);
$heatStmt = $pdo->prepare($heatmapSql);
$heatStmt->execute($heatParams);
$heatRows = $heatStmt->fetchAll(PDO::FETCH_ASSOC);

$bucketOrder = ['Actual', '1-30', '31-60', '61-90', '91-180', '181-360', '361+'];
$regTotals = [];
$matrix = [];
foreach ($heatRows as $row) {
    $reg = (string)$row['regional'];
    $bucket = (string)$row['bucket'];
    $saldo = (float)$row['saldo'];
    $regTotals[$reg] = ($regTotals[$reg] ?? 0) + $saldo;
    $matrix[$reg][$bucket] = $saldo;
}
$heatSeries = [];
foreach ($matrix as $regional => $bucketValues) {
    $points = [];
    foreach ($bucketOrder as $bucket) {
        $saldo = (float)($bucketValues[$bucket] ?? 0);
        $points[] = [
            'x' => $bucket,
            'y' => $saldo,
            'pct_regional' => ($regTotals[$regional] ?? 0) > 0 ? ($saldo / $regTotals[$regional]) * 100 : 0,
        ];
    }
    $heatSeries[] = ['name' => $regional, 'data' => $points];
}

$daysComponent = min(((float)($m['dias_prom_ponderados'] ?? 0) / 180) * 100, 100);
$scorePenalty = ($porcVencida * 0.35) + ($daysComponent * 0.25) + ($top10Pct * 0.20) + ($porc91Plus * 0.20);
$score = max(0, min(100, 100 - $scorePenalty));

echo json_encode([
    'ok' => true,
    'meta' => [
        'generated_at_human' => date('d/m/Y H:i:s'),
        'selected_filters' => $filters,
    ],
    'filter_options' => ['periodo' => $periodOptions, 'regional' => $regionalOptions, 'canal' => $canalOptions],
    'kpis' => [
        ['title' => 'Cartera Total', 'value' => $carteraTotal, 'unit' => 'currency', 'icon' => 'fa-solid fa-sack-dollar', 'tooltip' => 'Suma total del saldo pendiente de todos los documentos según filtros aplicados.'],
        ['title' => 'Cartera Vencida', 'value' => $carteraVencida, 'unit' => 'currency', 'icon' => 'fa-solid fa-triangle-exclamation', 'tooltip' => 'Saldo pendiente acumulado en documentos con días vencidos mayores a cero.'],
        ['title' => '% Cartera Vencida', 'value' => $porcVencida, 'unit' => 'percent', 'icon' => 'fa-solid fa-percent', 'tooltip' => 'Proporción del saldo pendiente que se encuentra en tramos vencidos respecto al total.'],
        ['title' => 'Días Promedio Ponderados', 'value' => (float)($m['dias_prom_ponderados'] ?? 0), 'unit' => 'days', 'icon' => 'fa-solid fa-calendar-days', 'tooltip' => 'Promedio de días vencidos ponderado por el saldo pendiente de cada documento.'],
        ['title' => 'Ticket Promedio', 'value' => (float)($m['ticket_promedio'] ?? 0), 'unit' => 'currency', 'icon' => 'fa-solid fa-receipt', 'tooltip' => 'Saldo pendiente promedio por documento bajo los filtros seleccionados.'],
    ],
    'charts' => [
        'aging' => $aging,
        'trend' => $trend,
        'top_clients' => $topClients,
        'regional' => $regionalData,
        'canal' => $canalData,
        'heatmap' => ['series' => $heatSeries],
        'score' => [
            'value' => $score,
            'components' => [
                'pct_vencido' => $porcVencida,
                'dias_promedio' => (float)($m['dias_prom_ponderados'] ?? 0),
                'concentracion_top10' => $top10Pct,
                'pct_91_plus' => $porc91Plus,
            ],
            'tooltip' => 'Indicador compuesto que refleja el nivel general de riesgo de la cartera bajo los filtros actuales.',
        ],
    ],
    'empty' => $carteraTotal <= 0,
]);
