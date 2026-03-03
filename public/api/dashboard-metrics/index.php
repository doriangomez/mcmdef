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
$filters = ['periodo' => f('periodo'), 'regional' => f('regional'), 'canal' => f('canal')];

$where = [];
$params = [];
if ($filters['periodo'] !== '') { $where[] = "DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m') = ?"; $params[] = $filters['periodo']; }
if ($filters['regional'] !== '') { $where[] = "COALESCE(NULLIF(TRIM(c.regional), ''), 'Sin dato') = ?"; $params[] = $filters['regional']; }
if ($filters['canal'] !== '') { $where[] = "COALESCE(NULLIF(TRIM(c.canal), ''), 'Sin dato') = ?"; $params[] = $filters['canal']; }
$where[] = "d.estado_documento = 'activo'";
$whereSql = ' WHERE ' . implode(' AND ', $where);

$baseSql = ' FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id ' . $whereSql;
$stmt = $pdo->prepare('SELECT COALESCE(SUM(d.saldo_pendiente),0) cartera_total, COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) cartera_vencida, COALESCE(AVG(d.dias_vencido),0) aging_promedio, COUNT(*) documentos, COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN 1 ELSE 0 END),0) docs_vencidos' . $baseSql);
$stmt->execute($params);
$m = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$carteraTotal = (float)($m['cartera_total'] ?? 0);
$carteraVencida = (float)($m['cartera_vencida'] ?? 0);

$top5 = $pdo->prepare('SELECT COALESCE(SUM(x.saldo),0) FROM (SELECT COALESCE(SUM(d.saldo_pendiente),0) saldo FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id ' . $whereSql . ' GROUP BY c.id ORDER BY saldo DESC LIMIT 5) x');
$top5->execute($params);
$top5Total = (float)$top5->fetchColumn();
$concentracion5 = $carteraTotal > 0 ? ($top5Total / $carteraTotal) * 100 : 0;

$periods = $pdo->query("SELECT DISTINCT DATE_FORMAT(fecha_contabilizacion, '%Y-%m') p FROM cartera_documentos WHERE estado_documento = 'activo' ORDER BY p DESC")->fetchAll(PDO::FETCH_COLUMN);
$regionales = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(regional), ''), 'Sin dato') v FROM clientes ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
$canales = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(canal), ''), 'Sin dato') v FROM clientes ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'ok' => true,
    'meta' => ['generated_at_human' => date('d/m/Y H:i:s'), 'selected_filters' => $filters, 'current_period' => $filters['periodo'], 'previous_period' => ''],
    'filter_options' => ['periodo' => $periods, 'regional' => $regionales, 'canal' => $canales],
    'kpis' => [
        ['title' => 'Cartera Total', 'value' => $carteraTotal, 'unit' => 'currency', 'icon' => 'fa-solid fa-sack-dollar', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Saldo pendiente total'],
        ['title' => 'Cartera Vencida', 'value' => $carteraVencida, 'unit' => 'currency', 'icon' => 'fa-solid fa-triangle-exclamation', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Dias vencido > 0'],
        ['title' => '% Cartera Vencida', 'value' => $carteraTotal > 0 ? ($carteraVencida / $carteraTotal) * 100 : 0, 'unit' => 'percent', 'icon' => 'fa-solid fa-percent', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Participación vencida'],
        ['title' => 'Aging Promedio', 'value' => (float)($m['aging_promedio'] ?? 0), 'unit' => 'days', 'icon' => 'fa-solid fa-calendar-days', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Días vencido promedio'],
        ['title' => 'Concentración Top 5', 'value' => $concentracion5, 'unit' => 'percent', 'icon' => 'fa-solid fa-users', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Peso top 5 clientes'],
        ['title' => 'Documentos Vencidos', 'value' => (int)($m['docs_vencidos'] ?? 0), 'unit' => 'number', 'icon' => 'fa-solid fa-file-circle-exclamation', 'variation_pct' => 0, 'direction' => 'flat', 'is_improving' => true, 'subtitle' => 'Cantidad de documentos'],
    ],
    'charts' => ['trend' => ['categories' => [], 'series' => []], 'aging' => ['labels' => ['Actual', '1-30', '31-60', '61-90', '91-180', '181-360', '361+'], 'values' => [0,0,0,0,0,0,0]], 'pareto' => ['categories' => [], 'exposure' => [], 'cumulative_pct' => []], 'heatmap' => ['regional_labels' => [], 'channel_labels' => [], 'series' => []], 'top_exposure' => ['categories' => [], 'values' => []], 'top_mora' => ['categories' => [], 'values' => []]],
    'tables' => ['top_exposure' => [], 'top_mora' => []],
    'insights' => ['risk_level' => 'Controlado', 'dominant_aging_bucket' => 'Actual', 'overdue_ratio' => $carteraTotal > 0 ? ($carteraVencida / $carteraTotal) * 100 : 0, 'top3_concentration' => 0, 'narrative' => 'Métricas alineadas a la nueva estructura SAP.'],
]);
