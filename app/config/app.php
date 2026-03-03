<?php

if (!defined('APP_BASE_URL')) {
    $configuredBase = getenv('APP_BASE_URL');
    if (is_string($configuredBase) && trim($configuredBase) !== '') {
        $base = '/' . trim(trim($configuredBase), '/');
        define('APP_BASE_URL', $base === '/' ? '' : $base);
    } else {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '' && preg_match('#^(.*?/public)(?:/.*)?$#', $scriptName, $matches) === 1) {
            $base = rtrim($matches[1], '/');
        } else {
            $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        }
        if ($base === '/' || $base === '.' || $base === '\\') {
            $base = '';
        }
        define('APP_BASE_URL', $base);
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        return APP_BASE_URL;
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
        if ($base !== '' && str_starts_with($uriPath, $base)) {
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
            if (str_starts_with($current, $prefix)) {
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
