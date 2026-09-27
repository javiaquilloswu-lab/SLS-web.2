<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Enforce real authentication
$student = requireAuthenticatedStudent($pdo);
$studentId = $student['student_id'];

// GET: Fetch scholarship overview, requirements, and compliance for the authenticated student
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Retrieve requirements for this student using consistent positional placeholders
        $stmt = $pdo->prepare(
            'SELECT id, title, description, status, updated_at
             FROM scholarship_requirements
             WHERE student_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$studentId]);
        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch active scholarship program info from scholarships table if available
        $progStmt = $pdo->query(
            "SELECT code, title, description, academic_year, deadline, stipend_amount
             FROM scholarships
             WHERE is_active = TRUE
             ORDER BY id ASC
             LIMIT 1"
        );
        $program = $progStmt ? $progStmt->fetch(PDO::FETCH_ASSOC) : null;

        $programName = $program['title'] ?? 'CHED Academic Excellence (CMSP)';
        $acadYear    = $program['academic_year'] ?? 'AY 2024–2025 • Mid-Year Renewal Cycle';
        $deadline    = $program['deadline'] ?? 'August 25, 2026';
        $stipend     = $program['stipend_amount'] ?? '₱30,000.00';

        // Calculate compliance stats from real student requirements
        $total = count($requirements);
        $verifiedCount = 0;
        $reviewingCount = 0;
        $uploadCount = 0;

        foreach ($requirements as $r) {
            $st = strtoupper((string)($r['status'] ?? ''));
            if ($st === 'VERIFIED') {
                $verifiedCount++;
            } elseif ($st === 'REVIEWING') {
                $reviewingCount++;
            } else {
                $uploadCount++;
            }
        }

        $progress = $total > 0 ? (int)round(($verifiedCount / $total) * 100) : 0;

        jsonResponse(true, 'Scholarship data retrieved successfully.', [
            'program_name'         => $programName,
            'academic_year'        => $acadYear,
            'grant_status'         => $total > 0 ? 'Active Scholar' : 'Application Available',
            'progress_percent'     => $progress,
            'verified_count'       => $verifiedCount,
            'reviewing_count'      => $reviewingCount,
            'required_count'       => $uploadCount,
            'total_count'          => $total,
            'requirements'         => $requirements,
            'stipend_amount'       => $stipend,
            'next_critical_cutoff' => $deadline
        ]);
    } catch (\PDOException $e) {
        error_log('Failed to fetch scholarship info: ' . $e->getMessage());
        jsonResponse(false, 'Unable to retrieve scholarship details at this time.', null, 500);
    }
}

// POST: Submit a scholarship application for authenticated student
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $programName = trim((string)($data['program_name'] ?? 'CHED Full Merit Scholarship Award (CMSP)'));
    $acadYear    = trim((string)($data['academic_year'] ?? '1st Semester, A.Y. 2024–2025'));
    $appType     = trim((string)($data['application_type'] ?? 'New Application'));
    $refNumber   = 'SL-2026-' . str_pad((string)random_int(100, 9999), 6, '0', STR_PAD_LEFT);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO scholarship_applications (student_id, program_name, academic_year, application_type, status, tracking_ref, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             RETURNING id'
        );
        $stmt->execute([$studentId, $programName, $acadYear, $appType, 'Under Review', $refNumber]);
        $appId = $stmt->fetchColumn();

        // Also record in student_requests
        $reqStmt = $pdo->prepare(
            'INSERT INTO student_requests (student_id, title, department, reference_number, status, status_message, category, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $reqStmt->execute([
            $studentId,
            'Scholarship Application — ' . $programName,
            'Scholarship & Grants Office',
            $refNumber,
            'Under Review',
            'Submitted for bursar evaluation',
            'Scholarship'
        ]);

        jsonResponse(true, 'Scholarship application submitted successfully.', [
            'application_id'   => (int)$appId,
            'reference_number' => $refNumber,
            'program_name'     => $programName,
            'status'           => 'Under Review'
        ], 201);
    } catch (\PDOException $e) {
        error_log('Failed to save scholarship application: ' . $e->getMessage());
        jsonResponse(false, 'Unable to submit scholarship application at this time.', null, 500);
    }
}

jsonResponse(false, 'Method Not Allowed.', null, 405);
