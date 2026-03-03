<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(
        [
            'ok' => false,
            'message' => 'No autorizado.',
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function sanitize_filter_value(?string $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return substr($value, 0, 120);
}

function get_filters(): array
{
    return [
        'periodo' => sanitize_filter_value($_GET['periodo'] ?? ''),
        'regional' => sanitize_filter_value($_GET['regional'] ?? ''),
        'canal' => sanitize_filter_value($_GET['canal'] ?? ''),
        'uen' => sanitize_filter_value($_GET['uen'] ?? ''),
    ];
}

function build_conditions(array $filters, array $skip = []): array
{
    $conditions = [];
    $params = [];

    if (!in_array('periodo', $skip, true) && ($filters['periodo'] ?? '') !== '') {
        $conditions[] = 'd.periodo = ?';
        $params[] = $filters['periodo'];
    }

    foreach (['regional', 'canal', 'uen'] as $dimension) {
        if (in_array($dimension, $skip, true)) {
            continue;
        }
        if (($filters[$dimension] ?? '') === '') {
            continue;
        }
        $conditions[] = sprintf("COALESCE(NULLIF(TRIM(c.%s), ''), 'Sin dato') = ?", $dimension);
        $params[] = $filters[$dimension];
    }

    return [$conditions, $params];
}

function fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_row(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

function fetch_dimension_options(PDO $pdo, array $filters, string $dimension): array
{
    $allowed = ['regional', 'canal', 'uen'];
    if (!in_array($dimension, $allowed, true)) {
        return [];
    }

    [$conditions, $params] = build_conditions($filters, [$dimension]);
    $whereSql = empty($conditions) ? '' : (' WHERE ' . implode(' AND ', $conditions));

    $sql = 'SELECT DISTINCT ' .
            sprintf("COALESCE(NULLIF(TRIM(c.%s), ''), 'Sin dato') AS valor", $dimension) .
            ' FROM documentos d
              INNER JOIN clientes c ON c.id = d.cliente_id' .
            $whereSql .
            ' ORDER BY valor ASC';

    $rows = fetch_rows($pdo, $sql, $params);
    $values = [];
    foreach ($rows as $row) {
        $value = (string)($row['valor'] ?? '');
        if ($value !== '') {
            $values[] = $value;
        }
    }

    return array_values(array_unique($values));
}

function fetch_period_options(PDO $pdo, array $filters): array
{
    [$conditions, $params] = build_conditions($filters, ['periodo']);
    $conditions[] = "d.periodo IS NOT NULL";
    $conditions[] = "d.periodo <> ''";

    $whereSql = ' WHERE ' . implode(' AND ', $conditions);
    $sql = 'SELECT DISTINCT d.periodo AS valor
            FROM documentos d
            INNER JOIN clientes c ON c.id = d.cliente_id' .
            $whereSql .
            ' ORDER BY d.periodo DESC';

    $rows = fetch_rows($pdo, $sql, $params);
    $values = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['valor'] ?? ''));
        if ($value !== '') {
            $values[] = $value;
        }
    }
    return array_values(array_unique($values));
}

function empty_metrics(): array
{
    return [
        'exposure_total' => 0.0,
        'overdue_total' => 0.0,
        'active_clients' => 0,
        'total_documents' => 0,
        'overdue_documents' => 0,
        'avg_days_past_due' => 0.0,
    ];
}

