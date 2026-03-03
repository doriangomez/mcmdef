<?php
$logDir = dirname(__DIR__, 2) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$logFile = $logDir . '/php-errors.log';
if (!is_file($logFile)) {
    touch($logFile);
    @chmod($logFile, 0664);
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
error_reporting(E_ALL);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'mcm_cartera';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('Error de conexión a base de datos: ' . $e->getMessage());
}
