<?php
require_once __DIR__ . '/../config/auth.php';

function require_role(array $roles): void
{
    if (!has_role($roles)) {
        http_response_code(403);
        die('No autorizado para esta acción.');
    }
}
