<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method Not Allowed. Use POST.', null, 405);
}

$token = getBearerToken();

if (!$token) {
    jsonResponse(false, 'No active administrative session token provided.', null, 400);
}

$revoked = destroyAdminToken($pdo, $token);

if ($revoked) {
    jsonResponse(true, 'Administrative session invalidated successfully.');
} else {
    jsonResponse(false, 'Failed to invalidate administrative session.', null, 500);
}
