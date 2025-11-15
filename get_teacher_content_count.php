<?php
// get_teacher_content_count.php
session_start();
include __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($teacher_id <= 0) {
    echo json_encode(['count' => 0]);
    exit;
}

// Example: fetch number of lessons/content assigned to this teacher
$q = $conn->prepare("SELECT COUNT(*) AS lesson_count FROM lessons WHERE teacher_id = ?");
$q->bind_param("i", $teacher_id);
$q->execute();
$res = $q->get_result();
$row = $res->fetch_assoc();
$q->close();

echo json_encode(['count' => (int)$row['lesson_count']]);
