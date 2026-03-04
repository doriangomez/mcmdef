<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || (current_user()['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$usuarioId = (int)($payload['responsable_usuario_id'] ?? 0);
$clienteIds = $payload['cliente_ids'] ?? [];
if (!is_array($clienteIds)) {
    $clienteIds = [];
}
$clienteIds = array_values(array_unique(array_filter(array_map('intval', $clienteIds), static fn (int $id): bool => $id > 0)));

if ($usuarioId <= 0 || empty($clienteIds)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Datos incompletos']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($clienteIds), '?'));
$stmt = $pdo->prepare('UPDATE clientes SET responsable_usuario_id = ? WHERE id IN (' . $placeholders . ')');
$stmt->execute(array_merge([$usuarioId], $clienteIds));

echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
