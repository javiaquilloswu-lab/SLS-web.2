<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Enforce real authentication
$student = requireAuthenticatedStudent($pdo);
$studentId = $student['student_id'];

// GET: Retrieve notifications for the authenticated student
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, title, body, category, status_tag, is_unread,
                    CASE
                        WHEN created_at >= NOW() - INTERVAL '3 hours' THEN '2 hours ago'
                        WHEN created_at >= NOW() - INTERVAL '1 day' THEN '1 day ago'
                        WHEN created_at >= NOW() - INTERVAL '3 days' THEN '2 days ago'
                        ELSE TO_CHAR(created_at, 'Mon DD')
                    END as timestamp
             FROM student_notifications
             WHERE student_id = ?
             ORDER BY id DESC"
        );
        $stmt->execute([$studentId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unreadCount = 0;
        foreach ($notifications as &$n) {
            $rawUnread = $n['is_unread'];
            $isUnread = ($rawUnread === true || $rawUnread === 1 || $rawUnread === '1' || $rawUnread === 't' || $rawUnread === 'true');
            $n['is_unread'] = $isUnread;
            if ($isUnread) {
                $unreadCount++;
            }
        }

        jsonResponse(true, 'Notifications retrieved.', [
            'unread_count'  => $unreadCount,
            'notifications' => $notifications
        ]);
    } catch (\PDOException $e) {
        error_log('Failed to fetch notifications: ' . $e->getMessage());
        jsonResponse(false, 'Unable to retrieve notifications at this time.', null, 500);
    }
}

// POST: Mark notifications as read for the authenticated student
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $notificationId = isset($data['notification_id']) ? (int)$data['notification_id'] : null;

    try {
        if ($notificationId) {
            $stmt = $pdo->prepare(
                'UPDATE student_notifications SET is_unread = FALSE WHERE id = ? AND student_id = ?'
            );
            $stmt->execute([$notificationId, $studentId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE student_notifications SET is_unread = FALSE WHERE student_id = ?'
            );
            $stmt->execute([$studentId]);
        }

        jsonResponse(true, 'Notification status updated successfully.');
    } catch (\PDOException $e) {
        error_log('Failed to update notifications: ' . $e->getMessage());
        jsonResponse(false, 'Unable to update notifications.', null, 500);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
