<?php
session_start();
include 'db_connect.php';

// Allow only admin users
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function columnExists(mysqli $conn, string $table, string $column): bool {
    $col = $conn->query("SHOW COLUMNS FROM `$table` LIKE '{$conn->real_escape_string($column)}'");
    $exists = $col && $col->num_rows > 0;
    if ($col) $col->free();
    return $exists;
}

/* CSRF token (for inline actions) */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* Handle inline payment status updates from the Pending Payments card */
$actionMsg = '';
$actionType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment_status') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    $new_status = strtolower(trim($_POST['new_status'] ?? ''));

    $allowed = ['pending', 'completed', 'failed'];
    if ($payment_id > 0 && in_array($new_status, $allowed, true)) {
        if ($stmt = $conn->prepare("UPDATE student_payments SET payment_status = ? WHERE payment_id = ?")) {
            $stmt->bind_param("si", $new_status, $payment_id);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                $actionMsg = "Payment #{$payment_id} marked as " . ucfirst($new_status) . ".";
                $actionType = 'success';
            } else {
                $actionMsg = "Failed to update payment #{$payment_id}.";
                $actionType = 'error';
            }
        } else {
            $actionMsg = "Failed to prepare update.";
            $actionType = 'error';
        }
    } else {
        $actionMsg = "Bad request.";
        $actionType = 'error';
    }
}

/* Handle announcement creation (no CSRF here by design; do not add 'action' field) */
$announce_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['title'], $_POST['message'], $_POST['audience'])
    && (!isset($_POST['action']))) {
    $title    = $conn->real_escape_string(trim($_POST['title']));
    $message  = $conn->real_escape_string(trim($_POST['message']));
    $audience = $conn->real_escape_string($_POST['audience']);
    if ($title && $message && in_array($audience, ['students', 'teachers', 'all'], true)) {
        $conn->query("INSERT INTO announcements (title, message, audience) VALUES ('$title', '$message', '$audience')");
        $announce_message = '<div class="relative rounded-xl px-4 py-3 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200"><ion-icon name="checkmark-circle-outline" class="mr-1 align-middle"></ion-icon> Announcement posted!</div>';
    } else {
        $announce_message = '<div class="relative rounded-xl px-4 py-3 bg-red-50 text-red-700 ring-1 ring-red-200"><ion-icon name="alert-circle-outline" class="mr-1 align-middle"></ion-icon> Please fill all fields.</div>';
    }
}

/* Fetch statistics */
$total_students    = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'] ?? 0;
$total_teachers    = $conn->query("SELECT COUNT(*) AS c FROM teachers")->fetch_assoc()['c'] ?? 0;
$total_courses     = $conn->query("SELECT COUNT(*) AS c FROM courses")->fetch_assoc()['c'] ?? 0;
$total_enrollments = $conn->query("SELECT COUNT(*) AS c FROM enrollments")->fetch_assoc()['c'] ?? 0;
$total_revenue     = $conn->query("SELECT IFNULL(SUM(amount), 0) AS s FROM student_payments WHERE payment_status = 'completed'")->fetch_assoc()['s'] ?? 0;

/* Pending payments (count + latest) */
$pending_count = $conn->query("SELECT COUNT(*) AS c FROM student_payments WHERE payment_status='pending'")->fetch_assoc()['c'] ?? 0;
$hasSlipCol = columnExists($conn, 'student_payments', 'slip_url');

$sqlPending = "
  SELECT sp.payment_id, sp.amount, sp.payment_method, sp.reference_code, sp.paid_at,
         s.first_name, s.last_name" . ($hasSlipCol ? ", sp.slip_url" : "") . "
  FROM student_payments sp
  JOIN students s ON s.student_id = sp.student_id
  WHERE sp.payment_status = 'pending'
  ORDER BY sp.paid_at DESC, sp.payment_id DESC
  LIMIT 12
";
$pending_rows = $conn->query($sqlPending);

