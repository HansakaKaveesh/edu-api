<?php 
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access Denied.");
}

// ✅ ACTION HANDLING
if (isset($_GET['action']) && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    if ($_GET['action'] === 'approve') {
        $conn->query("UPDATE users SET status = 'active' WHERE user_id = $user_id");
    } elseif ($_GET['action'] === 'suspend') {
        $conn->query("UPDATE users SET status = 'suspended' WHERE user_id = $user_id");
    } elseif ($_GET['action'] === 'delete') {
        $conn->query("DELETE FROM users WHERE user_id = $user_id");
    }
    header("Location: view_users.php");
    exit;
}

$query = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>All Users (Admin Panel)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col items-center p-6">
<?php include 'components/navbar.php'; ?>
  <div class="w-full max-w-6xl bg-white rounded-lg shadow p-6 mt-12">
    <h2 class="text-3xl font-semibold mb-6">👥 All Registered Users</h2>
    <div class="flex flex-wrap gap-3 mb-4">
      <a href="admin_dashboard.php" class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">
        ⬅ Back to Dashboard
      </a>
      <button onclick="downloadUsersPDF()" class="inline-block px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition">
        ⬇️ Download as PDF
      </button>
    </div>

    <div class="overflow-x-auto" id="users-table-section">
      <table class="min-w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-200 text-gray-700">
            <th class="px-4 py-3 border border-gray-300">User ID</th>
            <th class="px-4 py-3 border border-gray-300">Username</th>
            <th class="px-4 py-3 border border-gray-300">Role</th>
            <th class="px-4 py-3 border border-gray-300">Status</th>
            <th class="px-4 py-3 border border-gray-300">Created At</th>
            <th class="px-4 py-3 border border-gray-300">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($user = $query->fetch_assoc()): ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 border border-gray-300"><?= $user['user_id'] ?></td>
            <td class="px-4 py-3 border border-gray-300 font-medium"><?= htmlspecialchars($user['username']) ?></td>
            <td class="px-4 py-3 border border-gray-300"><?= ucfirst($user['role']) ?></td>
            <td class="px-4 py-3 border border-gray-300">
              <?php if ($user['status'] === 'active'): ?>
                <span class="inline-block px-2 py-1 rounded bg-green-100 text-green-800 font-semibold text-sm">Active</span>
              <?php elseif ($user['status'] === 'suspended'): ?>
                <span class="inline-block px-2 py-1 rounded bg-yellow-100 text-yellow-800 font-semibold text-sm">Suspended</span>
              <?php else: ?>
                <span class="inline-block px-2 py-1 rounded bg-red-100 text-red-800 font-semibold text-sm"><?= htmlspecialchars($user['status']) ?></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 border border-gray-300"><?= $user['created_at'] ?></td>
            <td class="px-4 py-3 border border-gray-300 space-x-2">
              <?php if ($user['status'] !== 'active'): ?>
                <a href="?action=approve&user_id=<?= $user['user_id'] ?>"
                   class="text-green-600 hover:text-green-800 font-semibold"
                   title="Approve">✅ Approve</a>
              <?php endif; ?>

              <?php if ($user['status'] === 'active'): ?>
                <a href="?action=suspend&user_id=<?= $user['user_id'] ?>"
                   class="text-yellow-600 hover:text-yellow-800 font-semibold"
                   title="Suspend">⛔ Suspend</a>
              <?php endif; ?>

              <a href="?action=delete&user_id=<?= $user['user_id'] ?>"
                 onclick="return confirm('Are you sure you want to delete this user?');"
                 class="text-red-600 hover:text-red-800 font-semibold"
                 title="Delete">❌ Delete</a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

<script>
function downloadUsersPDF() {
  // Optionally, you can clone the table and remove the Actions column for the PDF
  // For now, we export as is
  const element = document.getElementById('users-table-section');
  const opt = {
    margin:       0.2,
    filename:     'all_users_report.pdf',
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2 },
    jsPDF:        { unit: 'in', format: 'a4', orientation: 'landscape' }
  };
  html2pdf().set(opt).from(element).save();
}
</script>
</body>
</html>