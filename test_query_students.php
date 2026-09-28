<?php
require_once __DIR__ . '/db.php';
$stmt = $pdo->query('SELECT id, student_id, first_name, last_name, email, course, year_level, academic_status FROM students');
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($students) . "\n";
print_r($students);
