<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

// Accept both JSON payload and POST form-data
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$identifier = trim((string)($data['student_id'] ?? ($data['email'] ?? ($data['identifier'] ?? ''))));
$password   = (string)($data['password'] ?? '');

if ($identifier === '' || $password === '') {
    jsonResponse(false, 'Student ID / Email and password are required.', null, 422);
}

try {
    // Look up existing student by either student_id or email
    $stmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, student_id, email, password,
                course, year_level, academic_status, requirements_progress,
                verified_docs_count, total_docs_count, pending_actions_count, created_at
         FROM students
         WHERE email = :identifier OR student_id = :identifier
         LIMIT 1'
    );
    $stmt->execute([':identifier' => $identifier]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        jsonResponse(false, 'Invalid Student ID/Email or password.', null, 401);
    }

    // Verify password securely using password_verify
    if (!password_verify($password, $student['password'])) {
        jsonResponse(false, 'Invalid Student ID/Email or password.', null, 401);
    }

    // CRITICAL: Never return the password or hash to the frontend
    unset($student['password']);

    // Generate session auth token and persist to student_tokens
    $token = bin2hex(random_bytes(32));
    try {
        $tokenStmt = $pdo->prepare(
            "INSERT INTO student_tokens (student_id, token, expires_at)
             VALUES (?, ?, NOW() + INTERVAL '30 days')"
        );
        $tokenStmt->execute([$student['student_id'], $token]);
    } catch (\PDOException $e) {
        error_log('Token storage failed: ' . $e->getMessage());
    }

    // Ensure default values if fields are null
    $student['course'] = $student['course'] ?? 'BS Computer Science';
    $student['year_level'] = $student['year_level'] ?? '3rd Year';
    $student['academic_status'] = $student['academic_status'] ?? 'Active';
    $student['requirements_progress'] = (int)($student['requirements_progress'] ?? 67);
    $student['verified_docs_count'] = (int)($student['verified_docs_count'] ?? 4);
    $student['total_docs_count'] = (int)($student['total_docs_count'] ?? 6);
    $student['pending_actions_count'] = (int)($student['pending_actions_count'] ?? 2);

    jsonResponse(true, 'Login successful.', [
        'student' => $student,
        'token'   => $token
    ]);
} catch (PDOException $e) {
    error_log('Student login failed: ' . $e->getMessage());
    jsonResponse(false, 'Authentication process failed.', null, 500);
}
