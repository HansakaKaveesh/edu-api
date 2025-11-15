<?php
require_once __DIR__.'/db_connect.php'; // $conn (mysqli)
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* Helpers */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function csrf_token(): string { return $_SESSION['csrf_token']; }
function verify_csrf(string $t): bool { return hash_equals($_SESSION['csrf_token'] ?? '', $t); }

/* Auth: accountant role required */
function require_accountant(mysqli $conn) {
  if (empty($_SESSION['user_id'])) { header('Location: accountant_login.php'); exit; }
  $uid = (int)$_SESSION['user_id'];
  $stmt = $conn->prepare("SELECT role, status FROM users WHERE user_id = ?");
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $stmt->bind_result($role, $status);
  $stmt->fetch();
  $stmt->close();
  if ($status !== 'active' || $role !== 'accountant') {
    http_response_code(403);
    exit('Forbidden');
  }
}
require_accountant($conn);

/* Flash */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* Download CSV template */
if (isset($_GET['download_template'])) {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="teacher_payments_template.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['teacher_id','course_id','lesson_count','rate_per_lesson','amount','reference_code','paid_at']);
  // Example row (remove if you don't want a sample)
  fputcsv($out, [1, 10, 5, '1500.00', '', 'REF-2025-001', '']);
  fclose($out);
  exit;
}

/* Preload reference sets for validation */
function preload_ids(mysqli $conn, string $sql, int $cols = 1): array {
  $res = $conn->query($sql);
  $set = [];
  while ($row = $res->fetch_row()) {
    if ($cols === 1) $set[(int)$row[0]] = true;
    else $set[$row[0].':'.$row[1]] = true;
  }
  return $set;
}
$teacherSet = preload_ids($conn, "SELECT teacher_id FROM teachers");
$courseSet  = preload_ids($conn, "SELECT course_id FROM courses");
$teachCourseSet = preload_ids($conn, "SELECT teacher_id, course_id FROM teacher_courses", 2);

/* Parse date helper */
function normalize_datetime(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  // Accept Y-m-d or Y-m-d H:i:s
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s.' 00:00:00';
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $s)) {
    // Ensure seconds
    if (strlen($s) === 16) return $s.':00';
    return $s;
  }
  return null;
}

/* Allowed payment methods (ENUM) */
$ALLOWED_METHODS = ['bank_transfer','cash','card','other'];

