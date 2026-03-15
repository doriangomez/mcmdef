<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';
require_once __DIR__ . '/../../../app/services/UenService.php';

if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
    exit;
}

$tipo = trim((string)($_GET['tipo'] ?? 'cartera_regional'));
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
$format = trim((string)($_GET['format'] ?? 'json'));
$selectedUens = uen_requested_values('uen');
$allowedUens = uen_user_allowed_values($pdo);
$selectedUens = uen_apply_scope($selectedUens, $allowedUens);

$where = ["d.estado_documento = 'activo'"];
$params = [];
$scope = portfolio_document_scope_sql('d');
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
    $params = array_merge($params, $scope['params']);
}
$uenScope = uen_sql_condition('d.uens', $selectedUens);
if ($uenScope['sql'] !== '') {
    $where[] = ltrim($uenScope['sql'], ' AND');
    $params = array_merge($params, $uenScope['params']);
}
$wsql = implode(' AND ', $where);

$sql = '';
    $csvHeaders = ['categoria', 'total'];
if ($tipo === 'cartera_regional') {
    $sql = 'SELECT COALESCE(c.regional,"Sin regional") AS categoria, SUM(d.saldo_pendiente) AS total FROM cartera_documentos d INNER JOIN clientes c ON c.id=d.cliente_id WHERE ' . $wsql . ' GROUP BY c.regional ORDER BY total DESC';
} elseif ($tipo === 'cartera_canal') {
    $sql = 'SELECT COALESCE(c.canal,"Sin canal") AS categoria, SUM(d.saldo_pendiente) AS total FROM cartera_documentos d INNER JOIN clientes c ON c.id=d.cliente_id WHERE ' . $wsql . ' GROUP BY c.canal ORDER BY total DESC';
} elseif ($tipo === 'cartera_gestor') {
    $sql = 'SELECT COALESCE(u.nombre,"Sin gestor") AS categoria, SUM(d.saldo_pendiente) AS total FROM cartera_documentos d INNER JOIN clientes c ON c.id=d.cliente_id LEFT JOIN usuarios u ON u.id=c.responsable_usuario_id WHERE ' . $wsql . ' GROUP BY u.nombre ORDER BY total DESC';
} elseif ($tipo === 'promesas_pendientes') {
    $sql = 'SELECT c.nombre AS categoria, COUNT(*) AS total FROM bitacora_gestion g INNER JOIN cartera_documentos d ON d.id=g.id_documento INNER JOIN clientes c ON c.id=d.cliente_id WHERE ' . $wsql . ' AND COALESCE(g.estado_compromiso,"pendiente")="pendiente" GROUP BY c.nombre ORDER BY total DESC';
} elseif ($tipo === 'promesas_incumplidas') {
    $sql = 'SELECT c.nombre AS categoria, COUNT(*) AS total FROM bitacora_gestion g INNER JOIN cartera_documentos d ON d.id=g.id_documento INNER JOIN clientes c ON c.id=d.cliente_id WHERE ' . $wsql . ' AND COALESCE(g.estado_compromiso,"pendiente")="incumplido" GROUP BY c.nombre ORDER BY total DESC';
} elseif ($tipo === 'analisis_vencimiento') {
    $sql = 'SELECT
                d.cliente AS cliente,
                d.nro_documento AS documento,
                d.saldo_pendiente AS saldo,
                d.dias_vencido AS dias_vencido,
                d.bucket_actual AS actual,
                d.bucket_1_30 AS bucket_1_30,
                d.bucket_31_60 AS bucket_31_60,
                d.bucket_61_90 AS bucket_61_90,
                d.bucket_91_180 AS bucket_91_180,
                d.bucket_181_360 AS bucket_181_360,
                d.bucket_361_plus AS bucket_361_plus,
                d.canal AS canal,
                d.regional AS regional,
                c.empleado_ventas AS asesor,
                d.uens AS uen
            FROM cartera_documentos d
            INNER JOIN clientes c ON c.id=d.cliente_id
            WHERE ' . $wsql . '
            ORDER BY d.saldo_pendiente DESC';
    $csvHeaders = ['Cliente', 'Documento', 'Saldo', 'Dias vencido', 'Actual', '1-30 Días', '31-60 Días', '61-90 Días', '91-180 Días', '181-360 Días', '361+ Días', 'Canal', 'Regional', 'Empleado de ventas', 'UENS'];
} else {
    $extra = '';
    if ($desde !== '') { $extra .= ' AND DATE(g.created_at) >= ?'; $params[] = $desde; }
    if ($hasta !== '') { $extra .= ' AND DATE(g.created_at) <= ?'; $params[] = $hasta; }
    $sql = 'SELECT u.nombre AS categoria, COUNT(*) AS total FROM bitacora_gestion g INNER JOIN usuarios u ON u.id=g.usuario_id INNER JOIN cartera_documentos d ON d.id=g.id_documento INNER JOIN clientes c ON c.id=d.cliente_id WHERE ' . $wsql . $extra . ' GROUP BY u.nombre ORDER BY total DESC';
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $tipo . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, $csvHeaders);
    foreach ($rows as $row) {
        if ($tipo === 'analisis_vencimiento') {
            fputcsv($out, [
                $row['cliente'] ?? '',
                $row['documento'] ?? '',
                $row['saldo'] ?? 0,
                $row['dias_vencido'] ?? 0,
                $row['actual'] ?? 0,
                $row['bucket_1_30'] ?? 0,
                $row['bucket_31_60'] ?? 0,
                $row['bucket_61_90'] ?? 0,
                $row['bucket_91_180'] ?? 0,
                $row['bucket_181_360'] ?? 0,
                $row['bucket_361_plus'] ?? 0,
                $row['canal'] ?? '',
                $row['regional'] ?? '',
                $row['asesor'] ?? '',
                $row['uen'] ?? '',
            ]);
            continue;
        }
        fputcsv($out, [$row['categoria'] ?? '', $row['total'] ?? 0]);
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'rows' => $rows]);
