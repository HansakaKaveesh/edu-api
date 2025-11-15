<?php
// ceo_courses.php

session_start();
include __DIR__ . '/../db_connect.php';

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
function initials_from(string $name, string $fallback='C'): string {
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

/* ===== Range filter (enrollments & payments window) ===== */
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

/* ===== Detect schema ===== */
$coursePK        = pickFirstExistingColumn($conn, 'courses', ['course_id','id']);
$courseTitleCol  = pickFirstExistingColumn($conn, 'courses', ['name','title','course_name']);
$courseDateCol   = pickFirstExistingColumn($conn, 'courses', ['created_at','published_at','added_at','date']);

/* Enrollments (optional, for counts and fallback created) */
$enrollDateCol   = pickFirstExistingColumn($conn, 'enrollments', ['enrolled_at','created_at','added_at','date']);
$enrollCourseCol = pickFKColumn($conn, 'enrollments', 'courses', ['course_id','courseId','class_id','module_id','course']);
$enrollUserCol   = pickFirstExistingColumn($conn, 'enrollments', ['user_id','student_id','userId','studentId']);

/* Teachers via map table */
$tcCourseCol     = pickFKColumn($conn, 'teacher_courses', 'courses',  ['course_id']);
$tcTeacherCol    = pickFKColumn($conn, 'teacher_courses', 'teachers', ['teacher_id']);
$teacherPK       = pickFirstExistingColumn($conn, 'teachers', ['teacher_id','id']);
$tFirst          = columnExists($conn, 'teachers','first_name') ? 'first_name' : null;
$tLast           = columnExists($conn, 'teachers','last_name')  ? 'last_name'  : null;
$tFull           = columnExists($conn, 'teachers','name') ? 'name' : null;

/* Student payments (optional, for revenue in range) */
$payAmountCol  = pickFirstExistingColumn($conn, 'student_payments', ['amount','paid_amount','total_amount']);
$payStatusCol  = pickFirstExistingColumn($conn, 'student_payments', ['payment_status','status']);
$payDateCol    = pickFirstExistingColumn($conn, 'student_payments', ['paid_at','created_at','date']);
$payCourseCol  = pickFKColumn($conn, 'student_payments', 'courses', ['course_id','courseId','class_id','course']); // direct FK if present
$payStudentCol = pickFirstExistingColumn($conn, 'student_payments', ['student_id','user_id']);

/* Title expr */
$titleExpr = $courseTitleCol ? "c.`$courseTitleCol`" : "CONCAT('Course #', c.`$coursePK`)";

/* Teacher expr (row-wise) for WHERE (not aggregated) */
if ($tFirst || $tLast) {
  $teacherRowExpr = "TRIM(CONCAT_WS(' ', t.`$tFirst`, t.`$tLast`))";
} elseif ($tFull) {
  $teacherRowExpr = "t.`$tFull`";
} else {
  $teacherRowExpr = "NULL";
}

/* ===== Filters, search, sort, pagination ===== */
$q        = trim($_GET['q'] ?? '');
$sort     = strtolower($_GET['sort'] ?? 'enrollments'); // enrollments|name|created|amount
$dir      = strtolower($_GET['dir'] ?? 'desc');         // asc|desc
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
$offset   = ($page - 1) * $perPage;

$allowedSort = ['enrollments','name','created','amount'];
if (!in_array($sort, $allowedSort, true)) $sort = 'enrollments';
if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

/* ===== WHERE (search title or teacher) ===== */
$where = [];
$params = [];
$types  = '';

if ($q !== '') {
  $qLike = '%' . $q . '%';
  $parts = [];
  $parts[] = "$titleExpr LIKE ?";
  $params[] = $qLike; $types .= 's';
  // teacher search (non-aggregated expr, will still work with GROUP BY)
  if ($tcCourseCol && $tcTeacherCol && $teacherPK && $teacherRowExpr !== "NULL") {
    $parts[] = "$teacherRowExpr LIKE ?";
    $params[] = $qLike; $types .= 's';
  }
  $where[] = '(' . implode(' OR ', $parts) . ')';
}
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ===== Joins ===== */

/* Teacher join (many-to-many) */
$teacherJoin = ($tcCourseCol && $tcTeacherCol && $teacherPK)
  ? "LEFT JOIN teacher_courses tc ON tc.`$tcCourseCol` = c.`$coursePK`
     LEFT JOIN teachers t ON t.`$teacherPK` = tc.`$tcTeacherCol`"
  : "";

/* Enrollments aggregate (count in selected range) */
$useEnrollAgg = ($enrollDateCol && $enrollCourseCol && $coursePK);
$enrollAgg = $useEnrollAgg
  ? "LEFT JOIN (
       SELECT e.`$enrollCourseCol` AS cid, COUNT(*) AS cnt
       FROM enrollments e
       WHERE e.`$enrollDateCol` >= ? AND e.`$enrollDateCol` < ?
       GROUP BY e.`$enrollCourseCol`
     ) ec ON ec.cid = c.`$coursePK`"
  : "";

