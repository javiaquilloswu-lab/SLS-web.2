<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Helper to determine if a deadline date string (e.g. "October 31, 2026") has passed
function isDeadlineExpired(?string $deadlineStr): bool {
    if ($deadlineStr === null || trim($deadlineStr) === '') {
        return false;
    }
    $ts = strtotime(trim($deadlineStr));
    if ($ts === false) {
        return false; // Safe fallback if non-standard string
    }
    // Deadline expires after 23:59:59 on the deadline date
    $deadlineEnd = strtotime(date('Y-m-d 23:59:59', $ts));
    return time() > $deadlineEnd;
}

// Enforce real authentication
$student = requireAuthenticatedStudent($pdo);
$studentId = $student['student_id'];

// GET: Fetch scholarship overview, requirements, and compliance for the authenticated student
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Retrieve requirements for this student
        $stmt = $pdo->prepare(
            'SELECT id, title, description, status, updated_at
             FROM scholarship_requirements
             WHERE student_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$studentId]);
        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all active master scholarship programs
        $progStmt = $pdo->query(
            "SELECT code, title, description, academic_year, deadline, stipend_amount, is_active
             FROM scholarships
             WHERE is_active = TRUE
             ORDER BY id ASC"
        );
        $rawPrograms = $progStmt ? $progStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // Filter available programs to include only non-expired active programs for new applications
        $availablePrograms = [];
        foreach ($rawPrograms as $p) {
            $expired = isDeadlineExpired($p['deadline'] ?? null);
            if (!$expired) {
                $p['is_expired'] = false;
                $availablePrograms[] = $p;
            }
        }

        $program = !empty($availablePrograms) ? $availablePrograms[0] : (!empty($rawPrograms) ? $rawPrograms[0] : null);

        $programName = $program['title'] ?? 'CHED Academic Excellence (CMSP)';
        $acadYear    = $program['academic_year'] ?? 'AY 2026–2027 • Mid-Year Renewal Cycle';
        $deadline    = $program['deadline'] ?? 'October 31, 2026';
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
            'next_critical_cutoff' => $deadline,
            'available_programs'   => $availablePrograms
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
    $acadYear    = trim((string)($data['academic_year'] ?? '1st Semester, A.Y. 2026–2027'));
    $appType     = trim((string)($data['application_type'] ?? 'New Application'));

    if ($programName === '') {
        jsonResponse(false, 'Scholarship program name is required.', null, 422);
    }

    try {
        // 1. Verify program status and deadline in PostgreSQL master scholarships table
        $progLookup = $pdo->prepare('SELECT id, code, title, deadline, requirements, is_active FROM scholarships WHERE title ILIKE ? OR code ILIKE ? LIMIT 1');
        $progLookup->execute([$programName, $programName]);
        $prog = $progLookup->fetch(PDO::FETCH_ASSOC);

        if ($prog) {
            if (!(bool)$prog['is_active']) {
                jsonResponse(false, 'This scholarship program is no longer accepting applications.', null, 400);
            }
            if (isDeadlineExpired($prog['deadline'] ?? null)) {
                jsonResponse(false, 'The application deadline for this scholarship program has passed.', null, 400);
            }
        }

        // 2. Enforce Duplicate Protection: Check if active application already exists under review/processing/approved for this program
        $checkStmt = $pdo->prepare(
            "SELECT id, tracking_ref FROM scholarship_applications
             WHERE student_id = ? AND program_name = ? AND status IN ('Under Review', 'Processing', 'Approved')
             LIMIT 1"
        );
        $checkStmt->execute([$studentId, $programName]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            jsonResponse(false, "You already have an active application ({$existing['tracking_ref']}) for {$programName}.", null, 409);
        }

        $refNumber = 'SL-2026-' . str_pad((string)random_int(100, 9999), 6, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare(
            'INSERT INTO scholarship_applications (student_id, program_name, academic_year, application_type, status, tracking_ref, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             RETURNING id'
        );
        $stmt->execute([$studentId, $programName, $acadYear, $appType, 'Under Review', $refNumber]);
        $appId = $stmt->fetchColumn();

        // 3. Auto-seed student checklist in scholarship_requirements from program requirement template
        if ($prog && !empty($prog['requirements'])) {
            $reqsList = json_decode($prog['requirements'], true);
            if (is_array($reqsList)) {
                $insReqStmt = $pdo->prepare(
                    'INSERT INTO scholarship_requirements (student_id, title, description, status, updated_at)
                     VALUES (?, ?, ?, \'UPLOAD\', NOW())'
                );
                foreach ($reqsList as $rItem) {
                    $rTitle = trim((string)($rItem['name'] ?? 'Requirement'));
                    $rDesc  = trim((string)($rItem['description'] ?? ''));

                    $checkReq = $pdo->prepare('SELECT id FROM scholarship_requirements WHERE student_id = ? AND LOWER(title) = LOWER(?) LIMIT 1');
                    $checkReq->execute([$studentId, $rTitle]);
                    if (!$checkReq->fetch()) {
                        $insReqStmt->execute([$studentId, $rTitle, $rDesc]);
                    }
                }
            }
        }

        // 4. Also record in student_requests
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
