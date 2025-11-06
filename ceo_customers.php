<?php
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
    $sql = "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME=? LIMIT 1";
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

/* Range filter */
$allowedRanges = ['30d'=>'Last 30 days','90d'=>'Last 90 days','12m'=>'Last 12 months','ytd'=>'Year to date','all'=>'All time'];
$range = $_GET['range'] ?? '90d';
if (!isset($allowedRanges[$range])) $range = '90d';

/* Date windows */
$today = new DateTime('today');
$tomorrow = (clone $today)->modify('+1 day'); // exclusive
switch ($range) {
  case '30d': $start = (clone $today)->modify('-29 days'); break;
  case '90d': $start = (clone $today)->modify('-89 days'); break;
  case '12m': $start = (clone $today)->modify('-11 months')->modify('first day of this month'); break;
  case 'ytd': $start = new DateTime(date('Y-01-01')); break;
  case 'all': default: $start = new DateTime('1970-01-01'); break;
}
$startDate = $start->format('Y-m-d');
$endDate   = $tomorrow->format('Y-m-d');

/* Scope: lifetime or range */
$scope = $_GET['top'] ?? 'lifetime';
if (!in_array($scope, ['lifetime','range'], true)) $scope = 'lifetime';

/* Schema detection */
$studentPK     = pickFirstExistingColumn($conn, 'students', ['student_id','id']);
$payStudentCol = pickFKColumn($conn, 'student_payments', 'students', ['student_id','user_id','studentId','learner_id','student']);

/* Build name expression */
$nameExpr = "CONCAT('Student #', s.`" . ($studentPK ?? 'id') . "`)";
if ($studentPK) {
  $hasFirst = columnExists($conn, 'students', 'first_name');
  $hasLast  = columnExists($conn, 'students', 'last_name');
  $hasName  = columnExists($conn, 'students', 'name');
  if ($hasFirst || $hasLast) $nameExpr = "CONCAT_WS(' ', s.first_name, s.last_name)";
  elseif ($hasName)          $nameExpr = "s.name";
}

/* Fetch data */
$rows = [];
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;

if ($payStudentCol && $studentPK) {
  if ($scope === 'range') {
    $sql = "SELECT {$nameExpr} AS name, IFNULL(SUM(sp.amount),0) AS total
            FROM student_payments sp
            JOIN students s ON s.`{$studentPK}` = sp.`{$payStudentCol}`
            WHERE sp.payment_status='completed' AND sp.paid_at >= ? AND sp.paid_at < ?
            GROUP BY s.`{$studentPK}`
            ORDER BY total DESC
            LIMIT {$limit}";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
  } else {
    $sql = "SELECT {$nameExpr} AS name, IFNULL(SUM(sp.amount),0) AS total
            FROM student_payments sp
            JOIN students s ON s.`{$studentPK}` = sp.`{$payStudentCol}`
            WHERE sp.payment_status='completed'
            GROUP BY s.`{$studentPK}`
            ORDER BY total DESC
            LIMIT {$limit}";
    $stmt = $conn->prepare($sql);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) $rows[] = $row;
  $stmt->close();
}

$schemaWarnings = [];
if (!$payStudentCol) $schemaWarnings[] = "student_payments student FK not found";
if (!$studentPK)     $schemaWarnings[] = "students PK column not found";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Top Customers · CEO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>
<body class="bg-gray-50 min-h-screen text-gray-800">
<?php include 'components/navbar.php'; ?>

<main class="max-w-7xl mx-auto px-6 py-10">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-extrabold text-blue-700 inline-flex items-center gap-2">
      <ion-icon name="podium-outline"></ion-icon> Top Customers
    </h1>
    <form method="get" class="flex gap-2 text-sm items-center">
      <select name="top" class="border rounded px-2 py-1">
        <option value="lifetime" <?= $scope==='lifetime'?'selected':'' ?>>Lifetime</option>
        <option value="range"    <?= $scope==='range'   ?'selected':'' ?>>In Range</option>
      </select>
      <select name="range" class="border rounded px-2 py-1">
        <?php foreach ($allowedRanges as $k=>$label): ?>
          <option value="<?= e($k) ?>" <?= $k===$range?'selected':'' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" name="limit" min="1" max="100" value="<?= (int)$limit ?>" class="border rounded px-2 py-1 w-24" title="Rows">
      <button class="px-3 py-1 rounded bg-blue-600 text-white">Apply</button>
    </form>
  </div>

  <?php if ($schemaWarnings): ?>
    <div class="mb-4 rounded border border-amber-300 bg-amber-50 text-amber-800 p-3 text-sm">
      <b>Schema warnings:</b> <?= e(implode('; ', $schemaWarnings)) ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl ring-1 ring-gray-200 p-6">
    <?php if ($rows): ?>
    <table class="min-w-full table-auto text-sm">
      <thead class="bg-gray-100 text-gray-700">
        <tr><th class="px-3 py-2 text-left">Student</th><th class="px-3 py-2 text-right">Total Revenue</th></tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="px-3 py-2"><?= e($row['name']) ?></td>
            <td class="px-3 py-2 text-right font-semibold">$<?= number_format((float)$row['total'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="text-gray-600 text-sm">No payment data<?= $scope==='range' ? ' in this range' : '' ?>.</div>
    <?php endif; ?>
  </div>
</main>

<?php include 'components/footer.php'; ?>
</body>
</html>