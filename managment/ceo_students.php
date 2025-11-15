<?php
// ceo_students.php

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
  $exists = $col && $col->num_rows > 0;
  if ($col) $col->free();
  return $exists;
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
function initials_from(string $name, string $fallback = 'S'): string {
  $name = trim($name);
  if ($name === '') return $fallback;
  $parts = preg_split('/\s+/u', $name);
  $a = isset($parts[0]) ? mb_substr($parts[0], 0, 1, 'UTF-8') : '';
  $b = isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : '';
  $ini = strtoupper($a . $b);
  return $ini ?: $fallback;
}

/* ===== Detect students schema ===== */
$studentPK      = pickFirstExistingColumn($conn, 'students', ['student_id','id']);
$firstNameCol   = columnExists($conn, 'students', 'first_name') ? 'first_name' : null;
$lastNameCol    = columnExists($conn, 'students', 'last_name')  ? 'last_name'  : null;
$fullNameCol    = columnExists($conn, 'students', 'name') ? 'name' : null;
$emailCol       = pickFirstExistingColumn($conn, 'students', ['email','email_address','mail']);
$phoneCol       = pickFirstExistingColumn($conn, 'students', ['phone','contact_number','mobile','tel','telephone','phone_number']);
$studentDateCol = pickDateColumn($conn, 'students', ['created_at','joined_at','registered_at','added_at','date']);

$enrollStudentCol = pickFKColumn($conn, 'enrollments', 'students', ['student_id','user_id','studentId','learner_id','student']);

/* Name expression */
if ($firstNameCol || $lastNameCol) {
  $nameExpr = "TRIM(CONCAT_WS(' ', s.`$firstNameCol`, s.`$lastNameCol`))";
} elseif ($fullNameCol) {
  $nameExpr = "s.`$fullNameCol`";
} else {
  $nameExpr = "CONCAT('Student #', s.`$studentPK`)";
}

/* ===== Filters, search, sort, pagination ===== */
$q        = trim($_GET['q'] ?? '');
$sort     = strtolower($_GET['sort'] ?? ($studentDateCol ? 'joined' : 'name')); // name|joined|enrollments
$dir      = strtolower($_GET['dir'] ?? 'desc'); // asc|desc
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 20)));

$startJoin = $_GET['start'] ?? '';
$endJoin   = $_GET['end']   ?? '';

$allowedSort = ['name','joined','enrollments'];
if (!in_array($sort, $allowedSort, true)) $sort = 'name';
if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

$offset = ($page - 1) * $perPage;

/* ===== Build dynamic WHERE and params ===== */
$where = [];
$params = [];
$types  = '';

if ($q !== '') {
  $qLike = '%' . $q . '%';
  $nameCond = "$nameExpr LIKE ?";
  $where[] = '(' . $nameCond
          .  ($emailCol ? " OR s.`$emailCol` LIKE ?" : '')
          .  ($phoneCol ? " OR s.`$phoneCol` LIKE ?" : '')
          . ')';
  $params[] = $qLike; $types .= 's';
  if ($emailCol) { $params[] = $qLike; $types .= 's'; }
  if ($phoneCol) { $params[] = $qLike; $types .= 's'; }
}
if ($studentDateCol && $startJoin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startJoin)) {
  $where[] = "s.`$studentDateCol` >= ?";
  $params[] = $startJoin; $types .= 's';
}
if ($studentDateCol && $endJoin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endJoin)) {
  $where[] = "s.`$studentDateCol` <= ?";
  $params[] = $endJoin; $types .= 's';
}
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ===== Enrollments aggregate (optional) ===== */
$joinEnrollAgg = '';
$enrollSelect  = '0 AS enrollments';
if ($enrollStudentCol && $studentPK) {
  $joinEnrollAgg = "LEFT JOIN (
      SELECT e.`$enrollStudentCol` AS sid, COUNT(*) AS cnt
      FROM enrollments e
      GROUP BY e.`$enrollStudentCol`
    ) ec ON ec.sid = s.`$studentPK`";
  $enrollSelect = "IFNULL(ec.cnt,0) AS enrollments";
}

