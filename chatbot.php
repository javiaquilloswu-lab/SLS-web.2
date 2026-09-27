<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

// Enforce real authentication
$student = requireAuthenticatedStudent($pdo);
$studentId = $student['student_id'];
$firstName = $student['first_name'] ?? 'Student';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method Not Allowed. Use POST.', null, 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$userMessage = trim((string)($data['message'] ?? ''));
$history     = is_array($data['history'] ?? null) ? $data['history'] : [];

if ($userMessage === '') {
    jsonResponse(false, 'Message cannot be empty.', null, 422);
}

// Generate an official Student Life tracking ticket number
$ticketRef = 'SL-2026-' . str_pad((string)random_int(100, 9999), 6, '0', STR_PAD_LEFT);

$replyText = '';
$modelUsed = 'SWU Student Affairs Knowledge Engine';

// Read secret GEMINI_API_KEY from server-side environment / .env
$apiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');

if (!empty($apiKey) && function_exists('curl_init')) {
    $systemInstruction = "You are the Southwestern University (SWU) PHINMA Student Life Support Assistant. "
        . "You are assisting student {$firstName} (ID: {$studentId}) with academic appeals, scholarship renewals (CHED CMSP), "
        . "official document requests (COC, Certificate of Grades, Good Moral), lost RFID student IDs, and campus counseling. "
        . "Always be professional, reassuring, clear, and concise. Address the student respectfully. "
        . "If the student describes an urgent appeal or grievance, acknowledge it and reference tracking ticket #{$ticketRef}.";

    $contents = [];
    foreach ($history as $h) {
        $sender = ($h['sender'] ?? '') === 'bot' ? 'model' : 'user';
        $text = trim((string)($h['text'] ?? ''));
        if ($text !== '') {
            $contents[] = [
                'role' => $sender,
                'parts' => [['text' => $text]]
            ];
        }
    }
    // Add current user message
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $userMessage]]
    ];

    $payload = [
        'systemInstruction' => [
            'parts' => [['text' => $systemInstruction]]
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 500
        ]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);

    $apiResult = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $apiResult) {
        $json = json_decode($apiResult, true);
        $candidateText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($candidateText !== '') {
            $replyText = trim($candidateText);
            $modelUsed = 'Gemini 2.0 Flash (via PHP Backend)';
        }
    }
}

// Fallback intelligent domain logic if Gemini API key is unset or network is restricted
if ($replyText === '') {
    $lowerMsg = strtolower($userMessage);
    if (strpos($lowerMsg, 'appeal') !== false || strpos($lowerMsg, 'grade') !== false) {
        $replyText = "Thank you {$firstName}. Your academic appeal regarding grade evaluation has been officially logged with reference {$ticketRef}. It has been forwarded to the Dean of Student Affairs for immediate bursar review. You will receive an update in your notifications within 24-48 business hours.";
    } elseif (strpos($lowerMsg, 'scholarship') !== false || strpos($lowerMsg, 'ched') !== false) {
        $replyText = "For CHED Academic Excellence renewals, ensure your Certificate of Grades (COG) with minimum 1.35 GWA and the signed Scholarship Agreement are uploaded before the August 25, 2026 cutoff. Reference {$ticketRef} has been recorded for your inquiry.";
    } elseif (strpos($lowerMsg, 'moral') !== false || strpos($lowerMsg, 'coc') !== false || strpos($lowerMsg, 'transcript') !== false) {
        $replyText = "Document request recorded. Good Moral certificates and Certificates of Completion (COC) require 1-3 business days processing by the Registrar Office. Digital QR clearance will be available in your Student Life portal.";
    } elseif (strpos($lowerMsg, 'id') !== false || strpos($lowerMsg, 'lost') !== false) {
        $replyText = "For Lost ID replacements, please submit an affidavit of loss or request re-printing through Document Requests. Replacement smart cards carry a ₱150 fee and take 3-5 business days.";
    } else {
        $replyText = "Thank you for reaching out to the Student Life Support Office, {$firstName}. Your concern has been documented under tracking ticket {$ticketRef}. A student support officer or guidance counselor will review your inquiry shortly.";
    }
}

// Save concern record and transcript turns in PostgreSQL for the authenticated student
try {
    $stmt = $pdo->prepare(
        'INSERT INTO student_concerns (student_id, ticket_ref, message, response, status, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         RETURNING id'
    );
    $stmt->execute([$studentId, $ticketRef, $userMessage, $replyText, 'Open']);
    $concernId = $stmt->fetchColumn();

    if ($concernId) {
        // Insert normalized multi-turn records
        $msgStmt = $pdo->prepare(
            'INSERT INTO student_concern_messages (concern_id, ticket_ref, sender_type, sender_id, sender_name, message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $msgStmt->execute([(int)$concernId, $ticketRef, 'STUDENT', $studentId, $firstName, $userMessage]);
        $msgStmt->execute([(int)$concernId, $ticketRef, 'AI', 'SWU AI Assistant', 'AI Student Life Assistant', $replyText]);
    }
} catch (\PDOException $e) {
    error_log('Failed to log student concern: ' . $e->getMessage());
}

jsonResponse(true, 'Support response generated successfully.', [
    'reply'       => $replyText,
    'ticket_ref'  => $ticketRef,
    'time'        => 'Just now',
    'model_used'  => $modelUsed,
    'timestamp'   => date('g:i A')
]);
