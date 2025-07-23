<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'student') {
    die("Access denied.");
}

$user_id = $_SESSION['user_id'];
$student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'];

$course_id = intval($_GET['course_id'] ?? 0);
$enrollment_id = intval($_GET['enrollment_id'] ?? 0);

// Simulated price or fetch from DB
$course = $conn->query("SELECT name FROM courses WHERE course_id = $course_id")->fetch_assoc();
$amount = 100.00;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'];
    $reference_code = 'REF' . time();

    $stmt = $conn->prepare("INSERT INTO student_payments 
        (student_id, amount, payment_method, payment_status, reference_code) 
        VALUES (?, ?, ?, 'completed', ?)");
    $stmt->bind_param("idss", $student_id, $amount, $payment_method, $reference_code);
    $stmt->execute();

    $conn->query("UPDATE enrollments SET status = 'active' WHERE enrollment_id = $enrollment_id");

    echo "<div class='bg-green-100 text-green-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>
            ✅ Payment Successful! You are now enrolled in <strong>" . htmlspecialchars($course['name']) . "</strong>.
          </div>
          <div class='text-center mt-4'>
            <a href='student_dashboard.php' class='text-blue-600 underline hover:text-blue-800'>⬅ Go to Dashboard</a>
          </div>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Make Payment</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">

    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h2 class="text-2xl font-semibold text-center text-gray-800 mb-4">
            💳 Pay to Enroll in: <?= htmlspecialchars($course['name'] ?? 'Course') ?>
        </h2>

        <p class="text-lg text-gray-700 text-center mb-6">
            Amount: <span class="font-bold text-green-600">$<?= number_format($amount, 2) ?></span>
        </p>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Payment Method:</label>
                <select name="payment_method" required class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring focus:ring-blue-200">
                    <option value="card">Credit/Debit Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cash">Cash</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-md transition duration-200">
                💰 Pay & Enroll
            </button>
        </form>
    </div>

</body>
</html>