/* ===== Export CSV ===== */
function buildUrl(array $merge): string {
  $base = $_GET;
  foreach ($merge as $k=>$v) $base[$k] = $v;
  return '?' . http_build_query($base);
}
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=students_' . date('Ymd_His') . '.csv');

  $selectCSV = "SELECT s.`$studentPK` AS sid,
                       $nameExpr AS name" .
               ($emailCol ? ", s.`$emailCol` AS email" : ", NULL AS email") .
               ($phoneCol ? ", s.`$phoneCol` AS phone" : ", NULL AS phone") .
               ($studentDateCol ? ", s.`$studentDateCol` AS joined" : ", NULL AS joined") .
               ", $enrollSelect
                FROM students s
                $joinEnrollAgg
                $whereSQL
                ORDER BY " . ($sort === 'name' ? "name" : ($sort === 'joined' && $studentDateCol ? "s.`$studentDateCol`" : "enrollments")) . " $dir";

  $stmt = $conn->prepare($selectCSV);
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Name','Email','Phone','Joined','Enrollments']);
  while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
      $row['sid'],
      $row['name'],
      $row['email'],
      $row['phone'],
      $row['joined'],
      $row['enrollments'],
    ]);
  }
  fclose($out);
  exit;
}

/* ===== Count totals (filtered + all) ===== */
$totalStudents = (int)($conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'] ?? 0);

$countSQL = "SELECT COUNT(*) AS c FROM students s $whereSQL";
$stmtC = $conn->prepare($countSQL);
if ($types) $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$resC = $stmtC->get_result();
$filteredCount = (int)($resC->fetch_assoc()['c'] ?? 0);
$stmtC->close();

/* ===== Fetch page results ===== */
$orderBy = $sort === 'name' ? "name" : ($sort === 'joined' && $studentDateCol ? "s.`$studentDateCol`" : "enrollments");
$limit   = (int)$perPage;
$offsetI = (int)$offset;

$selectSQL = "SELECT s.`$studentPK` AS sid,
                     $nameExpr AS name" .
             ($emailCol ? ", s.`$emailCol` AS email" : ", NULL AS email") .
             ($phoneCol ? ", s.`$phoneCol` AS phone" : ", NULL AS phone") .
             ($studentDateCol ? ", s.`$studentDateCol` AS joined" : ", NULL AS joined") .
             ", $enrollSelect
              FROM students s
              $joinEnrollAgg
              $whereSQL
              ORDER BY $orderBy $dir
              LIMIT $limit OFFSET $offsetI";

$stmt = $conn->prepare($selectSQL);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) $rows[] = $r;
$stmt->close();

/* Quick on-page average enrollments */
$avgEnrollPage = 0;
if ($rows) {
  $sum = 0; $n = 0;
  foreach ($rows as $r) { $sum += (int)($r['enrollments'] ?? 0); $n++; }
  $avgEnrollPage = $n ? $sum / $n : 0;
}

/* ===== Sidebar component setup ===== */
$keepQuery    = ['q','sort','dir','page','per_page','start','end']; // preserved keys in sidebar links
$sidebarId    = 'ceoSidebar';
$toggleId     = 'ceoSbToggle';
$sidebarTitle = 'Menu';

/* Accent helpers for avatars */
$accentColors = ['bg-gradient-to-br from-indigo-500 to-blue-600','bg-gradient-to-br from-emerald-500 to-teal-600','bg-gradient-to-br from-violet-500 to-fuchsia-600','bg-gradient-to-br from-amber-500 to-orange-600','bg-gradient-to-br from-sky-500 to-cyan-600'];
function colorForId($id, $colors){ return $colors[(int)$id % max(count($colors),1)]; }

