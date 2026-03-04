<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$documentoId = (int)($payload['documento_id'] ?? 0);
$tipo = trim((string)($payload['tipo_gestion'] ?? ''));
$observacion = trim((string)($payload['observacion'] ?? ''));
$hasCompromiso = !empty($payload['tiene_compromiso']);
$fechaCompromiso = trim((string)($payload['fecha_compromiso'] ?? ''));
$valorCompromiso = trim((string)($payload['valor_compromiso'] ?? ''));
$estadoCompromiso = trim((string)($payload['estado_compromiso'] ?? 'pendiente'));

if ($documentoId <= 0 || $tipo === '' || $observacion === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Documento, tipo y observación son obligatorios']);
    exit;
}
if (!in_array($estadoCompromiso, ['pendiente', 'cumplido', 'incumplido'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Estado compromiso inválido']);
    exit;
}

$scope = portfolio_document_scope_sql('d');
$docStmt = $pdo->prepare('SELECT d.id FROM cartera_documentos d WHERE d.id = ?' . $scope['sql'] . ' LIMIT 1');
$docStmt->execute(array_merge([$documentoId], $scope['params']));
if (!$docStmt->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sin permisos sobre el documento']);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO bitacora_gestion (id_documento, usuario_id, tipo_gestion, observacion, compromiso_pago, valor_compromiso, estado_compromiso, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
$stmt->execute([
    $documentoId,
    (int)(current_user()['id'] ?? 0),
    $tipo,
    $observacion,
    $hasCompromiso && $fechaCompromiso !== '' ? $fechaCompromiso : null,
    $hasCompromiso && $valorCompromiso !== '' ? $valorCompromiso : null,
    $hasCompromiso ? $estadoCompromiso : null,
]);

echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
