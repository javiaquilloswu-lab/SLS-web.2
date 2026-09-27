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

// GET: Retrieve scholarship catalog and submitted applications
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Master programs
        $progStmt = $pdo->query('SELECT id, code, title, description, academic_year, deadline, stipend_amount, is_active, created_at FROM scholarships ORDER BY id DESC');
        $programs = $progStmt ? $progStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // Submitted applications
        $appStmt = $pdo->query(
            "SELECT sa.id, sa.student_id, sa.program_name, sa.academic_year, sa.application_type,
                    sa.status, sa.tracking_ref, sa.reviewed_by, sa.reviewed_at, sa.admin_notes, sa.created_at,
                    s.first_name || ' ' || s.last_name as student_name, s.course, s.year_level
             FROM scholarship_applications sa
             LEFT JOIN students s ON sa.student_id = s.student_id
             ORDER BY sa.id DESC"
        );
        $applications = $appStmt ? $appStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        jsonResponse(true, 'Scholarship administrative data retrieved.', [
            'programs'     => $programs,
            'applications' => $applications
        ]);
    } catch (\PDOException $e) {
        error_log('Admin scholarships GET error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to query scholarship data.', null, 500);
    }
}

// POST: Create scholarship program or evaluate application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $action = trim((string)($data['action'] ?? 'create_program'));

    if ($action === 'create_program') {
        $code       = trim((string)($data['code'] ?? ''));
        $title      = trim((string)($data['title'] ?? ''));
        $desc       = trim((string)($data['description'] ?? ''));
        $acadYear   = trim((string)($data['academic_year'] ?? 'AY 2026–2027'));
        $deadline   = trim((string)($data['deadline'] ?? ''));
        $stipend    = trim((string)($data['stipend_amount'] ?? '₱30,000.00'));
        $isActive   = isset($data['is_active']) ? (bool)$data['is_active'] : true;

        if ($code === '' || $title === '') {
            jsonResponse(false, 'Program code and title are required.', null, 422);
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO scholarships (code, title, description, academic_year, deadline, stipend_amount, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 RETURNING id'
            );
            $stmt->execute([$code, $title, $desc, $acadYear, $deadline, $stipend, $isActive ? 'TRUE' : 'FALSE']);
            $newId = $stmt->fetchColumn();

            jsonResponse(true, 'Scholarship program created successfully.', [
                'id'    => (int)$newId,
                'code'  => $code,
                'title' => $title
            ], 201);
        } catch (\PDOException $e) {
            error_log('Admin scholarship create error: ' . $e->getMessage());
            jsonResponse(false, 'Unable to create scholarship program. Code may already exist.', null, 409);
        }
    } elseif ($action === 'update_application') {
        $appId      = isset($data['application_id']) ? (int)$data['application_id'] : 0;
        $status     = trim((string)($data['status'] ?? 'Approved'));
        $adminNotes = trim((string)($data['admin_notes'] ?? ''));

        if ($appId <= 0) {
            jsonResponse(false, 'Valid application_id is required.', null, 422);
        }

        try {
            $findStmt = $pdo->prepare('SELECT id, student_id, program_name, tracking_ref FROM scholarship_applications WHERE id = ?');
            $findStmt->execute([$appId]);
            $app = $findStmt->fetch(PDO::FETCH_ASSOC);

            if (!$app) {
                jsonResponse(false, 'Application record not found.', null, 404);
            }

            $adminName = $admin['full_name'] ?: $admin['username'];

            $updateStmt = $pdo->prepare(
                'UPDATE scholarship_applications
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
                ':id'          => $appId
            ]);

            // Notify student
            $notifStmt = $pdo->prepare(
                'INSERT INTO student_notifications (student_id, title, body, category, status_tag, is_unread, created_at)
                 VALUES (?, ?, ?, ?, ?, TRUE, NOW())'
            );
            $notifTitle = "Scholarship Application: {$status}";
            $notifBody  = "Your application for {$app['program_name']} has been updated to: {$status}." . ($adminNotes ? " Note: {$adminNotes}" : '');
            $notifStmt->execute([
                $app['student_id'],
                $notifTitle,
                $notifBody,
                'Scholarship',
                $status === 'Approved' ? 'Award' : 'Notice'
            ]);

            jsonResponse(true, 'Application status updated and student notified.', [
                'application_id' => $appId,
                'status'         => $status
            ]);
        } catch (\PDOException $e) {
            error_log('Admin scholarship app update error: ' . $e->getMessage());
            jsonResponse(false, 'Unable to update scholarship application.', null, 500);
        }
    } else {
        jsonResponse(false, 'Invalid action specified.', null, 422);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
