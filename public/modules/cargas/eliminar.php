<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_once __DIR__ . '/../../../app/services/CargaDeletionService.php';

require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    carga_delete_redirect_with_flash('error', 'Método no permitido para eliminar el cargue.');
}

$cargaId = filter_input(INPUT_POST, 'carga_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($cargaId) || $cargaId < 1) {
    carga_delete_redirect_with_flash('error', 'Debe indicar un ID de cargue válido.');
}

try {
    carga_delete_by_id($pdo, $cargaId, (int)current_user()['id']);
    carga_delete_redirect_with_flash('ok', 'El cargue se eliminó correctamente.');
} catch (Throwable $exception) {
    carga_delete_redirect_with_flash('error', 'No fue posible eliminar el cargue: ' . $exception->getMessage());
}
