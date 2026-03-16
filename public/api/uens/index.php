<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/UenService.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado.']);
    exit;
}

$periodo = trim((string)($_GET['periodo'] ?? ''));
if ($periodo === '' || !preg_match('/^\d{4}-\d{2}$/', $periodo)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Periodo inválido. Use formato YYYY-MM.']);
    exit;
}

$stmt = $pdo->prepare("SELECT DISTINCT uens FROM cartera_documentos WHERE estado_documento = 'activo' AND DATE_FORMAT(COALESCE(fecha_contabilizacion, created_at), '%Y-%m') = ? AND uens IS NOT NULL AND TRIM(uens) <> '' ORDER BY uens");
$stmt->execute([$periodo]);
$uens = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$allowedUens = uen_user_allowed_values($pdo);
if (!empty($allowedUens)) {
    $uens = array_values(array_intersect($uens, $allowedUens));
}

echo json_encode([
    'ok' => true,
    'periodo' => $periodo,
    'uens' => $uens,
    'empty_message' => empty($uens) ? 'No existen UEN registradas para este periodo' : '',
]);
