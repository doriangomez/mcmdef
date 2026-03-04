<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

require_role(['admin', 'analista']);

$documentoId = (int)($_GET['documento_id'] ?? $_POST['documento_id'] ?? 0);
$clienteId = (int)($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);

if ($clienteId <= 0 && $documentoId > 0) {
    $scope = portfolio_document_scope_sql('d');
    $clienteStmt = $pdo->prepare('SELECT d.cliente_id FROM cartera_documentos d WHERE d.id = ?' . $scope['sql'] . ' LIMIT 1');
    $clienteStmt->execute(array_merge([$documentoId], $scope['params']));
    $clienteId = (int)$clienteStmt->fetchColumn();
}

$query = [];
if ($clienteId > 0) {
    $query['cliente_id'] = $clienteId;
}
if ($documentoId > 0) {
    $query['documento_id'] = $documentoId;
}

$target = app_url('gestion/detalle.php');
if (!empty($query)) {
    $target .= '?' . http_build_query($query) . '#registro-gestion';
}

header('Location: ' . $target);
exit;
