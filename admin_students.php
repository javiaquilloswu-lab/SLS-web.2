<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$admin = requireAuthenticatedAdmin($pdo);

$search   = trim((string)($_GET['search'] ?? ''));
$course   = trim((string)($_GET['course'] ?? ''));
$status   = trim((string)($_GET['status'] ?? ''));

try {
    $sql = 'SELECT id, student_id, first_name, middle_name, last_name, email,
                   course, year_level, academic_status, requirements_progress,
                   verified_docs_count, total_docs_count, pending_actions_count, created_at
            FROM students
            WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (first_name ILIKE ? OR last_name ILIKE ? OR student_id ILIKE ? OR email ILIKE ?)';
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($course !== '' && strtolower($course) !== 'all') {
        $sql .= ' AND course ILIKE ?';
        $params[] = "%{$course}%";
    }

    if ($status !== '' && strtolower($status) !== 'all') {
        $sql .= ' AND academic_status ILIKE ?';
        $params[] = "%{$status}%";
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, 'Students directory retrieved.', [
        'count'    => count($students),
        'students' => $students
    ]);
} catch (\PDOException $e) {
    error_log('Admin students error: ' . $e->getMessage());
    jsonResponse(false, 'Unable to query student directory.', null, 500);
}
