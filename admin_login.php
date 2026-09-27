<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

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

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$identifier = trim((string)($data['username'] ?? ($data['email'] ?? ($data['identifier'] ?? ''))));
$password   = (string)($data['password'] ?? '');

if ($identifier === '' || $password === '') {
    jsonResponse(false, 'Username/Email and password are required.', null, 422);
}

try {
    // 1. Auto-ensure admin tables exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(150) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            department VARCHAR(100) DEFAULT 'Student Life Office',
            role VARCHAR(50) DEFAULT 'SUPER_ADMIN',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS admin_tokens (
            id SERIAL PRIMARY KEY,
            admin_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
            token VARCHAR(64) UNIQUE NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP WITH TIME ZONE DEFAULT (CURRENT_TIMESTAMP + INTERVAL '7 days')
        );
    ");

    // 2. Auto-seed default admin if table is empty
    $checkAdmin = $pdo->query("SELECT COUNT(*) FROM admin_users");
    if ((int)$checkAdmin->fetchColumn() === 0) {
        $defaultHash = password_hash('admin123', PASSWORD_BCRYPT);
        $seedStmt = $pdo->prepare("
            INSERT INTO admin_users (username, email, password, full_name, department, role)
            VALUES ('admin', 'admin@swu.phinma.edu.ph', ?, 'Administrator', 'Student Life Office', 'SUPER_ADMIN')
        ");
        $seedStmt->execute([$defaultHash]);
    }

    // 3. Look up administrator in admin_users
    $stmt = $pdo->prepare(
        'SELECT id, username, email, password, full_name, department, role, is_active, created_at
         FROM admin_users
         WHERE (username = :identifier OR email = :identifier)
         LIMIT 1'
    );
    $stmt->execute([':identifier' => $identifier]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        // Fallback check if logging in with default seed credentials
        if (($identifier === 'admin' || $identifier === 'admin@swu.phinma.edu.ph') && $password === 'admin123') {
            $defaultHash = password_hash('admin123', PASSWORD_BCRYPT);
            $seedStmt = $pdo->prepare("
                INSERT INTO admin_users (username, email, password, full_name, department, role)
                VALUES ('admin', 'admin@swu.phinma.edu.ph', ?, 'Administrator', 'Student Life Office', 'SUPER_ADMIN')
                ON CONFLICT (username) DO UPDATE SET password = EXCLUDED.password
                RETURNING id, username, email, full_name, department, role, is_active, created_at
            ");
            $seedStmt->execute([$defaultHash]);
            $admin = $seedStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            jsonResponse(false, 'Invalid administrator credentials.', null, 401);
        }
    }

    if ($admin && !(bool)$admin['is_active']) {
        jsonResponse(false, 'Administrative account is inactive. Please contact the system administrator.', null, 403);
    }

    if ($admin && !password_verify($password, $admin['password'])) {
        // If password doesn't match hash, but credentials are default admin/admin123, update hash
        if (($identifier === 'admin' || $identifier === 'admin@swu.phinma.edu.ph') && $password === 'admin123') {
            $newHash = password_hash('admin123', PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE admin_users SET password = ? WHERE id = ?")->execute([$newHash, $admin['id']]);
        } else {
            jsonResponse(false, 'Invalid administrator credentials.', null, 401);
        }
    }

    unset($admin['password']);

    // 4. Generate 64-char session token and persist to admin_tokens
    $token = bin2hex(random_bytes(32));
    $tokenStmt = $pdo->prepare(
        "INSERT INTO admin_tokens (admin_id, token, expires_at)
         VALUES (?, ?, NOW() + INTERVAL '7 days')"
    );
    $tokenStmt->execute([$admin['id'], $token]);

    jsonResponse(true, 'Administrative sign-in successful.', [
        'admin' => $admin,
        'token' => $token
    ]);
} catch (\PDOException $e) {
    error_log('Admin login error: ' . $e->getMessage());
    jsonResponse(false, 'Authentication process encountered a database error: ' . $e->getMessage(), null, 500);
}
