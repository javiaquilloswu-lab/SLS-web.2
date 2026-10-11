<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Enforce real authentication for document access
$student = requireAuthenticatedStudent($pdo);
$studentId = $student['student_id'];

// GET: Retrieve uploaded documents for the authenticated student (optional request_id scoping)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reqId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
    try {
        if ($reqId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, student_id, request_id, document_type, original_filename, stored_filename, file_path, mime_type, file_size_bytes, status, is_current, reviewed_by, reviewed_at, admin_notes, uploaded_at
                 FROM student_documents
                 WHERE student_id = ? AND (request_id = ? OR request_id IS NULL)
                 ORDER BY id DESC'
            );
            $stmt->execute([$studentId, $reqId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, student_id, request_id, document_type, original_filename, stored_filename, file_path, mime_type, file_size_bytes, status, is_current, reviewed_by, reviewed_at, admin_notes, uploaded_at
                 FROM student_documents
                 WHERE student_id = ?
                 ORDER BY id DESC'
            );
            $stmt->execute([$studentId]);
        }
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, 'Documents retrieved successfully.', $docs);
    } catch (\PDOException $e) {
        error_log('Failed to fetch documents: ' . $e->getMessage());
        jsonResponse(false, 'Unable to retrieve documents at this time.', null, 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method Not Allowed.', null, 405);
}

// POST: Upload document file for authenticated student
$fileField = isset($_FILES['document']) ? 'document' : (isset($_FILES['file']) ? 'file' : null);

if (!$fileField || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $fileField ? $_FILES[$fileField]['error'] : 'No file provided';
    jsonResponse(false, 'File upload error: ' . $errorCode, null, 400);
}

$fileTmpPath   = $_FILES[$fileField]['tmp_name'];
$originalName  = basename($_FILES[$fileField]['name']);
$fileSizeBytes = (int)$_FILES[$fileField]['size'];
$documentType  = trim((string)($_POST['document_type'] ?? 'General Document'));

// Optional request_id and replace_document_id parameters
$requestIdRaw     = $_POST['request_id'] ?? ($_GET['request_id'] ?? null);
$requestId        = ($requestIdRaw !== null && (int)$requestIdRaw > 0) ? (int)$requestIdRaw : null;

$replaceDocIdRaw  = $_POST['replace_document_id'] ?? ($_GET['replace_document_id'] ?? null);
$replaceDocId     = ($replaceDocIdRaw !== null && (int)$replaceDocIdRaw > 0) ? (int)$replaceDocIdRaw : null;

if ($requestId !== null) {
    $checkReq = $pdo->prepare('SELECT id, student_id FROM student_requests WHERE id = ? LIMIT 1');
    $checkReq->execute([$requestId]);
    $reqRow = $checkReq->fetch(PDO::FETCH_ASSOC);

    if (!$reqRow) {
        jsonResponse(false, 'The selected request could not be found.', null, 404);
    }

    if ($reqRow['student_id'] !== $studentId) {
        jsonResponse(false, 'Access denied: Target service request does not belong to your account.', null, 403);
    }
}

if ($replaceDocId !== null) {
    $checkDoc = $pdo->prepare('SELECT id, student_id, request_id, document_type FROM student_documents WHERE id = ? LIMIT 1');
    $checkDoc->execute([$replaceDocId]);
    $oldDoc = $checkDoc->fetch(PDO::FETCH_ASSOC);

    if (!$oldDoc) {
        jsonResponse(false, 'Document to replace could not be found.', null, 404);
    }

    if ($oldDoc['student_id'] !== $studentId) {
        jsonResponse(false, 'Access denied: Target document does not belong to your account.', null, 403);
    }

    // Mark previous document as non-current (preserving historical record in PostgreSQL)
    $updOld = $pdo->prepare('UPDATE student_documents SET is_current = FALSE WHERE id = ?');
    $updOld->execute([$replaceDocId]);
} elseif ($requestId !== null) {
    // Automatically mark previous uploads for this same requirement & request as non-current
    $updPrev = $pdo->prepare('UPDATE student_documents SET is_current = FALSE WHERE student_id = ? AND request_id = ? AND LOWER(document_type) = LOWER(?) AND is_current = TRUE');
    $updPrev->execute([$studentId, $requestId, $documentType]);
}

$maxSize = 10 * 1024 * 1024; // 10MB
if ($fileSizeBytes > $maxSize) {
    jsonResponse(false, 'File exceeds maximum limit of 10MB.', null, 413);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($fileTmpPath);

$allowedMimes = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

if (!array_key_exists($mimeType, $allowedMimes)) {
    jsonResponse(false, 'Unsupported file format. Only PDF, JPG, and PNG are allowed.', null, 415);
}

$extension  = $allowedMimes[$mimeType];
$randomName = bin2hex(random_bytes(16)) . '.' . $extension;

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    error_log('Failed to create upload destination directory: ' . $uploadDir);
    jsonResponse(false, 'Storage server configuration issue.', null, 500);
}

$targetFilePath = $uploadDir . $randomName;
$publicUrlPath  = '/uploads/' . $randomName;

if (!move_uploaded_file($fileTmpPath, $targetFilePath)) {
    error_log('Failed to move uploaded file to: ' . $targetFilePath);
    jsonResponse(false, 'Failed to save uploaded file.', null, 500);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO student_documents (student_id, request_id, document_type, original_filename, stored_filename, file_path, mime_type, file_size_bytes, status, is_current, uploaded_at)
         VALUES (:student_id, :request_id, :document_type, :original_filename, :stored_filename, :file_path, :mime_type, :file_size_bytes, \'Pending Review\', TRUE, NOW())
         RETURNING id'
    );

    $stmt->execute([
        ':student_id'        => $studentId,
        ':request_id'        => $requestId,
        ':document_type'     => $documentType,
        ':original_filename' => $originalName,
        ':stored_filename'   => $randomName,
        ':file_path'         => $publicUrlPath,
        ':mime_type'         => $mimeType,
        ':file_size_bytes'   => $fileSizeBytes,
    ]);

    $docId = $stmt->fetchColumn();

    // Update matching scholarship_requirements status for this student to 'REVIEWING'
    $updReq = $pdo->prepare(
        "UPDATE scholarship_requirements
         SET status = 'REVIEWING', updated_at = NOW()
         WHERE student_id = ? AND (LOWER(title) LIKE LOWER(?) OR LOWER(?) LIKE LOWER('%' || title || '%'))"
    );
    $updReq->execute([$studentId, "%{$documentType}%", $documentType]);

    jsonResponse(true, 'File uploaded and logged successfully.', [
        'document_id'       => (int)$docId,
        'request_id'        => $requestId,
        'file_url'          => $publicUrlPath,
        'original_filename' => $originalName,
        'document_type'     => $documentType,
        'file_size_bytes'   => $fileSizeBytes,
        'status'            => 'REVIEWING'
    ]);
} catch (\PDOException $e) {
    error_log('Document upload failed: ' . $e->getMessage());

    if (file_exists($targetFilePath)) {
        unlink($targetFilePath);
    }

    jsonResponse(false, 'Unable to record document in the database.', null, 500);
}
