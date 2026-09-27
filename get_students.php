<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Protect directory: caller must be authenticated
$caller = requireAuthenticatedStudent($pdo);

try {
    $stmt = $pdo->query(
        "SELECT id, first_name, middle_name, last_name, student_id, email,
                course, year_level, academic_status, requirements_progress,
                verified_docs_count, total_docs_count, pending_actions_count, created_at
         FROM students
         ORDER BY id DESC"
    );

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true, 'Students retrieved successfully', $students);
} catch (\PDOException $e) {
    error_log('Failed to fetch students: ' . $e->getMessage());
    jsonResponse(false, 'Unable to fetch students.', null, 500);
}
