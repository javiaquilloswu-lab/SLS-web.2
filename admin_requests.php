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

// GET: Retrieve paginated student requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filterStatus = trim((string)($_GET['status'] ?? ''));

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        $whereSql = "";
        $params = [];

        if ($filterStatus !== '' && strtolower($filterStatus) !== 'all') {
            $whereSql .= " WHERE sr.status ILIKE ?";
            $params[] = "%{$filterStatus}%";
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM student_requests sr {$whereSql}");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRecords / $limit));

        $sql = "SELECT sr.id, sr.student_id, sr.title, sr.department, sr.reference_number,
                       sr.status, sr.status_message, sr.category, sr.assigned_to, sr.updated_at, sr.created_at,
                       s.first_name || ' ' || s.last_name as student_name, s.course, s.year_level
                FROM student_requests sr
                LEFT JOIN students s ON sr.student_id = s.student_id
                {$whereSql}
                ORDER BY sr.id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Student requests queue retrieved.', [
            'requests'   => $requests,
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
        error_log('Admin requests GET error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to query requests queue.', null, 500);
    }
}

// POST: Update request status or create service type
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $action = trim((string)($data['action'] ?? 'update_status'));

    if ($action === 'create_service') {
        $srvCode   = trim((string)($data['service_code'] ?? ''));
        $srvName   = trim((string)($data['service_name'] ?? ''));
        $desc      = trim((string)($data['description'] ?? ''));
        $reqs      = is_array($data['requirements'] ?? null) ? json_encode($data['requirements'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : trim((string)($data['requirements'] ?? ''));
        $dept      = trim((string)($data['department'] ?? 'University Registrar'));
        $cat       = trim((string)($data['category'] ?? 'Document'));
        $procDays  = trim((string)($data['processing_days'] ?? '2-3 Business Days'));
        $feeAmt    = trim((string)($data['fee_amount'] ?? 'Free / Digital'));
        $isActive  = isset($data['is_active']) ? (bool)$data['is_active'] : true;

        if ($srvCode === '' || $srvName === '') {
            jsonResponse(false, 'Service code and service name are required.', null, 422);
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO service_types (service_code, service_name, description, requirements, department, category, processing_days, fee_amount, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 RETURNING id'
            );
            $stmt->execute([$srvCode, $srvName, $desc, $reqs, $dept, $cat, $procDays, $feeAmt, $isActive ? 1 : 0]);
            $newId = $stmt->fetchColumn();

            jsonResponse(true, 'Service type created successfully in catalog.', [
                'id'           => (int)$newId,
                'service_code' => $srvCode,
                'service_name' => $srvName
            ], 201);
        } catch (\PDOException $e) {
            error_log('Admin service creation error: ' . $e->getMessage());
            jsonResponse(false, 'Unable to create service type. Code may already exist.', null, 409);
        }
    } else {
        $requestId     = isset($data['request_id']) ? (int)$data['request_id'] : 0;
        $status        = trim((string)($data['status'] ?? 'Processing'));
        $statusMessage = trim((string)($data['status_message'] ?? ''));
        $assignedTo    = trim((string)($data['assigned_to'] ?? ($admin['full_name'] ?: $admin['username'])));

        if ($requestId <= 0) {
            jsonResponse(false, 'Valid request_id is required.', null, 422);
        }

        try {
            $findStmt = $pdo->prepare('SELECT id, student_id, title, reference_number FROM student_requests WHERE id = ?');
            $findStmt->execute([$requestId]);
            $req = $findStmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                jsonResponse(false, 'Request record not found.', null, 404);
            }

            $updateStmt = $pdo->prepare(
                'UPDATE student_requests
                 SET status = :status,
                     status_message = :status_message,
                     assigned_to = :assigned_to,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':status'         => $status,
                ':status_message' => $statusMessage,
                ':assigned_to'    => $assignedTo,
                ':id'             => $requestId
            ]);

            // Dispatches notification to student so it appears on Android
            $notifStmt = $pdo->prepare(
                'INSERT INTO student_notifications (student_id, title, body, category, status_tag, is_unread, created_at)
                 VALUES (?, ?, ?, ?, ?, TRUE, NOW())'
            );
            $notifTitle = "Request Update: {$req['title']}";
            $notifBody  = "Status changed to: {$status}." . ($statusMessage ? " Note: {$statusMessage}" : " Reference: {$req['reference_number']}");
            $notifStmt->execute([
                $req['student_id'],
                $notifTitle,
                $notifBody,
                'Request',
                $status === 'Ready for Pickup' ? 'Action' : 'Notice'
            ]);

            jsonResponse(true, 'Request status updated and notification dispatched.', [
                'request_id' => $requestId,
                'status'     => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\PDOException $e) {
            error_log('Admin requests POST error: ' . $e->getMessage());
            jsonResponse(false, 'Unable to update request status.', null, 500);
        }
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
