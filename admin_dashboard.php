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

/* Handle announcement creation */
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
  <!-- Ionicons (icons) -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <!-- Font Awesome (for tools sidebar icons provided) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
    @keyframes wave { 0%, 60%, 100% { transform: rotate(0deg); } 30% { transform: rotate(15deg); } 50% { transform: rotate(-10deg); } }
    .animate-wave { display: inline-block; animation: wave 2s infinite; transform-origin: 70% 70%; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(2, 6, 23, 0.08); }
    .badge-chip { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen flex flex-col antialiased">

<?php include 'components/navbar.php'; ?>

<!-- Toast -->
<div id="toast" class="fixed top-24 right-6 z-50 hidden">
  <div id="toastBox" class="inline-flex items-center gap-2 rounded-md px-3.5 py-2 text-sm shadow-lg bg-slate-900/90 text-white">
    <ion-icon id="toastIcon" name="checkmark-circle-outline"></ion-icon>
    <span id="toastText">Done</span>
  </div>
</div>



<main class="max-w-8xl mx-auto px-6 py-10 flex-grow">
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
<?php
  // Optional customization before include:
  // $adminTools = [...];
  $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
  $createAnnouncementLink = '#create-announcement';
  include 'components/admin_tools_sidebar.php';
?>

    <!-- Main column -->
    <section class="lg:col-span-9 space-y-8">
      <!-- Mobile: open tools button -->
      <div class="lg:hidden flex justify-end">
        <button id="toolsOpen"
                class="inline-flex items-center gap-2 bg-white ring-1 ring-gray-200 px-3 py-2 rounded-lg shadow-sm text-blue-700 hover:bg-blue-50 transition"
                aria-controls="toolsDrawer" aria-expanded="false" aria-label="Open admin tools">
          <span class="fa-solid fa-sliders"></span> Admin Tools
        </button>
      </div>
<!-- Header Card -->
<section class="max-w-8xl mx-auto px-0 pt-12 ">
  <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-8">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
      <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
      <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
    </div>
    <div class="relative">
      <h1 class="text-3xl sm:text-4xl font-extrabold text-blue-700 tracking-tight inline-flex items-center gap-2">
        <ion-icon name="shield-checkmark-outline" class="text-blue-700"></ion-icon>
        Welcome back, Admin! <span class="animate-wave">👋</span>
      </h1>
      <p class="mt-3 text-gray-600 max-w-3xl">
        Monitor students, teachers, courses, and revenue in one place. Manage your platform with ease.
      </p>
    </div>

    <!-- Quick stats -->
    <div class="relative mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
      <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Students</span>
          <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-blue-50 text-blue-600">
            <ion-icon name="people-outline"></ion-icon>
          </span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= (int)$total_students ?></div>
      </div>

      <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Teachers</span>
          <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-indigo-50 text-indigo-600">
            <ion-icon name="easel-outline"></ion-icon>
          </span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= (int)$total_teachers ?></div>
      </div>

      <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Courses</span>
          <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-pink-50 text-pink-600">
            <ion-icon name="library-outline"></ion-icon>
          </span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= (int)$total_courses ?></div>
      </div>

      <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Enrollments</span>
          <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-violet-50 text-violet-600">
            <ion-icon name="person-add-outline"></ion-icon>
          </span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= (int)$total_enrollments ?></div>
      </div>

      <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Revenue</span>
          <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-emerald-50 text-emerald-600">
            <ion-icon name="cash-outline"></ion-icon>
          </span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-emerald-700">$<?= number_format((float)$total_revenue, 2) ?></div>
        <div class="mt-2 badge-chip text-amber-700 bg-amber-50 ring-1 ring-amber-200 inline-flex items-center gap-1">
          <ion-icon name="time-outline"></ion-icon> Pending: <b class="ml-1"><?= (int)$pending_count ?></b>
        </div>
      </div>
    </div>
  </div>
</section>
      <!-- Pending Payments -->
      <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-xl font-bold text-gray-900 inline-flex items-center gap-2">
            <ion-icon name="hourglass-outline" class="text-amber-600"></ion-icon>
            Pending Payments
          </h3>
          <a href="payment_reports.php" class="inline-flex items-center gap-1 text-blue-700 text-sm hover:underline">
            <ion-icon name="open-outline"></ion-icon> Open Payment Reports
          </a>
        </div>

        <?php if (!empty($actionMsg)): ?>
          <div class="mb-4 rounded-lg px-4 py-3 border <?= $actionType === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($actionType === 'error' ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-blue-50 text-blue-800 border-blue-200') ?>">
            <ion-icon name="<?= $actionType === 'success' ? 'checkmark-circle-outline' : ($actionType === 'error' ? 'alert-circle-outline' : 'information-circle-outline') ?>" class="mr-1 align-middle"></ion-icon>
            <?= e($actionMsg) ?>
          </div>
        <?php endif; ?>

        <?php if ($pending_count > 0 && $pending_rows && $pending_rows->num_rows > 0): ?>
          <div class="overflow-x-auto">
            <table class="min-w-full table-auto text-sm">
              <thead class="bg-gray-100 text-gray-700">
                <tr>
                  <th class="px-3 py-2 text-left">ID</th>
                  <th class="px-3 py-2 text-left">Student</th>
                  <th class="px-3 py-2 text-left">Amount</th>
                  <th class="px-3 py-2 text-left">Method</th>
                  <th class="px-3 py-2 text-left">Reference</th>
                  <th class="px-3 py-2 text-left">Date</th>
                  <th class="px-3 py-2 text-left">Proof</th>
                  <th class="px-3 py-2 text-left">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <?php while ($p = $pending_rows->fetch_assoc()):
                  $pid  = (int)$p['payment_id'];
                  $stud = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
                  $amt  = number_format((float)$p['amount'], 2);
                  $method = $p['payment_method'];
                  $ref  = $p['reference_code'] ?? '';
                  $dt   = $p['paid_at'] ? date("Y-m-d H:i", strtotime($p['paid_at'])) : '—';
                  $slip = $hasSlipCol ? ($p['slip_url'] ?? '') : '';
                  $methodChip = '<span class="badge-chip ring-1 ring-gray-200 bg-gray-50 text-gray-700 inline-flex items-center gap-1"><ion-icon name="card-outline"></ion-icon>'.e($method).'</span>';
                  if (stripos($method, 'bank') !== false) $methodChip = '<span class="badge-chip ring-1 ring-indigo-200 bg-indigo-50 text-indigo-700 inline-flex items-center gap-1"><ion-icon name="business-outline"></ion-icon>'.e($method).'</span>';
                  if (stripos($method, 'cash') !== false) $methodChip = '<span class="badge-chip ring-1 ring-amber-200 bg-amber-50 text-amber-700 inline-flex items-center gap-1"><ion-icon name="cash-outline"></ion-icon>'.e($method).'</span>';
                ?>
                  <tr class="align-top hover:bg-slate-50/60">
                    <td class="px-3 py-2 font-medium"><?= $pid ?></td>
                    <td class="px-3 py-2"><?= e($stud) ?></td>
                    <td class="px-3 py-2 text-emerald-700 font-semibold">$<?= $amt ?></td>
                    <td class="px-3 py-2"><?= $methodChip ?></td>
                    <td class="px-3 py-2">
                      <?php if ($ref): ?>
                        <div class="inline-flex items-center gap-2">
                          <code class="bg-gray-50 px-2 py-0.5 rounded text-xs"><?= e($ref) ?></code>
                          <button type="button" class="text-blue-700 text-xs hover:underline" onclick="copyRef('<?= e($ref) ?>')" title="Copy reference">
                            <ion-icon name="copy-outline"></ion-icon> Copy
                          </button>
                        </div>
                      <?php else: ?>
                        <span class="text-xs text-gray-500">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <ion-icon name="time-outline" class="text-slate-500"></ion-icon><?= e($dt) ?>
                      </span>
                    </td>
                    <td class="px-3 py-2">
                      <?php if ($slip): ?>
                        <a href="<?= e($slip) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-indigo-700 hover:underline">
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
                          <button class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-emerald-600 text-white text-xs hover:bg-emerald-700">
                            <ion-icon name="checkmark-circle-outline"></ion-icon> Activate
                          </button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Mark payment #<?= $pid ?> as Failed?');">
                          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                          <input type="hidden" name="action" value="update_payment_status">
                          <input type="hidden" name="payment_id" value="<?= $pid ?>">
                          <input type="hidden" name="new_status" value="failed">
                          <button class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-rose-600 text-white text-xs hover:bg-rose-700">
                            <ion-icon name="close-circle-outline"></ion-icon> Fail
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-gray-500 mt-2">Showing latest <?= (int)($pending_rows->num_rows) ?> pending payments.</p>
        <?php else: ?>
          <div class="text-gray-600 text-center py-10">
            <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
              <ion-icon name="checkmark-done-outline" class="text-emerald-600 text-xl"></ion-icon>
            </div>
            No pending payments.
          </div>
        <?php endif; ?>
      </section>

      <!-- Recent Announcements -->
      <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-xl font-bold text-gray-900 inline-flex items-center gap-2">
            <ion-icon name="megaphone-outline" class="text-rose-600"></ion-icon>
            Recent Announcements
          </h3>
          <a href="#create-announcement" class="inline-flex items-center gap-1 text-blue-700 text-sm hover:underline">
            <ion-icon name="add-circle-outline"></ion-icon> Create new
          </a>
        </div>

        <?php if ($announcements && $announcements->num_rows > 0): ?>
          <ul class="space-y-4">
            <?php while ($a = $announcements->fetch_assoc()):
              $aud = ucfirst($a['audience']);
              $badge = $a['audience'] === 'teachers' ? 'bg-indigo-100 text-indigo-700' :
                       ($a['audience'] === 'students' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700');
            ?>
              <li class="rounded-xl bg-blue-50/60 ring-1 ring-blue-100 p-4 hover:shadow transition">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-blue-100 text-blue-700 text-sm">
                        <ion-icon name="volume-high-outline"></ion-icon>
                      </span>
                      <span class="font-semibold text-blue-800 truncate"><?= e($a['title']) ?></span>
                    </div>
                    <div class="text-gray-700 mt-2 whitespace-pre-wrap"><?= nl2br(e($a['message'])) ?></div>
                  </div>
                  <div class="text-right shrink-0">
                    <div class="text-xs text-gray-500"><?= date('M d, Y', strtotime($a['created_at'])) ?></div>
                    <div class="mt-1 inline-block text-[11px] px-2 py-0.5 rounded-full <?= $badge ?>"><?= $aud ?></div>
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
      <section id="create-announcement" class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6 mb-2">
        <h3 class="text-xl font-bold text-gray-900 inline-flex items-center gap-2 mb-4">
          <ion-icon name="add-circle-outline" class="text-blue-600"></ion-icon>
          Create Announcement
        </h3>
        <?php if (!empty($announce_message)): ?>
          <div class="mb-4"><?= $announce_message ?></div>
        <?php endif; ?>

        <form method="post" class="space-y-5">
          <div>
            <label for="title" class="block font-medium mb-1">Title</label>
            <input id="title" type="text" name="title" required
                  class="w-full border border-gray-200 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>
          <div>
            <label for="message" class="block font-medium mb-1">Message</label>
            <textarea id="message" name="message" rows="4" required
                      class="w-full border border-gray-200 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"></textarea>
          </div>
          <div>
            <span class="block font-medium mb-2">Audience</span>
            <div class="flex flex-wrap gap-2">
              <label class="cursor-pointer">
                <input type="radio" name="audience" value="all" class="peer sr-only" checked>
                <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-1 px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">
                  <ion-icon name="globe-outline"></ion-icon> All
                </span>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="audience" value="students" class="peer sr-only">
                <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-1 px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">
                  <ion-icon name="school-outline"></ion-icon> Students
                </span>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="audience" value="teachers" class="peer sr-only">
                <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-1 px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">
                  <ion-icon name="easel-outline"></ion-icon> Teachers
                </span>
              </label>
            </div>
          </div>
          <div class="pt-2">
            <button type="submit"
                    class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg hover:bg-blue-700 shadow-sm">
              <ion-icon name="send-outline"></ion-icon> Post Announcement
            </button>
          </div>
        </form>
      </section>
    </section>
  </div>
</main>

<!-- Mobile Tools Drawer -->
<div id="toolsOverlay" class="fixed inset-0 bg-black/40 hidden lg:hidden z-40"></div>
<aside id="toolsDrawer"
       class="fixed top-0 left-0 h-full w-5/6 max-w-[320px] bg-white p-4 shadow-2xl z-50 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden"
       role="dialog" aria-modal="true" aria-labelledby="toolsDrawerTitle" aria-hidden="true">
  <div class="flex items-center justify-between mb-3">
    <h3 id="toolsDrawerTitle" class="text-lg font-bold text-gray-900"><ion-icon name="settings-outline" class="mr-1"></ion-icon> Admin Tools</h3>
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
        <span class="font-medium text-gray-800 group-hover:text-blue-800"><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
    <a href="#create-announcement" class="group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition">
      <span class="fa-solid fa-bullhorn text-blue-600 w-5 text-center"></span>
      <span class="font-medium text-gray-800 group-hover:text-blue-800">Create Announcement</span>
    </a>
  </nav>
</aside>

<?php include 'components/footer.php'; ?>

<!-- Scripts: mobile drawer + toast + copy -->
<script>
  // Tools drawer
  (function() {
    const openBtn = document.getElementById('toolsOpen');
    const closeBtn = document.getElementById('toolsClose');
    const drawer = document.getElementById('toolsDrawer');
    const overlay = document.getElementById('toolsOverlay');
    let prevFocus = null;

    function openDrawer() {
      prevFocus = document.activeElement;
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
      drawer.style.transform = 'translateX(-100%)';
      overlay.classList.add('hidden');
      drawer.setAttribute('aria-hidden', 'true');
      openBtn?.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeydown);
      prevFocus && prevFocus.focus && prevFocus.focus();
    }
    function onKeydown(e) { if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); } }

    openBtn?.addEventListener('click', openDrawer);
    closeBtn?.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (e) => {
      const t = e.target.closest('a,button');
      if (!t) return;
      closeDrawer();
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
    box.className = 'inline-flex items-center gap-2 rounded-md px-3.5 py-2 text-sm shadow-lg ' + (ok ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white');
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
</script>
</body>
</html>