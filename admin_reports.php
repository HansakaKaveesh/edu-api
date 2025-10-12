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

// Currency symbol (customize as needed)
$currencySymbol = '$';

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

    if ($stmt = $conn->prepare("UPDATE student_payments SET payment_status = ? WHERE payment_id = ?")) {
        $stmt->bind_param("si", $new_status, $payment_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            // Auto-activate enrollment if exists
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
$res = $conn->query($sql);
$payments = [];
if ($res) {
    while ($r = $res->fetch_assoc()) $payments[] = $r;
    $res->free();
}

// Stats
$counts = ['completed' => 0, 'pending' => 0, 'failed' => 0];
$sumCompleted = 0.0;
foreach ($payments as $p) {
    $st = strtolower($p['payment_status']);
    if (isset($counts[$st])) $counts[$st]++;
    if ($st === 'completed') $sumCompleted += (float)$p['amount'];
}

// Helpers for icons
function method_icon_class(string $method): string {
    $m = strtolower($method);
    if (str_contains($m, 'card')) return 'fa-credit-card';
    if (str_contains($m, 'bank') || str_contains($m, 'transfer')) return 'fa-building-columns';
    if (str_contains($m, 'paypal')) return 'fa-brands fa-paypal';
    if (str_contains($m, 'cash')) return 'fa-money-bill-wave';
    if (str_contains($m, 'wallet')) return 'fa-wallet';
    return 'fa-receipt';
}
function status_badge(string $status): array {
    $s = strtolower($status);
    if ($s === 'completed') return ['bg' => 'bg-green-100 text-green-800', 'icon' => 'fa-circle-check'];
    if ($s === 'failed')    return ['bg' => 'bg-red-100 text-red-800',     'icon' => 'fa-circle-xmark'];
    return ['bg' => 'bg-amber-100 text-amber-800', 'icon' => 'fa-hourglass-half'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Payment Reports</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- html2pdf -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <style>
      html { scroll-behavior: smooth; }
      .table-sticky thead th { position: sticky; top: 0; z-index: 5; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen">
<?php include 'components/navbar.php'; ?>

<div class="max-w-8xl mx-auto px-6 py-8 mt-16">
    <!-- Header -->
    <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-5 mb-6">
      <div aria-hidden="true" class="pointer-events-none absolute inset-0">
        <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
      </div>
      <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-2xl sm:text-3xl font-extrabold text-blue-700 tracking-tight flex items-center gap-3">
            <i class="fa-solid fa-file-invoice-dollar"></i> Payment Reports
          </h2>
          <p class="text-gray-600">Review, verify and manage student payments.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a href="admin_dashboard.php" class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
          </a>
          <button onclick="exportTablePDF()" class="inline-flex items-center gap-2 rounded-lg bg-rose-600 text-white px-4 py-2 hover:bg-rose-700 shadow-sm">
            <i class="fa-solid fa-file-pdf"></i> Export PDF
          </button>
          <button onclick="exportTableCSV()" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 text-white px-4 py-2 hover:bg-emerald-700 shadow-sm">
            <i class="fa-solid fa-file-csv"></i> Export CSV
          </button>
        </div>
      </div>

      <?php if (!empty($flashMsg)): ?>
        <div class="relative mt-4">
          <?php
            $alertClass = $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : ($flashType === 'error' ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-blue-50 text-blue-700 ring-blue-200');
            $iconClass  = $flashType === 'success' ? 'fa-circle-check' : ($flashType === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-info');
          ?>
          <div class="rounded-xl px-4 py-3 ring-1 <?= $alertClass ?>">
            <span class="inline-flex items-center gap-2 font-medium"><i class="fa-solid <?= $iconClass ?>"></i> <?= e($flashMsg) ?></span>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs text-gray-500 flex items-center gap-2"><i class="fa-solid fa-receipt text-indigo-600"></i> Total Payments</div>
        <div class="mt-1 text-2xl font-bold"><?= count($payments) ?></div>
      </div>
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs text-gray-500 flex items-center gap-2"><i class="fa-solid fa-circle-check text-green-600"></i> Completed</div>
        <div class="mt-1 text-2xl font-bold text-green-700"><?= (int)$counts['completed'] ?></div>
      </div>
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs text-gray-500 flex items-center gap-2"><i class="fa-solid fa-hourglass-half text-amber-600"></i> Pending</div>
        <div class="mt-1 text-2xl font-bold text-amber-700"><?= (int)$counts['pending'] ?></div>
      </div>
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs text-gray-500 flex items-center gap-2"><i class="fa-solid fa-sack-dollar text-emerald-600"></i> Sum Completed</div>
        <div class="mt-1 text-2xl font-bold text-emerald-700"><?= e($currencySymbol) . number_format($sumCompleted, 2) ?></div>
      </div>
    </div>

    <!-- Filters -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-4 mb-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="relative">
          <input id="searchInput" type="text" placeholder="Search by student, ref, method…" class="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
          <i class="fa-solid fa-magnifying-glass w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
        </div>
        <div>
          <select id="statusFilter" class="w-full py-2.5 px-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
            <option value="">All statuses</option>
            <option value="completed">Completed</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
          </select>
        </div>
        <div>
          <select id="methodFilter" class="w-full py-2.5 px-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
            <option value="">All methods</option>
            <option value="card">Card</option>
            <option value="bank">Bank/Transfer</option>
            <option value="paypal">PayPal</option>
            <option value="cash">Cash</option>
            <option value="wallet">Wallet</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto shadow-lg rounded-2xl bg-white ring-1 ring-gray-200" id="paymentsTableWrap">
        <table id="paymentsTable" class="min-w-full table-auto text-sm text-left border-collapse table-sticky">
            <thead class="bg-gray-100 text-gray-700">
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
                <?php foreach ($payments as $row): 
                    $status = strtolower($row['payment_status']);
                    $badge = status_badge($status);
                    $pid = (int)$row['payment_id'];
                    $studentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $amount = number_format((float)$row['amount'], 2);
                    $method = $row['payment_method'] ?? '';
                    $paidAt = !empty($row['paid_at']) ? date("Y-m-d H:i", strtotime($row['paid_at'])) : '—';
                    $ref = $row['reference_code'] ?? '';
                    $slipUrl = $hasSlipCol ? ($row['slip_url'] ?? '') : '';
                    $methodIcon = method_icon_class($method);

                    $searchKey = strtolower(trim($studentName.' '.$ref.' '.$method.' '.$status.' '.$pid));
                ?>
                <tr class="hover:bg-gray-50 transition-colors duration-150 align-top"
                    data-key="<?= e($searchKey) ?>"
                    data-status="<?= e($status) ?>"
                    data-method="<?= e(strtolower($method)) ?>">
                    <td class="px-4 py-3 font-medium">#<?= $pid ?></td>
                    <td class="px-4 py-3">
                      <span class="inline-flex items-center gap-2">
                        <i class="fa-regular fa-user text-gray-500"></i> <?= e($studentName) ?>
                      </span>
                    </td>
                    <td class="px-4 py-3 font-semibold text-emerald-700"><?= e($currencySymbol) . $amount ?></td>
                    <td class="px-4 py-3">
                      <span class="inline-flex items-center gap-2 capitalize">
                        <i class="fa-solid <?= e($methodIcon) ?> text-gray-600"></i> <?= e($method) ?>
                      </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold <?= e($badge['bg']) ?>">
                            <i class="fa-solid <?= e($badge['icon']) ?>"></i> <?= ucfirst($status) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-2">
                          <i class="fa-solid fa-hashtag text-gray-500"></i>
                          <code class="text-xs bg-gray-100 px-2 py-1 rounded"><?= e($ref ?: '—') ?></code>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                      <span class="inline-flex items-center gap-2">
                        <i class="fa-regular fa-calendar-days text-gray-500"></i> <?= e($paidAt) ?>
                      </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                          <?php if ($status !== 'completed'): ?>
                            <form method="POST" onsubmit="return confirm('Mark payment #<?= $pid ?> as Completed?');">
                              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                              <input type="hidden" name="action" value="update_status">
                              <input type="hidden" name="payment_id" value="<?= $pid ?>">
                              <input type="hidden" name="new_status" value="completed">
                              <button class="inline-flex items-center gap-2 px-3 py-1 rounded bg-emerald-600 text-white text-xs hover:bg-emerald-700">
                                <i class="fa-solid fa-circle-check"></i> Activate
                              </button>
                            </form>
                          <?php endif; ?>

                          <?php if ($status !== 'pending'): ?>
                            <form method="POST" onsubmit="return confirm('Move payment #<?= $pid ?> back to Pending?');">
                              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                              <input type="hidden" name="action" value="update_status">
                              <input type="hidden" name="payment_id" value="<?= $pid ?>">
                              <input type="hidden" name="new_status" value="pending">
                              <button class="inline-flex items-center gap-2 px-3 py-1 rounded bg-amber-500 text-white text-xs hover:bg-amber-600">
                                <i class="fa-solid fa-rotate-left"></i> Set Pending
                              </button>
                            </form>
                          <?php endif; ?>

                          <?php if ($status !== 'failed'): ?>
                            <form method="POST" onsubmit="return confirm('Mark payment #<?= $pid ?> as Failed?');">
                              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                              <input type="hidden" name="action" value="update_status">
                              <input type="hidden" name="payment_id" value="<?= $pid ?>">
                              <input type="hidden" name="new_status" value="failed">
                              <button class="inline-flex items-center gap-2 px-3 py-1 rounded bg-red-600 text-white text-xs hover:bg-red-700">
                                <i class="fa-solid fa-circle-xmark"></i> Mark Failed
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-col gap-2">
                          <?php if ($slipUrl): ?>
                            <div class="flex gap-2">
                              <button
                                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-xs font-semibold transition"
                                onclick='openSlip(<?= json_encode($slipUrl, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                              ><i class="fa-regular fa-eye"></i> View Proof</button>
                              <a
                                class="inline-flex items-center gap-2 bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-xs font-semibold transition"
                                href="<?= e($slipUrl) ?>" target="_blank" rel="noopener" download
                              ><i class="fa-solid fa-download"></i> Download</a>
                            </div>
                          <?php else: ?>
                            <span class="text-xs text-gray-500 inline-flex items-center gap-2"><i class="fa-regular fa-file"></i> No proof</span>
                          <?php endif; ?>

                          <button
                              class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs font-semibold transition"
                              onclick="downloadSlip(
                                  '<?= $pid ?>',
                                  '<?= e($studentName) ?>',
                                  '<?= $amount ?>',
                                  '<?= e($method) ?>',
                                  '<?= ucfirst($status) ?>',
                                  '<?= $paidAt !== '—' ? e($paidAt) : '' ?>',
                                  '<?= e($ref) ?>'
                              )"
                          ><i class="fa-regular fa-file-pdf"></i> Receipt PDF</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Hidden Payment Slip Template (Receipt PDF) -->
<div id="payment-slip-template" class="hidden">
    <div class="max-w-xs mx-auto p-6 bg-white rounded-lg shadow text-gray-800 text-sm border border-gray-200">
        <div class="text-center mb-4">
            <div class="text-lg font-semibold text-gray-500 flex items-center justify-center gap-2">
              <i class="fa-solid fa-graduation-cap text-blue-600"></i> SynapZ
            </div>
            <div class="text-2xl font-bold text-blue-600 mb-1 flex items-center justify-center gap-2">
              <i class="fa-solid fa-file-invoice-dollar"></i> Payment Slip
            </div>
            <div class="text-xs text-gray-400" id="slip-date"></div>
        </div>
        <div class="mb-2"><span class="font-semibold">Payment ID:</span> <span id="slip-id"></span></div>
        <div class="mb-2"><span class="font-semibold">Student:</span> <span id="slip-student"></span></div>
        <div class="mb-2"><span class="font-semibold">Amount:</span> <span id="slip-currency"><?= e($currencySymbol) ?></span><span id="slip-amount"></span></div>
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
      <h3 class="font-semibold flex items-center gap-2"><i class="fa-regular fa-eye"></i> Payment Proof</h3>
      <button class="text-gray-600 hover:text-black" onclick="closeSlip()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="p-4">
      <div id="proofContainer" class="w-full h-[70vh] flex items-center justify-center bg-gray-50 rounded border overflow-hidden">
        <!-- Filled dynamically -->
      </div>
      <div id="proofActions" class="mt-3 text-right hidden">
        <a id="proofDownload" href="#" download class="inline-flex items-center gap-2 px-3 py-1.5 rounded bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm"><i class="fa-solid fa-download"></i> Download</a>
        <a id="proofOpen" href="#" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-1.5 rounded bg-blue-600 hover:bg-blue-700 text-white text-sm"><i class="fa-solid fa-up-right-from-square"></i> Open</a>
      </div>
    </div>
  </div>
</div>

<script>
  // Export table as PDF
  function exportTablePDF() {
    const element = document.getElementById('paymentsTableWrap');
    const opt = {
      margin: 0.2,
      filename: 'payment_reports.pdf',
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2 },
      jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
    };
    html2pdf().set(opt).from(element).save();
  }

  // Export table as CSV
  function exportTableCSV() {
    const table = document.getElementById('paymentsTable');
    const rows = [...table.querySelectorAll('tbody tr')].filter(r => r.style.display !== 'none');
    const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
    const data = [headers];
    rows.forEach(r => {
      const cols = [...r.children].map(td => (td.innerText || '').replace(/\s+/g,' ').trim());
      data.push(cols);
    });
    const csv = data.map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'payment_reports.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  // Search & filters
  const searchInput = document.getElementById('searchInput');
  const statusFilter = document.getElementById('statusFilter');
  const methodFilter = document.getElementById('methodFilter');
  const rowsEl = [...document.querySelectorAll('#paymentsTable tbody tr')];

  function applyFilters() {
    const q = (searchInput.value || '').toLowerCase().trim();
    const st = (statusFilter.value || '').toLowerCase();
    const mt = (methodFilter.value || '').toLowerCase();

    rowsEl.forEach(tr => {
      const key = (tr.getAttribute('data-key') || '').toLowerCase();
      const rStatus = (tr.getAttribute('data-status') || '').toLowerCase();
      const rMethod = (tr.getAttribute('data-method') || '').toLowerCase();

      const okQ = !q || key.includes(q);
      const okS = !st || rStatus === st;
      const okM = !mt || rMethod.includes(mt); // partial match for bank/transfer etc.

      tr.style.display = (okQ && okS && okM) ? '' : 'none';
    });
  }
  searchInput?.addEventListener('input', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
  methodFilter?.addEventListener('change', applyFilters);

  // Receipt PDF
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

    container.innerHTML = '<div class="text-gray-500 text-sm flex items-center gap-2"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    actions.classList.add('hidden');

    const lower = url.toLowerCase();
    const isImg = /\.(png|jpe?g|webp|gif)$/i.test(lower);
    const isPdf = /\.pdf$/i.test(lower);

    if (isImg) {
      container.innerHTML = '<img src="'+escapeHtml(url)+'" alt="Payment proof" class="max-w-full max-h-full object-contain" />';
    } else if (isPdf) {
      container.innerHTML = '<iframe src="'+escapeHtml(url)+'#toolbar=1" class="w-full h-full" title="Payment proof (PDF)"></iframe>';
    } else {
      container.innerHTML = '<div class="text-gray-600 text-sm">Unsupported file type. <a class="text-blue-600 underline" href="'+escapeHtml(url)+'" target="_blank" rel="noopener">Open file</a></div>';
    }

    aDownload.href = url;
    aOpen.href = url;
    actions.classList.remove('hidden');

    document.getElementById('proofModal').classList.remove('hidden');
  }
  function closeSlip() {
    document.getElementById('proofModal').classList.add('hidden');
  }
  function escapeHtml(s) {
    return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
                 .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
                 .replace(/'/g,'&#039;');
  }

  // Init
  document.addEventListener('DOMContentLoaded', () => {
    applyFilters();
    if (window.lucide) { lucide.createIcons(); }
  });
</script>
</body>
</html>