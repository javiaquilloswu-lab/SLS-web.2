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
$action      = trim((string)($data['action'] ?? 'chat')); // 'chat' or 'create_concern'

if ($userMessage === '') {
    jsonResponse(false, 'Message cannot be empty.', null, 422);
}

$replyText = '';
$modelUsed = 'SWU Student Affairs Knowledge Engine';

// Read secret GEMINI_API_KEY from server-side environment / .env
$apiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');

if (!empty($apiKey) && function_exists('curl_init')) {
    $systemInstruction = "You are the Southwestern University (SWU) PHINMA Student Life Support Assistant. "
        . "You are assisting student {$firstName} (ID: {$studentId}) with academic appeals, scholarship renewals (CHED CMSP), "
        . "official document requests (COC, Certificate of Grades, Good Moral), lost RFID student IDs, and campus counseling. "
        . "Always be professional, reassuring, clear, and concise. Address the student respectfully. "
        . "Provide accurate guidance based on official SWU PHINMA Student Life policies.";

    $contents = [];
    foreach ($history as $h) {
        $sender = ($h['sender'] ?? '') === 'bot' || ($h['sender'] ?? '') === 'model' ? 'model' : 'user';
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

    // Attempt Gemini API call (gemini-2.0-flash / gemini-3.8-flash endpoint)
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
        $replyText = "For academic appeals regarding grade evaluation, certified Grade Slips must be submitted to the Office of the Dean of Student Affairs. Official appeals take 2-3 business days for bursar evaluation.";
    } elseif (strpos($lowerMsg, 'scholarship') !== false || strpos($lowerMsg, 'ched') !== false) {
        $replyText = "For CHED Academic Excellence renewals, ensure your Certificate of Grades (COG) with minimum 1.35 GWA and signed Scholarship Agreement are uploaded in your Student Portal before the cutoff.";
    } elseif (strpos($lowerMsg, 'moral') !== false || strpos($lowerMsg, 'coc') !== false || strpos($lowerMsg, 'transcript') !== false) {
        $replyText = "Good Moral certificates and Certificates of Completion (COC) require 1-3 business days processing by the Registrar Office. Digital QR clearance will be available in your Student Life portal.";
    } elseif (strpos($lowerMsg, 'id') !== false || strpos($lowerMsg, 'lost') !== false) {
        $replyText = "For Lost ID replacements, please submit an affidavit of loss or request re-printing through Document Requests. Replacement smart cards carry a ₱150 fee and take 3-5 business days.";
    } else {
        $replyText = "Thank you for reaching out to the Student Life Support Office, {$firstName}. You can ask me questions about scholarship renewals, document requests, lost IDs, or academic clearance procedures.";
    }
}

// Persist conversation turns in PostgreSQL student_concern_messages for history tracking
try {
    // If student explicitly requested creating an official support concern
    if ($action === 'create_concern') {
        $ticketRef = 'SL-2026-' . str_pad((string)random_int(100, 9999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare(
            'INSERT INTO student_concerns (student_id, ticket_ref, message, response, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             RETURNING id'
        );
        $stmt->execute([$studentId, $ticketRef, $userMessage, $replyText, 'Open']);
        $concernId = $stmt->fetchColumn();

        if ($concernId) {
            $msgStmt = $pdo->prepare(
                'INSERT INTO student_concern_messages (concern_id, ticket_ref, student_id, sender_type, sender_id, sender_name, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $msgStmt->execute([(int)$concernId, $ticketRef, $studentId, 'STUDENT', $studentId, $firstName, $userMessage]);
            $msgStmt->execute([(int)$concernId, $ticketRef, $studentId, 'AI', 'SWU AI Assistant', 'AI Student Life Assistant', $replyText]);
        }

        jsonResponse(true, 'Official support concern created successfully.', [
            'reply'       => $replyText,
            'ticket_ref'  => $ticketRef,
            'time'        => 'Just now',
            'model_used'  => $modelUsed,
            'timestamp'   => date('g:i A')
        ]);
    } else {
        // Normal AI conversation turn: Persist in student_concern_messages WITHOUT creating an official concern ticket
        $msgStmt = $pdo->prepare(
                'INSERT INTO student_concern_messages (concern_id, ticket_ref, student_id, sender_type, sender_id, sender_name, message, created_at)
             VALUES (NULL, NULL, ?, ?, ?, ?, ?, NOW())'
        );
        $msgStmt->execute([$studentId, 'STUDENT', $studentId, $firstName, $userMessage]);
        $msgStmt->execute([$studentId, 'AI', 'SWU AI Assistant', 'AI Student Life Assistant', $replyText]);

        jsonResponse(true, 'AI response generated successfully.', [
            'reply'      => $replyText,
            'time'       => 'Just now',
            'model_used' => $modelUsed,
            'timestamp'  => date('g:i A')
        ]);
    }
} catch (\PDOException $e) {
    error_log('Failed to log conversation turn: ' . $e->getMessage());

    // Still return the generated AI reply even if conversation logging fails
    jsonResponse(true, 'AI response generated successfully.', [
        'reply'      => $replyText,
        'time'       => 'Just now',
        'model_used' => $modelUsed,
        'timestamp'  => date('g:i A')
    ]);
}
