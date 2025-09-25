<?php
session_start();
include 'db_connect.php';

// Only admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit("Access denied.");
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function columnExists(mysqli $conn, string $table, string $column): bool {
    $col = $conn->query("SHOW COLUMNS FROM `$table` LIKE '{$conn->real_escape_string($column)}'");
    $exists = $col && $col->num_rows > 0;
    if ($col) $col->free();
    return $exists;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Handle status updates (Activate/Mark Failed/Restore Pending)
$flashMsg = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    $new_status = strtolower(trim($_POST['new_status'] ?? ''));

    $allowed = ['pending', 'completed', 'failed'];
    if ($payment_id <= 0 || !in_array($new_status, $allowed, true)) {
        http_response_code(400);
        exit('Bad request');
    }

    // Update payment status
    if ($stmt = $conn->prepare("UPDATE student_payments SET payment_status = ? WHERE payment_id = ?")) {
        $stmt->bind_param("si", $new_status, $payment_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            // Optional: auto-activate enrollment if you store enrollment_id on payments
            if ($new_status === 'completed' && columnExists($conn, 'student_payments', 'enrollment_id')) {
                $enrollment_id = 0;
                if ($stmt = $conn->prepare("SELECT enrollment_id FROM student_payments WHERE payment_id = ? LIMIT 1")) {
                    $stmt->bind_param("i", $payment_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) $enrollment_id = (int)$row['enrollment_id'];
                    $stmt->close();
                }
                if ($enrollment_id > 0 && columnExists($conn, 'enrollments', 'status')) {
                    if ($stmt = $conn->prepare("UPDATE enrollments SET status = 'active' WHERE enrollment_id = ?")) {
                        $stmt->bind_param("i", $enrollment_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            $flashMsg = "Payment #{$payment_id} status updated to " . ucfirst($new_status) . ".";
            $flashType = 'success';
        } else {
            $flashMsg = "Failed to update payment #{$payment_id}.";
            $flashType = 'error';
        }
    } else {
        $flashMsg = "Failed to prepare update.";
        $flashType = 'error';
    }
}

// Fetch payments list (include slip_url if present)
$hasSlipCol = columnExists($conn, 'student_payments', 'slip_url');

$sql = "
    SELECT 
        sp.payment_id,
        s.first_name, 
        s.last_name, 
        sp.amount, 
        sp.payment_method, 
        sp.payment_status, 
        sp.reference_code,
        sp.paid_at" .
    ($hasSlipCol ? ", sp.slip_url" : "") . "
    FROM student_payments sp
    JOIN students s ON sp.student_id = s.student_id
    ORDER BY sp.paid_at DESC
";
$all_payments = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Payment Reports</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="bg-gray-100 text-gray-800">
<?php include 'components/navbar.php'; ?>

<div class="container mx-auto px-6 py-8 mt-12">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold flex items-center gap-3">
            <span>📑</span> Payment Reports
        </h2>
        <a href="admin_dashboard.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition">
            ⬅ Back to Dashboard
        </a>
    </div>

    <?php if (!empty($flashMsg)): ?>
      <div class="mb-4 rounded-lg px-4 py-3 border <?= $flashType === 'success' ? 'bg-green-50 text-green-800 border-green-200' : ($flashType === 'error' ? 'bg-red-50 text-red-800 border-red-200' : 'bg-blue-50 text-blue-800 border-blue-200') ?>">
        <?= e($flashMsg) ?>
      </div>
    <?php endif; ?>

    <div class="overflow-x-auto shadow-lg rounded-lg bg-white">
        <table class="min-w-full table-auto text-sm text-left border-collapse">
            <thead class="bg-gray-200 text-gray-700">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">Student</th>
                    <th class="px-4 py-3">Amount</th>
                    <th class="px-4 py-3">Method</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Reference</th>
                    <th class="px-4 py-3">Date Paid</th>
                    <th class="px-4 py-3">Actions</th>
                    <th class="px-4 py-3">Slip</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php while ($row = $all_payments->fetch_assoc()): 
                    $status = strtolower($row['payment_status']);
                    $statusClass = $status === 'completed' ? 'bg-green-100 text-green-800' : ($status === 'failed' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800');
                    $pid = (int)$row['payment_id'];
                    $studentName = $row['first_name'] . ' ' . $row['last_name'];
                    $amount = number_format((float)$row['amount'], 2);
                    $method = $row['payment_method'];
                    $paidAt = $row['paid_at'] ? date("Y-m-d H:i", strtotime($row['paid_at'])) : '—';
                    $ref = $row['reference_code'] ?? '';
                    $slipUrl = $hasSlipCol ? ($row['slip_url'] ?? '') : '';
                ?>
                <tr class="hover:bg-gray-50 transition-colors duration-150 align-top">
                    <td class="px-4 py-3 font-medium"><?= $pid ?></td>
                    <td class="px-4 py-3"><?= e($studentName) ?></td>
                    <td class="px-4 py-3 text-green-600 font-semibold">$<?= $amount ?></td>
                    <td class="px-4 py-3 capitalize"><?= e($method) ?></td>
                    <td class="px-4 py-3">
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>">
                            <?= ucfirst($status) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded"><?= e($ref) ?></code>
                    </td>
                    <td class="px-4 py-3"><?= $paidAt ?></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                          <?php if ($status !== 'completed'): ?>
                            <!-- Activate (mark completed) -->
                            <form method="POST" onsubmit="return confirm('Mark payment #<?= $pid ?> as Completed?');">
                              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                              <input type="hidden" name="action" value="update_status">
                              <input type="hidden" name="payment_id" value="<?= $pid ?>">
                              <input type="hidden" name="new_status" value="completed">
                              <button class="px-3 py-1 rounded bg-emerald-600 text-white text-xs hover:bg-emerald-700">Activate</button>
                            </form>
                          <?php endif; ?>

                          <?php if ($status !== 'pending'): ?>
                            <!-- Set Pending -->
                            <form method="POST" onsubmit="return confirm('Move payment #<?= $pid ?> back to Pending?');">
                              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                              <input type="hidden" name="action" value="update_status">
                              <input type="hidden" name="payment_id" value="<?= $pid ?>">
                              <input type="hidden" name="new_status" value="pending">
                              <button class="px-3 py-1 rounded bg-amber-500 text-white text-xs hover:bg-amber-600">Set Pending</button>
                            </form>
                          <?php endif; ?>

                          <?php if ($status !== 'failed'): ?>
                            <!-- Mark Failed -->
                            <form method="POST" onsubmit="return confirm('Mark payment #<?= $pid ?> as Failed?');">
                              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                              <input type="hidden" name="action" value="update_status">
                              <input type="hidden" name="payment_id" value="<?= $pid ?>">
                              <input type="hidden" name="new_status" value="failed">
                              <button class="px-3 py-1 rounded bg-red-600 text-white text-xs hover:bg-red-700">Mark Failed</button>
                            </form>
                          <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-col gap-2">
                          <?php if ($slipUrl): ?>
                            <div class="flex gap-2">
                              <button
                                class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-xs font-semibold transition"
                                onclick='openSlip(<?= json_encode($slipUrl, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                              >View Proof</button>
                              <a
                                class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-xs font-semibold transition"
                                href="<?= e($slipUrl) ?>" target="_blank" rel="noopener"
                                download
                              >Download Proof</a>
                            </div>
                          <?php else: ?>
                            <span class="text-xs text-gray-500">No proof</span>
                          <?php endif; ?>

                          <!-- Existing receipt PDF (generated from row) -->
                          <button
                              class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs font-semibold transition"
                              onclick="downloadSlip(
                                  '<?= $pid ?>',
                                  '<?= e($studentName) ?>',
                                  '<?= $amount ?>',
                                  '<?= e($method) ?>',
                                  '<?= ucfirst($status) ?>',
                                  '<?= $paidAt !== '—' ? $paidAt : '' ?>',
                                  '<?= e($ref) ?>'
                              )"
                          >Receipt PDF</button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- Hidden Payment Slip Template (Receipt PDF) -->
<div id="payment-slip-template" class="hidden">
    <div class="max-w-xs mx-auto p-6 bg-white rounded-lg shadow text-gray-800 text-sm border border-gray-200">
        <div class="text-center mb-4">
            <div class="text-lg font-semibold text-gray-500">SynapZ</div>
            <div class="text-2xl font-bold text-blue-600 mb-1">Payment Slip</div>
            <div class="text-xs text-gray-400" id="slip-date"></div>
        </div>
        <div class="mb-2"><span class="font-semibold">Payment ID:</span> <span id="slip-id"></span></div>
        <div class="mb-2"><span class="font-semibold">Student:</span> <span id="slip-student"></span></div>
        <div class="mb-2"><span class="font-semibold">Amount:</span> $<span id="slip-amount"></span></div>
        <div class="mb-2"><span class="font-semibold">Method:</span> <span id="slip-method"></span></div>
        <div class="mb-2"><span class="font-semibold">Status:</span> <span id="slip-status"></span></div>
        <div class="mb-2"><span class="font-semibold">Reference Code:</span> <code id="slip-ref" class="bg-gray-100 px-1 rounded"></code></div>
        <div class="mb-2"><span class="font-semibold">Date Paid:</span> <span id="slip-paidat"></span></div>
        <div class="mt-4 text-center text-xs text-gray-400">Thank you for your payment!</div>
    </div>
</div>

<!-- Modal to view uploaded proof -->
<div id="proofModal" class="hidden fixed inset-0 z-50">
  <div class="absolute inset-0 bg-black/50" onclick="closeSlip()"></div>
  <div class="relative max-w-3xl mx-auto mt-16 bg-white rounded-2xl shadow-xl overflow-hidden">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h3 class="font-semibold">Payment Proof</h3>
      <button class="text-gray-600 hover:text-black" onclick="closeSlip()">✕</button>
    </div>
    <div class="p-4">
      <div id="proofContainer" class="w-full h-[70vh] flex items-center justify-center bg-gray-50 rounded border overflow-hidden">
        <!-- Filled dynamically -->
      </div>
      <div id="proofActions" class="mt-3 text-right hidden">
        <a id="proofDownload" href="#" download class="inline-flex items-center gap-2 px-3 py-1.5 rounded bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm">Download</a>
        <a id="proofOpen" href="#" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-1.5 rounded bg-blue-600 hover:bg-blue-700 text-white text-sm">Open in New Tab</a>
      </div>
    </div>
  </div>
</div>

<script>
function downloadSlip(id, student, amount, method, status, paidat, refcode) {
    document.getElementById('slip-id').textContent = id;
    document.getElementById('slip-student').textContent = student;
    document.getElementById('slip-amount').textContent = amount;
    document.getElementById('slip-method').textContent = method;
    document.getElementById('slip-status').textContent = status;
    document.getElementById('slip-paidat').textContent = paidat || '—';
    document.getElementById('slip-ref').textContent = refcode || '—';
    document.getElementById('slip-date').textContent = "Generated: " + new Date().toLocaleString();

    var slip = document.getElementById('payment-slip-template').cloneNode(true);
    slip.classList.remove('hidden');
    slip.style.display = 'block';

    html2pdf().set({
        margin: 0.2,
        filename: 'payment_slip_' + id + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'a6', orientation: 'portrait' }
    }).from(slip).save();
}

// View uploaded proof (image/pdf) in modal
function openSlip(url) {
  if (!url) return;
  const container = document.getElementById('proofContainer');
  const actions = document.getElementById('proofActions');
  const aDownload = document.getElementById('proofDownload');
  const aOpen = document.getElementById('proofOpen');

  // Reset
  container.innerHTML = '<div class="text-gray-500 text-sm">Loading…</div>';
  actions.classList.add('hidden');

  // Basic ext check
  const lower = url.toLowerCase();
  const isImg = /\.(png|jpe?g|webp|gif)$/i.test(lower);
  const isPdf = /\.pdf$/i.test(lower);

  // Build viewer
  if (isImg) {
    container.innerHTML = '<img src="'+escapeHtml(url)+'" alt="Payment proof" class="max-w-full max-h-full object-contain" />';
  } else if (isPdf) {
    // Use iframe/object for PDF
    container.innerHTML = '<iframe src="'+escapeHtml(url)+'#toolbar=1" class="w-full h-full" title="Payment proof (PDF)"></iframe>';
  } else {
    container.innerHTML = '<div class="text-gray-600 text-sm">Unsupported file type. <a class="text-blue-600 underline" href="'+escapeHtml(url)+'" target="_blank" rel="noopener">Open file</a></div>';
  }

  // Set action links
  aDownload.href = url;
  aOpen.href = url;
  actions.classList.remove('hidden');

  document.getElementById('proofModal').classList.remove('hidden');
}

function closeSlip() {
  document.getElementById('proofModal').classList.add('hidden');
}

// Minimal HTML escape for inserted URLs
function escapeHtml(s) {
  return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
               .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
               .replace(/'/g,'&#039;');
}
</script>
</body>
</html>