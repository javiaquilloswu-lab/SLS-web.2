<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Enforce real authentication
$student = requireAuthenticatedStudent($pdo);
$studentId = $student['student_id'];

// GET: List requests belonging to the authenticated student
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filterStatus = trim((string)($_GET['status'] ?? ''));

    try {
        $sql = "SELECT id, title, department, reference_number,
                       TO_CHAR(created_at, 'Mon DD, YYYY') as submission_date,
                       status, status_message, category, created_at
                FROM student_requests
                WHERE student_id = ?";
        $params = [$studentId];

        if ($filterStatus !== '' && strtolower($filterStatus) !== 'all') {
            $statusNormalized = strtolower(str_replace('_', ' ', $filterStatus));
            $sql .= " AND LOWER(status) LIKE ?";
            $params[] = "%{$statusNormalized}%";
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Requests retrieved successfully.', $results);
    } catch (\PDOException $e) {
        error_log('Failed to fetch requests: ' . $e->getMessage());
        jsonResponse(false, 'Unable to retrieve requests at this time.', null, 500);
    }
}

// POST: Create a new request for the authenticated student
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $title       = trim((string)($data['title'] ?? ''));
    $department  = trim((string)($data['department'] ?? 'Office of Student Affairs'));
    $category    = trim((string)($data['category'] ?? 'Document'));
    $refNumber   = 'SL-2026-' . str_pad((string)random_int(100, 9999), 6, '0', STR_PAD_LEFT);
    $status      = 'Processing';
    $statusMsg   = 'Ready for review in 2-3 business days';

    if ($title === '') {
        jsonResponse(false, 'Request title is required.', null, 422);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO student_requests (student_id, title, department, reference_number, status, status_message, category, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             RETURNING id'
        );
        $stmt->execute([$studentId, $title, $department, $refNumber, $status, $statusMsg, $category]);
        $newId = $stmt->fetchColumn();

        jsonResponse(true, 'Request submitted successfully.', [
            'id'               => (int)$newId,
            'reference_number' => $refNumber,
            'title'            => $title,
            'department'       => $department,
            'status'           => $status,
            'status_message'   => $statusMsg,
            'submission_date'  => date('M d, Y')
        ], 201);
    } catch (\PDOException $e) {
        error_log('Failed to create request: ' . $e->getMessage());
        jsonResponse(false, 'Unable to submit request at this time.', null, 500);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