/* Fetch announcements */
$announcements = $conn->query("SELECT id, title, message, audience, created_at FROM announcements ORDER BY created_at DESC LIMIT 10");

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Ionicons -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial;
      min-height: 100vh;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background:
        radial-gradient(circle at 0% 0%, rgba(129,140,248,0.20) 0, transparent 55%),
        radial-gradient(circle at 100% 40%, rgba(37,99,235,0.16) 0, transparent 60%),
        radial-gradient(circle at 30% 100%, rgba(45,212,191,0.16) 0, transparent 60%);
      pointer-events: none;
      z-index: -1;
    }
    @keyframes wave { 0%, 60%, 100% { transform: rotate(0deg); } 30% { transform: rotate(15deg); } 50% { transform: rotate(-10deg); } }
    .animate-wave { display: inline-block; animation: wave 2s infinite; transform-origin: 70% 70%; }

    .card {
      background: rgba(255,255,255,.96);
      border-radius: 1.5rem;
      border: 1px solid rgba(226,232,240,.95);
      box-shadow: 0 20px 40px -22px rgba(15,23,42,.6);
      backdrop-filter: blur(16px);
    }
    .stat-card {
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
      border-radius: 1.25rem;
    }
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 16px 30px -22px rgba(15,23,42,.8);
      border-color: #c7d2fe;
    }
    .badge-chip {
      font-size: 11px;
      padding: 2px 9px;
      border-radius: 999px;
    }
    .btn-plain {
      display:inline-flex; align-items:center; gap:.4rem;
      padding:.45rem .8rem; border-radius:.7rem;
      border:1px solid #dbeafe; background:#f8fafc; color:#1d4ed8;
      font-size:.8rem; font-weight:500;
      transition: background .18s, box-shadow .18s, transform .1s, border-color .18s;
    }
    .btn-plain:hover {
      background:#eff6ff; border-color:#bfdbfe;
      box-shadow:0 8px 18px -12px rgba(59,130,246,.55);
      transform: translateY(-1px);
    }
    .table-sticky th {
      position: sticky;
      top: var(--thead-offset, 0px);
      z-index: 10;
      backdrop-filter: blur(10px);
    }
    .clamp-3 {
      display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen flex flex-col antialiased">

<?php include 'components/navbar.php'; ?>

<!-- Toast -->
<div id="toast" class="fixed top-24 right-6 z-50 hidden">
  <div id="toastBox" class="inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm shadow-xl bg-slate-900/90 text-white">
    <ion-icon id="toastIcon" name="checkmark-circle-outline"></ion-icon>
    <span id="toastText">Done</span>
  </div>
</div>

<main class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-24 flex-grow space-y-8">
  <!-- Hero -->
  <section class="relative overflow-hidden rounded-3xl shadow-2xl bg-gradient-to-br from-indigo-950 via-slate-900 to-sky-900 text-white">
    <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-indigo-500/40 blur-3xl"></div>
    <div class="absolute -right-32 top-6 h-64 w-64 rounded-full bg-sky-400/40 blur-3xl"></div>

    <div class="relative z-10 px-6 py-7 sm:px-8 sm:py-8 flex flex-col gap-5">
      <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/20">
            <ion-icon name="grid-outline" class="text-sm"></ion-icon>
          </span>
          <span>Admin · Dashboard</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <ion-icon name="shield-checkmark-outline" class="text-sm"></ion-icon>
          <span>Admin access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        <div class="space-y-3 max-w-2xl">
          <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold tracking-tight">
            Welcome back, Admin <span class="animate-wave">👋</span>
          </h1>
          <p class="text-sm sm:text-base text-sky-100/90">
            Monitor students, teachers, courses, revenue and payments from a single, clear dashboard.
          </p>
          <div class="flex flex-wrap items-center gap-2 text-[11px] sm:text-xs text-sky-100/90">
            <span class="inline-flex items-center gap-1 rounded-full bg-black/25 px-3 py-1 backdrop-blur">
              <ion-icon name="checkmark-circle-outline" class="text-emerald-300"></ion-icon>
              CSRF protection on sensitive actions
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-black/25 px-3 py-1 backdrop-blur">
              <ion-icon name="cloud-download-outline" class="text-sky-300"></ion-icon>
              Quick access to reports & tools
            </span>
          </div>
        </div>
        <div class="rounded-2xl bg-black/10 ring-1 ring-white/20 px-4 py-3 sm:px-5 sm:py-4 backdrop-blur flex flex-col gap-1 text-xs sm:text-sm text-sky-100/95">
          <div class="font-semibold tracking-wide uppercase text-[10px] text-sky-200">
            Snapshot
          </div>
          <div class="flex items-center justify-between gap-4">
            <span class="inline-flex items-center gap-2">
              <ion-icon name="time-outline" class="text-sky-200"></ion-icon>
              <span>Pending payments</span>
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100/90 px-2.5 py-0.5 text-xs font-semibold text-amber-900">
              <?= (int)$pending_count ?>
            </span>
          </div>
          <div class="flex items-center justify-between gap-4">
            <span class="inline-flex items-center gap-2">
              <ion-icon name="cash-outline" class="text-emerald-200"></ion-icon>
              <span>Lifetime revenue</span>
            </span>
            <span class="font-semibold text-emerald-200">
              $<?= number_format((float)$total_revenue, 2) ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Grid: sidebar + main -->
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <?php
      $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
      $createAnnouncementLink = '#create-announcement';
      include 'components/admin_tools_sidebar.php';
    ?>

    <!-- Main column -->
    <section class="lg:col-span-9 space-y-8">

      <!-- Quick stats -->
      <section class="card p-5 sm:p-7">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-3">
          <div>
            <h2 class="text-lg sm:text-xl font-semibold tracking-tight text-slate-900 flex items-center gap-2">
              <ion-icon name="analytics-outline" class="text-indigo-600"></ion-icon>
              Platform Overview
            </h2>
            <p class="text-xs sm:text-sm text-slate-500">
              Key numbers at a glance: learners, staff, courses, enrollments and revenue.
            </p>
          </div>
        </div>

        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
          <div class="stat-card bg-white/90 ring-1 ring-slate-200 p-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-slate-500 uppercase tracking-wide">Students</span>
              <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-blue-50 text-blue-600">
                <ion-icon name="people-outline"></ion-icon>
              </span>
            </div>
            <div class="text-3xl font-extrabold mt-1.5 text-slate-900">
              <span class="countup" data-target="<?= (int)$total_students ?>">0</span>
            </div>
          </div>

          <div class="stat-card bg-white/90 ring-1 ring-slate-200 p-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-slate-500 uppercase tracking-wide">Teachers</span>
              <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-indigo-50 text-indigo-600">
                <ion-icon name="easel-outline"></ion-icon>
              </span>
            </div>
            <div class="text-3xl font-extrabold mt-1.5 text-slate-900">
              <span class="countup" data-target="<?= (int)$total_teachers ?>">0</span>
            </div>
          </div>

          <div class="stat-card bg-white/90 ring-1 ring-slate-200 p-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-slate-500 uppercase tracking-wide">Courses</span>
              <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-pink-50 text-pink-600">
                <ion-icon name="library-outline"></ion-icon>
              </span>
            </div>
            <div class="text-3xl font-extrabold mt-1.5 text-slate-900">
              <span class="countup" data-target="<?= (int)$total_courses ?>">0</span>
            </div>
          </div>

          <div class="stat-card bg-white/90 ring-1 ring-slate-200 p-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-slate-500 uppercase tracking-wide">Enrollments</span>
              <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-violet-50 text-violet-600">
                <ion-icon name="person-add-outline"></ion-icon>
              </span>
            </div>
            <div class="text-3xl font-extrabold mt-1.5 text-slate-900">
              <span class="countup" data-target="<?= (int)$total_enrollments ?>">0</span>
            </div>
          </div>

          <div class="stat-card bg-white/90 ring-1 ring-slate-200 p-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-slate-500 uppercase tracking-wide">Revenue</span>
              <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-emerald-50 text-emerald-600">
                <ion-icon name="cash-outline"></ion-icon>
              </span>
            </div>
            <div class="text-3xl font-extrabold mt-1.5 text-emerald-700">
              <span class="countup" data-target="<?= (float)$total_revenue ?>" data-decimals="2" data-prefix="$">0</span>
            </div>
            <div class="mt-2 badge-chip text-amber-800 bg-amber-50 ring-1 ring-amber-200 inline-flex items-center gap-1">
              <ion-icon name="time-outline"></ion-icon>
              Pending: <b class="ml-1"><?= (int)$pending_count ?></b>
            </div>
          </div>
        </div>
      </section>

      <!-- Pending Payments -->
      <section class="card p-5 sm:p-7">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-4">
          <div>
            <h3 class="text-lg sm:text-xl font-semibold text-gray-900 inline-flex items-center gap-2">
              <ion-icon name="hourglass-outline" class="text-amber-600"></ion-icon>
              Pending Payments
            </h3>
            <p class="text-xs sm:text-sm text-slate-500">
              Quickly review, verify and activate or fail pending payments.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
              <ion-icon name="search-outline" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></ion-icon>
              <input id="paymentSearch" type="text" placeholder="Search name or reference"
                     class="pl-8 pr-3 py-2 rounded-lg border border-slate-300 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
            </div>
            <select id="paymentMethodFilter"
                    class="py-2 px-2.5 rounded-lg border border-slate-300 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All methods</option>
            </select>
            <button id="paymentClearFilters" class="btn-plain text-sm" type="button">
              <ion-icon name="close-circle-outline"></ion-icon> Clear
            </button>
            <a href="payment_reports.php"
               class="ml-auto inline-flex items-center gap-1 text-blue-700 text-sm hover:underline">
              <ion-icon name="open-outline"></ion-icon> Payment reports
            </a>
          </div>
        </div>

        <?php if (!empty($actionMsg)): ?>
          <div class="mb-4 rounded-lg px-4 py-3 border
                      <?= $actionType === 'success'
                          ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                          : ($actionType === 'error'
                              ? 'bg-rose-50 text-rose-800 border-rose-200'
                              : 'bg-blue-50 text-blue-800 border-blue-200') ?>">
            <ion-icon name="<?= $actionType === 'success'
                                ? 'checkmark-circle-outline'
                                : ($actionType === 'error'
                                    ? 'alert-circle-outline'
                                    : 'information-circle-outline') ?>"
                      class="mr-1 align-middle"></ion-icon>
            <?= e($actionMsg) ?>
          </div>
        <?php endif; ?>

        <?php if ($pending_count > 0 && $pending_rows && $pending_rows->num_rows > 0): ?>
          <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full table-auto text-sm">
              <thead id="pendingHead" class="table-sticky bg-slate-50/90 text-gray-700 border-b">
                <tr class="text-xs font-semibold uppercase tracking-wide">
                  <th class="px-3 py-2 text-left">ID</th>
                  <th class="px-3 py-2 text-left">Student</th>
                  <th class="px-3 py-2 text-left">Amount</th>
                  <th class="px-3 py-2 text-left">Method</th>
                  <th class="px-3 py-2 text-left">Reference</th>
                  <th class="px-3 py-2 text-left">Date</th>
                  <th class="px-3 py-2 text-left">Slip</th>
                  <th class="px-3 py-2 text-left">Actions</th>
                </tr>
              </thead>
              <tbody id="pendingBody" class="divide-y divide-slate-100 bg-white">
                <?php while ($p = $pending_rows->fetch_assoc()):
                  $pid  = (int)$p['payment_id'];
                  $stud = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
                  $amt  = number_format((float)$p['amount'], 2);
                  $method = $p['payment_method'] ?? '';
                  $ref  = $p['reference_code'] ?? '';
                  $iso  = $p['paid_at'] ? date("c", strtotime($p['paid_at'])) : '';
                  $dt   = $p['paid_at'] ? date("Y-m-d H:i", strtotime($p['paid_at'])) : '—';
                  $slip = $hasSlipCol ? ($p['slip_url'] ?? '') : '';
                  $methodChip = '<span class="badge-chip ring-1 ring-gray-200 bg-gray-50 text-gray-700 inline-flex items-center gap-1"><ion-icon name="card-outline"></ion-icon>'.e($method).'</span>';
                  if (stripos($method, 'bank') !== false) $methodChip = '<span class="badge-chip ring-1 ring-indigo-200 bg-indigo-50 text-indigo-700 inline-flex items-center gap-1"><ion-icon name="business-outline"></ion-icon>'.e($method).'</span>';
                  if (stripos($method, 'cash') !== false) $methodChip = '<span class="badge-chip ring-1 ring-amber-200 bg-amber-50 text-amber-700 inline-flex items-center gap-1"><ion-icon name="cash-outline"></ion-icon>'.e($method).'</span>';
                ?>
                  <tr class="align-top hover:bg-slate-50/70 transition-colors"
                      data-student="<?= e(strtolower($stud)) ?>"
                      data-ref="<?= e(strtolower($ref)) ?>"
                      data-method="<?= e(strtolower($method)) ?>">
                    <td class="px-3 py-2 font-medium text-slate-800"><?= $pid ?></td>
                    <td class="px-3 py-2 text-slate-800"><?= e($stud) ?></td>
                    <td class="px-3 py-2 text-emerald-700 font-semibold">$<?= $amt ?></td>
                    <td class="px-3 py-2"><?= $methodChip ?></td>
                    <td class="px-3 py-2">
                      <?php if ($ref): ?>
                        <div class="inline-flex items-center gap-2">
                          <code class="bg-slate-50 px-2 py-0.5 rounded text-xs border border-slate-200"><?= e($ref) ?></code>
                          <button type="button" class="text-blue-700 text-xs hover:underline" onclick="copyRef('<?= e($ref) ?>')" title="Copy reference">
                            <ion-icon name="copy-outline"></ion-icon> Copy
                          </button>
                        </div>
                      <?php else: ?>
                        <span class="text-xs text-gray-500">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center gap-1 text-slate-700 text-xs sm:text-sm">
                        <ion-icon name="time-outline" class="text-slate-500"></ion-icon>
                        <?php if ($iso): ?>
                          <time datetime="<?= e($iso) ?>" title="<?= e($dt) ?>" class="rel-time"><?= e($dt) ?></time>
                        <?php else: ?>
                          <span class="text-xs text-gray-500">—</span>
                        <?php endif; ?>
                      </span>
                    </td>
                    <td class="px-3 py-2">
                      <?php if ($slip): ?>
                        <a href="<?= e($slip) ?>" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1 text-indigo-700 hover:underline text-xs sm:text-sm">
                          <ion-icon name="document-attach-outline"></ion-icon> View
                        </a>
                      <?php else: ?>
                        <span class="text-xs text-gray-500">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                      <div class="flex flex-wrap gap-2">
                        <form method="POST" onsubmit="return confirm('Activate payment #<?= $pid ?>?');">
                          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                          <input type="hidden" name="action" value="update_payment_status">
                          <input type="hidden" name="payment_id" value="<?= $pid ?>">
                          <input type="hidden" name="new_status" value="completed">
                          <button class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-emerald-600 text-white text-xs hover:bg-emerald-700 shadow-sm">
                            <ion-icon name="checkmark-circle-outline"></ion-icon> Activate
                          </button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Mark payment #<?= $pid ?> as Failed?');">
                          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                          <input type="hidden" name="action" value="update_payment_status">
                          <input type="hidden" name="payment_id" value="<?= $pid ?>">
                          <input type="hidden" name="new_status" value="failed">
                          <button class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-rose-600 text-white text-xs hover:bg-rose-700 shadow-sm">
                            <ion-icon name="close-circle-outline"></ion-icon> Fail
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
                <tr id="noPaymentMatch" class="hidden">
                  <td colspan="8" class="px-3 py-6 text-center text-slate-500 text-sm">No payments match your filter.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p id="pendingCountText" class="text-xs text-gray-500 mt-2">
            Showing latest <?= (int)($pending_rows->num_rows) ?> pending payments.
          </p>
        <?php else: ?>
          <div class="text-gray-600 text-center py-10">
            <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
              <ion-icon name="checkmark-done-outline" class="text-emerald-600 text-xl"></ion-icon>
            </div>
            No pending payments.
          </div>
        <?php endif; ?>
      </section>

      <!-- Announcements & Create form in a responsive grid -->
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <!-- Recent Announcements -->
        <section class="card p-5 sm:p-7">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-lg sm:text-xl font-semibold text-gray-900 inline-flex items-center gap-2">
                <ion-icon name="megaphone-outline" class="text-rose-600"></ion-icon>
                Recent Announcements
              </h3>
              <p class="text-xs sm:text-sm text-slate-500">
                Latest messages displayed to students and teachers.
              </p>
            </div>
            <a href="#create-announcement"
               class="inline-flex items-center gap-1 text-blue-700 text-sm hover:underline">
              <ion-icon name="add-circle-outline"></ion-icon> New
            </a>
          </div>

          <?php if ($announcements && $announcements->num_rows > 0): ?>
            <ul class="space-y-4">
              <?php while ($a = $announcements->fetch_assoc()):
                $aud = ucfirst($a['audience']);
                $badge = $a['audience'] === 'teachers' ? 'bg-indigo-100 text-indigo-700' :
                         ($a['audience'] === 'students' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700');
              ?>
                <li class="rounded-xl bg-blue-50/70 ring-1 ring-blue-100 p-4 hover:shadow-md transition">
                  <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                      <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-blue-100 text-blue-700 text-sm">
                          <ion-icon name="volume-high-outline"></ion-icon>
                        </span>
                        <span class="font-semibold text-blue-900 truncate"><?= e($a['title']) ?></span>
                      </div>
                      <div class="text-gray-800 mt-2 whitespace-pre-wrap clamp-3 text-sm" data-expandable>
                        <?= nl2br(e($a['message'])) ?>
                      </div>
                      <button type="button"
                              class="mt-1 text-xs text-blue-700 hover:underline hidden"
                              data-expand-toggle>
                        Show more
                      </button>
                    </div>
                    <div class="text-right shrink-0">
                      <div class="text-xs text-gray-500">
                        <?= date('M d, Y', strtotime($a['created_at'])) ?>
                      </div>
                      <div class="mt-1 inline-block text-[11px] px-2 py-0.5 rounded-full <?= $badge ?>">
                        <?= $aud ?>
                      </div>
                    </div>
                  </div>
                </li>
              <?php endwhile; ?>
            </ul>
          <?php else: ?>
            <div class="text-gray-600 text-center py-10">
              <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                <ion-icon name="document-outline" class="text-slate-600 text-xl"></ion-icon>
              </div>
              No announcements yet.
            </div>
          <?php endif; ?>
        </section>

        <!-- Create Announcement -->
        <section id="create-announcement" class="card p-5 sm:p-7">
          <h3 class="text-lg sm:text-xl font-semibold text-gray-900 inline-flex items-center gap-2 mb-4">
            <ion-icon name="add-circle-outline" class="text-blue-600"></ion-icon>
            Create Announcement
          </h3>

          <?php if (!empty($announce_message)): ?>
            <div class="mb-4"><?= $announce_message ?></div>
          <?php endif; ?>

          <form method="post" class="space-y-5" novalidate>
            <div>
              <label for="title" class="block font-medium text-sm mb-1">Title</label>
              <input id="title" type="text" name="title" required
                     class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
              <div class="text-xs text-slate-500 mt-1">
                <span id="titleCount">0</span>/120
              </div>
            </div>
            <div>
              <label for="message" class="block font-medium text-sm mb-1">Message</label>
              <textarea id="message" name="message" rows="4" required
                        class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"></textarea>
              <div class="text-xs text-slate-500 mt-1">
                <span id="msgCount">0</span>/1000
              </div>
            </div>
            <div>
              <span class="block font-medium text-sm mb-2">Audience</span>
              <div class="flex flex-wrap gap-2">
                <label class="cursor-pointer">
                  <input type="radio" name="audience" value="all" class="peer sr-only" checked>
                  <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-1 px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200 text-xs sm:text-sm">
                    <ion-icon name="globe-outline"></ion-icon> All
                  </span>
                </label>
                <label class="cursor-pointer">
                  <input type="radio" name="audience" value="students" class="peer sr-only">
                  <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-1 px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200 text-xs sm:text-sm">
                    <ion-icon name="school-outline"></ion-icon> Students
                  </span>
                </label>
                <label class="cursor-pointer">
                  <input type="radio" name="audience" value="teachers" class="peer sr-only">
                  <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-1 px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200 text-xs sm:text-sm">
                    <ion-icon name="easel-outline"></ion-icon> Teachers
                  </span>
                </label>
              </div>
            </div>
            <div class="pt-1">
              <button type="submit"
                      class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 shadow-sm">
                <ion-icon name="send-outline"></ion-icon> Post announcement
              </button>
            </div>
          </form>
        </section>
      </div>
    </section>
  </div>
</main>

<!-- Mobile Tools Drawer -->
<div id="toolsOverlay" class="fixed inset-0 bg-black/40 hidden lg:hidden z-40"></div>
<aside id="toolsDrawer"
       class="fixed top-0 left-0 h-full w-5/6 max-w-[320px] bg-white p-4 shadow-2xl z-50 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden"
       role="dialog" aria-modal="true" aria-labelledby="toolsDrawerTitle" aria-hidden="true">
  <div class="flex items-center justify-between mb-3">
    <h3 id="toolsDrawerTitle" class="text-lg font-bold text-gray-900">
      <ion-icon name="settings-outline" class="mr-1"></ion-icon> Admin Tools
    </h3>
    <button id="toolsClose" class="p-2 rounded-lg hover:bg-gray-100" aria-label="Close admin tools">
      <span class="fa-solid fa-xmark"></span>
    </button>
  </div>
  <nav class="space-y-2">
    <?php foreach ($adminTools as $tool):
      $link = $tool[0]; $icon = $tool[1]; $label = $tool[2];
      $isActive = (basename(parse_url($link, PHP_URL_PATH)) === $activePath);
      $classes = 'group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition';
      if ($isActive) $classes .= ' bg-blue-50 ring-blue-300';
    ?>
      <a href="<?= e($link) ?>"
         class="<?= $classes ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>>
        <span class="fa-solid <?= e($icon) ?> text-blue-600 w-5 text-center"></span>
        <span class="font-medium text-gray-800 group-hover:text-blue-800 text-sm"><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
    <a href="#create-announcement"
       class="group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition">
      <span class="fa-solid fa-bullhorn text-blue-600 w-5 text-center"></span>
      <span class="font-medium text-gray-800 group-hover:text-blue-800 text-sm">Create Announcement</span>
    </a>
  </nav>
</aside>

<?php include 'components/footer.php'; ?>

<!-- Scripts -->
<script>
  // Tools drawer
  (function() {
    const openBtn = document.getElementById('toolsOpen');
    const closeBtn = document.getElementById('toolsClose');
    const drawer = document.getElementById('toolsDrawer');
    const overlay = document.getElementById('toolsOverlay');
    let prevFocus = null;

    function onKeydown(e) { if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); } }
    function openDrawer() {
      prevFocus = document.activeElement;
      if (!drawer) return;
      drawer.style.transform = 'translateX(0)';
      overlay.classList.remove('hidden');
      drawer.setAttribute('aria-hidden', 'false');
      openBtn?.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
      const first = drawer.querySelector('a,button');
      first && first.focus();
      document.addEventListener('keydown', onKeydown);
      overlay.addEventListener('click', closeDrawer, { once: true });
    }
    function closeDrawer() {
      if (!drawer) return;
      drawer.style.transform = 'translateX(-100%)';
      overlay.classList.add('hidden');
      drawer.setAttribute('aria-hidden', 'true');
      openBtn?.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeydown);
      prevFocus && prevFocus.focus && prevFocus.focus();
    }
    openBtn?.addEventListener('click', openDrawer);
    closeBtn?.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (e) => {
      const t = e.target.closest('a,button');
      if (!t) return; closeDrawer();
    });
    window.addEventListener('resize', () => { if (window.innerWidth >= 1024) closeDrawer(); });
  })();

  // Toast helper
  function showToast(text, ok = true) {
    const t = document.getElementById('toast');
    const box = document.getElementById('toastBox');
    const icon = document.getElementById('toastIcon');
    const span = document.getElementById('toastText');
    if (!t || !box || !span) return;
    icon.setAttribute('name', ok ? 'checkmark-circle-outline' : 'alert-circle-outline');
    box.className = 'inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm shadow-xl ' +
      (ok ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white');
    span.textContent = text || (ok ? 'Saved' : 'Error');
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 2200);
  }

  // Show action toast if any
  (function() {
    const type = <?= json_encode($actionType) ?>;
    const msg  = <?= json_encode($actionMsg) ?>;
    if (msg) showToast(msg, type === 'success');
  })();

  // Copy reference
  function copyRef(text) {
    if (!text) return;
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(() => showToast('Reference copied', true));
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select(); document.execCommand('copy');
      document.body.removeChild(ta);
      showToast('Reference copied', true);
    }
  }

  // Sticky thead under sticky toolbars (if any)
  (function() {
    const head = document.getElementById('pendingHead');
    if (!head) return;
    document.documentElement.style.setProperty('--thead-offset', '0px');
  })();

  // CountUp animation for stats
  (function() {
    function animateCount(el) {
      const target = parseFloat(el.dataset.target || '0');
      const decimals = parseInt(el.dataset.decimals || '0', 10);
      const prefix = el.dataset.prefix || '';
      const duration = 1200;
      const start = performance.now();
      const from = 0;
      function tick(t) {
        const p = Math.min(1, (t - start) / duration);
        const val = from + (target - from) * p;
        el.textContent = prefix + val.toLocaleString(undefined, {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
        });
        if (p < 1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    }
    document.querySelectorAll('.countup').forEach(animateCount);
  })();

  // Relative time for payments
  (function() {
    const fmt = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const units = [
      ['year', 365*24*3600],
      ['month', 30*24*3600],
      ['day', 24*3600],
      ['hour', 3600],
      ['minute', 60],
      ['second', 1]
    ];
    function relTime(date) {
      const now = Date.now() / 1000;
      const ts = date.getTime() / 1000;
      let diff = Math.round(ts - now);
      const abs = Math.abs(diff);
      for (const [unit, sec] of units) {
        if (abs >= sec || unit === 'second') {
          return fmt.format(Math.round(diff / sec), unit);
        }
      }
      return '';
    }
    document.querySelectorAll('time.rel-time').forEach(t => {
      const d = new Date(t.getAttribute('datetime'));
      if (!isNaN(d)) t.textContent = relTime(d);
    });
  })();

  // Pending payments filtering (client-side)
  (function() {
    const tbody = document.getElementById('pendingBody');
    const search = document.getElementById('paymentSearch');
    const methodSel = document.getElementById('paymentMethodFilter');
    const clearBtn = document.getElementById('paymentClearFilters');
    const noMatch = document.getElementById('noPaymentMatch');
    const countText = document.getElementById('pendingCountText');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r !== noMatch);

    // Populate method options dynamically
    const methods = Array.from(new Set(rows.map(r => (r.dataset.method || '').trim()).filter(Boolean)));
    methods.sort().forEach(m => {
      const o = document.createElement('option');
      o.value = m; o.textContent = m.charAt(0).toUpperCase() + m.slice(1);
      methodSel.appendChild(o);
    });

    function applyFilter() {
      const q = (search.value || '').trim().toLowerCase();
      const m = (methodSel.value || '').trim();
      let shown = 0;
      rows.forEach(r => {
        const matchQ = !q || r.dataset.student.includes(q) || r.dataset.ref.includes(q);
        const matchM = !m || r.dataset.method === m;
        const show = matchQ && matchM;
        r.style.display = show ? '' : 'none';
        if (show) shown++;
      });
      noMatch.classList.toggle('hidden', shown !== 0);
      countText && (countText.textContent = `Showing ${shown} of ${rows.length} pending payments.`);
    }
    search?.addEventListener('input', applyFilter);
    methodSel?.addEventListener('change', applyFilter);
    clearBtn?.addEventListener('click', () => {
      search.value = ''; methodSel.value = ''; applyFilter();
    });
    applyFilter();
  })();

  // Expandable announcements (show more/less)
  (function() {
    document.querySelectorAll('[data-expandable]').forEach(el => {
      const btn = el.parentElement.querySelector('[data-expand-toggle]');
      if (!btn) return;
      // Only show toggle if content overflows
      if (el.scrollHeight > el.clientHeight + 4) btn.classList.remove('hidden');
      btn.addEventListener('click', () => {
        const clamped = el.classList.toggle('clamp-3');
        btn.textContent = clamped ? 'Show more' : 'Show less';
      });
    });
  })();

  // Title/Message counters
  (function() {
    const t = document.getElementById('title');
    const m = document.getElementById('message');
    const tc = document.getElementById('titleCount');
    const mc = document.getElementById('msgCount');
    function bind(el, counter, max) {
      if (!el || !counter) return;
      const upd = () => { counter.textContent = Math.min(max, el.value.length); };
      el.addEventListener('input', upd); upd();
    }
    bind(t, tc, 120);
    bind(m, mc, 1000);
  })();
</script>
</body>
</html>