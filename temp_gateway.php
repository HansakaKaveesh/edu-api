<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$user_id = (int)$_SESSION['user_id'];

/* Get student_id */
$student_id = 0;
if ($stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) $student_id = (int)$row['student_id'];
    $stmt->close();
}
if ($student_id === 0) { header("Location: login.php"); exit; }

$ref = $_GET['ref'] ?? '';
$course_id = (int)($_GET['course_id'] ?? 0);
$enrollment_id = (int)($_GET['enrollment_id'] ?? 0);

if ($ref === '') {
    http_response_code(400);
    die('Missing reference.');
}

/* Load payment row to confirm it is pending & belongs to student */
$payment = null;
if ($stmt = $conn->prepare("SELECT student_id, amount, payment_status, payment_method FROM student_payments WHERE reference_code = ? LIMIT 1")) {
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    $payment = $res->fetch_assoc();
    $stmt->close();
}
if (!$payment || (int)$payment['student_id'] !== $student_id) {
    http_response_code(403);
    die('Invalid or unauthorized reference code.');
}
if ($payment['payment_status'] !== 'pending') {
    http_response_code(400);
    die('This payment is already processed.');
}

/* Fetch course (for display only) */
$course = ['name' => 'Course'];
if ($stmt = $conn->prepare("SELECT name FROM courses WHERE course_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $course = $r;
    $stmt->close();
}

/* CSRF token per ref */
if (empty($_SESSION['temp_csrf'][$ref])) {
    $_SESSION['temp_csrf'][$ref] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['temp_csrf'][$ref];

/* Handle POST simulate success/cancel */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token  = $_POST['csrf'] ?? '';

    if (!hash_equals($csrf, $token)) {
        http_response_code(403);
        die('Invalid token');
    }

    if ($action === 'success') {
        // Mark payment completed
        if ($stmt = $conn->prepare("UPDATE student_payments SET payment_status = 'completed' WHERE reference_code = ? LIMIT 1")) {
            $stmt->bind_param("s", $ref);
            $stmt->execute();
            $stmt->close();
        }
        // Activate enrollment
        if ($enrollment_id > 0) {
            $stmt = $conn->prepare("UPDATE enrollments SET status = 'active' WHERE enrollment_id = ?");
            $stmt->bind_param("i", $enrollment_id);
            $stmt->execute();
            $stmt->close();
        }

        unset($_SESSION['temp_csrf'][$ref]);

        echo "<div class='bg-green-100 text-green-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>
                ✅ Payment Successful! You are now enrolled in <strong>" . e($course['name']) . "</strong>.
              </div>
              <div class='text-center mt-4'>
                <a href='student_dashboard.php' class='text-blue-600 underline hover:text-blue-800'>⬅ Go to Dashboard</a>
              </div>";
        exit;
    }

    if ($action === 'cancel') {
        // Mark failed
        if ($stmt = $conn->prepare("UPDATE student_payments SET payment_status = 'failed' WHERE reference_code = ? LIMIT 1")) {
            $stmt->bind_param("s", $ref);
            $stmt->execute();
            $stmt->close();
        }

        unset($_SESSION['temp_csrf'][$ref]);

        echo "<div class='bg-yellow-100 text-yellow-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>
                ⚠️ Payment canceled. You can try again anytime.
              </div>
              <div class='text-center mt-4'>
                <a href='make_payment.php?".http_build_query(['course_id'=>$course_id,'enrollment_id'=>$enrollment_id])."' class='text-blue-600 underline hover:text-blue-800'>⬅ Back to Payment</a>
              </div>";
        exit;
    }

    http_response_code(400);
    die('Unknown action.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Temporary Payment Gateway (Test)</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
  <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
    <h2 class="text-2xl font-semibold text-center text-gray-800 mb-1">Temporary Payment Gateway</h2>
    <p class="text-center text-gray-500 mb-6">This is a test screen — no real charges will occur.</p>

    <div class="space-y-2 text-gray-700 mb-6">
      <div class="flex justify-between"><span>Course:</span> <strong><?= e($course['name']) ?></strong></div>
      <div class="flex justify-between"><span>Amount:</span> <strong>$<?= number_format((float)$payment['amount'], 2) ?></strong></div>
      <div class="flex justify-between"><span>Reference:</span> <code class="text-sm"><?= e($ref) ?></code></div>
      <div class="flex justify-between"><span>Method:</span> <span class="uppercase text-xs bg-gray-100 px-2 py-0.5 rounded"><?= e($payment['payment_method']) ?></span></div>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>" />
      <button name="action" value="success" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2 rounded-md transition">
        ✅ Simulate Success
      </button>
      <button name="action" value="cancel" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-2 rounded-md transition">
        ❌ Cancel
      </button>
    </form>

    <p class="text-xs text-gray-500 mt-4 text-center">Use this only for development/testing.</p>
  </div>
</body>
</html>