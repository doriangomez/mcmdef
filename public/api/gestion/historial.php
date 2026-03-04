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

$documentoId = (int)($_GET['documento_id'] ?? 0);
if ($documentoId <= 0) {
    echo json_encode(['ok' => true, 'rows' => []]);
    exit;
}

$scope = portfolio_document_scope_sql('d');
$sql = 'SELECT g.id, g.created_at, g.tipo_gestion, g.observacion, g.compromiso_pago, g.valor_compromiso, g.estado_compromiso, u.nombre AS usuario
        FROM bitacora_gestion g
        INNER JOIN cartera_documentos d ON d.id = g.id_documento
        INNER JOIN usuarios u ON u.id = g.usuario_id
        WHERE g.id_documento = ?' . $scope['sql'] . '
        ORDER BY g.created_at DESC, g.id DESC
        LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$documentoId], $scope['params']));

echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
