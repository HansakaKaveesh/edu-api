<?php
// ceo_dashboard.php

session_start();
include 'db_connect.php';

/* Allow CEO or Admin */
if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['ceo', 'admin'], true)) {
  header("Location: login.php");
  exit;
}

/* Helpers */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function columnExists(mysqli $conn, string $table, string $column): bool {
  $col = $conn->query("SHOW COLUMNS FROM `{$conn->real_escape_string($table)}` LIKE '{$conn->real_escape_string($column)}'");
  $exists = $col && $col->num_rows > 0;
  if ($col) $col->free();
  return $exists;
}
function pickFirstExistingColumn(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (columnExists($conn, $table, $c)) return $c;
  return null;
}
function pickFKColumn(mysqli $conn, string $table, string $refTable, array $fallbacks): ?string {
  $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? null;
  if ($db) {
    $sql = "SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME = ?
            LIMIT 1";
    if ($stmt = $conn->prepare($sql)) {
      $stmt->bind_param('sss', $db, $table, $refTable);
      $stmt->execute();
      $stmt->bind_result($colName);
      if ($stmt->fetch()) { $stmt->close(); return $colName; }
      $stmt->close();
    }
  }
  return pickFirstExistingColumn($conn, $table, $fallbacks);
}
function pickDateColumn(mysqli $conn, string $table, array $candidates): ?string {
  return pickFirstExistingColumn($conn, $table, $candidates);
}

/* Range filter */
$allowedRanges = [
  '30d' => 'Last 30 days',
  '90d' => 'Last 90 days',
  '12m' => 'Last 12 months',
  'ytd' => 'Year to date',
  'all' => 'All time'
];
$range = $_GET['range'] ?? '90d';
if (!isset($allowedRanges[$range])) $range = '90d';

/* Date windows */
$today    = new DateTime('today');
$tomorrow = (clone $today)->modify('+1 day'); // exclusive upper bound
switch ($range) {
  case '30d': $start = (clone $today)->modify('-29 days'); break;
  case '90d': $start = (clone $today)->modify('-89 days'); break;
  case '12m': $start = (clone $today)->modify('-11 months')->modify('first day of this month'); break;
  case 'ytd': $start = new DateTime(date('Y-01-01')); break;
  case 'all': default: $start = new DateTime('1970-01-01'); break;
}
$startDate = $start->format('Y-m-d');
$endDate   = $tomorrow->format('Y-m-d');

/* Previous-period window for growth */
$periodDays   = (int)$today->diff($start)->format('%a') + 1; // inclusive days
$prevEnd      = (clone $start)->modify('-1 day');
$prevStart    = (clone $prevEnd)->modify("-" . ($periodDays - 1) . " days");
$prevStartDate= $prevStart->format('Y-m-d');
$prevEndDate  = $prevEnd->format('Y-m-d');

/* Detect schema (graceful fallback) */
$enrollDateCol    = pickDateColumn($conn, 'enrollments', ['enrolled_at','created_at','added_at','date']);
$studentDateCol   = pickDateColumn($conn, 'students',   ['created_at','joined_at','registered_at','added_at','date']);
$courseTitleCol   = pickFirstExistingColumn($conn, 'courses', ['title','name','course_name']);
$coursePK         = pickFirstExistingColumn($conn, 'courses', ['course_id','id']);
$studentPK        = pickFirstExistingColumn($conn, 'students', ['student_id','id']);
$enrollStudentCol = pickFKColumn($conn, 'enrollments', 'students', ['student_id','user_id','studentId','learner_id','student']);
$enrollCourseCol  = pickFKColumn($conn, 'enrollments', 'courses',  ['course_id','courseId','class_id','module_id','course']);
$payStudentCol    = pickFKColumn($conn, 'student_payments', 'students', ['student_id','user_id','studentId','learner_id','student']);

/* KPI: revenue (range, all-time, previous period) */
$q = $conn->prepare("SELECT IFNULL(SUM(amount),0) AS s FROM student_payments WHERE payment_status='completed' AND paid_at >= ? AND paid_at < ?");
$q->bind_param('ss', $startDate, $endDate);
$q->execute(); $res = $q->get_result(); $revenue_range = (float)($res->fetch_assoc()['s'] ?? 0); $q->close();

