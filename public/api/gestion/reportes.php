<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

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

$where = ["d.estado_documento = 'activo'"];
$params = [];
$scope = portfolio_document_scope_sql('d');
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
    $params = array_merge($params, $scope['params']);
}
$wsql = implode(' AND ', $where);

$sql = '';
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
    fputcsv($out, ['categoria', 'total']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['categoria'], $row['total']]);
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'rows' => $rows]);
