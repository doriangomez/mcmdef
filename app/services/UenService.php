<?php

declare(strict_types=1);

require_once __DIR__ . '/SystemSettingsService.php';
require_once __DIR__ . '/../config/auth.php';

function uen_parse_list_string(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $parts = $decoded;
    } else {
        $parts = preg_split('/[,;|]+/', $raw) ?: [];
    }

    $values = [];
    foreach ($parts as $part) {
        $value = trim((string)$part);
        if ($value !== '') {
            $values[$value] = true;
        }
    }

    return array_keys($values);
}

function uen_requested_values(string $key = 'uen'): array
{
    $raw = $_GET[$key] ?? null;
    if ($raw === null && $key === 'uen') {
        $raw = $_GET['uens'] ?? [];
    }
    $values = [];
    if (is_array($raw)) {
        $values = $raw;
    } elseif (is_string($raw)) {
        $values = uen_parse_list_string($raw);
    }

    $unique = [];
    foreach ($values as $value) {
        $clean = trim((string)$value);
        if ($clean !== '') {
            $unique[$clean] = true;
        }
    }

    return array_keys($unique);
}

function uen_user_allowed_values(PDO $pdo, ?array $user = null): array
{
    $user = $user ?? current_user();
    if ((string)($user['rol'] ?? '') === 'admin') {
        return [];
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }

    $configured = system_setting_get($pdo, 'user_' . $userId . '_uens', '');
    $configuredValues = uen_parse_list_string((string)$configured);
    if (!empty($configuredValues)) {
        return $configuredValues;
    }

    $stmt = $pdo->prepare('SELECT DISTINCT d.uens AS uen FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE c.responsable_usuario_id = ? AND d.uens IS NOT NULL AND TRIM(d.uens) <> "" ORDER BY d.uens');
    $stmt->execute([$userId]);
    return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
}

function uen_apply_scope(array $requested, array $allowed): array
{
    if (!empty($allowed)) {
        $set = array_fill_keys($allowed, true);
        $selected = [];
        foreach ($requested as $item) {
            if (isset($set[$item])) {
                $selected[] = $item;
            }
        }
        if (empty($selected)) {
            $selected = $allowed;
        }

        return array_values(array_unique($selected));
    }

    return array_values(array_unique($requested));
}

function uen_sql_condition(string $columnExpr, array $selected): array
{
    if (empty($selected)) {
        return ['sql' => '', 'params' => []];
    }

    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    return ['sql' => ' AND ' . $columnExpr . ' IN (' . $placeholders . ')', 'params' => $selected];
}
