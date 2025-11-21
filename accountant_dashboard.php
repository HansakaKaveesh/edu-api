<?php
session_start();
include 'db_connect.php';
$conn->set_charset('utf8mb4');

// Only accountant role
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'accountant')) {
    header("Location: login.php");
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$userId = (int)$_SESSION['user_id'];

// Resolve accountant_id for current user (for verified_by)
$accId = 0;
if ($stmt = $conn->prepare("SELECT accountant_id FROM accountants WHERE user_id=? LIMIT 1")) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($accIdVal);
    if ($stmt->fetch()) $accId = (int)$accIdVal;
    $stmt->close();
}

$actionMsg = '';
$actionType = 'info';

// Date range filter (for metrics + list)
$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end'] ?? date('Y-m-t');
$startDt = $start . ' 00:00:00';
$endDt   = $end   . ' 23:59:59';

// Helpers
$ALLOWED_METHODS = ['cash','bank_transfer','card','other'];

function teacherHasCourse(mysqli $conn, int $teacherId, int $courseId): bool {
    $q = $conn->prepare("SELECT 1 FROM teacher_courses WHERE teacher_id=? AND course_id=? LIMIT 1");
    $q->bind_param('ii', $teacherId, $courseId);
    $q->execute();
    $q->store_result();
    $ok = $q->num_rows > 0;
    $q->close();
    return $ok;
}

