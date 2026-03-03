<?php

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '' && preg_match('#^(.*?/public)(?:/.*)?$#', $scriptName, $matches) === 1) {
            $base = rtrim($matches[1], '/');
            return $base === '/' ? '' : $base;
        }

        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            $base = '';
            return $base;
        }

        $base = rtrim($dir, '/');
        return $base;
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

if (!function_exists('redirect_to')) {
    function redirect_to(string $path): void
    {
        header('Location: ' . app_url($path));
        exit;
    }
}