/* Handle preview */
$previewRows = [];
$hasErrors = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
  if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid CSRF'); }

  $method = $_POST['payment_method'] ?? 'bank_transfer';
  if (!in_array($method, $ALLOWED_METHODS, true)) $method = 'bank_transfer';

  if (empty($_FILES['csv_file']['tmp_name'])) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Please select a CSV file.'];
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
  }

  $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
  if (!$fh) { $_SESSION['flash']=['type'=>'error','msg'=>'Could not read uploaded file.']; header('Location: '.$_SERVER['REQUEST_URI']); exit; }

  $header = fgetcsv($fh);
  if (!$header) { $_SESSION['flash']=['type'=>'error','msg'=>'Empty CSV.']; header('Location: '.$_SERVER['REQUEST_URI']); exit; }

  // Map headers (case-insensitive)
  $map = [];
  foreach ($header as $i => $h) { $map[strtolower(trim($h))] = $i; }

  $required = ['teacher_id','course_id','lesson_count'];
  foreach ($required as $col) {
    if (!array_key_exists($col, $map)) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>"Missing required column: $col"];
      header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }
  }

  $lineNo = 1;
  while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;
    if (count(array_filter($row, fn($v)=>trim((string)$v) !== '')) === 0) continue; // skip empty

    $teacher_id     = (int)($row[$map['teacher_id']] ?? 0);
    $course_id      = (int)($row[$map['course_id']] ?? 0);
    $lesson_count   = (int)($row[$map['lesson_count']] ?? 0);
    $rate           = isset($map['rate_per_lesson']) ? (float)$row[$map['rate_per_lesson']] : null;
    $amountCsv      = isset($map['amount']) ? trim((string)$row[$map['amount']]) : '';
    $reference_code = isset($map['reference_code']) ? trim((string)$row[$map['reference_code']]) : '';
    $paid_at_csv    = isset($map['paid_at']) ? trim((string)$row[$map['paid_at']]) : '';

    $errors = [];

    if ($teacher_id <= 0 || empty($teacherSet[$teacher_id])) $errors[] = 'Invalid teacher_id';
    if ($course_id <= 0 || empty($courseSet[$course_id])) $errors[] = 'Invalid course_id';
    if ($lesson_count < 0) $errors[] = 'lesson_count must be >= 0';
    if (!empty($teacher_id) && !empty($course_id) && empty($teachCourseSet[$teacher_id.':'.$course_id])) {
      $errors[] = 'Teacher is not assigned to this course';
    }

    $amount = null;
    if ($amountCsv !== '') {
      if (!is_numeric($amountCsv)) {
        $errors[] = 'amount must be numeric';
      } else {
        $amount = (float)$amountCsv;
      }
    } elseif ($rate !== null) {
      $amount = round((float)$rate * (int)$lesson_count, 2);
    } else {
      $errors[] = 'Either amount or rate_per_lesson is required';
    }

    $paid_at = $paid_at_csv !== '' ? normalize_datetime($paid_at_csv) : null;
    if ($paid_at_csv !== '' && $paid_at === null) {
      $errors[] = 'Invalid paid_at format (use YYYY-MM-DD or YYYY-MM-DD HH:MM[:SS])';
    }

    $previewRows[] = [
      'line' => $lineNo,
      'teacher_id' => $teacher_id,
      'course_id' => $course_id,
      'lesson_count' => $lesson_count,
      'rate_per_lesson' => $rate,
      'amount' => $amount,
      'reference_code' => substr($reference_code, 0, 100),
      'paid_at' => $paid_at,
      'payment_method' => $method,
      'errors' => $errors,
    ];
  }
  fclose($fh);

  foreach ($previewRows as $r) { if (!empty($r['errors'])) { $hasErrors = true; break; } }

  // Save to session for commit
  $_SESSION['import_rows'] = $previewRows;
}