/* ===== Pagination helpers ===== */
$totalPages = max(1, (int)ceil($filteredCount / $perPage));
$prevPage   = max(1, $page - 1);
$nextPage   = min($totalPages, $page + 1);
$qs = $_GET ? ('?' . http_build_query($_GET)) : '';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEO · Students</title>
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
          <div class="absolute -top-20 -right-14 w-64 h-64 rounded-full bg-blue-200/40 blur-3xl"></div>
          <div class="absolute -bottom-20 -left-24 w-80 h-80 rounded-full bg-indigo-200/40 blur-3xl"></div>
        </div>
        <div class="relative">
          <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
              <h1 class="text-2xl sm:text-3xl font-extrabold text-blue-700 inline-flex items-center gap-2">
                <ion-icon name="school-outline"></ion-icon> Students
              </h1>
              <p class="text-gray-600 mt-1 text-sm">Manage and explore student records.</p>

              <!-- Quick stats chips -->
              <div class="mt-3 flex flex-wrap gap-3 text-xs">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                  <ion-icon name="grid-outline"></ion-icon> Total: <b><?= (int)$totalStudents ?></b>
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-violet-50 text-violet-700 ring-1 ring-violet-200">
                  <ion-icon name="filter-outline"></ion-icon> Filtered: <b><?= (int)$filteredCount ?></b>
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                  <ion-icon name="library-outline"></ion-icon> Avg enroll (page): <b><?= number_format($avgEnrollPage,1) ?></b>
                </span>
              </div>
            </div>

            <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-7 gap-2 text-sm">
              <div class="lg:col-span-3">
                <label class="block text-gray-600 mb-1">Search</label>
                <div class="relative">
                  <ion-icon name="search-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name, email, phone"
                         class="w-full border border-gray-200 rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200" />
                </div>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Sort</label>
                <select name="sort" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <option value="name" <?= $sort==='name'?'selected':'' ?>>Name</option>
                  <option value="joined" <?= $sort==='joined'?'selected':'' ?> <?= $studentDateCol ? '' : 'disabled' ?>>Joined</option>
                  <option value="enrollments" <?= $sort==='enrollments'?'selected':'' ?> <?= $enrollStudentCol ? '' : 'disabled' ?>>Enrollments</option>
                </select>
              </div>
              <div>
                <label class="block text-gray-600 mb-1">Direction</label>
                <select name="dir" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                  <option value="asc"  <?= $dir==='asc'?'selected':'' ?>>Ascending</option>
                  <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Descending</option>
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
              <div class="lg:col-span-2">
                <label class="block text-gray-600 mb-1">Joined From</label>
                <input type="date" name="start" value="<?= e($startJoin) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2" <?= $studentDateCol ? '' : 'disabled' ?> />
              </div>
              <div class="lg:col-span-2">
                <label class="block text-gray-600 mb-1">Joined To</label>
                <input type="date" name="end" value="<?= e($endJoin) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2" <?= $studentDateCol ? '' : 'disabled' ?> />
              </div>
              <div class="flex items-end gap-2">
                <button class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                  <ion-icon name="options-outline"></ion-icon> Apply
                </button>
                <a href="<?= e(buildUrl(['export'=>'csv'])) ?>"
                   class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                  <ion-icon name="download-outline"></ion-icon> CSV
                </a>
                <a href="ceo_students.php"
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
                <th class="px-3 py-2 text-left">Student</th>
                <th class="px-3 py-2 text-left">Email</th>
                <th class="px-3 py-2 text-left">Phone</th>
                <th class="px-3 py-2 text-left">Joined</th>
                <th class="px-3 py-2 text-right">Enrollments</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php if ($rows): foreach ($rows as $st):
                $name = (string)($st['name'] ?? '');
                $ini  = initials_from($name, 'S');
                $accent = colorForId($st['sid'], $accentColors);
              ?>
                <tr class="hover:bg-slate-50/60">
                  <td class="px-3 py-3">
                    <div class="flex items-center gap-3">
                      <span class="inline-flex h-9 w-9 items-center justify-center rounded-full text-white text-xs font-bold ring-2 ring-white shadow-sm <?= e($accent) ?>">
                        <?= e($ini) ?>
                      </span>
                      <div class="min-w-0">
                        <div class="font-semibold text-gray-900 truncate"><?= e($name) ?></div>
                        <div class="text-[11px] text-gray-500">ID: <?= (int)$st['sid'] ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="px-3 py-3"><?= e($st['email'] ?? '') ?></td>
                  <td class="px-3 py-3"><?= e($st['phone'] ?? '') ?></td>
                  <td class="px-3 py-3">
                    <?php if (!empty($st['joined'])): ?>
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                        <ion-icon name="calendar-outline"></ion-icon>
                        <?= e(date('Y-m-d', strtotime($st['joined']))) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-3 text-right">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                      <ion-icon name="library-outline"></ion-icon>
                      <?= (int)($st['enrollments'] ?? 0) ?>
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
                      No students found. Try adjusting filters.
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
</body>
</html>