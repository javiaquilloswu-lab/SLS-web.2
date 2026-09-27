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
