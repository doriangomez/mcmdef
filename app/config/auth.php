<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function has_role(array $roles): bool
{
    $user = current_user();
    return $user && in_array($user['rol'], $roles, true);
}