/* Handle commit */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit') {
  if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid CSRF'); }
  $rows = $_SESSION['import_rows'] ?? null;
  if (!$rows || !is_array($rows)) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Nothing to import. Please upload a CSV first.'];
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
  }

  // Ensure no row has errors
  foreach ($rows as $r) {
    if (!empty($r['errors'])) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>'Fix errors before committing import.'];
      header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }
  }

  try {
    $conn->begin_transaction();
    $sql = "INSERT INTO teacher_payments
            (teacher_id, course_id, amount, lesson_count, rate_per_lesson, payment_method, payment_status, reference_code, paid_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)";
    $stmt = $conn->prepare($sql);

    $count = 0;
    foreach ($rows as $r) {
      $teacher_id = (int)$r['teacher_id'];
      $course_id = (int)$r['course_id'];
      $amount = (float)$r['amount'];
      $lesson_count = (int)$r['lesson_count'];
      $rate = $r['rate_per_lesson'] !== null ? (float)$r['rate_per_lesson'] : null;
      $method = $r['payment_method'];
      $ref = $r['reference_code'] ?? null;
      $paid_at = $r['paid_at']; // may be null

      // Bind with appropriate types; rate can be null (use 'd' but we need to pass a double; use null safe)
      // We'll convert null rate to NULL via binding with 'd' requires number; so we switch to 'sd' types carefully:
      // Simpler: use set to NULL via $stmt->bind_param with 'dsis' doesn't support nulls; use $stmt->bind_param then set to null with $stmt->send_long_data? Not needed.
      // We’ll cast rate as string and let MySQL convert or bind 's' and pass NULL as null.
      $rateStr = $rate !== null ? (string)$rate : null;

      // paid_at may be null; bind as string and pass null
      $stmt->bind_param('iisissss',
        $teacher_id,        // i
        $course_id,         // i
        $amount,            // s (we used s to allow passing numeric strings consistently)
        $lesson_count,      // i
        $rateStr,           // s or null
        $method,            // s
        $ref,               // s or null
        $paid_at            // s or null
      );
      $stmt->execute();
      $count++;
    }
    $stmt->close();
    $conn->commit();

    unset($_SESSION['import_rows']);
    $_SESSION['flash'] = ['type'=>'success','msg'=>"Imported $count teacher payment row(s) as pending."];
  } catch (Throwable $e) {
    if ($conn->errno) $conn->rollback();
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Import failed: '.$e->getMessage()];
  }

  header('Location: '.$_SERVER['REQUEST_URI']); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Upload Teacher Payments (CSV)</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
  <div class="max-w-5xl mx-auto p-4 md:p-8">
    <header class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Upload Teacher Payments (CSV)</h1>
      <div class="flex items-center gap-2">
        <a href="accountant_dashboard.php" class="px-3 py-2 rounded border border-gray-300 bg-white text-gray-700 hover:bg-gray-100">Back to Dashboard</a>
        <a href="?download_template=1" class="px-3 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">Download Template</a>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
        <?= e($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <section class="mb-6 bg-white rounded shadow p-4">
      <form method="post" enctype="multipart/form-data" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="preview">
        <div>
          <label class="block text-sm font-medium text-gray-700">Payment method</label>
          <select name="payment_method" class="mt-1 w-full rounded border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 max-w-xs">
            <option value="bank_transfer">Bank transfer</option>
            <option value="cash">Cash</option>
            <option value="card">Card</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">CSV file</label>
          <input type="file" name="csv_file" accept=".csv,text/csv" class="mt-1 block w-full text-sm text-gray-700" required>
          <p class="mt-1 text-xs text-gray-500">Headers required: teacher_id, course_id, lesson_count. Optional: rate_per_lesson, amount, reference_code, paid_at.</p>
        </div>
        <button class="px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">Preview</button>
      </form>
    </section>

    <?php if (!empty($previewRows)): ?>
      <section class="bg-white rounded shadow overflow-hidden">
        <div class="p-4 border-b">
          <h2 class="text-lg font-semibold">Preview</h2>
          <p class="text-sm text-gray-500">Review rows and confirm import. <?= $hasErrors ? 'There are errors — fix your CSV and re-upload.' : 'Looks good — you can commit.' ?></p>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Line</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Teacher</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Course</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Lessons</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Paid At</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 text-sm">
              <?php foreach ($previewRows as $r): ?>
                <?php
                  $ok = empty($r['errors']);
                  $badge = $ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                ?>
                <tr class="<?= $ok ? '' : 'bg-red-50' ?>">
                  <td class="px-3 py-2">#<?= (int)$r['line'] ?></td>
                  <td class="px-3 py-2"><?= (int)$r['teacher_id'] ?></td>
                  <td class="px-3 py-2"><?= (int)$r['course_id'] ?></td>
                  <td class="px-3 py-2"><?= (int)$r['lesson_count'] ?></td>
                  <td class="px-3 py-2"><?= $r['rate_per_lesson'] !== null ? number_format((float)$r['rate_per_lesson'], 2) : '-' ?></td>
                  <td class="px-3 py-2 font-medium">Rs <?= $r['amount'] !== null ? number_format((float)$r['amount'], 2) : '-' ?></td>
                  <td class="px-3 py-2"><?= e($r['reference_code'] ?? '') ?></td>
                  <td class="px-3 py-2"><?= e($r['paid_at'] ?? '') ?></td>
                  <td class="px-3 py-2"><?= e(ucwords(str_replace('_',' ',$r['payment_method']))) ?></td>
                  <td class="px-3 py-2">
                    <span class="px-2.5 py-1 rounded text-xs font-medium <?= $badge ?>">
                      <?= $ok ? 'OK' : e(implode('; ', $r['errors'])) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="p-4 bg-gray-50 flex items-center justify-between">
          <div class="text-sm text-gray-600">
            <?= $hasErrors ? 'Fix the errors in your CSV and re-upload.' : 'All rows are valid.' ?>
          </div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="commit">
            <button <?= $hasErrors ? 'disabled' : '' ?>
              class="px-4 py-2 rounded <?= $hasErrors ? 'bg-gray-300 text-gray-600 cursor-not-allowed' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>">
              Commit Import
            </button>
          </form>
        </div>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>