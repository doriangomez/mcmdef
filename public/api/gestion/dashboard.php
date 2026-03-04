<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$user = current_user();
$isAdmin = portfolio_is_admin($user);
$responsableId = $isAdmin ? (int)($_GET['responsable_id'] ?? 0) : (int)($user['id'] ?? 0);
$params = [];
$where = ["d.estado_documento = 'activo'"];
$scope = portfolio_document_scope_sql('d', $user);
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
    $params = array_merge($params, $scope['params']);
}
if ($responsableId > 0) {
    $where[] = 'c.responsable_usuario_id = ?';
    $params[] = $responsableId;
}
$whereSql = implode(' AND ', $where);

$kpi = $pdo->prepare('SELECT COALESCE(SUM(d.saldo_pendiente),0) cartera_total,
                             SUM(CASE WHEN d.dias_vencido > 0 THEN 1 ELSE 0 END) docs_mora,
                             COUNT(DISTINCT CASE WHEN d.dias_vencido > 0 THEN d.cliente_id ELSE NULL END) clientes_mora
                      FROM cartera_documentos d
                      INNER JOIN clientes c ON c.id=d.cliente_id
                      WHERE ' . $whereSql);
$kpi->execute($params);
$base = $kpi->fetch(PDO::FETCH_ASSOC) ?: [];

$prom = $pdo->prepare('SELECT SUM(CASE WHEN COALESCE(g.estado_compromiso,"pendiente")="pendiente" THEN 1 ELSE 0 END) pendientes,
                              SUM(CASE WHEN COALESCE(g.estado_compromiso,"pendiente")="incumplido" THEN 1 ELSE 0 END) incumplidas
                       FROM bitacora_gestion g
                       INNER JOIN cartera_documentos d ON d.id=g.id_documento
                       INNER JOIN clientes c ON c.id=d.cliente_id
                       WHERE ' . $whereSql);
$prom->execute($params);
$promesas = $prom->fetch(PDO::FETCH_ASSOC) ?: [];

$gest = $pdo->prepare('SELECT SUM(CASE WHEN DATE(g.created_at)=CURDATE() THEN COALESCE(g.valor_compromiso,0) ELSE 0 END) recuperado_hoy,
                              SUM(CASE WHEN YEARWEEK(g.created_at,1)=YEARWEEK(CURDATE(),1) THEN COALESCE(g.valor_compromiso,0) ELSE 0 END) recuperado_semana,
                              SUM(CASE WHEN DATE_FORMAT(g.created_at,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m") THEN COALESCE(g.valor_compromiso,0) ELSE 0 END) recuperado_mes
                       FROM bitacora_gestion g
                       INNER JOIN cartera_documentos d ON d.id=g.id_documento
                       INNER JOIN clientes c ON c.id=d.cliente_id
                       WHERE ' . $whereSql);
$gest->execute($params);
$rec = $gest->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['ok' => true, 'kpis' => array_merge($base, $promesas, $rec)]);
