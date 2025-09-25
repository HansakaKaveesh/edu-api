<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function uploadErrorMessage($code) {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds upload_max_filesize in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        default => 'Unknown file upload error.'
    };
}
function columnExists(mysqli $conn, string $table, string $column): bool {
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '{$conn->real_escape_string($column)}'");
    $ok = $q && $q->num_rows > 0;
    if ($q) $q->free();
    return $ok;
}

$user_id = (int)$_SESSION['user_id'];

/* Get student_id safely */
$student_id = 0;
if ($stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) $student_id = (int)$row['student_id'];
    $stmt->close();
}
if ($student_id === 0) {
    header("Location: login.php");
    exit;
}

$course_id = (int)($_GET['course_id'] ?? 0);
$enrollment_id = (int)($_GET['enrollment_id'] ?? 0);

/* Fetch course info */
$course = ['name' => 'Course', 'price' => 0.00];
if ($stmt = $conn->prepare("SELECT name, price FROM courses WHERE course_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $course = $r;
    $stmt->close();
}
$amount = isset($course['price']) ? (float)$course['price'] : 0.00;

/* Handle form submit */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? 'card';
    $reference_code = 'REF' . time() . rand(100,999);

    // Free course: enroll immediately regardless of method
    if ($amount == 0) {
        if ($stmt = $conn->prepare("INSERT INTO student_payments (student_id, amount, payment_method, payment_status, reference_code) VALUES (?, ?, 'free', 'completed', ?)")) {
            $stmt->bind_param("ids", $student_id, $amount, $reference_code);
            $stmt->execute();
            $stmt->close();
        }
        if ($enrollment_id > 0) {
            if ($stmt = $conn->prepare("UPDATE enrollments SET status = 'active' WHERE enrollment_id = ?")) {
                $stmt->bind_param("i", $enrollment_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        echo "<div class='bg-green-100 text-green-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>
                🎉 Enrolled for free! You are now in <strong>" . e($course['name']) . "</strong>.
              </div>
              <div class='text-center mt-4'>
                <a href='student_dashboard.php' class='text-blue-600 underline hover:text-blue-800'>⬅ Go to Dashboard</a>
              </div>";
        exit;
    }

    // Bank transfer: allow slip upload, mark payment as pending (to be approved by admin)
    if ($payment_method === 'bank_transfer') {
        $slip_url = '';

        // Process uploaded slip if any
        if (isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] !== UPLOAD_ERR_NO_FILE) {
            $err = $_FILES['payment_slip']['error'];
            if ($err === UPLOAD_ERR_OK) {
                $tmp = $_FILES['payment_slip']['tmp_name'];
                $orig = $_FILES['payment_slip']['name'];
                $size = (int)$_FILES['payment_slip']['size'];

                // Validate extension and size
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allowed = ['pdf','jpg','jpeg','png','webp'];
                if (!in_array($ext, $allowed, true)) {
                    echo "<div class='bg-red-100 text-red-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>❌ Invalid slip type. Allowed: PDF, JPG, PNG, WEBP.</div>";
                    exit;
                }
                // Limit ~10MB
                if ($size > 10 * 1024 * 1024) {
                    echo "<div class='bg-red-100 text-red-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>❌ File too large. Max 10MB.</div>";
                    exit;
                }

                $folder = __DIR__ . '/uploads/payment_slips/';
                if (!is_dir($folder)) @mkdir($folder, 0755, true);
                $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
                $filename = $reference_code . '_' . substr($safeBase, 0, 40) . '.' . $ext;
                $destFs = $folder . $filename;
                if (move_uploaded_file($tmp, $destFs)) {
                    $slip_url = 'uploads/payment_slips/' . $filename;
                } else {
                    echo "<div class='bg-red-100 text-red-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>❌ Failed to save uploaded file.</div>";
                    exit;
                }
            } else {
                echo "<div class='bg-red-100 text-red-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>❌ Upload error: " . e(uploadErrorMessage($err)) . "</div>";
                exit;
            }
        }

        // Insert pending payment with reference; store slip_url if the column exists
        $hasSlipCol = columnExists($conn, 'student_payments', 'slip_url');
        if ($hasSlipCol) {
            if ($stmt = $conn->prepare("INSERT INTO student_payments (student_id, amount, payment_method, payment_status, reference_code, slip_url) VALUES (?, ?, 'bank_transfer', 'pending', ?, ?)")) {
                $stmt->bind_param("idss", $student_id, $amount, $reference_code, $slip_url);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            if ($stmt = $conn->prepare("INSERT INTO student_payments (student_id, amount, payment_method, payment_status, reference_code) VALUES (?, ?, 'bank_transfer', 'pending', ?)")) {
                $stmt->bind_param("ids", $student_id, $amount, $reference_code);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Do NOT activate enrollment yet; admin will verify and activate
        echo "<div class='bg-white p-6 rounded-lg shadow-md w-full max-w-xl mx-auto mt-10'>
                <h3 class='text-2xl font-bold text-gray-800 mb-2'>Bank Transfer Submitted</h3>
                <p class='text-gray-700 mb-3'>Your payment has been recorded as <strong>pending</strong>. Our team will verify your slip and activate your enrollment in <strong>" . e($course['name']) . "</strong>.</p>
                <div class='bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-4 mb-4'>
                    <div class='flex items-center justify-between'>
                        <div>
                          <div class='text-sm'>Amount</div>
                          <div class='text-2xl font-extrabold'>$" . number_format($amount, 2) . "</div>
                        </div>
                        <div class='text-right'>
                          <div class='text-sm'>Reference Code</div>
                          <div class='font-mono text-lg font-semibold'>" . e($reference_code) . "</div>
                        </div>
                    </div>
                </div>" .
                ($slip_url ? "<p class='text-sm text-gray-600 mb-2'>Uploaded slip: <a href='" . e($slip_url) . "' target='_blank' class='text-blue-600 underline'>View file</a></p>" :
                              "<p class='text-sm text-gray-600 mb-2'>No slip uploaded.</p>") .
               "<div class='text-sm text-gray-600 mb-4'>If we need more information, we'll reach out. You can also contact support with your reference code.</div>
                <div class='text-center'>
                  <a href='student_dashboard.php' class='inline-flex items-center gap-2 px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700'>
                    ⬅ Back to Dashboard
                  </a>
                </div>
              </div>";
        exit;
    }

    // Other methods (card, cash, other) — keep existing immediate completion behavior
    if ($stmt = $conn->prepare("INSERT INTO student_payments (student_id, amount, payment_method, payment_status, reference_code) VALUES (?, ?, ?, 'completed', ?)")) {
        $stmt->bind_param("idss", $student_id, $amount, $payment_method, $reference_code);
        $stmt->execute();
        $stmt->close();
    }
    if ($enrollment_id > 0) {
        if ($stmt = $conn->prepare("UPDATE enrollments SET status = 'active' WHERE enrollment_id = ?")) {
            $stmt->bind_param("i", $enrollment_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    echo "<div class='bg-green-100 text-green-800 p-4 rounded-md max-w-xl mx-auto mt-10 shadow'>
            ✅ Payment Successful! You are now enrolled in <strong>" . e($course['name']) . "</strong>.
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
            💳 Pay to Enroll in: <?= e($course['name'] ?? 'Course') ?>
        </h2>

        <p class="text-lg text-gray-700 text-center mb-6">
            Amount: <span class="font-bold text-green-600">
                $<?= number_format($amount, 2) ?>
            </span>
        </p>

        <?php if ($amount == 0): ?>
            <div class="bg-blue-50 text-blue-800 p-3 rounded mb-4 text-center">
                This course is free! Click below to enroll instantly.
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Payment Method:</label>
                <select id="payment_method" name="payment_method" required class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring focus:ring-blue-200">
                    <option value="card">Credit/Debit Card</option>
                    <option value="bank_transfer">Bank Transfer (upload slip)</option>
                    <option value="cash">Cash</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <!-- Bank Transfer: Slip upload -->
            <div id="bankSlipBox" class="hidden border rounded-md p-3 bg-amber-50 border-amber-200">
                <label class="block text-sm font-medium text-amber-900 mb-1">Upload Payment Slip (PDF/JPG/PNG/WEBP, max 10MB):</label>
                <input type="hidden" name="MAX_FILE_SIZE" value="10485760" />
                <input type="file" name="payment_slip" accept=".pdf,image/*" class="w-full border border-gray-300 rounded-md px-3 py-2 bg-white" />
                <p class="text-xs text-amber-900 mt-2">
                    Tip: You can submit without a slip, but verification may take longer. Use your bank receipt/screenshot if possible.
                </p>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-md transition duration-200">
                <?= $amount == 0 ? "Enroll for Free" : "Pay & Enroll" ?>
            </button>
        </form>
        <p class="text-xs text-gray-500 mt-3 text-center">Bank transfer submissions are set to pending until verified.</p>
    </div>

    <script>
      const pm = document.getElementById('payment_method');
      const slip = document.getElementById('bankSlipBox');
      function toggleSlip() {
        slip.classList.toggle('hidden', pm.value !== 'bank_transfer');
      }
      pm.addEventListener('change', toggleSlip);
      toggleSlip();
    </script>
</body>
</html>