/* Payments aggregate (sum amount in selected range)
   - Preferred: student_payments has course_id
   - Fallback: join payments to enrollments via student_id/user_id, then group by course */
$usePaymentsAgg = ($payAmountCol && $payDateCol && $coursePK && ($payCourseCol || ($payStudentCol && $enrollUserCol && $enrollCourseCol)));
$paymentsAgg = '';
if ($usePaymentsAgg) {
  $statusCond = $payStatusCol ? " AND p.`$payStatusCol`='completed'" : "";
  if ($payCourseCol) {
    // direct: payments -> course
    $paymentsAgg = "LEFT JOIN (
        SELECT p.`$payCourseCol` AS cid, SUM(p.`$payAmountCol`) AS rev
        FROM student_payments p
        WHERE p.`$payDateCol` >= ? AND p.`$payDateCol` < ? $statusCond
        GROUP BY p.`$payCourseCol`
      ) pr ON pr.cid = c.`$coursePK`";
  } else {
    // fallback: payments -> enrollments (via student/user id) -> course
    $paymentsAgg = "LEFT JOIN (
        SELECT e2.`$enrollCourseCol` AS cid, SUM(p.`$payAmountCol`) AS rev
        FROM student_payments p
        JOIN enrollments e2 ON e2.`$enrollUserCol` = p.`$payStudentCol`
        WHERE p.`$payDateCol` >= ? AND p.`$payDateCol` < ? $statusCond
        GROUP BY e2.`$enrollCourseCol`
      ) pr ON pr.cid = c.`$coursePK`";
  }
}

/* Created fallback (earliest enrollment across ALL time) if courses.created_at not present */
$createdFallbackJoin = (!$courseDateCol && $useEnrollAgg)
  ? "LEFT JOIN (
       SELECT e.`$enrollCourseCol` AS cid, MIN(e.`$enrollDateCol`) AS first_enroll
       FROM enrollments e
       GROUP BY e.`$enrollCourseCol`
     ) e0 ON e0.cid = c.`$coursePK`"
  : "";

/* Created expression */
$createdExpr = $courseDateCol ? "c.`$courseDateCol`" : ($useEnrollAgg ? "e0.first_enroll" : "NULL");

/* ===== Count filtered courses ===== */
$countSQL = "SELECT COUNT(DISTINCT c.`$coursePK`) AS c
             FROM courses c
             $teacherJoin
             $enrollAgg
             $paymentsAgg
             $createdFallbackJoin
             $whereSQL";
$stC = $conn->prepare($countSQL);

