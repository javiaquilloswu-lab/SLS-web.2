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

// Require administrative authentication
$admin = requireAuthenticatedAdmin($pdo);

try {
    // 1. Total registered students
    $stmtStudents = $pdo->query('SELECT count(*) FROM students');
    $totalStudents = (int)$stmtStudents->fetchColumn();

    // 2. Pending scholarship applications
    $stmtScholarships = $pdo->query("SELECT count(*) FROM scholarship_applications WHERE status ILIKE '%review%' OR status ILIKE '%pending%'");
    $pendingScholarships = (int)$stmtScholarships->fetchColumn();

    // 3. Uploaded documents pending review
    $stmtDocs = $pdo->query("SELECT count(*) FROM student_documents WHERE status ILIKE '%pending%' OR status ILIKE '%review%'");
    $pendingDocs = (int)$stmtDocs->fetchColumn();

    // 4. Student service requests in processing
    $stmtRequests = $pdo->query("SELECT count(*) FROM student_requests WHERE status ILIKE '%processing%' OR status ILIKE '%review%'");
    $activeRequests = (int)$stmtRequests->fetchColumn();

    // 5. Total and escalated student concerns/inquiries
    $stmtConcerns = $pdo->query('SELECT count(*) FROM student_concerns');
    $totalConcerns = (int)$stmtConcerns->fetchColumn();

    $stmtEscalated = $pdo->query("SELECT count(*) FROM student_concerns WHERE status ILIKE '%escalat%' OR status ILIKE '%review%' OR status = 'Staff Handling'");
    $escalatedConcerns = (int)$stmtEscalated->fetchColumn();

    $stmtResolved = $pdo->query("SELECT count(*) FROM student_concerns WHERE status ILIKE '%resolv%' OR status ILIKE '%close%'");
    $resolvedConcerns = (int)$stmtResolved->fetchColumn();

    // 6. Recent system activities across services for dashboard feed
    $recentActivityStmt = $pdo->query(
        "(SELECT 'document' as type, sd.original_filename as title, s.first_name || ' ' || s.last_name as student_name, sd.status, sd.uploaded_at as event_time
          FROM student_documents sd
          LEFT JOIN students s ON sd.student_id = s.student_id
          ORDER BY sd.uploaded_at DESC LIMIT 5)
         UNION ALL
         (SELECT 'request' as type, sr.title, s.first_name || ' ' || s.last_name as student_name, sr.status, sr.created_at as event_time
          FROM student_requests sr
          LEFT JOIN students s ON sr.student_id = s.student_id
          ORDER BY sr.created_at DESC LIMIT 5)
         UNION ALL
         (SELECT 'concern' as type, sc.category || ' (' || sc.ticket_ref || ')' as title, s.first_name || ' ' || s.last_name as student_name, sc.status, sc.created_at as event_time
          FROM student_concerns sc
          LEFT JOIN students s ON sc.student_id = s.student_id
          ORDER BY sc.created_at DESC LIMIT 5)
         ORDER BY event_time DESC
         LIMIT 10"
    );
    $recentActivities = $recentActivityStmt ? $recentActivityStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    jsonResponse(true, 'Telemetry statistics retrieved.', [
        'total_students'           => $totalStudents,
        'pending_scholarships'     => $pendingScholarships,
        'documents_pending_review' => $pendingDocs,
        'active_requests'          => $activeRequests,
        'total_concerns'           => $totalConcerns,
        'escalated_concerns'       => $escalatedConcerns,
        'resolved_concerns'        => $resolvedConcerns,
        'recent_activities'        => $recentActivities,
        'authenticated_admin'      => [
            'username'   => $admin['username'],
            'full_name'  => $admin['full_name'],
            'department' => $admin['department'],
            'role'       => $admin['role']
        ]
    ]);
} catch (\PDOException $e) {
    error_log('Admin stats error: ' . $e->getMessage());
    jsonResponse(false, 'Unable to aggregate administrative statistics.', null, 500);
}
