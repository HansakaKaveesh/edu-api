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
    <div class="mt-5 grid grid-cols-1 lg:grid-cols-5 gap-3 max-w-7xl mx-auto">
      <form method="get" class="bg-white/10 ring-1 ring-white/20 rounded-xl p-3 backdrop-blur grid grid-cols-2 gap-3 lg:col-span-2">
        <div>
          <label class="text-xs text-white/80">Start</label>
          <input type="date" name="start" value="<?= e($start) ?>" class="w-full rounded-md px-3 py-2 text-slate-900">
        </div>
        <div>
          <label class="text-xs text-white/80">End</label>
          <input type="date" name="end" value="<?= e($end) ?>" class="w-full rounded-md px-3 py-2 text-slate-900">
        </div>
        <div class="col-span-2">
          <button class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-white/90 text-emerald-700 font-semibold px-4 py-2 shadow hover:shadow-lg">
            <ion-icon name="options-outline"></ion-icon> Apply
          </button>
        </div>
      </form>

      <div class="bg-white/10 ring-1 ring-white/20 rounded-xl p-3 backdrop-blur text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="cash-outline"></ion-icon> Paid out</div>
        <div class="text-xl font-semibold mt-1">$<?= money($completedSum) ?></div>
      </div>
      <div class="bg-white/10 ring-1 ring-white/20 rounded-xl p-3 backdrop-blur text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="time-outline"></ion-icon> Pending</div>
        <div class="text-xl font-semibold mt-1"><?= (int)$pendingCount ?></div>
      </div>
      <div class="bg-white/10 ring-1 ring-white/20 rounded-xl p-3 backdrop-blur text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="checkmark-circle-outline"></ion-icon> Completed</div>
        <div class="text-xl font-semibold mt-1"><?= (int)$completedCount ?></div>
      </div>
      <div class="bg-white/10 ring-1 ring-white/20 rounded-xl p-3 backdrop-blur text-white">
        <div class="text-xs opacity-90 inline-flex items-center gap-1"><ion-icon name="alert-circle-outline"></ion-icon> Failed</div>
        <div class="text-xl font-semibold mt-1"><?= (int)$failedCount ?></div>
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
  <section class="bg-white p-6 rounded-2xl shadow ring-1 ring-slate-200 mb-10">
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
        <button type="submit" class="bg-emerald-600 text-white px-6 py-2 rounded hover:bg-emerald-700 inline-flex items-center gap-2">
          <ion-icon name="checkmark-outline"></ion-icon> Create Payout
        </button>
      </div>
    </form>
  </section>

  <!-- Recent payments -->
  <section class="bg-white p-6 rounded-2xl shadow ring-1 ring-slate-200">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold inline-flex items-center gap-2">
        <ion-icon name="list-outline"></ion-icon> Recent Teacher Payments
      </h2>
      <a href="export.php?type=teacher&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>" class="text-sm text-emerald-700 hover:underline inline-flex items-center gap-1">
        <ion-icon name="download-outline"></ion-icon> CSV
      </a>
    </div>

    <?php if ($payments && $payments->num_rows>0): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full table-auto text-sm border">
          <thead class="bg-gray-100">
            <tr>
              <th class="px-3 py-2 border">ID</th>
              <th class="px-3 py-2 border">Teacher</th>
              <th class="px-3 py-2 border">Course</th>
              <th class="px-3 py-2 border">Lessons</th>
              <th class="px-3 py-2 border">Rate</th>
              <th class="px-3 py-2 border">Amount</th>
              <th class="px-3 py-2 border">Method</th>
              <th class="px-3 py-2 border">Status</th>
              <th class="px-3 py-2 border">Verified By</th>
              <th class="px-3 py-2 border">Created At</th>
              <th class="px-3 py-2 border">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php while($p = $payments->fetch_assoc()): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border"><?= (int)$p['teacher_payment_id'] ?></td>
                <td class="px-3 py-2 border"><?= e($p['first_name'].' '.$p['last_name']) ?></td>
                <td class="px-3 py-2 border"><?= e($p['course_name'] ?? '—') ?></td>
                <td class="px-3 py-2 border text-center"><?= (int)$p['lesson_count'] ?></td>
                <td class="px-3 py-2 border text-right">$<?= money($p['rate_per_lesson']) ?></td>
                <td class="px-3 py-2 border text-right">$<?= money($p['amount']) ?></td>
                <td class="px-3 py-2 border text-center"><?= e(ucwords(str_replace('_',' ',$p['payment_method']))) ?></td>
                <td class="px-3 py-2 border text-center">
                  <?php
                    $st = $p['payment_status'];
                    $badge = 'bg-gray-100 text-gray-800';
                    if ($st==='pending') $badge='bg-amber-100 text-amber-800';
                    if ($st==='completed') $badge='bg-green-100 text-green-800';
                    if ($st==='failed') $badge='bg-red-100 text-red-800';
                  ?>
                  <span class="px-2 py-1 rounded text-xs <?= $badge ?>"><?= ucfirst($st) ?></span>
                </td>
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
                      <button class="text-green-600 hover:text-green-800 mr-2"><ion-icon name="checkmark-circle-outline"></ion-icon></button>
                    </form>
                    <form method="POST" class="inline-block" onsubmit="return confirm('Mark as failed?');">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="action" value="fail_payment">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['teacher_payment_id'] ?>">
                      <button class="text-amber-600 hover:text-amber-800 mr-2"><ion-icon name="close-circle-outline"></ion-icon></button>
                    </form>
                    <form method="POST" class="inline-block" onsubmit="return confirm('Delete this pending payment?');">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_payment">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['teacher_payment_id'] ?>">
                      <button class="text-red-600 hover:text-red-800"><ion-icon name="trash-outline"></ion-icon></button>
                    </form>
                  <?php else: ?>
                    <span class="text-gray-400 text-xs">No actions</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
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
  if (!tid || !cid) { previewBox.textContent = 'Outstanding: — · Rate: — · Amount: —'; return; }
  try {
    const res = await fetch(`?ajax=calc&teacher_id=${encodeURIComponent(tid)}&course_id=${encodeURIComponent(cid)}`, {credentials:'same-origin'});
    const data = await res.json();
    if (!data.ok) { previewBox.textContent = data.error || 'Error calculating outstanding'; return; }
    const outstanding = Number(data.outstanding || 0);
    const amt = rate > 0 ? (outstanding * rate) : 0;
    previewBox.textContent = `Outstanding: ${outstanding} · Rate: ${rate>0?('$'+rate.toFixed(2)):'—'} · Amount: ${amt>0?('$'+amt.toFixed(2)):'—'}`;
  } catch {
    previewBox.textContent = 'Error calculating outstanding';
  }
}

teacherSelect.addEventListener('change', populateCourses);
courseSelect.addEventListener('change', updatePreview);
rateInput.addEventListener('input', updatePreview);
</script>

</body>
</html>