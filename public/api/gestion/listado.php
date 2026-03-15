<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';
require_once __DIR__ . '/../../../app/services/UenService.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado']);
    exit;
}

$user = current_user();
$isAdmin = portfolio_is_admin($user);
$search = trim((string)($_GET['q'] ?? ''));
$regional = trim((string)($_GET['regional'] ?? ''));
$canal = trim((string)($_GET['canal'] ?? ''));
$moraRango = trim((string)($_GET['mora_rango'] ?? ''));
$estadoGestion = trim((string)($_GET['estado_gestion'] ?? ''));
$estadoCompromiso = trim((string)($_GET['estado_compromiso'] ?? ''));
$responsableId = $isAdmin ? (int)($_GET['responsable_id'] ?? 0) : (int)($user['id'] ?? 0);

$where = ['d.estado_documento = "activo"'];
$params = [];

$scope = portfolio_document_scope_sql('d', $user);
$selectedUens = uen_apply_scope(uen_requested_values('uens'), uen_user_allowed_values($pdo, $user));
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
    $params = array_merge($params, $scope['params']);
}
$uenScope = uen_sql_condition('d.uens', $selectedUens);
if ($uenScope['sql'] !== '') {
    $where[] = ltrim($uenScope['sql'], ' AND');
    $params = array_merge($params, $uenScope['params']);
}

if ($responsableId > 0) {
    $where[] = 'c.responsable_usuario_id = ?';
    $params[] = $responsableId;
}
if ($regional !== '') {
    $where[] = 'LOWER(COALESCE(c.regional, "")) LIKE LOWER(?)';
    $params[] = '%' . $regional . '%';
}
if ($canal !== '') {
    $where[] = 'LOWER(COALESCE(c.canal, "")) LIKE LOWER(?)';
    $params[] = '%' . $canal . '%';
}
if ($search !== '') {
    $where[] = '(c.nombre LIKE ? OR c.nit LIKE ? OR c.cuenta LIKE ? OR d.nro_documento LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($moraRango !== '') {
    if ($moraRango === '0-30') {
        $where[] = 'd.dias_vencido BETWEEN 0 AND 30';
    } elseif ($moraRango === '31-60') {
        $where[] = 'd.dias_vencido BETWEEN 31 AND 60';
    } elseif ($moraRango === '61-90') {
        $where[] = 'd.dias_vencido BETWEEN 61 AND 90';
    } elseif ($moraRango === '+90') {
        $where[] = 'd.dias_vencido > 90';
    }
}
if ($estadoGestion === 'sin_gestion') {
    $where[] = 'ult.id IS NULL';
}
if ($estadoGestion === 'con_gestion') {
    $where[] = 'ult.id IS NOT NULL';
}
if ($estadoCompromiso !== '') {
    $where[] = 'COALESCE(ult.estado_compromiso, "") = ?';
    $params[] = $estadoCompromiso;
}

$sql = 'SELECT
            d.id,
            d.nro_documento,
            d.saldo_pendiente,
            d.dias_vencido,
            d.fecha_contabilizacion,
            c.id AS cliente_id,
            c.nombre AS cliente,
            c.nit,
            c.cuenta,
            c.direccion,
            c.telefono,
            c.canal,
            c.regional,
            u.nombre AS responsable,
            ult.id AS ultima_gestion_id,
            ult.created_at AS fecha_ultima_gestion,
            ult.observacion AS ultima_observacion,
            ult.tipo_gestion AS ultima_tipo,
            ult.compromiso_pago,
            ult.valor_compromiso,
            ult.estado_compromiso,
            CASE
                WHEN ult.compromiso_pago IS NOT NULL AND COALESCE(ult.estado_compromiso, "pendiente") = "pendiente" AND ult.compromiso_pago < CURDATE() THEN 0
                WHEN ult.id IS NULL THEN 1
                ELSE 2
            END AS prioridad_grupo
        FROM cartera_documentos d
        INNER JOIN clientes c ON c.id = d.cliente_id
        LEFT JOIN usuarios u ON u.id = c.responsable_usuario_id
        LEFT JOIN (
            SELECT g1.*
            FROM bitacora_gestion g1
            INNER JOIN (
                SELECT id_documento, MAX(id) AS last_id
                FROM bitacora_gestion
                GROUP BY id_documento
            ) lg ON lg.last_id = g1.id
        ) ult ON ult.id_documento = d.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY prioridad_grupo ASC, d.dias_vencido DESC, d.saldo_pendiente DESC
        LIMIT 500';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['ok' => true, 'rows' => $rows]);
