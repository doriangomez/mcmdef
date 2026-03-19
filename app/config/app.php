<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', '/mcmcartera');
}

if (!function_exists('normalize_base_path')) {
    function normalize_base_path(string $basePath): string
    {
        $normalized = '/' . trim(str_replace('\\', '/', $basePath), '/');
        return $normalized === '/' ? '' : $normalized;
    }
}

if (!function_exists('detect_base_path')) {
    function detect_base_path(): string
    {
        $serverCandidates = [
            $_SERVER['SCRIPT_NAME'] ?? '',
            $_SERVER['PHP_SELF'] ?? '',
            $_SERVER['REQUEST_URI'] ?? '',
        ];

        foreach ($serverCandidates as $candidate) {
            $candidatePath = (string)(parse_url((string)$candidate, PHP_URL_PATH) ?? '');
            $candidatePath = str_replace('\\', '/', $candidatePath);
            if ($candidatePath === '' || $candidatePath === '/') {
                continue;
            }

            if (preg_match('#^(.*?)/public(?:/.*)?$#', $candidatePath, $matches) === 1) {
                return normalize_base_path($matches[1]);
            }

            $directory = str_replace('\\', '/', dirname($candidatePath));
            if ($directory !== '' && $directory !== '.' && $directory !== '/' && $directory !== '\\') {
                return normalize_base_path($directory);
            }
        }

        return '';
    }
}

if (!defined('APP_BASE_URL')) {
    $configuredBase = getenv('APP_BASE_URL');
    if (is_string($configuredBase) && trim($configuredBase) !== '') {
        define('APP_BASE_URL', normalize_base_path($configuredBase));
    } else {
        $basePath = normalize_base_path(BASE_PATH);
        define('APP_BASE_URL', $basePath !== '' ? $basePath : detect_base_path());
    }
}
if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        return APP_BASE_URL;
    }
}

if (!function_exists('app_starts_with')) {
    function app_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        if ($path !== '' && preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $base = app_base_url();
        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }

        $cleanPath = ltrim($path, '/');
        return $base === '' ? '/' . $cleanPath : $base . '/' . $cleanPath;
    }
}

if (!function_exists('app_request_path')) {
    function app_request_path(): string
    {
        $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '/');
        if ($uriPath === '') {
            $uriPath = '/';
        }

        $base = app_base_url();
        if ($base !== '' && app_starts_with($uriPath, $base)) {
            $uriPath = substr($uriPath, strlen($base));
        }

        $uriPath = '/' . ltrim($uriPath, '/');
        if ($uriPath === '/') {
            return '/index.php';
        }

        return $uriPath;
    }
}

if (!function_exists('app_route_is')) {
    function app_route_is(array $paths): bool
    {
        $current = app_request_path();
        foreach ($paths as $path) {
            $normalized = '/' . ltrim($path, '/');
            if ($current === $normalized) {
                return true;
            }

            $prefix = rtrim($normalized, '/') . '/';
            if (app_starts_with($current, $prefix)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('redirect_to')) {
    function redirect_to(string $path): void
    {
        header('Location: ' . app_url($path));
        exit;
    }
}
