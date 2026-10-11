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

// GET: Retrieve concerns list or full transcript for a specific ticket
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ticket = trim((string)($_GET['ticket'] ?? ''));

    if ($ticket !== '') {
        try {
            // Retrieve single concern
            $stmt = $pdo->prepare(
                "SELECT sc.id, sc.student_id, sc.ticket_ref, sc.message, sc.category, sc.response,
                        sc.status, sc.responded_by, sc.responded_at, sc.internal_notes,
                        sc.assigned_to, sc.escalation_reason, sc.is_ai_paused, sc.created_at, sc.updated_at,
                        s.first_name || ' ' || s.last_name as student_name, s.email as student_email, s.course, s.year_level
                 FROM student_concerns sc
                 LEFT JOIN students s ON sc.student_id = s.student_id
                 WHERE sc.ticket_ref = ?
                 LIMIT 1"
            );
            $stmt->execute([$ticket]);
            $concern = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$concern) {
                jsonResponse(false, "Conversation with ticket reference '{$ticket}' not found.", null, 404);
            }

            // Retrieve multi-turn transcript messages
            $msgStmt = $pdo->prepare(
                "SELECT id, concern_id, ticket_ref, sender_type, sender_id, sender_name, message, created_at
                 FROM student_concern_messages
                 WHERE ticket_ref = ?
                 ORDER BY id ASC"
            );
            $msgStmt->execute([$ticket]);
            $transcript = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

            // Backward compatibility fallback: If no transcript messages yet exist for this legacy concern,
            // seed the initial messages into the normalized table
            if (empty($transcript)) {
                $studentName = $concern['student_name'] ?: 'Student';
                $studentMsg = $concern['message'] ?: 'Inquiry logged';
                $aiResponse = $concern['response'] ?: 'Thank you. Your inquiry has been documented.';

                $insStmt = $pdo->prepare(
                    'INSERT INTO student_concern_messages (concern_id, ticket_ref, sender_type, sender_id, sender_name, message, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insStmt->execute([$concern['id'], $ticket, 'STUDENT', $concern['student_id'], $studentName, $studentMsg, $concern['created_at']]);
                $insStmt->execute([$concern['id'], $ticket, 'AI', 'SWU Student Affairs AI', 'AI Student Life Assistant', $aiResponse, $concern['created_at']]);
                
                if (strcasecmp($concern['status'], 'Escalated') === 0 || strcasecmp($concern['status'], 'Under Review') === 0) {
                    $insStmt->execute([$concern['id'], $ticket, 'SYSTEM', 'system', 'System Event', 'Conversation escalated to Student Life staff review', $concern['created_at']]);
                }

                // Refetch
                $msgStmt->execute([$ticket]);
                $transcript = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            jsonResponse(true, 'Conversation detail and transcript retrieved.', [
                'concern'    => $concern,
                'transcript' => $transcript
            ]);
        } catch (\PDOException $e) {
            error_log('Admin concern detail error: ' . $e->getMessage());
            jsonResponse(false, 'Unable to query conversation detail.', null, 500);
        }
    } else {
        // List all concerns with pagination and search/status filtering
        $filterStatus = trim((string)($_GET['status'] ?? ''));
        $search       = trim((string)($_GET['search'] ?? ''));

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        try {
            $whereSql = " WHERE 1=1";
            $params = [];

            if ($filterStatus !== '' && strtolower($filterStatus) !== 'all') {
                $whereSql .= " AND sc.status ILIKE ?";
                $params[] = "%{$filterStatus}%";
            }

            if ($search !== '') {
                $whereSql .= " AND (s.first_name ILIKE ? OR s.last_name ILIKE ? OR sc.student_id ILIKE ? OR sc.ticket_ref ILIKE ? OR sc.message ILIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM student_concerns sc LEFT JOIN students s ON sc.student_id = s.student_id {$whereSql}");
            $countStmt->execute($params);
            $totalRecords = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalRecords / $limit));

            $sql = "SELECT sc.id, sc.student_id, sc.ticket_ref, sc.message, sc.category, sc.response,
                           sc.status, sc.responded_by, sc.responded_at, sc.internal_notes,
                           sc.assigned_to, sc.escalation_reason, sc.is_ai_paused, sc.created_at, sc.updated_at,
                           s.first_name || ' ' || s.last_name as student_name, s.course, s.year_level
                    FROM student_concerns sc
                    LEFT JOIN students s ON sc.student_id = s.student_id
                    {$whereSql}
                    ORDER BY sc.id DESC
                    LIMIT {$limit} OFFSET {$offset}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $concerns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(true, 'Conversations list retrieved.', [
                'concerns'   => $concerns,
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
            error_log('Admin concerns list error: ' . $e->getMessage());
            jsonResponse(false, 'Unable to query conversations list.', null, 500);
        }
    }
}