$q = $conn->query("SELECT IFNULL(SUM(amount),0) AS s FROM student_payments WHERE payment_status='completed'");
$revenue_all = (float)($q->fetch_assoc()['s'] ?? 0);

$q = $conn->prepare("SELECT IFNULL(SUM(amount),0) AS s FROM student_payments WHERE payment_status='completed' AND paid_at >= ? AND paid_at <= ?");
$q->bind_param('ss', $prevStartDate, $prevEndDate);
$q->execute(); $res = $q->get_result(); $revenue_prev = (float)($res->fetch_assoc()['s'] ?? 0); $q->close();

$growth = ($revenue_prev > 0) ? (($revenue_range - $revenue_prev) / $revenue_prev) * 100 : null;

/* MRR (last 30d) and ARR */
$q = $conn->prepare("SELECT IFNULL(SUM(amount),0) AS s FROM student_payments WHERE payment_status='completed' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$q->execute(); $res = $q->get_result(); $mrr = (float)($res->fetch_assoc()['s'] ?? 0); $q->close();
$arr = $mrr * 12;

/* Active students (range) */
$active_students = 0;
if ($enrollDateCol && $enrollStudentCol) {
  $sql = "SELECT COUNT(DISTINCT `{$enrollStudentCol}`) AS c
          FROM enrollments
          WHERE `{$enrollDateCol}` >= ? AND `{$enrollDateCol}` < ?";
  $q = $conn->prepare($sql);
  $q->bind_param('ss', $startDate, $endDate);
  $q->execute(); $res = $q->get_result(); $active_students = (int)($res->fetch_assoc()['c'] ?? 0); $q->close();
}

/* New students (range) */
$new_students = 0;
if ($studentDateCol) {
  $sql = "SELECT COUNT(*) AS c FROM students WHERE `{$studentDateCol}` >= ? AND `{$studentDateCol}` < ?";
  $q = $conn->prepare($sql);
  $q->bind_param('ss', $startDate, $endDate);
  $q->execute(); $res = $q->get_result(); $new_students = (int)($res->fetch_assoc()['c'] ?? 0); $q->close();
}

/* Totals */
$total_students = (int)($conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'] ?? 0);

/* Pending count (for KPI card) */
$pending_count = (int)($conn->query("SELECT COUNT(*) AS c FROM student_payments WHERE payment_status='pending'")->fetch_assoc()['c'] ?? 0);

/* ARPU (range) */
$arpu = $active_students > 0 ? ($revenue_range / $active_students) : 0;

/* Payment mix (range) */
$pm_labels = []; $pm_values = [];
$q = $conn->prepare("SELECT payment_method, IFNULL(SUM(amount),0) AS s
                     FROM student_payments
                     WHERE payment_status='completed' AND paid_at >= ? AND paid_at < ?
                     GROUP BY payment_method ORDER BY s DESC");
$q->bind_param('ss', $startDate, $endDate);
$q->execute(); $r = $q->get_result();
while ($row = $r->fetch_assoc()) { $pm_labels[] = $row['payment_method'] ?: 'Unknown'; $pm_values[] = (float)$row['s']; }
$q->close();

/* Revenue last 12 months (monthly series) */
$monthMap = []; $labels12 = [];
for ($i = 11; $i >= 0; $i--) {
  $dt = (new DateTime('first day of this month'))->modify("-$i months");
  $ym = $dt->format('Y-m');
  $monthMap[$ym] = 0;
  $labels12[] = $dt->format('M Y');
}
$q = $conn->query("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, IFNULL(SUM(amount),0) AS s
                   FROM student_payments
                   WHERE payment_status='completed' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   GROUP BY ym");
while ($row = $q->fetch_assoc()) {
  if (isset($monthMap[$row['ym']])) $monthMap[$row['ym']] = (float)$row['s'];
}
$series12 = array_values($monthMap);

/* Growth badge and currency */
$growthUp  = !is_null($growth) && $growth >= 0;
$CURRENCY  = '$';

/* Sidebar component options (reusable) */
$keepQuery    = ['range'];     // preserve 'range' in sidebar links
$sidebarId    = 'ceoSidebar';
$toggleId     = 'ceoSbToggle';
$sidebarTitle = 'Menu';

/* Query string for CTAs */
$qs = $_GET ? ('?' . http_build_query($_GET)) : '';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEO Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <!-- Ionicons -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(2, 6, 23, 0.08); }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen flex flex-col antialiased">
<?php include 'components/navbar.php'; ?>

<main class="max-w-8xl mx-auto px-6 py-28 flex-grow">
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Sidebar (reusable component) -->
    <aside class="lg:col-span-3 lg:sticky lg:top-28 self-start">
      <?php include 'components/ceo_sidebar.php'; ?>
    </aside>

    <!-- Content -->
    <section class="lg:col-span-9 space-y-8">
      <!-- Header card -->
      <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-8">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
          <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
          <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
        </div>
        <div class="relative">
          <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
              <h1 class="text-3xl sm:text-4xl font-extrabold text-blue-700 tracking-tight inline-flex items-center gap-2">
                <ion-icon name="speedometer-outline" class="text-blue-700"></ion-icon>
                CEO Dashboard
              </h1>
              <p class="mt-2 text-gray-600">Executive view of revenue, growth, and engagement.</p>
              <div class="mt-3 flex flex-wrap gap-2 text-sm">
                <a href="ceo_revenue.php<?= e($qs) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 ring-1 ring-blue-200 hover:bg-blue-100">
                  <ion-icon name="stats-chart-outline"></ion-icon> Revenue
                </a>
                <a href="ceo_payments.php<?= e($qs) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100">
                  <ion-icon name="card-outline"></ion-icon> Payments
                </a>
                <a href="ceo_courses.php<?= e($qs) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 ring-1 ring-amber-200 hover:bg-amber-100">
                  <ion-icon name="trophy-outline"></ion-icon> Top Courses
                </a>
                <a href="ceo_customers.php<?= e($qs) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-violet-50 text-violet-700 ring-1 ring-violet-200 hover:bg-violet-100">
                  <ion-icon name="podium-outline"></ion-icon> Top Customers
                </a>
              </div>
            </div>

            <!-- Range Filter + Export -->
            <form method="get" class="flex items-end gap-2 text-sm">
              <div>
                <label class="block text-gray-600 mb-1">Range</label>
                <select name="range" onchange="this.form.submit()"
                        class="border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                  <?php foreach ($allowedRanges as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $k === $range ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <a href="ceo_export.php?which=payments<?= $qs ? '&' . e(ltrim($qs,'?')) : '' ?>"
                 class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                <ion-icon name="download-outline"></ion-icon> Export CSV
              </a>
            </form>
          </div>
        </div>
      </div>

      <!-- KPIs -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
          <div class="flex items-center justify-between">
            <span class="text-sm text-gray-500">Revenue (<?= e($allowedRanges[$range]) ?>)</span>
            <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-emerald-50 text-emerald-600">
              <ion-icon name="cash-outline"></ion-icon>
            </span>
          </div>
          <div class="mt-2 flex items-baseline gap-2">
            <div class="text-3xl font-extrabold text-gray-900"><?= $CURRENCY . number_format($revenue_range, 2) ?></div>
            <?php if (!is_null($growth)): ?>
              <span class="text-xs px-2 py-0.5 rounded-full <?= $growthUp ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' ?>">
                <ion-icon name="<?= $growthUp ? 'arrow-up-outline':'arrow-down-outline' ?>"></ion-icon>
                <?= ($growthUp?'+':'') . number_format($growth,1) ?>%
              </span>
            <?php endif; ?>
          </div>
        </div>

        <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
          <div class="flex items-center justify-between">
            <span class="text-sm text-gray-500">MRR (last 30d)</span>
            <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-blue-50 text-blue-600">
              <ion-icon name="calendar-outline"></ion-icon>
            </span>
          </div>
          <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= $CURRENCY . number_format($mrr, 2) ?></div>
          <div class="mt-2 text-xs text-gray-600">ARR est: <?= $CURRENCY . number_format($arr, 0) ?></div>
        </div>

        <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
          <div class="flex items-center justify-between">
            <span class="text-sm text-gray-500">Active Students</span>
            <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-violet-50 text-violet-600">
              <ion-icon name="people-outline"></ion-icon>
            </span>
          </div>
          <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= (int)$active_students ?></div>
          <div class="mt-2 text-xs text-gray-600">Total: <?= (int)$total_students ?></div>
        </div>

        <div class="stat-card rounded-xl bg-white ring-1 ring-gray-200 p-5 transition">
          <div class="flex items-center justify-between">
            <span class="text-sm text-gray-500">ARPU</span>
            <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-amber-50 text-amber-600">
              <ion-icon name="trending-up-outline"></ion-icon>
            </span>
          </div>
          <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= $CURRENCY . number_format($arpu, 2) ?></div>
          <div class="mt-2 text-xs text-gray-600">New: <?= (int)$new_students ?> • Pending: <?= (int)$pending_count ?></div>
        </div>
      </section>

      <!-- Charts -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900 inline-flex items-center gap-2">
              <ion-icon name="stats-chart-outline" class="text-blue-600"></ion-icon>
              Revenue (last 12 months)
            </h3>
            <span class="text-xs text-gray-500">All-time: <?= $CURRENCY . number_format($revenue_all, 2) ?></span>
          </div>
          <canvas id="rev12"></canvas>
        </div>

        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <h3 class="text-lg font-bold text-gray-900 inline-flex items-center gap-2 mb-4">
            <ion-icon name="pie-chart-outline" class="text-emerald-600"></ion-icon>
            Payment Mix
          </h3>
          <?php if (count($pm_values) > 0): ?>
            <canvas id="paymix"></canvas>
          <?php else: ?>
            <div class="text-gray-600 text-sm">No completed payments in this range.</div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Focused sections (CTAs) -->
      <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold inline-flex items-center gap-2">
              <ion-icon name="trophy-outline" class="text-amber-600"></ion-icon> Top Courses
            </h3>
            <a class="px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700" href="ceo_courses.php<?= e($qs) ?>">Open page</a>
          </div>
          <p class="mt-2 text-sm text-gray-600">View courses ranked by enrollments for the selected range.</p>
        </div>

        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold inline-flex items-center gap-2">
              <ion-icon name="podium-outline" class="text-violet-600"></ion-icon> Top Customers
            </h3>
            <a class="px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700" href="ceo_customers.php<?= e($qs) ?>">Open page</a>
          </div>
          <p class="mt-2 text-sm text-gray-600">See highest revenue students lifetime or in this window.</p>
        </div>
      </section>
    </section>
  </div>
</main>

<?php include 'components/footer.php'; ?>

<script>
  // Revenue 12 months chart
  const rev12Labels = <?= json_encode($labels12) ?>;
  const rev12Data   = <?= json_encode($series12) ?>;
  (function(){
    const el = document.getElementById('rev12');
    if (!el) return;
    new Chart(el, {
      type: 'line',
      data: {
        labels: rev12Labels,
        datasets: [{
          label: 'Revenue',
          data: rev12Data,
          tension: 0.35,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37, 99, 235, 0.15)',
          fill: true,
          pointRadius: 3,
          pointHoverRadius: 5
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
        scales: {
          x: { grid: { display: false } },
          y: { grid: { color: 'rgba(2,6,23,0.06)' }, ticks: { callback: v => '<?= e($CURRENCY) ?>' + Number(v).toLocaleString() } }
        }
      }
    });
  })();

  // Payment mix chart
  const payMixLabels = <?= json_encode($pm_labels) ?>;
  const payMixData   = <?= json_encode($pm_values) ?>;
  (function(){
    const el = document.getElementById('paymix');
    if (!el || !payMixData.length) return;
    const total = payMixData.reduce((a,b)=>a+b,0)||1;
    new Chart(el, {
      type: 'doughnut',
      data: {
        labels: payMixLabels,
        datasets: [{
          data: payMixData,
          backgroundColor: ['#10b981','#6366f1','#f59e0b','#ef4444','#06b6d4','#84cc16','#a855f7','#14b8a6','#f97316'],
          borderWidth: 0
        }]
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: ctx => {
            const val = ctx.parsed || 0;
            const pct = (val/total*100).toFixed(1);
            return `${ctx.label}: <?= e($CURRENCY) ?>${val.toLocaleString()} (${pct}%)`;
          }}}
        },
        cutout: '62%'
      }
    });
  })();
</script>
</body>
</html>