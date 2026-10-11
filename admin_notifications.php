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

// GET: Retrieve paginated notifications
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        $countStmt = $pdo->query('SELECT COUNT(*) FROM student_notifications');
        $totalRecords = (int)($countStmt ? $countStmt->fetchColumn() : 0);
        $totalPages = max(1, (int)ceil($totalRecords / $limit));

        $stmt = $pdo->prepare(
            "SELECT sn.id, sn.student_id, sn.title, sn.body, sn.category, sn.status_tag, sn.is_unread, sn.created_at,
                    s.first_name || ' ' || s.last_name as student_name
             FROM student_notifications sn
             LEFT JOIN students s ON sn.student_id = s.student_id
             ORDER BY sn.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Dispatched notifications retrieved.', [
            'notifications' => $notifs,
            'pagination'    => [
                'current_page'  => $page,
                'per_page'      => $limit,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'has_next'      => $page < $totalPages,
                'has_prev'      => $page > 1
            ]
        ]);
    } catch (\PDOException $e) {
        error_log('Admin notifications GET error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to query notifications.', null, 500);
    }
}

// POST: Create and dispatch persistent notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $title     = trim((string)($data['title'] ?? ''));
    $body      = trim((string)($data['body'] ?? ''));
    $category  = trim((string)($data['category'] ?? 'Announcement'));
    $statusTag = trim((string)($data['status_tag'] ?? 'Official'));
    $target    = trim((string)($data['target'] ?? 'all')); // 'all', or specific student_id

    if ($title === '' || $body === '') {
        jsonResponse(false, 'Notification title and message body are required.', null, 422);
    }

    try {
        $recipientIds = [];

        if ($target === 'all' || $target === 'broadcast') {
            // Determine all registered students for real database persistence
            $studStmt = $pdo->query('SELECT student_id FROM students');
            $recipientIds = $studStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Target specific student
            $studStmt = $pdo->prepare('SELECT student_id FROM students WHERE student_id = ?');
            $studStmt->execute([$target]);
            $foundId = $studStmt->fetchColumn();
            if ($foundId) {
                $recipientIds[] = $foundId;
            } else {
                jsonResponse(false, "Target student ID '{$target}' not found.", null, 404);
            }
        }

        if (empty($recipientIds)) {
            jsonResponse(false, 'No eligible student recipients found.', null, 400);
        }

        // Insert persistent student_notifications for each recipient
        $insertStmt = $pdo->prepare(
            'INSERT INTO student_notifications (student_id, title, body, category, status_tag, is_unread, created_at)
             VALUES (?, ?, ?, ?, ?, TRUE, NOW())'
        );

        $dispatchedCount = 0;
        foreach ($recipientIds as $sid) {
            $insertStmt->execute([$sid, $title, $body, $category, $statusTag]);
            $dispatchedCount++;
        }

        jsonResponse(true, "Successfully dispatched {$dispatchedCount} persistent notification(s).", [
            'recipients_count' => $dispatchedCount,
            'title'            => $title,
            'target'           => $target
        ], 201);
    } catch (\PDOException $e) {
        error_log('Admin notifications POST error: ' . $e->getMessage());
        jsonResponse(false, 'Failed to dispatch notifications.', null, 500);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