// POST: Actions (takeover, reply, note, status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $ticket = trim((string)($data['ticket_ref'] ?? ''));
    $action = trim((string)($data['action'] ?? ''));

    if ($ticket === '' || $action === '') {
        jsonResponse(false, 'ticket_ref and action are required.', null, 422);
    }

    try {
        // Lookup concern
        $findStmt = $pdo->prepare('SELECT id, student_id, category, status, is_ai_paused FROM student_concerns WHERE ticket_ref = ?');
        $findStmt->execute([$ticket]);
        $concern = $findStmt->fetch(PDO::FETCH_ASSOC);

        if (!$concern) {
            jsonResponse(false, "Concern ticket '{$ticket}' not found.", null, 404);
        }

        $concernId = (int)$concern['id'];
        $adminName = $admin['full_name'] ?: $admin['username'];

        if ($action === 'takeover') {
            // Rule 10: Staff takeover must be persistent
            $upd = $pdo->prepare(
                "UPDATE student_concerns
                 SET is_ai_paused = TRUE,
                     assigned_to = :assigned_to,
                     status = 'Staff Handling',
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $upd->execute([
                ':assigned_to' => $adminName,
                ':id'          => $concernId
            ]);

            // Add system event message in transcript
            $sysMsg = $pdo->prepare(
                'INSERT INTO student_concern_messages (concern_id, ticket_ref, sender_type, sender_id, sender_name, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $sysMsg->execute([$concernId, $ticket, 'SYSTEM', (string)$admin['id'], 'System Event', "Student Life staff ({$adminName}) took over the conversation"]);

            jsonResponse(true, 'Staff takeover confirmed. AI responses paused for this conversation.', [
                'ticket_ref'   => $ticket,
                'status'       => 'Staff Handling',
                'assigned_to'  => $adminName,
                'is_ai_paused' => true
            ]);
        } elseif ($action === 'reply') {
            $replyMessage = trim((string)($data['message'] ?? ''));
            if ($replyMessage === '') {
                jsonResponse(false, 'Staff reply message cannot be empty.', null, 422);
            }

            // Save message turn as STAFF
            $msgStmt = $pdo->prepare(
                'INSERT INTO student_concern_messages (concern_id, ticket_ref, sender_type, sender_id, sender_name, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                 RETURNING id'
            );
            $msgStmt->execute([$concernId, $ticket, 'STAFF', (string)$admin['id'], $adminName, $replyMessage]);
            $msgId = $msgStmt->fetchColumn();

            // Update concern record
            $upd = $pdo->prepare(
                "UPDATE student_concerns
                 SET response = :response,
                     responded_by = :responded_by,
                     responded_at = NOW(),
                     status = 'Staff Responded',
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $upd->execute([
                ':response'     => $replyMessage,
                ':responded_by' => $adminName,
                ':id'           => $concernId
            ]);

            // Notify student via persistent student_notifications so it is visible in Android app
            $notif = $pdo->prepare(
                'INSERT INTO student_notifications (student_id, title, body, category, status_tag, is_unread, created_at)
                 VALUES (?, ?, ?, ?, ?, TRUE, NOW())'
            );
            $notif->execute([
                $concern['student_id'],
                "Support Reply: Ticket {$ticket}",
                "A Student Life officer responded to your inquiry: \"{$replyMessage}\"",
                'Inquiry',
                'Response'
            ]);

            jsonResponse(true, 'Staff response recorded and student notified.', [
                'message_id' => (int)$msgId,
                'ticket_ref' => $ticket,
                'status'     => 'Staff Responded'
            ]);
        } elseif ($action === 'note') {
            // Rule 17: Internal staff notes must be persistent
            $notes = trim((string)($data['internal_notes'] ?? ''));
            $upd = $pdo->prepare('UPDATE student_concerns SET internal_notes = :notes, updated_at = NOW() WHERE id = :id');
            $upd->execute([':notes' => $notes, ':id' => $concernId]);

            jsonResponse(true, 'Internal staff note saved successfully.', [
                'ticket_ref'     => $ticket,
                'internal_notes' => $notes
            ]);
        } elseif ($action === 'status') {
            $newStatus = trim((string)($data['status'] ?? 'Resolved'));
            $upd = $pdo->prepare('UPDATE student_concerns SET status = :status, updated_at = NOW() WHERE id = :id');
            $upd->execute([':status' => $newStatus, ':id' => $concernId]);

            jsonResponse(true, "Conversation status updated to '{$newStatus}'.", [
                'ticket_ref' => $ticket,
                'status'     => $newStatus
            ]);
        } else {
            jsonResponse(false, "Unknown action '{$action}'. Supported: takeover, reply, note, status.", null, 422);
        }
    } catch (\PDOException $e) {
        error_log('Admin concern action error: ' . $e->getMessage());
        jsonResponse(false, 'Failed to execute conversation action.', null, 500);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
