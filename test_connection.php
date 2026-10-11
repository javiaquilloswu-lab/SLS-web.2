
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
    exit;
}

try {
    require_once __DIR__ . '/db.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database unavailable');
    }

    $stmt = $pdo->query('SELECT 1');
    $connected = $stmt !== false && (int) $stmt->fetchColumn() === 1;

    if (!$connected) {
        throw new RuntimeException('Database check failed');
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'service' => 'Student Life API',
        'database' => 'connected',
        'timestamp' => gmdate('c')
    ]);

} catch (Throwable $e) {
    error_log('API health check failed: ' . $e->getMessage());

    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'service' => 'Student Life API',
        'database' => 'unavailable'
    ]);
}
