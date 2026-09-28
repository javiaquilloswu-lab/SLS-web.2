<?php
require_once __DIR__ . '/db.php';
$stmt = $pdo->query('SELECT id, student_id, title, body, category, status_tag, is_unread, created_at FROM student_notifications ORDER BY id DESC LIMIT 20');
$notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($notifs) . "\n";
print_r($notifs);
