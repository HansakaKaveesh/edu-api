<?php 
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access Denied.");
}

// Handle actions (approve, suspend, delete)
if (isset($_GET['action'], $_GET['user_id'])) {
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

// Fetch users with joined role info
$query = $conn->query("
    SELECT 
        u.user_id, u.username, u.role, u.status, u.created_at,
        COALESCE(s.first_name, t.first_name, a.first_name) AS first_name,
        COALESCE(s.last_name, t.last_name, a.last_name) AS last_name,
        COALESCE(s.email, t.email, a.email) AS email,
        COALESCE(s.contact_number, a.contact_number) AS contact_number
    FROM users u
    LEFT JOIN students s ON u.user_id = s.user_id
    LEFT JOIN teachers t ON u.user_id = t.user_id
    LEFT JOIN admins a ON u.user_id = a.user_id
    ORDER BY u.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>All Users (Admin Panel)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen p-4 sm:p-6">
<?php include 'components/navbar.php'; ?>

<div class="w-full max-w-7xl bg-white rounded-lg shadow mx-auto mt-24 p-4 sm:p-6">

    <?php if (!empty($_GET['msg'])): ?>
    <div class="mb-4 sm:mb-6 p-4 bg-green-100 text-green-800 rounded">
        <?= htmlspecialchars($_GET['msg']) ?>
    </div>
    <?php endif; ?>

    <h2 class="text-2xl sm:text-3xl font-semibold mb-4 sm:mb-6 text-center sm:text-left">👥 All Registered Users</h2>

    <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-3 mb-4">
      <a href="admin_dashboard.php" class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-center">
        ⬅ Back to Dashboard
      </a>
      <a href="add_user.php" class="inline-block px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 text-center">
        ➕ Add New User
      </a>
      <button onclick="downloadUsersPDF()" class="inline-block px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-center">
        ⬇️ Download as PDF
      </button>
    </div>

    <div class="overflow-x-auto text-sm sm:text-base" id="users-table-section">
      <table class="min-w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-200 text-gray-700">
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300 whitespace-nowrap">User ID</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">Username</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">Role</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">Full Name</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">Email</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">Contact</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">Status</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300 whitespace-nowrap">Created At</th>
            <th class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($user = $query->fetch_assoc()): ?>
          <tr class="hover:bg-gray-50">
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300"><?= (int)$user['user_id'] ?></td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300 truncate"><?= htmlspecialchars($user['username']) ?></td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300"><?= ucfirst(htmlspecialchars($user['role'])) ?></td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300 break-words"><?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?></td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300 break-words"><?= htmlspecialchars($user['email']) ?></td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300"><?= htmlspecialchars($user['contact_number'] ?? '-') ?></td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300">
              <?php if ($user['status'] === 'active'): ?>
                <span class="inline-block px-2 py-1 rounded bg-green-100 text-green-800 font-semibold text-xs sm:text-sm">Active</span>
              <?php elseif ($user['status'] === 'suspended'): ?>
                <span class="inline-block px-2 py-1 rounded bg-yellow-100 text-yellow-800 font-semibold text-xs sm:text-sm">Suspended</span>
              <?php else: ?>
                <span class="inline-block px-2 py-1 rounded bg-red-100 text-red-800 font-semibold text-xs sm:text-sm"><?= htmlspecialchars($user['status']) ?></span>
              <?php endif; ?>
            </td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300 whitespace-nowrap"><?= htmlspecialchars($user['created_at']) ?></td>
            <td class="px-2 sm:px-4 py-2 sm:py-3 border border-gray-300 space-y-1 sm:space-y-0 sm:space-x-2 whitespace-nowrap">
              <?php if ($user['status'] !== 'active'): ?>
                <a href="?action=approve&user_id=<?= (int)$user['user_id'] ?>" class="text-green-600 hover:text-green-800 font-semibold block sm:inline" title="Approve">✅ Approve</a>
              <?php endif; ?>
              <?php if ($user['status'] === 'active'): ?>
                <a href="?action=suspend&user_id=<?= (int)$user['user_id'] ?>" class="text-yellow-600 hover:text-yellow-800 font-semibold block sm:inline" title="Suspend">⛔ Suspend</a>
              <?php endif; ?>
              <a href="?action=delete&user_id=<?= (int)$user['user_id'] ?>" onclick="return confirm('Are you sure you want to delete this user?');" class="text-red-600 hover:text-red-800 font-semibold block sm:inline" title="Delete">❌ Delete</a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
</div>

<script>
function downloadUsersPDF() {
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
