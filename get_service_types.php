<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Enforce real authentication
$student = requireAuthenticatedStudent($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method Not Allowed. Use GET.', null, 405);
}

try {
    $stmt = $pdo->query(
        "SELECT id, service_code, service_name, description, requirements, department, category, processing_days, fee_amount, is_active
         FROM service_types
         WHERE is_active = TRUE
         ORDER BY id ASC"
    );
    $services = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    jsonResponse(true, 'Active service catalog retrieved successfully.', $services);
} catch (\PDOException $e) {
    error_log('Failed to fetch service types: ' . $e->getMessage());
    jsonResponse(false, 'Unable to retrieve service catalog.', null, 500);
}