// AJAX: outstanding calc
if (($_GET['ajax'] ?? '') === 'calc') {
    header('Content-Type: application/json');
    $teacher_id = (int)($_GET['teacher_id'] ?? 0);
    $course_id  = (int)($_GET['course_id'] ?? 0);
    if (!$teacher_id || !$course_id) {
        echo json_encode(['ok'=>false,'error'=>'Missing teacher or course']);
        exit;
    }
    if (!teacherHasCourse($conn, $teacher_id, $course_id)) {
        echo json_encode(['ok'=>false,'error'=>'Teacher is not assigned to this course']);
        exit;
    }
    // lessons (only type='lesson')
    $stmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE course_id=? AND type='lesson'");
    $stmt->bind_param('i', $course_id);
    $stmt->execute();
    $stmt->bind_result($currentLessons);
    $stmt->fetch(); $stmt->close();

    // already issued (pending + completed)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(lesson_count),0) FROM teacher_payments WHERE teacher_id=? AND course_id=? AND payment_status IN ('pending','completed')");
    $stmt->bind_param('ii', $teacher_id, $course_id);
    $stmt->execute();
    $stmt->bind_result($alreadyCount);
    $stmt->fetch(); $stmt->close();

    $outstanding = max(0, (int)$currentLessons - (int)$alreadyCount);
    echo json_encode(['ok'=>true,'currentLessons'=>(int)$currentLessons,'alreadyCount'=>(int)$alreadyCount,'outstanding'=>$outstanding]);
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $action = $_POST['action'] ?? '';

    // Create payout (pending or complete now)
    if ($action === 'pay_teacher') {
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $course_id  = (int)($_POST['course_id'] ?? 0);
        $rate       = (float)($_POST['rate_per_lesson'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? '';
        $complete_now   = isset($_POST['complete_now']) && $_POST['complete_now'] === '1';

        if (!$teacher_id || !$course_id) {
            $actionMsg = "Select a teacher and course."; $actionType='error';
        } elseif (!in_array($payment_method, $ALLOWED_METHODS, true)) {
            $actionMsg = "Invalid payment method."; $actionType='error';
        } elseif ($rate <= 0) {
            $actionMsg = "Rate must be greater than 0."; $actionType='error';
        } elseif (!teacherHasCourse($conn, $teacher_id, $course_id)) {
            $actionMsg = "Teacher is not assigned to this course."; $actionType='error';
        } else {
            // Calculate outstanding
            $stmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE course_id=? AND type='lesson'");
            $stmt->bind_param('i', $course_id);
            $stmt->execute();
            $stmt->bind_result($currentLessons);
            $stmt->fetch(); $stmt->close();

            $stmt = $conn->prepare("SELECT COALESCE(SUM(lesson_count),0) FROM teacher_payments WHERE teacher_id=? AND course_id=? AND payment_status IN ('pending','completed')");
            $stmt->bind_param('ii', $teacher_id, $course_id);
            $stmt->execute();
            $stmt->bind_result($alreadyCount);
            $stmt->fetch(); $stmt->close();

            $outstanding = max(0, (int)$currentLessons - (int)$alreadyCount);
            if ($outstanding <= 0) {
                $actionMsg = "No outstanding lessons to pay for."; $actionType='error';
            } else {
                $amount = $rate * $outstanding;
                $now = date('Y-m-d H:i:s');

                if ($complete_now) {
                    if (!$accId) {
                        $actionMsg = "Cannot complete now — your accountant profile is missing."; $actionType='error';
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO teacher_payments
                            (teacher_id, course_id, lesson_count, rate_per_lesson, amount,
                             payment_status, payment_method, created_by, verified_by, verified_at, created_at)
                            VALUES (?,?,?,?,?, 'completed', ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param(
                            'iiiddsiiss',
                            $teacher_id, $course_id, $outstanding, $rate, $amount,
                            $payment_method, $userId, $accId, $now, $now
                        );
                        if ($stmt->execute()) {
                            $actionMsg = "Payout completed · Lessons: $outstanding · Amount: $".money($amount);
                            $actionType='success';
                        } else {
                            $actionMsg = "DB error: ".e($stmt->error); $actionType='error';
                        }
                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO teacher_payments
                        (teacher_id, course_id, lesson_count, rate_per_lesson, amount,
                         payment_status, payment_method, created_by, created_at)
                        VALUES (?,?,?,?,?, 'pending', ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        'iiiddsis',
                        $teacher_id, $course_id, $outstanding, $rate, $amount,
                        $payment_method, $userId, $now
                    );
                    if ($stmt->execute()) {
                        $actionMsg = "Payout created (pending) · Lessons: $outstanding · Amount: $".money($amount);
                        $actionType='success';
                    } else {
                        $actionMsg = "DB error: ".e($stmt->error); $actionType='error';
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'verify_payment') {
        if (!$accId) { $actionMsg="No accountant profile to verify."; $actionType='error'; }
        else {
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                UPDATE teacher_payments
                SET payment_status='completed', verified_by=?, verified_at=?
                WHERE teacher_payment_id=? AND payment_status='pending'
            ");
            $stmt->bind_param('isi', $accId, $now, $payment_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) { $actionMsg="Payment #$payment_id verified."; $actionType='success'; }
            else { $actionMsg="Unable to verify (maybe already processed)."; $actionType='error'; }
            $stmt->close();
        }
    }

    if ($action === 'fail_payment') {
        if (!$accId) { $actionMsg="No accountant profile to mark failed."; $actionType='error'; }
        else {
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                UPDATE teacher_payments
                SET payment_status='failed', verified_by=?, verified_at=?
                WHERE teacher_payment_id=? AND payment_status='pending'
            ");
            $stmt->bind_param('isi', $accId, $now, $payment_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) { $actionMsg="Payment #$payment_id marked as failed."; $actionType='success'; }
            else { $actionMsg="Unable to update (maybe already processed)."; $actionType='error'; }
            $stmt->close();
        }
    }

    if ($action === 'delete_payment') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM teacher_payments WHERE teacher_payment_id=? AND payment_status='pending'");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) { $actionMsg="Pending payment deleted."; $actionType='success'; }
        else { $actionMsg="Only pending payments can be deleted."; $actionType='error'; }
        $stmt->close();
    }
}

// Teachers
$teachers = $conn->query("
    SELECT DISTINCT t.teacher_id, t.first_name, t.last_name
    FROM teacher_courses tc
    JOIN teachers t ON t.teacher_id = tc.teacher_id
    ORDER BY t.first_name, t.last_name
");

// Courses map
$tcRes = $conn->query("
    SELECT tc.teacher_id, co.course_id, co.name AS course_name
    FROM teacher_courses tc
    JOIN courses co ON co.course_id = tc.course_id
    ORDER BY co.name
");
$coursesMap = [];
if ($tcRes) {
    while($r = $tcRes->fetch_assoc()) {
        $tid = (int)$r['teacher_id'];
        $coursesMap[$tid][] = ['id'=>(int)$r['course_id'], 'name'=>$r['course_name']];
    }
}

// Metrics
$completedSum = 0.0; $pendingCount = 0; $completedCount = 0; $failedCount = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM teacher_payments WHERE payment_status='completed' AND created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $startDt, $endDt); $stmt->execute(); $stmt->bind_result($completedSum); $stmt->fetch(); $stmt->close();
$stmt = $conn->prepare("SELECT COUNT(*) FROM teacher_payments WHERE payment_status='pending' AND created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $startDt, $endDt); $stmt->execute(); $stmt->bind_result($pendingCount); $stmt->fetch(); $stmt->close();
$stmt = $conn->prepare("SELECT COUNT(*) FROM teacher_payments WHERE payment_status='completed' AND created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $startDt, $endDt); $stmt->execute(); $stmt->bind_result($completedCount); $stmt->fetch(); $stmt->close();
$stmt = $conn->prepare("SELECT COUNT(*) FROM teacher_payments WHERE payment_status='failed' AND created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $startDt, $endDt); $stmt->execute(); $stmt->bind_result($failedCount); $stmt->fetch(); $stmt->close();

// Payments (filtered)
$payments = null;
$stmt = $conn->prepare("
    SELECT tp.*, t.first_name, t.last_name, co.name AS course_name,
           CONCAT(a1.first_name,' ',a1.last_name) AS verified_by_name
    FROM teacher_payments tp
    JOIN teachers t ON t.teacher_id = tp.teacher_id
    LEFT JOIN courses co ON co.course_id = tp.course_id
    LEFT JOIN accountants a1 ON a1.accountant_id = tp.verified_by
    WHERE tp.created_at BETWEEN ? AND ?
    ORDER BY tp.created_at DESC
    LIMIT 50
");
$stmt->bind_param('ss', $startDt, $endDt);
$stmt->execute();
$payments = $stmt->get_result();

// Quick range helpers
$today = date('Y-m-d');
$last7 = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');
$m = (int)date('n'); $y = (int)date('Y'); $qMonth = (int)(floor(($m-1)/3)*3 + 1);
$qStart = date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $y, $qMonth)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accountant Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial; }
  .card { background:#fff; border:1px solid rgba(226,232,240,.9); border-radius:1rem; box-shadow:0 10px 20px -10px rgba(15,23,42,.12); }
  .glass { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.25); backdrop-filter: blur(10px); border-radius: .9rem; }
  .chip { display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .7rem; border:1px solid #e0e7ff; border-radius:9999px; background:#eef2ff; color:#4338ca; font-size:.78rem; }
  .chip:hover { background:#e0e7ff; }
  .badge { display:inline-block; padding:.2rem .45rem; border-radius:.5rem; font-size:.72rem; }
  .table-sticky thead th { position: sticky; top: var(--thead-offset, 0px); z-index: 10; backdrop-filter: blur(8px); background: rgba(248,250,252,.9); }
  .btn-primary { display:inline-flex; align-items:center; gap:.5rem; background:#059669; color:#fff; padding:.55rem .9rem; border-radius:.6rem; }
  .btn-primary:hover { filter:brightness(1.05); }
  .btn-ghost { display:inline-flex; align-items:center; gap:.45rem; padding:.5rem .8rem; border-radius:.6rem; border:1px solid #cbd5e1; background:#fff; color:#1e293b; }
  .btn-ghost:hover { background:#f8fafc; }
  .btn-disabled { opacity:.6; pointer-events:none; }
  .countup { transition: color .2s ease; }
</style>
</head>
<body class="bg-gray-50 min-h-screen">

<?php include 'components/navbar.php'; ?>

<!-- Hero -->
<section class="relative overflow-hidden rounded-3xl shadow max-w-7xl mx-auto mt-28 mb-8">
  <div class="absolute inset-0 bg-gradient-to-br from-emerald-700 via-green-600 to-lime-500"></div>
  <div class="relative z-10 text-white p-6 sm:p-8">
    <div class="flex items-center justify-between gap-3">
      <div class="text-xs sm:text-sm opacity-90">Accounting · <?= e(date('M j, Y')) ?></div>
      <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/20 px-3 py-1 rounded-full text-xs">
        <ion-icon name="wallet-outline" class="text-white"></ion-icon> Accountant Dashboard
      </span>
    </div>
    <div class="mt-3 text-center">
      <h1 class="text-2xl sm:text-3xl font-extrabold">Welcome back, <?= e($_SESSION['full_name'] ?? 'Accountant') ?> 👋</h1>
      <p class="text-white/90 text-sm sm:text-base">Process teacher payouts, verify transactions, and keep books tidy.</p>
    </div>

    <!-- Filters + Metrics -->
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-5 gap-3 max-w-7xl mx-auto">
      <form method="get" class="glass p-3 grid grid-cols-2 gap-3 lg:col-span-2">
        <div>
          <label class="text-xs text-white/80">Start</label>
          <input type="date" name="start" value="<?= e($start) ?>" class="w-full rounded-md px-3 py-2 text-slate-900">
        </div>
        <div>
          <label class="text-xs text-white/80">End</label>
          <input type="date" name="end" value="<?= e($end) ?>" class="w-full rounded-md px-3 py-2 text-slate-900">
        </div>
        <div class="col-span-2 flex items-center gap-2">
          <button class="btn-ghost bg-white/90 text-emerald-700"><ion-icon name="options-outline"></ion-icon> Apply</button>
          <div class="ml-auto flex flex-wrap gap-2">
            <a class="chip" href="?start=<?= e($today) ?>&end=<?= e($today) ?>">Today</a>
            <a class="chip" href="?start=<?= e($last7) ?>&end=<?= e($today) ?>">Last 7 days</a>
            <a class="chip" href="?start=<?= e($monthStart) ?>&end=<?= e($today) ?>">This month</a>
            <a class="chip" href="?start=<?= e($qStart) ?>&end=<?= e($today) ?>">This quarter</a>
          </div>
        </div>
      </form>

      <div class="glass p-3 text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="cash-outline"></ion-icon> Paid out</div>
        <div class="text-2xl font-extrabold mt-1"><span class="countup" data-target="<?= (float)$completedSum ?>" data-decimals="2" data-prefix="$">0</span></div>
      </div>
      <div class="glass p-3 text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="time-outline"></ion-icon> Pending</div>
        <div class="text-2xl font-extrabold mt-1"><span class="countup" data-target="<?= (int)$pendingCount ?>">0</span></div>
      </div>
      <div class="glass p-3 text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="checkmark-circle-outline"></ion-icon> Completed</div>
        <div class="text-2xl font-extrabold mt-1"><span class="countup" data-target="<?= (int)$completedCount ?>">0</span></div>
      </div>
      <div class="glass p-3 text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="alert-circle-outline"></ion-icon> Failed</div>
        <div class="text-2xl font-extrabold mt-1"><span class="countup" data-target="<?= (int)$failedCount ?>">0</span></div>
      </div>
    </div>
  </div>
</section>

<div class="max-w-7xl mx-auto px-6">
  <?php if ($actionMsg): ?>
    <div class="mb-4 px-4 py-3 rounded <?= $actionType==='success'?'bg-green-100 text-green-800':($actionType==='error'?'bg-red-100 text-red-800':'bg-blue-100 text-blue-800') ?>">
      <?= e($actionMsg) ?>
    </div>
  <?php endif; ?>

  <?php if (!$accId): ?>
    <div class="mb-4 px-4 py-3 rounded bg-amber-50 text-amber-800">
      Heads up: Your user is not linked to an accountant profile. Create an entry in the accountants table for user_id=<?= (int)$userId ?> to enable verification.
    </div>
  <?php endif; ?>

  <!-- Create payout -->
  <section class="card p-6 mb-10">
    <h2 class="text-xl font-semibold mb-4 inline-flex items-center gap-2">
      <ion-icon name="create-outline"></ion-icon> Create Teacher Payout
    </h2>
    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="pay_teacher">

      <div>
        <label class="block font-medium mb-1">Teacher</label>
        <select name="teacher_id" id="teacherSelect" required class="w-full border px-3 py-2 rounded">
          <option value="">-- Select Teacher --</option>
          <?php while($t = $teachers->fetch_assoc()): ?>
            <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['first_name'].' '.$t['last_name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div>
        <label class="block font-medium mb-1">Course</label>
        <select name="course_id" id="courseSelect" required class="w-full border px-3 py-2 rounded">
          <option value="">-- Select Course --</option>
        </select>
      </div>

      <div>
        <label class="block font-medium mb-1">Rate per Lesson</label>
        <input type="number" min="0.01" step="0.01" name="rate_per_lesson" id="rateInput" required class="w-full border px-3 py-2 rounded">
      </div>

      <div>
        <label class="block font-medium mb-1">Payment Method</label>
        <select name="payment_method" required class="w-full border px-3 py-2 rounded">
          <option value="">-- Select Method --</option>
          <option value="bank_transfer">Bank Transfer</option>
          <option value="card">Card</option>
          <option value="cash">Cash</option>
          <option value="other">Other</option>
        </select>
      </div>

      <div class="md:col-span-2 flex items-center gap-2">
        <input type="checkbox" id="completeNow" name="complete_now" value="1" class="w-4 h-4">
        <label for="completeNow" class="text-sm text-gray-700">Mark as completed now (otherwise it will be pending for verification)</label>
      </div>

      <div class="md:col-span-2 flex items-center justify-between bg-gray-50 border rounded p-3">
        <div id="previewBox" class="text-sm text-gray-700">
          Outstanding: — · Rate: — · Amount: —
        </div>
        <button id="createBtn" type="submit" class="btn-primary btn-disabled">
          <ion-icon name="checkmark-outline"></ion-icon> Create Payout
        </button>
      </div>
    </form>
  </section>

  <!-- Recent payments -->
  <section class="card p-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
      <h2 class="text-xl font-semibold inline-flex items-center gap-2">
        <ion-icon name="list-outline"></ion-icon> Recent Teacher Payments
      </h2>
      <div class="flex items-center gap-2">
        <div class="relative">
          <ion-icon name="search-outline" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400"></ion-icon>
          <input id="searchPayments" type="text" placeholder="Search teacher, course, or method"
                 class="pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200" />
        </div>
        <select id="statusFilter" class="py-2 px-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200">
          <option value="">All status</option>
          <option value="pending">Pending</option>
          <option value="completed">Completed</option>
          <option value="failed">Failed</option>
        </select>
        <select id="methodFilter" class="py-2 px-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200">
          <option value="">All methods</option>
          <option value="bank_transfer">Bank Transfer</option>
          <option value="card">Card</option>
          <option value="cash">Cash</option>
          <option value="other">Other</option>
        </select>
        <a href="export.php?type=teacher&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>" class="btn-ghost">
          <ion-icon name="download-outline"></ion-icon> CSV
        </a>
      </div>
    </div>

    <?php if ($payments && $payments->num_rows>0): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full table-auto text-sm border table-sticky">
          <thead class="bg-slate-100">
            <tr>
              <th class="px-3 py-2 border text-left">ID</th>
              <th class="px-3 py-2 border text-left">Teacher</th>
              <th class="px-3 py-2 border text-left">Course</th>
              <th class="px-3 py-2 border text-center">Lessons</th>
              <th class="px-3 py-2 border text-right">Rate</th>
              <th class="px-3 py-2 border text-right">Amount</th>
              <th class="px-3 py-2 border text-center">Method</th>
              <th class="px-3 py-2 border text-center">Status</th>
              <th class="px-3 py-2 border text-center">Verified By</th>
              <th class="px-3 py-2 border text-left">Created At</th>
              <th class="px-3 py-2 border text-center">Actions</th>
            </tr>
          </thead>
          <tbody id="paymentsBody">
            <?php while($p = $payments->fetch_assoc()): 
              $st = $p['payment_status'];
              $badge = 'bg-gray-100 text-gray-800';
              if ($st==='pending') $badge='bg-amber-100 text-amber-800';
              if ($st==='completed') $badge='bg-green-100 text-green-800';
              if ($st==='failed') $badge='bg-red-100 text-red-800';
              $teacherFull = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
              $method = $p['payment_method'] ?? '';
              $courseName = $p['course_name'] ?? '—';
            ?>
              <tr class="hover:bg-gray-50"
                  data-status="<?= e(strtolower($st)) ?>"
                  data-method="<?= e(strtolower($method)) ?>"
                  data-text="<?= e(strtolower($teacherFull.' '.$courseName.' '.$method)) ?>">
                <td class="px-3 py-2 border"><?= (int)$p['teacher_payment_id'] ?></td>
                <td class="px-3 py-2 border"><?= e($teacherFull) ?></td>
                <td class="px-3 py-2 border"><?= e($courseName) ?></td>
                <td class="px-3 py-2 border text-center"><?= (int)$p['lesson_count'] ?></td>
                <td class="px-3 py-2 border text-right">$<?= money($p['rate_per_lesson']) ?></td>
                <td class="px-3 py-2 border text-right">$<?= money($p['amount']) ?></td>
                <td class="px-3 py-2 border text-center"><?= e(ucwords(str_replace('_',' ',$method))) ?></td>
                <td class="px-3 py-2 border text-center"><span class="badge <?= $badge ?>"><?= ucfirst($st) ?></span></td>
                <td class="px-3 py-2 border text-center">
                  <?= $p['verified_by'] ? e($p['verified_by_name']).' at '.date("Y-m-d H:i", strtotime($p['verified_at'])) : '—' ?>
                </td>
                <td class="px-3 py-2 border"><?= date("Y-m-d H:i", strtotime($p['created_at'])) ?></td>
                <td class="px-3 py-2 border text-center">
                  <?php if ($p['payment_status']==='pending'): ?>
                    <form method="POST" class="inline-block" onsubmit="return confirm('Verify this payment?');">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="action" value="verify_payment">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['teacher_payment_id'] ?>">
                      <button class="text-green-600 hover:text-green-800 mr-2" title="Verify"><ion-icon name="checkmark-circle-outline"></ion-icon></button>
                    </form>
                    <form method="POST" class="inline-block" onsubmit="return confirm('Mark as failed?');">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="action" value="fail_payment">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['teacher_payment_id'] ?>">
                      <button class="text-amber-600 hover:text-amber-800 mr-2" title="Fail"><ion-icon name="close-circle-outline"></ion-icon></button>
                    </form>
                    <form method="POST" class="inline-block" onsubmit="return confirm('Delete this pending payment?');">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_payment">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['teacher_payment_id'] ?>">
                      <button class="text-red-600 hover:text-red-800" title="Delete"><ion-icon name="trash-outline"></ion-icon></button>
                    </form>
                  <?php else: ?>
                    <span class="text-gray-400 text-xs">No actions</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
            <tr id="noMatchRow" class="hidden"><td colspan="11" class="px-3 py-6 text-center text-slate-500">No payments match your filters.</td></tr>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-gray-500">No payments found for the selected date range.</p>
    <?php endif; ?>
  </section>
</div>

<?php include 'components/footer.php'; ?>

<script>
const COURSES_MAP = <?= json_encode($coursesMap, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

const teacherSelect = document.getElementById('teacherSelect');
const courseSelect  = document.getElementById('courseSelect');
const rateInput     = document.getElementById('rateInput');
const previewBox    = document.getElementById('previewBox');
const createBtn     = document.getElementById('createBtn');

function populateCourses() {
  const tid = teacherSelect.value || '';
  const courses = COURSES_MAP[tid] || [];
  courseSelect.innerHTML = '<option value="">-- Select Course --</option>';
  for (const c of courses) {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    courseSelect.appendChild(opt);
  }
  updatePreview();
}

async function updatePreview() {
  const tid = teacherSelect.value;
  const cid = courseSelect.value;
  const rate = parseFloat(rateInput.value || '0');
  createBtn.classList.add('btn-disabled');
  if (!tid || !cid) { previewBox.textContent = 'Outstanding: — · Rate: — · Amount: —'; return; }
  try {
    const res = await fetch(`?ajax=calc&teacher_id=${encodeURIComponent(tid)}&course_id=${encodeURIComponent(cid)}`, {credentials:'same-origin'});
    const data = await res.json();
    if (!data.ok) { previewBox.textContent = (data.error || 'Error calculating outstanding'); return; }
    const outstanding = Number(data.outstanding || 0);
    const amt = rate > 0 ? (outstanding * rate) : 0;
    previewBox.textContent = `Outstanding: ${outstanding} · Rate: ${rate>0?('$'+rate.toFixed(2)):'—'} · Amount: ${amt>0?('$'+amt.toFixed(2)):'—'}`;
    if (outstanding > 0 && rate > 0) createBtn.classList.remove('btn-disabled');
  } catch {
    previewBox.textContent = 'Error calculating outstanding';
  }
}

teacherSelect.addEventListener('change', populateCourses);
courseSelect.addEventListener('change', updatePreview);
rateInput.addEventListener('input', updatePreview);

// Count-up stats
(function() {
  function animate(el){
    const target = parseFloat(el.dataset.target || '0');
    const decimals = parseInt(el.dataset.decimals || '0', 10);
    const prefix = el.dataset.prefix || '';
    const duration = 1200;
    const start = performance.now();
    const from = 0;
    function tick(t){
      const p = Math.min(1, (t - start) / duration);
      const val = from + (target - from) * p;
      el.textContent = prefix + val.toLocaleString(undefined, {minimumFractionDigits:decimals, maximumFractionDigits:decimals});
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
  document.querySelectorAll('.countup').forEach(animate);
})();

// Sticky table thead offset (if you have sticky nav, adjust if needed)
document.documentElement.style.setProperty('--thead-offset', '0px');

// Client-side filter for payments table
(function() {
  const tbody = document.getElementById('paymentsBody');
  const rows = Array.from(tbody?.querySelectorAll('tr') || []).filter(r => r.id !== 'noMatchRow');
  const search = document.getElementById('searchPayments');
  const status = document.getElementById('statusFilter');
  const method = document.getElementById('methodFilter');
  const noRow = document.getElementById('noMatchRow');
  if (!rows.length) return;

  function apply() {
    const q = (search.value || '').trim().toLowerCase();
    const s = (status.value || '').trim();
    const m = (method.value || '').trim();
    let shown = 0;
    rows.forEach(r => {
      const mt = r.dataset.text || '';
      const ms = r.dataset.status || '';
      const mm = r.dataset.method || '';
      const ok = (!q || mt.includes(q)) && (!s || s === ms) && (!m || m === mm);
      r.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    if (noRow) noRow.classList.toggle('hidden', shown !== 0);
  }
  search?.addEventListener('input', apply);
  status?.addEventListener('change', apply);
  method?.addEventListener('change', apply);
  apply();
})();
</script>

</body>
</html>