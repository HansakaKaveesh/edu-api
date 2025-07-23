<?php
include 'db_connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$content_id = $_GET['content_id'] ?? null;

if (!$content_id) {
    die("Invalid request");
}

$stmt = $conn->prepare("DELETE FROM contents WHERE content_id = ?");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$stmt->close();

header("Location: teacher_dashboard.php");
exit;
