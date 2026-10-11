<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Strictly enforce administrator privileges
$admin = requireAuthenticatedAdmin($pdo);

// GET: Retrieve paginated students with search and filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search   = trim((string)($_GET['search'] ?? ''));
    $course   = trim((string)($_GET['course'] ?? ''));
    $status   = trim((string)($_GET['status'] ?? ''));

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        $whereSql = ' WHERE 1=1';
        $params = [];

        if ($search !== '') {
            $whereSql .= ' AND (first_name ILIKE ? OR last_name ILIKE ? OR student_id ILIKE ? OR email ILIKE ?)';
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if ($course !== '' && strtolower($course) !== 'all') {
            $whereSql .= ' AND course ILIKE ?';
            $params[] = "%{$course}%";
        }

        if ($status !== '' && strtolower($status) !== 'all') {
            $whereSql .= ' AND academic_status ILIKE ?';
            $params[] = "%{$status}%";
        }

        // Count Query
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM students {$whereSql}");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRecords / $limit));

        // Data Query
        $sql = "SELECT id, student_id, first_name, middle_name, last_name, email,
                       course, year_level, academic_status, requirements_progress,
                       verified_docs_count, total_docs_count, pending_actions_count, created_at
                FROM students {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Students directory retrieved.', [
            'count'      => count($students),
            'students'   => $students,
            'pagination' => [
                'current_page'  => $page,
                'per_page'      => $limit,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'has_next'      => $page < $totalPages,
                'has_prev'      => $page > 1
            ]
        ]);
    } catch (\PDOException $e) {
        error_log('Admin students GET error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to query student directory.', null, 500);
    }
}

// POST / DELETE: Delete Student Account securely (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $targetStudentId = trim((string)($data['student_id'] ?? ($_GET['student_id'] ?? '')));

    if ($targetStudentId === '') {
        jsonResponse(false, 'Target student_id is required.', null, 422);
    }

    try {
        // 1. Verify target student exists in PostgreSQL
        $findStmt = $pdo->prepare('SELECT id, student_id, first_name, last_name, email FROM students WHERE student_id = ?');
        $findStmt->execute([$targetStudentId]);
        $targetStudent = $findStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetStudent) {
            jsonResponse(false, 'Student account not found.', null, 404);
        }

        // 2. Perform deletion inside a PostgreSQL Transaction
        $pdo->beginTransaction();

        // Delete related student records across dependent tables
        $pdo->prepare('DELETE FROM student_tokens WHERE student_id = ?')->execute([$targetStudentId]);
        $pdo->prepare('DELETE FROM student_documents WHERE student_id = ?')->execute([$targetStudentId]);
        $pdo->prepare('DELETE FROM student_requests WHERE student_id = ?')->execute([$targetStudentId]);
        $pdo->prepare('DELETE FROM scholarship_applications WHERE student_id = ?')->execute([$targetStudentId]);
        $pdo->prepare('DELETE FROM scholarship_requirements WHERE student_id = ?')->execute([$targetStudentId]);
        $pdo->prepare('DELETE FROM student_notifications WHERE student_id = ?')->execute([$targetStudentId]);
        $pdo->prepare('DELETE FROM student_concern_messages WHERE student_id = ?')->execute([$targetStudentId]);
        $pdo->prepare('DELETE FROM student_concerns WHERE student_id = ?')->execute([$targetStudentId]);

        // Delete main student record
        $delStmt = $pdo->prepare('DELETE FROM students WHERE student_id = ?');
        $delStmt->execute([$targetStudentId]);

        $pdo->commit();

        jsonResponse(true, 'Student account deleted successfully.', [
            'deleted_student_id' => $targetStudentId,
            'student_name'       => $targetStudent['first_name'] . ' ' . $targetStudent['last_name'],
            'deleted_at'         => date('Y-m-d H:i:s')
        ]);
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Delete student account error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to delete student account.', null, 500);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
