<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Enforce real authentication
$currentStudent = requireAuthenticatedStudent($pdo);

// If client passed a student_id parameter, verify ownership
$requestedId = trim((string)($_GET['student_id'] ?? ($_GET['id'] ?? '')));

if ($requestedId !== '' && $requestedId !== $currentStudent['student_id'] && (string)$currentStudent['id'] !== $requestedId) {
    jsonResponse(false, 'Forbidden: You cannot access another student’s profile.', null, 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $firstName  = trim((string)($data['first_name'] ?? $currentStudent['first_name']));
    $middleName = trim((string)($data['middle_name'] ?? $currentStudent['middle_name']));
    $lastName   = trim((string)($data['last_name'] ?? $currentStudent['last_name']));
    $email      = trim((string)($data['email'] ?? $currentStudent['email']));

    if ($firstName === '' || $lastName === '' || $email === '') {
        jsonResponse(false, 'First name, last name, and email cannot be empty.', null, 422);
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE students
             SET first_name = :first_name,
                 middle_name = :middle_name,
                 last_name = :last_name,
                 email = :email
             WHERE student_id = :student_id'
        );
        $stmt->execute([
            ':first_name'  => $firstName,
            ':middle_name' => $middleName,
            ':last_name'   => $lastName,
            ':email'       => $email,
            ':student_id'  => $currentStudent['student_id']
        ]);

        $refetch = $pdo->prepare('SELECT id, first_name, middle_name, last_name, student_id, email, course, year_level, academic_status, requirements_progress, verified_docs_count, total_docs_count, pending_actions_count, created_at FROM students WHERE student_id = ?');
        $refetch->execute([$currentStudent['student_id']]);
        $updated = $refetch->fetch(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Student profile updated successfully.', $updated);
    } catch (\PDOException $e) {
        error_log('Failed to update student profile: ' . $e->getMessage());
        jsonResponse(false, 'Unable to update student profile.', null, 500);
    }
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, first_name, middle_name, last_name, student_id, email,
                course, year_level, academic_status, requirements_progress,
                verified_docs_count, total_docs_count, pending_actions_count, created_at
         FROM students
         WHERE student_id = :student_id
         LIMIT 1"
    );

    $stmt->execute([':student_id' => $currentStudent['student_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        jsonResponse(false, 'Student not found.', null, 404);
    }

    jsonResponse(true, 'Student profile retrieved successfully', $student);
} catch (\PDOException $e) {
    error_log('Failed to fetch student profile: ' . $e->getMessage());
    jsonResponse(false, 'Unable to fetch student profile.', null, 500);
}