function fetch_snapshot_metrics(PDO $pdo, array $filters): array
{
    [$conditions, $params] = build_conditions($filters);
    $whereSql = empty($conditions) ? '' : (' WHERE ' . implode(' AND ', $conditions));

    $sql = 'SELECT
                COALESCE(SUM(d.saldo_actual), 0) AS exposure_total,
                COALESCE(SUM(CASE WHEN d.estado_documento = "vencido" OR d.dias_mora > 0 THEN d.saldo_actual ELSE 0 END), 0) AS overdue_total,
                COUNT(DISTINCT d.cliente_id) AS active_clients,
                COUNT(*) AS total_documents,
                COALESCE(SUM(CASE WHEN d.estado_documento = "vencido" OR d.dias_mora > 0 THEN 1 ELSE 0 END), 0) AS overdue_documents,
                COALESCE(AVG(d.dias_mora), 0) AS avg_days_past_due
            FROM documentos d
            INNER JOIN clientes c ON c.id = d.cliente_id' .
            $whereSql;

    $row = fetch_row($pdo, $sql, $params);
    if (empty($row)) {
        return empty_metrics();
    }

    return [
        'exposure_total' => (float)$row['exposure_total'],
        'overdue_total' => (float)$row['overdue_total'],
        'active_clients' => (int)$row['active_clients'],
        'total_documents' => (int)$row['total_documents'],
        'overdue_documents' => (int)$row['overdue_documents'],
        'avg_days_past_due' => (float)$row['avg_days_past_due'],
    ];
}

function build_variation($current, $previous): array
{
    $currentValue = (float)$current;
    $previousValue = (float)$previous;
    $delta = $currentValue - $previousValue;

    if (abs($delta) < 0.00001) {
        $direction = 'flat';
    } elseif ($delta > 0) {
        $direction = 'up';
    } else {
        $direction = 'down';
    }

    if (abs($previousValue) < 0.00001) {
        $percent = abs($currentValue) < 0.00001 ? 0.0 : 100.0;
    } else {
        $percent = ($delta / abs($previousValue)) * 100;
    }

    return [
        'delta' => $delta,
        'percent' => $percent,
        'direction' => $direction,
    ];
}

function compact_client_label(array $row): string
{
    $name = trim((string)($row['nombre'] ?? ''));
    if ($name === '') {
        $name = 'Cliente sin nombre';
    }
    if (strlen($name) > 30) {
        $name = substr($name, 0, 27) . '...';
    }

    $nit = trim((string)($row['nit'] ?? ''));
    return $nit === '' ? $name : ($name . ' · ' . $nit);
}

function where_sql(array $conditions): string
{
    return empty($conditions) ? '' : (' WHERE ' . implode(' AND ', $conditions));
}