// Bind order: enrollAgg dates, paymentsAgg dates, then search params
$bindTypes = '';
$bindVals  = [];
if ($useEnrollAgg)   { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
if ($usePaymentsAgg) { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
if ($types)          { $bindTypes .= $types; array_push($bindVals, ...$params); }

if ($bindTypes !== '') $stC->bind_param($bindTypes, ...$bindVals);
$stC->execute();
$resC = $stC->get_result();
$filteredCount = (int)($resC->fetch_assoc()['c'] ?? 0);
$stC->close();

/* Total courses */
$totalCourses = (int)($conn->query("SELECT COUNT(*) AS c FROM courses")->fetch_assoc()['c'] ?? 0);

/* ===== Sum enrollments across filtered (range) ===== */
$totalEnrollments = 0;
if ($useEnrollAgg) {
  $sumSQL = "SELECT IFNULL(SUM(ec.cnt),0) AS s
             FROM courses c
             $teacherJoin
             $enrollAgg
             $paymentsAgg
             $createdFallbackJoin
             $whereSQL";
  $stS = $conn->prepare($sumSQL);
  $bindTypes = '';
  $bindVals  = [];
  if ($useEnrollAgg)   { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
  if ($usePaymentsAgg) { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
  if ($types)          { $bindTypes .= $types; array_push($bindVals, ...$params); }
  if ($bindTypes !== '') $stS->bind_param($bindTypes, ...$bindVals);
  $stS->execute();
  $rsS = $stS->get_result();
  $totalEnrollments = (int)($rsS->fetch_assoc()['s'] ?? 0);
  $stS->close();
}

/* ===== Sum revenue across filtered (range) ===== */
$totalRevenue = 0.0;
if ($usePaymentsAgg) {
  $sumRevSQL = "SELECT IFNULL(SUM(pr.rev),0) AS s
                FROM courses c
                $teacherJoin
                $enrollAgg
                $paymentsAgg
                $createdFallbackJoin
                $whereSQL";
  $stR = $conn->prepare($sumRevSQL);
  $bindTypes = '';
  $bindVals  = [];
  if ($useEnrollAgg)   { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
  if ($usePaymentsAgg) { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
  if ($types)          { $bindTypes .= $types; array_push($bindVals, ...$params); }
  if ($bindTypes !== '') $stR->bind_param($bindTypes, ...$bindVals);
  $stR->execute();
  $rsR = $stR->get_result();
  $totalRevenue = (float)($rsR->fetch_assoc()['s'] ?? 0);
  $stR->close();
}

/* ===== Fetch page results ===== */
$orderBy = "IFNULL(ec.cnt,0)";
if ($sort === 'name')    $orderBy = $courseTitleCol ? "c.`$courseTitleCol`" : "c.`$coursePK`";
if ($sort === 'created') $orderBy = $courseDateCol ? "c.`$courseDateCol`" : ($useEnrollAgg ? "e0.first_enroll" : "c.`$coursePK`");
if ($sort === 'amount')  $orderBy = $usePaymentsAgg ? "IFNULL(pr.rev,0)" : "0";

$selectSQL = "SELECT
                c.`$coursePK` AS id,
                $titleExpr AS title,
                $createdExpr AS created,
                IFNULL(ec.cnt,0) AS enrollments,
                " . ($usePaymentsAgg ? "IFNULL(pr.rev,0)" : "NULL") . " AS revenue,
                /* aggregate all teachers per course */
                " . ($teacherJoin
                      ? "GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', " .
                          ($tFirst ? "t.`$tFirst`" : "NULL") . ", " . ($tLast ? "t.`$tLast`" : "NULL") .
                        ")) ORDER BY " . ($tFirst ? "t.`$tFirst`" : "t.`$teacherPK`") . " SEPARATOR ', ')"
                      : "NULL") . " AS teachers
              FROM courses c
              $teacherJoin
              $enrollAgg
              $paymentsAgg
              $createdFallbackJoin
              $whereSQL
              GROUP BY c.`$coursePK`
              ORDER BY $orderBy $dir
              LIMIT ? OFFSET ?";
$st = $conn->prepare($selectSQL);

// Bind: enroll dates, payment dates, search, then limit/offset
$bindTypes = '';
$bindVals  = [];
if ($useEnrollAgg)   { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
if ($usePaymentsAgg) { $bindTypes .= 'ss'; $bindVals[] = $startDate; $bindVals[] = $endDate; }
if ($types)          { $bindTypes .= $types; array_push($bindVals, ...$params); }
$bindTypes .= 'ii';   array_push($bindVals, (int)$perPage, (int)$offset);
$st->bind_param($bindTypes, ...$bindVals);

$st->execute();
$res = $st->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$st->close();

/* ===== CSV Export ===== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=courses_' . date('Ymd_His') . '.csv');
  $csv = fopen('php://output', 'w');
  fputcsv($csv, ['ID','Course','Teacher(s)','Created','Enrollments (range)','Amount (range)']);
  $csvSQL = "SELECT
                c.`$coursePK` AS id,
                $titleExpr AS title,
                $createdExpr AS created,
                IFNULL(ec.cnt,0) AS enrollments,
                " . ($usePaymentsAgg ? "IFNULL(pr.rev,0)" : "NULL") . " AS revenue,
                " . ($teacherJoin
                      ? "GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', " .
                          ($tFirst ? "t.`$tFirst`" : "NULL") . ", " . ($tLast ? "t.`$tLast`" : "NULL") .
                        ")) ORDER BY " . ($tFirst ? "t.`$tFirst`" : "t.`$teacherPK`") . " SEPARATOR ', ')"
                      : "NULL") . " AS teachers
             FROM courses c
             $teacherJoin
             $enrollAgg
             $paymentsAgg
             $createdFallbackJoin
             $whereSQL
             GROUP BY c.`$coursePK`
             ORDER BY $orderBy $dir";
  $stx = $conn->prepare($csvSQL);

  // Bind order: enroll dates, payment dates, then search
  $csvBindTypes = '';
  $csvVals = [];
  if ($useEnrollAgg)   { $csvBindTypes .= 'ss'; $csvVals[]=$startDate; $csvVals[]=$endDate; }
  if ($usePaymentsAgg) { $csvBindTypes .= 'ss'; $csvVals[]=$startDate; $csvVals[]=$endDate; }
  if ($types)          { $csvBindTypes .= $types; array_push($csvVals, ...$params); }
  if ($csvBindTypes !== '') $stx->bind_param($csvBindTypes, ...$csvVals);

  $stx->execute();
  $rsx = $stx->get_result();
  while ($row = $rsx->fetch_assoc()) {
    fputcsv($csv, [$row['id'], $row['title'], $row['teachers'], $row['created'], $row['enrollments'], $row['revenue']]);
  }
  fclose($csv);
  exit;
}

/* ===== Sidebar component setup ===== */
$keepQuery    = ['q','range','start','end','sort','dir','page','per_page']; // preserve on links
$sidebarId    = 'ceoSidebar';
$toggleId     = 'ceoSbToggle';
$sidebarTitle = 'Menu';

/* Pagination helpers */
$totalPages = max(1, (int)ceil($filteredCount / $perPage));
$prevPage   = max(1, $page - 1);
$nextPage   = min($totalPages, $page + 1);
$qs = $_GET ? ('?' . http_build_query($_GET)) : '';

/* Accent colors for course avatars */
$accentColors = ['bg-gradient-to-br from-indigo-500 to-blue-600','bg-gradient-to-br from-emerald-500 to-teal-600','bg-gradient-to-br from-violet-500 to-fuchsia-600','bg-gradient-to-br from-amber-500 to-orange-600','bg-gradient-to-br from-sky-500 to-cyan-600'];
function colorForId($id, $colors){ return $colors[(int)$id % max(count($colors),1)]; }

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEO · Top Courses</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}</style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen flex flex-col antialiased">
<?php include __DIR__ . '/../components/navbar.php'; ?>

<main class="max-w-8xl mx-auto px-6 py-28 flex-grow">
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Sidebar -->
    <aside class="lg:col-span-3 lg:sticky lg:top-28 self-start">
      <?php include __DIR__ . '/../components/ceo_sidebar.php'; ?>
    </aside>

    <!-- Content -->
    <section class="lg:col-span-9 space-y-6">
      <!-- Header / Filters -->
      <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
          <div class="absolute -top-20 -right-16 w-64 h-64 rounded-full bg-blue-200/40 blur-3xl"></div>
          <div class="absolute -bottom-24 -left-20 w-80 h-80 rounded-full bg-indigo-200/40 blur-3xl"></div>
        </div>
        <div class="relative">
          <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
              <h1 class="text-2xl sm:text-3xl font-extrabold text-blue-700 inline-flex items-center gap-2">
                <ion-icon name="trophy-outline"></ion-icon> Top Courses
              </h1>
              <p class="text-gray-600 mt-1 text-sm">Teacher names, Created, and Amount are now populated.</p>
              <!-- Quick chips -->
              <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                  <ion-icon name="grid-outline"></ion-icon> Total: <b><?= (int)$totalCourses ?></b>
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-violet-50 text-violet-700 ring-1 ring-violet-200">
                  <ion-icon name="filter-outline"></ion-icon> Filtered: <b><?= (int)$filteredCount ?></b>
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                  <ion-icon name="library-outline"></ion-icon> Enrollments (range): <b><?= number_format($totalEnrollments) ?></b>
                </span>
                <?php if ($usePaymentsAgg): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                  <ion-icon name="cash-outline"></ion-icon> Amount (range): <b><?= number_format((float)$totalRevenue, 2) ?></b>
                </span>
                <?php endif; ?>
              </div>
            </div>

            <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-9 gap-2 text-sm">
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
              <div class="lg:col-span-3">
                <label class="block text-gray-600 mb-1">Search</label>
                <div class="relative">
                  <ion-icon name="search-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Course or teacher"
                        class="w-full border border-gray-200 rounded-lg pl-9 pr-3 py-2">
                </div>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Sort</label>
                <select name="sort" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <option value="enrollments" <?= $sort==='enrollments'?'selected':'' ?>>Enrollments</option>
                  <option value="name"        <?= $sort==='name'?'selected':'' ?>>Name</option>
                  <option value="created"     <?= $sort==='created'?'selected':'' ?>>Created</option>
                  <option value="amount"      <?= $sort==='amount'?'selected':'' ?>>Amount</option>
                </select>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Dir</label>
                <select name="dir" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Desc</option>
                  <option value="asc"  <?= $dir==='asc'?'selected':''  ?>>Asc</option>
                </select>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Per page</label>
                <select name="per_page" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <?php foreach ([10,20,50,100] as $pp): ?>
                    <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="flex items-end gap-2">
                <button class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                  <ion-icon name="options-outline"></ion-icon> Apply
                </button>
                <a href="<?= e(buildUrl(['export'=>'csv'])) ?>"
                   class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                  <ion-icon name="download-outline"></ion-icon> CSV
                </a>
                <a href="ceo_courses.php"
                   class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">
                   <ion-icon name="refresh-outline"></ion-icon> Reset
                </a>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50/70 backdrop-blur text-gray-700">
              <tr>
                <th class="px-3 py-2 text-left">Course</th>
                <th class="px-3 py-2 text-left">Teacher(s)</th>
                <th class="px-3 py-2 text-left">Created</th>
                <th class="px-3 py-2 text-right">Amount received</th>
                <th class="px-3 py-2 text-right">Enrollments</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php if ($rows): foreach ($rows as $c):
                $title    = (string)($c['title'] ?? '');
                $ini      = initials_from($title, 'C');
                $accent   = colorForId($c['id'], $accentColors);
                $teachers = (string)($c['teachers'] ?? '');
                $created  = $c['created'] ? date('Y-m-d', strtotime($c['created'])) : '—';
              ?>
                <tr class="hover:bg-slate-50/60">
                  <td class="px-3 py-3">
                    <div class="flex items-center gap-3">
                      <span class="inline-flex h-9 w-9 items-center justify-center rounded-full text-white text-xs font-bold ring-2 ring-white shadow-sm <?= e($accent) ?>">
                        <?= e($ini) ?>
                      </span>
                      <div class="min-w-0">
                        <div class="font-semibold text-gray-900 truncate"><?= e($title ?: ('Course #'.$c['id'])) ?></div>
                        <div class="text-[11px] text-gray-500">ID: <?= (int)$c['id'] ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="px-3 py-3"><?= e($teachers ?: '—') ?></td>
                  <td class="px-3 py-3">
                    <?php if ($created !== '—'): ?>
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                        <ion-icon name="calendar-outline"></ion-icon>
                        <?= e($created) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-3 text-right">
                    <?php if ($usePaymentsAgg): ?>
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                        <ion-icon name="cash-outline"></ion-icon>
                        <?= number_format((float)($c['revenue'] ?? 0), 2) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-3 text-right">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                      <ion-icon name="people-outline"></ion-icon>
                      <?= (int)$c['enrollments'] ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr>
                  <td colspan="5" class="px-3 py-10">
                    <div class="text-center text-gray-600">
                      <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                        <ion-icon name="file-tray-outline" class="text-xl text-gray-500"></ion-icon>
                      </div>
                      No course data found. Try adjusting filters.
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

<?php include __DIR__ . '/../components/footer.php'; ?>

<script>
  // Toggle date inputs when range = custom
  (function(){
    const rangeSel = document.querySelector('select[name="range"]');
    const s = document.querySelector('input[name="start"]');
    const e = document.querySelector('input[name="end"]');
    if (!rangeSel || !s || !e) return;
    function sync(){
      const c = rangeSel.value === 'custom';
      s.disabled = !c; e.disabled = !c;
      if (!c) { s.value=''; e.value=''; }
    }
    rangeSel.addEventListener('change', sync); sync();
  })();
</script>
</body>
</html>