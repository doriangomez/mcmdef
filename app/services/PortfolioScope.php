<?php
require_once __DIR__ . '/../config/auth.php';

function portfolio_is_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return (string)($user['rol'] ?? '') === 'admin';
}

function portfolio_client_scope_sql(string $clientAlias = 'c', ?array $user = null): array
{
    $user = $user ?? current_user();
    if (portfolio_is_admin($user)) {
        return ['sql' => '', 'params' => []];
    }

    return [
        'sql' => " AND ({$clientAlias}.responsable_usuario_id = ? OR {$clientAlias}.responsable_usuario_id IS NULL)",
        'params' => [(int)($user['id'] ?? 0)],
    ];
}

function portfolio_document_scope_sql(string $documentAlias = 'd', ?array $user = null): array
{
    $user = $user ?? current_user();
    if (portfolio_is_admin($user)) {
        return ['sql' => '', 'params' => []];
    }

    return [
        'sql' => " AND EXISTS (SELECT 1 FROM clientes csc WHERE csc.id = {$documentAlias}.cliente_id AND (csc.responsable_usuario_id = ? OR csc.responsable_usuario_id IS NULL))",
        'params' => [(int)($user['id'] ?? 0)],
    ];
}

function user_portfolio_scope($pdo = null, ?array $user = null, string $documentAlias = 'd', string $clientAlias = 'c'): array
{
    unset($pdo, $documentAlias);

    return portfolio_client_scope_sql($clientAlias, $user);
}
