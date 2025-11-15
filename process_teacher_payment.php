<?php
// process_teacher_payment.php
session_start();
include __DIR__ . '/db_connect.php';

// Only allow accountant, admin, ceo
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['accountant','admin','ceo'], true)) {
    header("Location: login.php");
    exit;
}

// CSRF protection
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request");
}
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF token mismatch");
}

$action = $_POST['action'] ?? '';
$userId = (int)$_SESSION['user_id'];

// Helpers
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($action === 'create') {
    // Create a manual payment
    $teacher_id   = (int)($_POST['teacher_id'] ?? 0);
    $course_id    = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $lesson_count = (int)($_POST['lesson_count'] ?? 0);
    $rate_per_lesson = isset($_POST['rate_per_lesson']) && $_POST['rate_per_lesson'] !== '' ? (float)$_POST['rate_per_lesson'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if (!$teacher_id || $lesson_count <= 0) die("Invalid teacher or lesson count");

    // Check for previous completed payments for same teacher & course & lesson count
    $checkSql = "SELECT SUM(lesson_count) as total_paid 
                 FROM teacher_payments 
                 WHERE teacher_id=? AND course_id ".($course_id ? "=?" : "IS NULL")." AND payment_status='completed'";
    if ($course_id) {
        $stmt = $conn->prepare($checkSql);
        $stmt->bind_param("ii", $teacher_id, $course_id);
    } else {
        $stmt = $conn->prepare($checkSql);
        $stmt->bind_param("i", $teacher_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $paid = $res->fetch_assoc()['total_paid'] ?? 0;
    $stmt->close();

    if ($paid >= $lesson_count) {
        die("This teacher has already been paid for these lessons.");
    }

    // Default rate if not provided
    if ($rate_per_lesson === null) {
        $stmt = $conn->prepare("SELECT rate_per_lesson FROM teacher_rates tr
                                JOIN teachers t ON t.board=tr.board AND t.level=tr.level
                                WHERE t.teacher_id=? LIMIT 1");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rate_per_lesson = (float)($res->fetch_assoc()['rate_per_lesson'] ?? 0);
        $stmt->close();
    }

    $amount = $lesson_count * $rate_per_lesson;

    // Insert payment
    $stmt = $conn->prepare("INSERT INTO teacher_payments
        (teacher_id, course_id, lesson_count, rate_per_lesson, amount, notes, payment_status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())");
    $stmt->bind_param("iiiddsi", $teacher_id, $course_id, $lesson_count, $rate_per_lesson, $amount, $notes, $userId);
    if ($stmt->execute()) {
        header("Location: accountant_dashboard?created=1");
        exit;
    } else {
        die("Failed to create payment: " . $stmt->error);
    }
    $stmt->close();

} elseif ($action === 'verify') {
    // Verify & pay a pending payment
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    if (!$payment_id) die("Invalid payment ID");

    // Fetch payment to ensure it exists and is pending
    $stmt = $conn->prepare("SELECT payment_status FROM teacher_payments WHERE teacher_payment_id = ?");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $payment = $res->fetch_assoc();
    $stmt->close();

    if (!$payment) die("Payment not found");
    if ($payment['payment_status'] !== 'pending') die("Payment already completed");

    // Update payment as completed
    $new_status = 'completed';
    $stmt = $conn->prepare("UPDATE teacher_payments 
                            SET payment_status = ?, verified_by = ?, verified_at = NOW() 
                            WHERE teacher_payment_id = ?");
    $stmt->bind_param("sii", $new_status, $userId, $payment_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        header("Location: accountant_dashboard?verified=1");
        exit;
    } else {
        die("Failed to verify payment.");
    }
    $stmt->close();

} else {
    die("Unknown action");
}
?>
