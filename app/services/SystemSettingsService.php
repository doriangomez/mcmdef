<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function system_settings_ensure_table(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_settings (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(120) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $initialized = true;
}

function system_setting_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    system_settings_ensure_table($pdo);

    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    if ($value === false || $value === null || trim((string)$value) === '') {
        return $default;
    }

    return (string)$value;
}

function system_setting_set(PDO $pdo, string $key, ?string $value): void
{
    system_settings_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, created_at, updated_at)
         VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([$key, $value]);
}

function system_logo_default_path(): string
{
    return 'assets/img/logo-mcm.svg';
}

function system_logo_public_path(?string $relativePath): ?string
{
    if (!is_string($relativePath) || trim($relativePath) === '') {
        return null;
    }

    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (strpos($normalized, '..') !== false) {
        return null;
    }

    $publicPath = dirname(__DIR__, 2) . '/public/' . $normalized;
    if (!is_file($publicPath)) {
        return null;
    }

    return $normalized;
}

function system_logo_url(?PDO $pdo = null): string
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $customPath = null;

    if ($pdo instanceof PDO) {
        try {
            $customPath = system_setting_get($pdo, 'institutional_logo_path');
        } catch (Throwable $exception) {
            $customPath = null;
        }
    } else {
        try {
            require __DIR__ . '/../config/db.php';
            if (isset($pdo) && $pdo instanceof PDO) {
                $customPath = system_setting_get($pdo, 'institutional_logo_path');
            }
        } catch (Throwable $exception) {
            $customPath = null;
        }
    }

    $resolvedCustomPath = system_logo_public_path($customPath);
    $cached = app_url($resolvedCustomPath ?? system_logo_default_path());

    return $cached;
}
