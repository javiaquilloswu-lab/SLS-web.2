<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

try {
    $versionStmt = $pdo->query('SELECT version()');
    $version = $versionStmt->fetchColumn();

    // Check table counts
    $tables = ['admin_users', 'admin_tokens', 'students', 'student_tokens', 'student_documents', 'student_requests', 'scholarships', 'scholarship_applications', 'scholarship_requirements', 'student_notifications', 'student_concerns', 'student_concern_messages'];
    $tableCounts = [];

    foreach ($tables as $table) {
        try {
            $countStmt = $pdo->query("SELECT count(*) FROM {$table}");
            $tableCounts[$table] = (int)$countStmt->fetchColumn();
        } catch (PDOException $e) {
            $tableCounts[$table] = 'Table not initialized yet';
        }
    }

    jsonResponse(true, 'PostgreSQL connection successful!', [
        'database' => getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'android_studio_db'),
        'host' => getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost'),
        'port' => getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '5432'),
        'server_version' => $version,
        'table_counts' => $tableCounts,
        'timestamp' => date('Y-m-d H:i:s T')
    ]);
} catch (PDOException $e) {
    jsonResponse(false, 'PostgreSQL test query failed: ' . $e->getMessage(), null, 500);
}