try {
    $filters = get_filters();

    $filterOptions = [
        'periodo' => fetch_period_options($pdo, $filters),
        'regional' => fetch_dimension_options($pdo, $filters, 'regional'),
        'canal' => fetch_dimension_options($pdo, $filters, 'canal'),
        'uen' => fetch_dimension_options($pdo, $filters, 'uen'),
    ];

    foreach (['periodo', 'regional', 'canal', 'uen'] as $filterName) {
        if (($filters[$filterName] ?? '') !== '' && !in_array($filters[$filterName], $filterOptions[$filterName], true)) {
            array_unshift($filterOptions[$filterName], $filters[$filterName]);
        }
    }

    $currentPeriod = $filters['periodo'] !== '' ? $filters['periodo'] : ($filterOptions['periodo'][0] ?? '');
    $previousPeriod = '';
    if ($currentPeriod !== '' && !empty($filterOptions['periodo'])) {
        $currentIndex = array_search($currentPeriod, $filterOptions['periodo'], true);
        if ($currentIndex !== false && isset($filterOptions['periodo'][$currentIndex + 1])) {
            $previousPeriod = $filterOptions['periodo'][$currentIndex + 1];
        } elseif ($currentIndex === false && isset($filterOptions['periodo'][0])) {
            $previousPeriod = $filterOptions['periodo'][0];
        }
    }

    $currentFilters = $filters;
    $currentFilters['periodo'] = $currentPeriod;
    $currentMetrics = fetch_snapshot_metrics($pdo, $currentFilters);

    $previousMetrics = empty_metrics();
    if ($previousPeriod !== '') {
        $previousFilters = $filters;
        $previousFilters['periodo'] = $previousPeriod;
        $previousMetrics = fetch_snapshot_metrics($pdo, $previousFilters);
    }

    $currentOverdueRatio = $currentMetrics['exposure_total'] > 0
        ? ($currentMetrics['overdue_total'] / $currentMetrics['exposure_total']) * 100
        : 0.0;
    $previousOverdueRatio = $previousMetrics['exposure_total'] > 0
        ? ($previousMetrics['overdue_total'] / $previousMetrics['exposure_total']) * 100
        : 0.0;

    $currentAvgTicket = $currentMetrics['active_clients'] > 0
        ? ($currentMetrics['exposure_total'] / $currentMetrics['active_clients'])
        : 0.0;
    $previousAvgTicket = $previousMetrics['active_clients'] > 0
        ? ($previousMetrics['exposure_total'] / $previousMetrics['active_clients'])
        : 0.0;

    $kpiBlueprints = [
        [
            'key' => 'exposure_total',
            'title' => 'Exposición total',
            'unit' => 'currency',
            'icon' => 'fa-solid fa-sack-dollar',
            'subtitle' => 'Saldo total administrado',
            'current' => $currentMetrics['exposure_total'],
            'previous' => $previousMetrics['exposure_total'],
            'positive_is_good' => true,
        ],
        [
            'key' => 'overdue_total',
            'title' => 'Cartera en mora',
            'unit' => 'currency',
            'icon' => 'fa-solid fa-triangle-exclamation',
            'subtitle' => 'Saldo vencido y expuesto',
            'current' => $currentMetrics['overdue_total'],
            'previous' => $previousMetrics['overdue_total'],
            'positive_is_good' => false,
        ],
        [
            'key' => 'overdue_ratio',
            'title' => 'Índice de mora',
            'unit' => 'percent',
            'icon' => 'fa-solid fa-wave-square',
            'subtitle' => 'Mora / exposición total',
            'current' => $currentOverdueRatio,
            'previous' => $previousOverdueRatio,
            'positive_is_good' => false,
        ],
        [
            'key' => 'active_clients',
            'title' => 'Clientes activos',
            'unit' => 'number',
            'icon' => 'fa-solid fa-users',
            'subtitle' => 'Clientes con saldo vigente',
            'current' => $currentMetrics['active_clients'],
            'previous' => $previousMetrics['active_clients'],
            'positive_is_good' => true,
        ],
        [
            'key' => 'avg_ticket',
            'title' => 'Ticket promedio',
            'unit' => 'currency',
            'icon' => 'fa-solid fa-chart-pie',
            'subtitle' => 'Saldo promedio por cliente',
            'current' => $currentAvgTicket,
            'previous' => $previousAvgTicket,
            'positive_is_good' => true,
        ],
        [
            'key' => 'avg_days_past_due',
            'title' => 'Mora promedio',
            'unit' => 'days',
            'icon' => 'fa-solid fa-calendar-days',
            'subtitle' => 'Días promedio de mora',
            'current' => $currentMetrics['avg_days_past_due'],
            'previous' => $previousMetrics['avg_days_past_due'],
            'positive_is_good' => false,
        ],
    ];

    $kpis = [];
    foreach ($kpiBlueprints as $kpi) {
        $variation = build_variation($kpi['current'], $kpi['previous']);
        $isImproving = $variation['direction'] === 'flat'
            ? true
            : ($kpi['positive_is_good']
                ? $variation['direction'] === 'up'
                : $variation['direction'] === 'down');

        $kpis[] = [
            'key' => $kpi['key'],
            'title' => $kpi['title'],
            'unit' => $kpi['unit'],
            'icon' => $kpi['icon'],
            'subtitle' => $kpi['subtitle'],
            'value' => (float)$kpi['current'],
            'previous_value' => (float)$kpi['previous'],
            'variation_pct' => (float)$variation['percent'],
            'variation_abs' => (float)$variation['delta'],
            'direction' => $variation['direction'],
            'is_improving' => $isImproving,
        ];
    }

    $periodsAsc = array_reverse($filterOptions['periodo']);
    $trendPeriods = [];

    if (!empty($periodsAsc)) {
        if ($currentPeriod === '') {
            $trendPeriods = array_slice($periodsAsc, max(count($periodsAsc) - 6, 0));
        } else {
            $currentIndexAsc = array_search($currentPeriod, $periodsAsc, true);
            if ($currentIndexAsc === false) {
                $currentIndexAsc = count($periodsAsc) - 1;
            }
            $trendPeriods = array_slice($periodsAsc, max(0, $currentIndexAsc - 5), 6);
        }
    } elseif ($currentPeriod !== '') {
        $trendPeriods = [$currentPeriod];
    }

    $trendByPeriod = [];
    if (!empty($trendPeriods)) {
        [$trendConditions, $trendParams] = build_conditions($filters, ['periodo']);
        $trendConditions[] = 'd.periodo IN (' . implode(',', array_fill(0, count($trendPeriods), '?')) . ')';
        $trendParams = array_merge($trendParams, $trendPeriods);

        $trendSql = 'SELECT
                        d.periodo,
                        COALESCE(SUM(d.saldo_actual), 0) AS exposure_total,
                        COALESCE(SUM(CASE WHEN d.estado_documento = "vencido" OR d.dias_mora > 0 THEN d.saldo_actual ELSE 0 END), 0) AS overdue_total
                     FROM documentos d
                     INNER JOIN clientes c ON c.id = d.cliente_id' .
                    where_sql($trendConditions) .
                    ' GROUP BY d.periodo';

        foreach (fetch_rows($pdo, $trendSql, $trendParams) as $row) {
            $period = (string)($row['periodo'] ?? '');
            $trendByPeriod[$period] = [
                'exposure_total' => (float)$row['exposure_total'],
                'overdue_total' => (float)$row['overdue_total'],
            ];
        }
    }

    $trendExposure = [];
    $trendOverdue = [];
    foreach ($trendPeriods as $period) {
        $trendExposure[] = (float)($trendByPeriod[$period]['exposure_total'] ?? 0.0);
        $trendOverdue[] = (float)($trendByPeriod[$period]['overdue_total'] ?? 0.0);
    }

    [$agingConditions, $agingParams] = build_conditions($currentFilters);
    $agingSql = 'SELECT
                    CASE
                        WHEN d.dias_mora <= 0 THEN "0 días"
                        WHEN d.dias_mora BETWEEN 1 AND 30 THEN "1-30"
                        WHEN d.dias_mora BETWEEN 31 AND 60 THEN "31-60"
                        WHEN d.dias_mora BETWEEN 61 AND 90 THEN "61-90"
                        WHEN d.dias_mora BETWEEN 91 AND 120 THEN "91-120"
                        ELSE "121+"
                    END AS bucket,
                    COALESCE(SUM(d.saldo_actual), 0) AS exposure_total
                FROM documentos d
                INNER JOIN clientes c ON c.id = d.cliente_id' .
                where_sql($agingConditions) .
                ' GROUP BY bucket';

    $agingRows = fetch_rows($pdo, $agingSql, $agingParams);
    $agingBuckets = ['0 días', '1-30', '31-60', '61-90', '91-120', '121+'];
    $agingMap = array_fill_keys($agingBuckets, 0.0);
    foreach ($agingRows as $row) {
        $bucket = (string)($row['bucket'] ?? '');
        if (array_key_exists($bucket, $agingMap)) {
            $agingMap[$bucket] = (float)$row['exposure_total'];
        }
    }

    [$paretoConditions, $paretoParams] = build_conditions($currentFilters);
    $paretoSql = 'SELECT
                    c.id,
                    c.nombre,
                    c.nit,
                    COALESCE(SUM(d.saldo_actual), 0) AS exposure_total
                FROM documentos d
                INNER JOIN clientes c ON c.id = d.cliente_id' .
                where_sql($paretoConditions) .
                ' GROUP BY c.id, c.nombre, c.nit
                  HAVING exposure_total > 0
                  ORDER BY exposure_total DESC
                  LIMIT 15';
    $paretoRows = fetch_rows($pdo, $paretoSql, $paretoParams);

    $paretoCategories = [];
    $paretoExposure = [];
    $paretoCumulative = [];
    $runningExposure = 0.0;
    $concentrationBase = $currentMetrics['exposure_total'] > 0 ? $currentMetrics['exposure_total'] : 0.0;
    foreach ($paretoRows as $row) {
        $exposure = (float)$row['exposure_total'];
        $paretoCategories[] = compact_client_label($row);
        $paretoExposure[] = $exposure;
        $runningExposure += $exposure;
        $paretoCumulative[] = $concentrationBase > 0 ? ($runningExposure / $concentrationBase) * 100 : 0.0;
    }

    [$heatmapConditions, $heatmapParams] = build_conditions($currentFilters);
    $heatmapSql = 'SELECT
                    COALESCE(NULLIF(TRIM(c.regional), ""), "Sin dato") AS regional,
                    COALESCE(NULLIF(TRIM(c.canal), ""), "Sin dato") AS canal,
                    COALESCE(SUM(d.saldo_actual), 0) AS exposure_total
                FROM documentos d
                INNER JOIN clientes c ON c.id = d.cliente_id' .
                where_sql($heatmapConditions) .
                ' GROUP BY regional, canal
                  ORDER BY regional ASC, canal ASC';
    $heatmapRows = fetch_rows($pdo, $heatmapSql, $heatmapParams);

    $regionalLabels = [];
    $channelLabels = [];
    foreach ($heatmapRows as $row) {
        $regionalLabels[] = (string)$row['regional'];
        $channelLabels[] = (string)$row['canal'];
    }
    $regionalLabels = array_values(array_unique($regionalLabels));
    $channelLabels = array_values(array_unique($channelLabels));
    sort($regionalLabels);
    sort($channelLabels);

    $matrix = [];
    foreach ($regionalLabels as $regional) {
        $matrix[$regional] = [];
        foreach ($channelLabels as $channel) {
            $matrix[$regional][$channel] = 0.0;
        }
    }
    foreach ($heatmapRows as $row) {
        $matrix[(string)$row['regional']][(string)$row['canal']] = (float)$row['exposure_total'];
    }

    $heatmapSeries = [];
    foreach ($regionalLabels as $regional) {
        $points = [];
        foreach ($channelLabels as $channel) {
            $points[] = [
                'x' => $channel,
                'y' => (float)$matrix[$regional][$channel],
            ];
        }
        $heatmapSeries[] = [
            'name' => $regional,
            'data' => $points,
        ];
    }

    $topExposureRows = array_slice($paretoRows, 0, 10);
    $topExposureList = [];
    foreach ($topExposureRows as $row) {
        $topExposureList[] = [
            'cliente' => compact_client_label($row),
            'valor' => (float)$row['exposure_total'],
            'nit' => (string)$row['nit'],
        ];
    }

    [$topMoraConditions, $topMoraParams] = build_conditions($currentFilters);
    $topMoraSql = 'SELECT
                    c.id,
                    c.nombre,
                    c.nit,
                    COALESCE(SUM(CASE WHEN d.dias_mora > 0 THEN d.saldo_actual ELSE 0 END), 0) AS overdue_exposure,
                    COALESCE(AVG(CASE WHEN d.dias_mora > 0 THEN d.dias_mora END), 0) AS avg_days
                FROM documentos d
                INNER JOIN clientes c ON c.id = d.cliente_id' .
                where_sql($topMoraConditions) .
                ' GROUP BY c.id, c.nombre, c.nit
                  HAVING overdue_exposure > 0
                  ORDER BY overdue_exposure DESC, avg_days DESC
                  LIMIT 10';
    $topMoraRows = fetch_rows($pdo, $topMoraSql, $topMoraParams);

    $topMoraList = [];
    foreach ($topMoraRows as $row) {
        $topMoraList[] = [
            'cliente' => compact_client_label($row),
            'valor' => (float)$row['overdue_exposure'],
            'dias_promedio' => (float)$row['avg_days'],
            'nit' => (string)$row['nit'],
        ];
    }

    $top3Exposure = 0.0;
    foreach (array_slice($topExposureList, 0, 3) as $item) {
        $top3Exposure += $item['valor'];
    }
    $concentrationTop3 = $currentMetrics['exposure_total'] > 0
        ? ($top3Exposure / $currentMetrics['exposure_total']) * 100
        : 0.0;

    $dominantAgingBucket = '0 días';
    $dominantAgingValue = 0.0;
    foreach ($agingMap as $bucket => $value) {
        if ((float)$value > $dominantAgingValue) {
            $dominantAgingValue = (float)$value;
            $dominantAgingBucket = $bucket;
        }
    }

    $riskLevel = 'Controlado';
    if ($currentOverdueRatio >= 40 || $concentrationTop3 >= 55 || $currentMetrics['avg_days_past_due'] >= 75) {
        $riskLevel = 'Alto';
    } elseif ($currentOverdueRatio >= 25 || $concentrationTop3 >= 40 || $currentMetrics['avg_days_past_due'] >= 45) {
        $riskLevel = 'Moderado';
    }

    $riskNarrative = sprintf(
        'Mora en %s: %.1f%% del portafolio; concentración Top 3: %.1f%%.',
        $dominantAgingBucket,
        $currentOverdueRatio,
        $concentrationTop3
    );

    echo json_encode(
        [
            'ok' => true,
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'generated_at_human' => date('d/m/Y H:i:s'),
                'selected_filters' => $filters,
                'current_period' => $currentPeriod,
                'previous_period' => $previousPeriod,
            ],
            'filter_options' => $filterOptions,
            'kpis' => $kpis,
            'charts' => [
                'trend' => [
                    'categories' => $trendPeriods,
                    'series' => [
                        [
                            'name' => 'Exposición total',
                            'data' => $trendExposure,
                        ],
                        [
                            'name' => 'Exposición en mora',
                            'data' => $trendOverdue,
                        ],
                    ],
                ],
                'aging' => [
                    'labels' => array_keys($agingMap),
                    'values' => array_values($agingMap),
                ],
                'pareto' => [
                    'categories' => $paretoCategories,
                    'exposure' => $paretoExposure,
                    'cumulative_pct' => $paretoCumulative,
                ],
                'heatmap' => [
                    'regional_labels' => $regionalLabels,
                    'channel_labels' => $channelLabels,
                    'series' => $heatmapSeries,
                ],
                'top_exposure' => [
                    'categories' => array_map(static fn(array $item): string => $item['cliente'], $topExposureList),
                    'values' => array_map(static fn(array $item): float => (float)$item['valor'], $topExposureList),
                ],
                'top_mora' => [
                    'categories' => array_map(static fn(array $item): string => $item['cliente'], $topMoraList),
                    'values' => array_map(static fn(array $item): float => (float)$item['valor'], $topMoraList),
                    'avg_days' => array_map(static fn(array $item): float => (float)$item['dias_promedio'], $topMoraList),
                ],
            ],
            'tables' => [
                'top_exposure' => $topExposureList,
                'top_mora' => $topMoraList,
            ],
            'insights' => [
                'risk_level' => $riskLevel,
                'dominant_aging_bucket' => $dominantAgingBucket,
                'overdue_ratio' => $currentOverdueRatio,
                'top3_concentration' => $concentrationTop3,
                'narrative' => $riskNarrative,
            ],
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(
        [
            'ok' => false,
            'message' => 'No fue posible calcular métricas del dashboard.',
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
}
