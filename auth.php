<?php
/**
 * Southwestern University PHINMA - Student Life API
 * Authentication & Identity Verification Middleware
 * 
 * Manages token validation, student ownership, and session security.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

/**
 * Extracts the bearer token from HTTP headers or request payload.
 */
function getBearerToken(): ?string {
    $header = null;
    
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(
            array_map('ucwords', array_keys($requestHeaders)),
            array_values($requestHeaders)
        );
        if (isset($requestHeaders['Authorization'])) {
            $header = trim($requestHeaders['Authorization']);
        }
    }

    if (!empty($header)) {
        if (preg_match('/Bearer\s(\S+)/i', $header, $matches)) {
            return $matches[1];
        }
        return $header;
    }

    // Secondary fallback for X-Auth-Token header
    if (isset($_SERVER['HTTP_X_AUTH_TOKEN']) && !empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return trim($_SERVER['HTTP_X_AUTH_TOKEN']);
    }

    // Tertiary fallback for form/query parameters during local testing
    if (isset($_POST['token']) && !empty($_POST['token'])) {
        return trim($_POST['token']);
    }
    if (isset($_GET['token']) && !empty($_GET['token'])) {
        return trim($_GET['token']);
    }

    return null;
}

/**
 * Validates the caller's session token and returns the authenticated student profile.
 * Halts request execution with HTTP 401 if invalid or expired.
 *
 * @param PDO $pdo Active PostgreSQL PDO connection
 * @return array Authenticated student record
 */
function requireAuthenticatedStudent(PDO $pdo): array {
    $token = getBearerToken();

    if (!$token) {
        jsonResponse(false, 'Authentication required. Please provide a valid Bearer token.', null, 401);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.email, 
                    s.course, s.year_level, s.academic_status, s.requirements_progress,
                    s.verified_docs_count, s.total_docs_count, s.pending_actions_count,
                    t.expires_at, t.token
             FROM student_tokens t
             JOIN students s ON t.student_id = s.student_id
             WHERE t.token = ? AND t.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $student = $stmt->fetch();

        if (!$student) {
            jsonResponse(false, 'Invalid or expired session token. Please log in again.', null, 401);
            exit;
        }

        return $student;
    } catch (\PDOException $e) {
        error_log('Auth verification error: ' . $e->getMessage());
        jsonResponse(false, 'Authentication verification failed.', null, 500);
        exit;
    }
}

/**
 * Destroys an active session token (Logout).
 */
function destroyStudentToken(PDO $pdo, string $token): bool {
    try {
        $stmt = $pdo->prepare('DELETE FROM student_tokens WHERE token = ?');
        return $stmt->execute([$token]);
    } catch (\PDOException $e) {
        error_log('Token revocation error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Validates the caller's administrative session token and returns the admin profile.
 * Rejects unauthenticated requests with HTTP 401.
 * Rejects student tokens attempting administrative actions with HTTP 403.
 *
 * @param PDO $pdo Active PostgreSQL PDO connection
 * @return array Authenticated admin record
 */
function requireAuthenticatedAdmin(PDO $pdo): array {
    $token = getBearerToken();

    if (!$token) {
        jsonResponse(false, 'Administrative authentication required. Missing token.', null, 401);
        exit;
    }

    try {
        // Query admin_tokens and admin_users
        $stmt = $pdo->prepare(
            'SELECT a.id, a.username, a.email, a.full_name, a.department, a.role, a.is_active,
                    t.expires_at, t.token
             FROM admin_tokens t
             JOIN admin_users a ON t.admin_id = a.id
             WHERE t.token = ? AND t.expires_at > NOW() AND a.is_active = TRUE
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            return $admin;
        }

        // Check if the caller mistakenly sent a valid student token to an admin endpoint
        $studentCheck = $pdo->prepare('SELECT id FROM student_tokens WHERE token = ? LIMIT 1');
        $studentCheck->execute([$token]);
        if ($studentCheck->fetch()) {
            jsonResponse(false, 'Forbidden: Student credentials are not authorized for administrative operations.', null, 403);
            exit;
        }

        jsonResponse(false, 'Invalid or expired administrative session token. Please log in again.', null, 401);
        exit;
    } catch (\PDOException $e) {
        error_log('Admin auth verification error: ' . $e->getMessage());
        jsonResponse(false, 'Administrative verification process encountered an error.', null, 500);
        exit;
    }
}

/**
 * Destroys an active administrative session token (Admin Logout).
 */
function destroyAdminToken(PDO $pdo, string $token): bool {
    try {
        $stmt = $pdo->prepare('DELETE FROM admin_tokens WHERE token = ?');
        return $stmt->execute([$token]);
    } catch (\PDOException $e) {
        error_log('Admin token revocation error: ' . $e->getMessage());
        return false;
    }
}
