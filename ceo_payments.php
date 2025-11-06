<?php
// ceo_payments.php

session_start();
include 'db_connect.php';

/* Allow CEO or Admin */
if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['ceo', 'admin'], true)) {
  header("Location: login.php");
  exit;
}

/* ===== Helpers ===== */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function columnExists(mysqli $conn, string $table, string $column): bool {
  $col = $conn->query("SHOW COLUMNS FROM `{$conn->real_escape_string($table)}` LIKE '{$conn->real_escape_string($column)}'");
  $ok  = $col && $col->num_rows > 0;
  if ($col) $col->free();
  return $ok;
}
function pickFirstExistingColumn(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (columnExists($conn, $table, $c)) return $c;
  return null;
}
function pickFKColumn(mysqli $conn, string $table, string $refTable, array $fallbacks): ?string {
  $dbRes = $conn->query("SELECT DATABASE() AS db");
  $db = $dbRes ? ($dbRes->fetch_assoc()['db'] ?? null) : null;
  if ($db) {
    $sql = "SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME=?
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
function initials_from(string $name, string $fallback='U'): string {
  $name = trim($name);
  if ($name === '') return $fallback;
  $parts = preg_split('/\s+/u', $name);
  $a = isset($parts[0]) ? mb_substr($parts[0], 0, 1, 'UTF-8') : '';
  $b = isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : '';
  $ini = strtoupper($a . $b);
  return $ini ?: $fallback;
}
function buildUrl(array $merge): string {
  $base = $_GET;
  foreach ($merge as $k=>$v) $base[$k] = $v;
  return '?' . http_build_query($base);
}

/* ===== Range filter ===== */
$allowedRanges = [
  '30d' => 'Last 30 days',
  '90d' => 'Last 90 days',
  '12m' => 'Last 12 months',
  'ytd' => 'Year to date',
  'all' => 'All time',
  'custom' => 'Custom'
];
$range = $_GET['range'] ?? '90d';
if (!isset($allowedRanges[$range])) $range = '90d';

$today    = new DateTime('today');
$tomorrow = (clone $today)->modify('+1 day'); // exclusive upper bound

$startParam = $_GET['start'] ?? '';
$endParam   = $_GET['end']   ?? '';
$useCustom  = ($range === 'custom');

if ($useCustom) {
  $sd = DateTime::createFromFormat('Y-m-d', $startParam) ?: new DateTime('today');
  $ed = DateTime::createFromFormat('Y-m-d', $endParam)   ?: new DateTime('today');
  if ($sd > $ed) { $tmp=$sd; $sd=$ed; $ed=$tmp; }
  $start = (clone $sd);
  $endExclusive = (clone $ed)->modify('+1 day');
} else {
  switch ($range) {
    case '30d': $start = (clone $today)->modify('-29 days'); break;
    case '90d': $start = (clone $today)->modify('-89 days'); break;
    case '12m': $start = (clone $today)->modify('-11 months')->modify('first day of this month'); break;
    case 'ytd': $start = new DateTime(date('Y-01-01')); break;
    case 'all': default: $start = new DateTime('1970-01-01'); break;
  }
  $endExclusive = (clone $tomorrow);
}
$startDate = $start->format('Y-m-d');
$endDate   = $endExclusive->format('Y-m-d');

/* ===== Detect payments + students schema ===== */
$payIdCol       = pickFirstExistingColumn($conn, 'student_payments', ['payment_id','id']);
$payAmountCol   = pickFirstExistingColumn($conn, 'student_payments', ['amount','payment_amount','total']);
$payMethodCol   = pickFirstExistingColumn($conn, 'student_payments', ['payment_method','method']);
$payStatusCol   = pickFirstExistingColumn($conn, 'student_payments', ['payment_status','status']);
$payDateCol     = pickFirstExistingColumn($conn, 'student_payments', ['paid_at','created_at','date']);
$payRefCol      = pickFirstExistingColumn($conn, 'student_payments', ['reference_code','reference','ref']);
$slipCol        = pickFirstExistingColumn($conn, 'student_payments', ['slip_url','slip','proof_url']);

$studentPK      = pickFirstExistingColumn($conn, 'students', ['student_id','id']);
$payStudentCol  = pickFKColumn($conn, 'student_payments', 'students', ['student_id','user_id','studentId','learner_id','student']);
$firstNameCol   = columnExists($conn, 'students', 'first_name') ? 'first_name' : null;
$lastNameCol    = columnExists($conn, 'students', 'last_name')  ? 'last_name'  : null;
$fullNameCol    = columnExists($conn, 'students', 'name') ? 'name' : null;
if ($firstNameCol || $lastNameCol) {
  $nameExpr = "TRIM(CONCAT_WS(' ', s.`$firstNameCol`, s.`$lastNameCol`))";
} elseif ($fullNameCol) {
  $nameExpr = "s.`$fullNameCol`";
} else {
  $nameExpr = "CONCAT('Student #', s.`$studentPK`)";
}

/* ===== Filters, search, sort, pagination ===== */
$status = strtolower(trim($_GET['status'] ?? 'all')); // all|completed|pending|failed
$method = trim($_GET['method'] ?? '');                // payment method value
$q      = trim($_GET['q'] ?? '');                     // search: ref, student, method
$sort   = strtolower($_GET['sort'] ?? 'date');        // date|amount|status|method|id
$dir    = strtolower($_GET['dir'] ?? 'desc');         // asc|desc
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage= min(100, max(5, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$allowedSort = ['date','amount','status','method','id'];
if (!in_array($sort, $allowedSort, true)) $sort = 'date';
if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

$accentColors = ['bg-gradient-to-br from-indigo-500 to-blue-600','bg-gradient-to-br from-emerald-500 to-teal-600','bg-gradient-to-br from-violet-500 to-fuchsia-600','bg-gradient-to-br from-amber-500 to-orange-600','bg-gradient-to-br from-sky-500 to-cyan-600'];
function colorForId($id, $colors){ return $colors[(int)$id % max(count($colors),1)]; }

/* ===== Build WHERE ===== */
$where = [];
$types = '';
$params= [];

if ($status !== 'all' && $payStatusCol) {
  $where[] = "sp.`$payStatusCol` = ?";
  $params[] = $status; $types .= 's';
}
if ($method !== '' && $payMethodCol) {
  $where[] = "sp.`$payMethodCol` = ?";
  $params[] = $method; $types .= 's';
}
if ($payDateCol) {
  // Filter by date range on paid_at (records with NULL paid_at will be excluded)
  $where[] = "sp.`$payDateCol` >= ? AND sp.`$payDateCol` < ?";
  $params[] = $startDate; $types .= 's';
  $params[] = $endDate;   $types .= 's';
}
if ($q !== '') {
  $qLike = '%' . $q . '%';
  $parts = [];
  if ($payRefCol)    $parts[] = "sp.`$payRefCol` LIKE ?";
  if ($payMethodCol) $parts[] = "sp.`$payMethodCol` LIKE ?";
  $parts[] = "$nameExpr LIKE ?";
  $where[] = '(' . implode(' OR ', $parts) . ')';
  if ($payRefCol)    { $params[] = $qLike; $types .= 's'; }
  if ($payMethodCol) { $params[] = $qLike; $types .= 's'; }
  $params[] = $qLike; $types .= 's';
}
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ===== Method list (for filter) ===== */
$methodList = [];
if ($payMethodCol) {
  $mr = $conn->query("SELECT DISTINCT `$payMethodCol` AS m FROM student_payments WHERE `$payMethodCol` IS NOT NULL AND `$payMethodCol`<>'' ORDER BY m");
  while ($row = $mr->fetch_assoc()) $methodList[] = $row['m'];
}

/* ===== Aggregates (mix + KPI in range for completed) ===== */
$mixLabels = []; $mixValues = [];
if ($payMethodCol && $payStatusCol && $payDateCol) {
  $sql = "SELECT sp.`$payMethodCol` AS m, IFNULL(SUM(sp.`$payAmountCol`),0) AS s
          FROM student_payments sp
          WHERE sp.`$payStatusCol`='completed' AND sp.`$payDateCol` >= ? AND sp.`$payDateCol` < ?
          GROUP BY sp.`$payMethodCol` ORDER BY s DESC";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $startDate, $endDate);
  $st->execute();
  $rs = $st->get_result();
  while ($row = $rs->fetch_assoc()) { $mixLabels[] = $row['m'] ?: 'Unknown'; $mixValues[] = (float)$row['s']; }
  $st->close();
}

/* Simple KPI counts in current filter (excluding search) */
$kpi = ['completed'=>0,'pending'=>0,'failed'=>0,'revenue'=>0.0];
if ($payStatusCol && $payDateCol) {
  $sqlK = "SELECT `$payStatusCol` AS st, COUNT(*) AS c, IFNULL(SUM(CASE WHEN `$payStatusCol`='completed' THEN `$payAmountCol` END),0) AS r
           FROM student_payments
           WHERE `$payDateCol` >= ? AND `$payDateCol` < ?
           GROUP BY `$payStatusCol`";
  $st = $conn->prepare($sqlK);
  $st->bind_param('ss', $startDate, $endDate);
  $st->execute();
  $rs = $st->get_result();
  while ($row = $rs->fetch_assoc()) {
    $stt = strtolower($row['st'] ?? '');
    if (isset($kpi[$stt])) $kpi[$stt] = (int)$row['c'];
    $kpi['revenue'] += (float)$row['r'];
  }
  $st->close();
}

/* ===== Count + fetch list ===== */
$joinStudent = ($payStudentCol && $studentPK) ? "LEFT JOIN students s ON s.`$studentPK` = sp.`$payStudentCol`" : "";
$countSQL = "SELECT COUNT(*) AS c FROM student_payments sp $joinStudent $whereSQL";
$stC = $conn->prepare($countSQL);
if ($types) $stC->bind_param($types, ...$params);
$stC->execute();
$resC = $stC->get_result();
$filteredCount = (int)($resC->fetch_assoc()['c'] ?? 0);
$stC->close();

$totalPages = max(1, (int)ceil($filteredCount / $perPage));
$prevPage   = max(1, $page - 1);
$nextPage   = min($totalPages, $page + 1);

/* Sorting map */
$sortCol = $payDateCol;
if ($sort === 'amount') $sortCol = $payAmountCol;
elseif ($sort === 'status') $sortCol = $payStatusCol;
elseif ($sort === 'method') $sortCol = $payMethodCol;
elseif ($sort === 'id')     $sortCol = $payIdCol;

$selectSQL = "SELECT sp.`$payIdCol` AS id,
                     sp.`$payAmountCol` AS amount,
                     " . ($payMethodCol ? "sp.`$payMethodCol`" : "NULL") . " AS method,
                     " . ($payStatusCol ? "sp.`$payStatusCol`" : "NULL") . " AS status,
                     " . ($payDateCol   ? "sp.`$payDateCol`"   : "NULL") . " AS paid_at,
                     " . ($payRefCol    ? "sp.`$payRefCol`"    : "NULL") . " AS ref,
                     " . ($slipCol      ? "sp.`$slipCol`"      : "NULL") . " AS slip,
                     " . ($payStudentCol && $studentPK ? "$nameExpr" : "NULL") . " AS student
              FROM student_payments sp
              $joinStudent
              $whereSQL
              ORDER BY sp.`$sortCol` $dir
              LIMIT ? OFFSET ?";
$st = $conn->prepare($selectSQL);
if ($types) {
  $bindTypes = $types . 'ii';
  $params2 = array_merge($params, [ (int)$perPage, (int)$offset ]);
  $st->bind_param($bindTypes, ...$params2);
} else {
  $st->bind_param('ii', $perPage, $offset);
}
$st->execute();
$list = $st->get_result();
$rows = [];
while ($r = $list->fetch_assoc()) $rows[] = $r;
$st->close();

/* ===== CSV Export (uses current filters) ===== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=payments_' . date('Ymd_His') . '.csv');
  $csv = fopen('php://output', 'w');
  fputcsv($csv, ['ID','Student','Amount','Method','Status','Paid At','Reference']);
  $sqlCSV = "SELECT sp.`$payIdCol` AS id,
                    " . ($payStudentCol && $studentPK ? "$nameExpr" : "NULL") . " AS student,
                    sp.`$payAmountCol` AS amount,
                    " . ($payMethodCol ? "sp.`$payMethodCol`" : "NULL") . " AS method,
                    " . ($payStatusCol ? "sp.`$payStatusCol`" : "NULL") . " AS status,
                    " . ($payDateCol   ? "sp.`$payDateCol`"   : "NULL") . " AS paid_at,
                    " . ($payRefCol    ? "sp.`$payRefCol`"    : "NULL") . " AS ref
             FROM student_payments sp
             $joinStudent
             $whereSQL
             ORDER BY sp.`$sortCol` $dir";
  $stx = $conn->prepare($sqlCSV);
  if ($types) $stx->bind_param($types, ...$params);
  $stx->execute();
  $rsx = $stx->get_result();
  while ($row = $rsx->fetch_assoc()) {
    fputcsv($csv, [$row['id'], $row['student'], $row['amount'], $row['method'], $row['status'], $row['paid_at'], $row['ref']]);
  }
  fclose($csv);
  exit;
}

/* ===== Sidebar component setup ===== */
$keepQuery    = ['q','status','method','range','start','end','sort','dir','page','per_page']; // preserve on links
$sidebarId    = 'ceoSidebar';
$toggleId     = 'ceoSbToggle';
$sidebarTitle = 'Menu';

/* Currency */
$CURRENCY = '$';

/* For header chips */
$qs = $_GET ? ('?' . http_build_query($_GET)) : '';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEO · Payments</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}</style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen flex flex-col antialiased">
<?php include 'components/navbar.php'; ?>

<main class="max-w-8xl mx-auto px-6 py-28 flex-grow">
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Sidebar -->
    <aside class="lg:col-span-3 lg:sticky lg:top-28 self-start">
      <?php include 'components/ceo_sidebar.php'; ?>
    </aside>

    <!-- Content -->
    <section class="lg:col-span-9 space-y-6">
      <!-- Header & Filters -->
      <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
          <div class="absolute -top-20 -right-16 w-64 h-64 rounded-full bg-blue-200/40 blur-3xl"></div>
          <div class="absolute -bottom-24 -left-20 w-80 h-80 rounded-full bg-indigo-200/40 blur-3xl"></div>
        </div>
        <div class="relative">
          <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
              <h1 class="text-2xl sm:text-3xl font-extrabold text-blue-700 inline-flex items-center gap-2">
                <ion-icon name="card-outline"></ion-icon> Payments
              </h1>
              <p class="text-gray-600 mt-1 text-sm">Monitor transactions, filter by status/method, export CSV.</p>
              <!-- KPI chips -->
              <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                  <ion-icon name="checkmark-circle-outline"></ion-icon> Completed: <b><?= (int)$kpi['completed'] ?></b>
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                  <ion-icon name="hourglass-outline"></ion-icon> Pending: <b><?= (int)$kpi['pending'] ?></b>
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                  <ion-icon name="close-circle-outline"></ion-icon> Failed: <b><?= (int)$kpi['failed'] ?></b>
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                  <ion-icon name="cash-outline"></ion-icon> Revenue (range): <b><?= $CURRENCY . number_format($kpi['revenue'],2) ?></b>
                </span>
              </div>
            </div>

            <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-8 gap-2 text-sm">
              <div class="lg:col-span-2">
                <label class="block text-gray-600 mb-1">Range</label>
                <select name="range" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <?php foreach ($allowedRanges as $k=>$label): ?>
                    <option value="<?= e($k) ?>" <?= $k===$range?'selected':'' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">From</label>
                <input type="date" name="start" value="<?= e($useCustom ? $start->format('Y-m-d') : '') ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2" <?= $useCustom?'':'disabled' ?> />
              </div>
              <div>
                <label class="block text-gray-600 mb-1">To</label>
                <input type="date" name="end" value="<?= e($useCustom ? (clone $endExclusive)->modify('-1 day')->format('Y-m-d') : '') ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2" <?= $useCustom?'':'disabled' ?> />
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Status</label>
                <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <?php foreach (['all'=>'All','completed'=>'Completed','pending'=>'Pending','failed'=>'Failed'] as $k=>$lab): ?>
                    <option value="<?= e($k) ?>" <?= $status===$k?'selected':'' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Method</label>
                <select name="method" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <option value="" <?= $method===''?'selected':'' ?>>All</option>
                  <?php foreach ($methodList as $m): ?>
                    <option value="<?= e($m) ?>" <?= $method===$m?'selected':'' ?>><?= e($m) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Sort</label>
                <select name="sort" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <option value="date"   <?= $sort==='date'?'selected':'' ?>>Date</option>
                  <option value="amount" <?= $sort==='amount'?'selected':'' ?>>Amount</option>
                  <option value="method" <?= $sort==='method'?'selected':'' ?>>Method</option>
                  <option value="status" <?= $sort==='status'?'selected':'' ?>>Status</option>
                  <option value="id"     <?= $sort==='id'?'selected':'' ?>>ID</option>
                </select>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Dir</label>
                <select name="dir" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Desc</option>
                  <option value="asc"  <?= $dir==='asc'?'selected':''  ?>>Asc</option>
                </select>
              </div>
              <div class="lg:col-span-2">
                <label class="block text-gray-600 mb-1">Search</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Reference, student, method" class="w-full border border-gray-200 rounded-lg px-3 py-2">
              </div>
              <div class="flex items-end gap-2">
                <button class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                  <ion-icon name="options-outline"></ion-icon> Apply
                </button>
                <a href="<?= e(buildUrl(['export'=>'csv'])) ?>"
                   class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                  <ion-icon name="download-outline"></ion-icon> CSV
                </a>
                <a href="ceo_payments.php"
                   class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">
                  <ion-icon name="refresh-outline"></ion-icon> Reset
                </a>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Payment Mix + Quick Stats -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <h3 class="text-lg font-bold text-gray-900 inline-flex items-center gap-2 mb-3">
            <ion-icon name="pie-chart-outline" class="text-emerald-600"></ion-icon> Payment Mix (Completed)
          </h3>
          <?php if ($mixValues): ?>
            <canvas id="paymix"></canvas>
          <?php else: ?>
            <div class="text-gray-600 text-sm">No completed payments in this window.</div>
          <?php endif; ?>
        </div>

        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <h3 class="text-lg font-bold text-gray-900 inline-flex items-center gap-2 mb-4">
            <ion-icon name="stats-chart-outline" class="text-blue-600"></ion-icon> Summary (in range)
          </h3>
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-xl ring-1 ring-emerald-200 bg-emerald-50 p-3">
              <div class="text-emerald-700 font-semibold"><?= $CURRENCY . number_format($kpi['revenue'],2) ?></div>
              <div class="text-emerald-800/80">Revenue (completed)</div>
            </div>
            <div class="rounded-xl ring-1 ring-gray-200 bg-gray-50 p-3">
              <div class="text-gray-900 font-semibold"><?= (int)$kpi['completed'] ?></div>
              <div class="text-gray-600">Completed</div>
            </div>
            <div class="rounded-xl ring-1 ring-amber-200 bg-amber-50 p-3">
              <div class="text-amber-700 font-semibold"><?= (int)$kpi['pending'] ?></div>
              <div class="text-amber-800/80">Pending</div>
            </div>
            <div class="rounded-xl ring-1 ring-rose-200 bg-rose-50 p-3">
              <div class="text-rose-700 font-semibold"><?= (int)$kpi['failed'] ?></div>
              <div class="text-rose-800/80">Failed</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50/70 backdrop-blur text-gray-700">
              <tr>
                <th class="px-3 py-2 text-left">Payment</th>
                <th class="px-3 py-2 text-left">Student</th>
                <th class="px-3 py-2 text-left">Method</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-left">Date</th>
                <th class="px-3 py-2 text-right">Amount</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php if ($rows): foreach ($rows as $p):
                $student = (string)($p['student'] ?? '');
                $ini     = initials_from($student, 'U');
                $accent  = colorForId($p['id'], $accentColors);
                $statusBadge = 'bg-gray-100 text-gray-700 ring-1 ring-gray-200';
                if (strtolower((string)$p['status']) === 'completed') $statusBadge = 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200';
                elseif (strtolower((string)$p['status']) === 'pending') $statusBadge = 'bg-amber-50 text-amber-700 ring-1 ring-amber-200';
                elseif (strtolower((string)$p['status']) === 'failed')  $statusBadge = 'bg-rose-50 text-rose-700 ring-1 ring-rose-200';
              ?>
                <tr class="hover:bg-slate-50/60">
                  <td class="px-3 py-3">
                    <div class="flex items-center gap-3">
                      <span class="inline-flex h-9 w-9 items-center justify-center rounded-full text-white text-xs font-bold ring-2 ring-white shadow-sm <?= e($accent) ?>">
                        <?= e(substr((string)$p['id'], -2)) ?>
                      </span>
                      <div class="min-w-0">
                        <div class="font-semibold text-gray-900">#<?= (int)$p['id'] ?></div>
                        <div class="text-[11px] text-gray-500"><?= e($p['ref'] ?: '—') ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="px-3 py-3">
                    <div class="flex items-center gap-3">
                      <span class="inline-flex h-8 w-8 items-center justify-center rounded-full text-white text-[11px] font-bold ring-2 ring-white shadow-sm bg-gradient-to-br from-indigo-500 to-blue-600">
                        <?= e($ini) ?>
                      </span>
                      <span class="truncate"><?= e($student ?: '—') ?></span>
                    </div>
                  </td>
                  <td class="px-3 py-3">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                      <ion-icon name="card-outline"></ion-icon> <?= e($p['method'] ?: '—') ?>
                    </span>
                  </td>
                  <td class="px-3 py-3">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full <?= e($statusBadge) ?>">
                      <?php if (strtolower((string)$p['status'])==='completed'): ?>
                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                      <?php elseif (strtolower((string)$p['status'])==='pending'): ?>
                        <ion-icon name="hourglass-outline"></ion-icon>
                      <?php else: ?>
                        <ion-icon name="close-circle-outline"></ion-icon>
                      <?php endif; ?>
                      <?= e(ucfirst((string)$p['status'])) ?>
                    </span>
                  </td>
                  <td class="px-3 py-3">
                    <?php if (!empty($p['paid_at'])): ?>
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-50 text-slate-700 ring-1 ring-slate-200">
                        <ion-icon name="calendar-outline"></ion-icon>
                        <?= e(date('Y-m-d H:i', strtotime($p['paid_at']))) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-3 text-right font-semibold">
                    <?= $CURRENCY . number_format((float)$p['amount'], 2) ?>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr>
                  <td colspan="6" class="px-3 py-10">
                    <div class="text-center text-gray-600">
                      <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                        <ion-icon name="file-tray-outline" class="text-xl text-gray-500"></ion-icon>
                      </div>
                      No payments found. Try adjusting filters.
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 bg-white border-t border-gray-100">
          <div class="text-xs text-gray-600">
            Page <b><?= (int)$page ?></b> of <b><?= (int)$totalPages ?></b> • Showing
            <b><?= (int)count($rows) ?></b> of <b><?= (int)$filteredCount ?></b>
          </div>
          <div class="flex items-center gap-2 text-sm">
            <a class="px-3 py-1.5 rounded ring-1 ring-gray-200 bg-white hover:bg-gray-50 <?= $page<=1?'pointer-events-none opacity-50':'' ?>"
               href="<?= e(buildUrl(['page'=>$prevPage])) ?>">
               <ion-icon name="chevron-back-outline"></ion-icon> Prev
            </a>
            <a class="px-3 py-1.5 rounded ring-1 ring-gray-200 bg-white hover:bg-gray-50 <?= $page>=$totalPages?'pointer-events-none opacity-50':'' ?>"
               href="<?= e(buildUrl(['page'=>$nextPage])) ?>">
               Next <ion-icon name="chevron-forward-outline"></ion-icon>
            </a>
          </div>
        </div>
      </div>
    </section>
  </div>
</main>

<?php include 'components/footer.php'; ?>

<script>
  // Enable/disable date inputs for custom
  (function(){
    const rangeSel = document.querySelector('select[name="range"]');
    const s = document.querySelector('input[name="start"]');
    const e = document.querySelector('input[name="end"]');
    if (!rangeSel || !s || !e) return;
    function sync(){ const c = rangeSel.value === 'custom'; s.disabled=!c; e.disabled=!c; if(!c){ s.value=''; e.value=''; } }
    rangeSel.addEventListener('change', sync); sync();
  })();

  // Payment mix
  (function(){
    const ctx = document.getElementById('paymix');
    if (!ctx) return;
    const labels = <?= json_encode($mixLabels) ?>;
    const data   = <?= json_encode($mixValues) ?>;
    if (!data.length) return;
    const total = data.reduce((a,b)=>a+b,0)||1;
    new Chart(ctx, {
      type:'doughnut',
      data:{ labels, datasets:[{ data, backgroundColor:['#10b981','#6366f1','#f59e0b','#ef4444','#06b6d4','#84cc16','#a855f7','#14b8a6','#f97316'], borderWidth:0 }] },
      options:{
        plugins:{
          legend:{ position:'bottom' },
          tooltip:{ callbacks:{ label: c => `${c.label}: <?= e($CURRENCY) ?>${(c.parsed||0).toLocaleString()} (${((c.parsed||0)/total*100).toFixed(1)}%)` } }
        },
        cutout:'62%'
      }
    });
  })();
</script>
</body>
</html>