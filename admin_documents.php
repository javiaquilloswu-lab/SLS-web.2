<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$admin = requireAuthenticatedAdmin($pdo);

// GET: Retrieve uploaded documents queue with pagination
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filterStatus = trim((string)($_GET['status'] ?? ''));

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        $whereSql = "";
        $params = [];

        if ($filterStatus !== '' && strtolower($filterStatus) !== 'all') {
            $whereSql .= " WHERE sd.status ILIKE ?";
            $params[] = "%{$filterStatus}%";
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM student_documents sd {$whereSql}");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRecords / $limit));

        $sql = "SELECT sd.id, sd.student_id, sd.request_id, sd.document_type, sd.original_filename,
                       sd.stored_filename, sd.file_path, sd.mime_type, sd.file_size_bytes,
                       sd.status, sd.reviewed_by, sd.reviewed_at, sd.admin_notes, sd.uploaded_at,
                       s.first_name || ' ' || s.last_name as student_name, s.course, s.year_level
                FROM student_documents sd
                LEFT JOIN students s ON sd.student_id = s.student_id
                {$whereSql}
                ORDER BY sd.id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Documents queue retrieved.', [
            'docs'       => $docs,
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
        error_log('Admin documents GET error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to query documents queue.', null, 500);
    }
}

// POST: Review and update document status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $documentId = isset($data['document_id']) ? (int)$data['document_id'] : 0;
    $status     = trim((string)($data['status'] ?? 'Verified'));
    $adminNotes = trim((string)($data['admin_notes'] ?? ''));

    if ($documentId <= 0) {
        jsonResponse(false, 'Valid document_id is required.', null, 422);
    }

    try {
        // Find document
        $findStmt = $pdo->prepare('SELECT id, student_id, document_type FROM student_documents WHERE id = ?');
        $findStmt->execute([$documentId]);
        $doc = $findStmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            jsonResponse(false, 'Document record not found.', null, 404);
        }

        $adminName = $admin['full_name'] ?: $admin['username'];

        // Update document review record
        $updateStmt = $pdo->prepare(
            'UPDATE student_documents
             SET status = :status,
                 reviewed_by = :reviewed_by,
                 reviewed_at = NOW(),
                 admin_notes = :admin_notes
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status'      => $status,
            ':reviewed_by' => $adminName,
            ':admin_notes' => $adminNotes,
            ':id'          => $documentId
        ]);

        // If status is Verified, update student's verified documents count
        if (strcasecmp($status, 'Verified') === 0) {
            $countStmt = $pdo->prepare("SELECT count(*) FROM student_documents WHERE student_id = ? AND status ILIKE 'Verified'");
            $countStmt->execute([$doc['student_id']]);
            $verifiedCount = (int)$countStmt->fetchColumn();

            $studentUpdate = $pdo->prepare('UPDATE students SET verified_docs_count = ? WHERE student_id = ?');
            $studentUpdate->execute([$verifiedCount, $doc['student_id']]);
        }

        // Notify student via persistent student_notifications so Android app receives it
        $notifStmt = $pdo->prepare(
            'INSERT INTO student_notifications (student_id, title, body, category, status_tag, is_unread, created_at)
             VALUES (?, ?, ?, ?, ?, TRUE, NOW())'
        );
        $notifTitle = "Document {$status}: " . $doc['document_type'];
        $notifBody  = "Your {$doc['document_type']} has been reviewed by the Student Life Office. Status: {$status}." . ($adminNotes ? " Note: {$adminNotes}" : '');
        $notifStmt->execute([
            $doc['student_id'],
            $notifTitle,
            $notifBody,
            'Document',
            $status === 'Verified' ? 'Approved' : 'Notice'
        ]);

        jsonResponse(true, 'Document review status updated and notification dispatched.', [
            'document_id' => $documentId,
            'status'      => $status,
            'reviewed_by' => $adminName,
            'reviewed_at' => date('Y-m-d H:i:s')
        ]);
    } catch (\PDOException $e) {
        error_log('Admin documents POST error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to update document review status.', null, 500);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
