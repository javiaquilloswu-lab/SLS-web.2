<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

// Accept both JSON payload and application/x-www-form-urlencoded
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$first_name  = trim((string)($data['first_name'] ?? ''));
$middle_name = trim((string)($data['middle_name'] ?? ''));
$last_name   = trim((string)($data['last_name'] ?? ''));
$email       = trim((string)($data['email'] ?? ''));
$student_id  = trim((string)($data['student_id'] ?? ''));
$password    = (string)($data['password'] ?? '');

$course          = trim((string)($data['course'] ?? 'BS Computer Science'));
$year_level      = trim((string)($data['year_level'] ?? '3rd Year'));
$academic_status = trim((string)($data['academic_status'] ?? 'Active'));

if ($first_name === '' || $last_name === '' || $email === '' || $student_id === '' || $password === '') {
    jsonResponse(false, 'All required fields (first_name, last_name, email, student_id, password) must be filled out.', null, 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Invalid email format.', null, 422);
}

$lowerEmail = strtolower($email);
if (!str_ends_with($lowerEmail, '@swu.edu.ph') && !str_ends_with($lowerEmail, '@phinmaed.com') && !str_ends_with($lowerEmail, '@swu.phinma.edu.ph')) {
    jsonResponse(false, 'Registration is restricted to official university email addresses (@swu.edu.ph or @phinmaed.com).', null, 422);
}

try {
    // Check for duplicate student_id or email
    $checkStmt = $pdo->prepare('SELECT id FROM students WHERE email = ? OR student_id = ?');
    $checkStmt->execute([$email, $student_id]);

    if ($checkStmt->rowCount() > 0) {
        jsonResponse(false, 'Student ID or Email already exists in the system.', null, 409);
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Support schema with or without extra profile columns
    $stmt = $pdo->prepare(
        'INSERT INTO students (first_name, middle_name, last_name, email, student_id, password, course, year_level, academic_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $first_name,
        $middle_name,
        $last_name,
        $email,
        $student_id,
        $hashed_password,
        $course,
        $year_level,
        $academic_status
    ]);

    $studentIdGenerated = $pdo->lastInsertId();

    jsonResponse(true, 'Student registered successfully.', [
        'id' => (int)$studentIdGenerated,
        'student_id' => $student_id,
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'last_name' => $last_name,
        'email' => $email,
        'course' => $course,
        'year_level' => $year_level,
        'academic_status' => $academic_status
    ], 201);
} catch (PDOException $e) {
    error_log('Student registration failed: ' . $e->getMessage());
    jsonResponse(false, 'Unable to register student at this time. Please check your details and try again.', null, 500);
}
