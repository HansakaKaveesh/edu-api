<?php
session_start();
include 'db_connect.php';

// Allow only admin users
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit("Access denied.");
}

$all_payments = $conn->query("
    SELECT sp.payment_id, s.first_name, s.last_name, sp.amount, sp.payment_method, 
           sp.payment_status, sp.paid_at
    FROM student_payments sp
    JOIN students s ON sp.student_id = s.student_id
    ORDER BY sp.paid_at DESC
");
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

    <div class="overflow-x-auto shadow-lg rounded-lg bg-white">
        <table class="min-w-full table-auto text-sm text-left border-collapse">
            <thead class="bg-gray-200 text-gray-700">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">Student</th>
                    <th class="px-4 py-3">Amount</th>
                    <th class="px-4 py-3">Method</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Date Paid</th>
                    <th class="px-4 py-3">Slip</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php while ($row = $all_payments->fetch_assoc()): ?>
                <tr class="hover:bg-gray-50 transition-colors duration-150">
                    <td class="px-4 py-3 font-medium"><?= $row['payment_id'] ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                    <td class="px-4 py-3 text-green-600 font-semibold">$<?= number_format($row['amount'], 2) ?></td>
                    <td class="px-4 py-3 capitalize"><?= htmlspecialchars($row['payment_method']) ?></td>
                    <td class="px-4 py-3">
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold
                            <?= $row['payment_status'] === 'completed' 
                                ? 'bg-green-100 text-green-800' 
                                : 'bg-yellow-100 text-yellow-800' ?>">
                            <?= ucfirst($row['payment_status']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3"><?= date("Y-m-d H:i", strtotime($row['paid_at'])) ?></td>
                    <td class="px-4 py-3">
                        <button
                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs font-semibold transition"
                            onclick="downloadSlip(
                                '<?= $row['payment_id'] ?>',
                                '<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>',
                                '<?= number_format($row['amount'], 2) ?>',
                                '<?= htmlspecialchars($row['payment_method']) ?>',
                                '<?= ucfirst($row['payment_status']) ?>',
                                '<?= date("Y-m-d H:i", strtotime($row['paid_at'])) ?>'
                            )"
                        >Download Slip</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Hidden Payment Slip Template -->
<div id="payment-slip-template" class="hidden">
    <div class="max-w-xs mx-auto p-6 bg-white rounded-lg shadow text-gray-800 text-sm border border-gray-200">
        <div class="text-center mb-4">
            <div class="text-lg font-semibold text-gray-500">SynapZ</div> <!-- Company Name -->
            <div class="text-2xl font-bold text-blue-600 mb-1">Payment Slip</div>
            <div class="text-xs text-gray-400" id="slip-date"></div>
        </div>
        <div class="mb-2"><span class="font-semibold">Payment ID:</span> <span id="slip-id"></span></div>
        <div class="mb-2"><span class="font-semibold">Student:</span> <span id="slip-student"></span></div>
        <div class="mb-2"><span class="font-semibold">Amount:</span> $<span id="slip-amount"></span></div>
        <div class="mb-2"><span class="font-semibold">Method:</span> <span id="slip-method"></span></div>
        <div class="mb-2"><span class="font-semibold">Status:</span> <span id="slip-status"></span></div>
        <div class="mb-2"><span class="font-semibold">Date Paid:</span> <span id="slip-paidat"></span></div>
        <div class="mt-4 text-center text-xs text-gray-400">Thank you for your payment!</div>
    </div>
</div>

<script>
function downloadSlip(id, student, amount, method, status, paidat) {
    document.getElementById('slip-id').textContent = id;
    document.getElementById('slip-student').textContent = student;
    document.getElementById('slip-amount').textContent = amount;
    document.getElementById('slip-method').textContent = method;
    document.getElementById('slip-status').textContent = status;
    document.getElementById('slip-paidat').textContent = paidat;
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
</script>
</body>
</html>