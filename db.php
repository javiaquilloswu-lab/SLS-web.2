<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key !== '') {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

$host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
$port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '5432');
$db   = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'android_studio_db');
$user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'postgres');
$pass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? 'root');

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$db};options='--client_encoding=UTF8'";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());

    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');

    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please ensure PostgreSQL is running and credentials in .env are correct.'
    ]);

    exit;
}
