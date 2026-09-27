<?php
/**
 * Southwestern University PHINMA - Student Life API
 * Logout / Session Revocation Endpoint
 *
 * Revokes the caller's active session token.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    jsonResponse(false, 'Method not allowed. Use POST or DELETE.', null, 405);
}

$token = getBearerToken();

if (!$token) {
    jsonResponse(false, 'No active session token provided.', null, 400);
}

$revoked = destroyStudentToken($pdo, $token);

if ($revoked) {
    jsonResponse(true, 'Logged out successfully. Session invalidated.');
} else {
    jsonResponse(false, 'Failed to invalidate session token.', null, 500);
}
