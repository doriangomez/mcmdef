<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

require_role(['admin', 'analista']);

$query = $_GET;
$target = app_url('gestion/detalle.php');
if (!empty($query)) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target);
exit;
