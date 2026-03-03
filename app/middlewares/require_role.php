<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';

function require_role(array $roles): void
{
    if (!is_logged_in()) {
        redirect_to('login.php');
    }

    if (!has_role($roles)) {
        http_response_code(403);
        die('No autorizado para esta acción.');
    }
}
