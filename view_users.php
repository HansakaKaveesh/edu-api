<?php 
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied.");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle actions via POST (approve, suspend, delete) with CSRF and prepared statements
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $action = $_POST['action'];
    $user_id = (int)$_POST['user_id'];
    $msg = '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid session. Please refresh and try again.';
        header("Location: view_users.php?msg=" . urlencode($msg));
        exit;
    }

    if ($action === 'approve') {
        if ($stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE user_id = ?")) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
            $msg = "User #$user_id approved.";
        } else {
            $msg = "Failed to approve user #$user_id.";
        }
    } elseif ($action === 'suspend') {
        if ($stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE user_id = ?")) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
            $msg = "User #$user_id suspended.";
        } else {
            $msg = "Failed to suspend user #$user_id.";
        }
    } elseif ($action === 'delete') {
        // Manual cascade delete: students, teachers, admins, then user (in a transaction)
        $conn->begin_transaction();
        try {
            if ($stmt = $conn->prepare("DELETE FROM students WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $conn->prepare("DELETE FROM teachers WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $conn->prepare("DELETE FROM admins WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $msg = "User #$user_id and related records deleted.";
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = "Failed to delete user #$user_id. Please try again.";
        }
    } else {
        $msg = "Unknown action.";
    }

    header("Location: view_users.php?msg=" . urlencode($msg));
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
      html { scroll-behavior: smooth; }
      body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
      th.sticky { position: sticky; top: 0; z-index: 10; }
      form.inline-action { display: inline; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 min-h-screen antialiased text-gray-800">
<?php include 'components/navbar.php'; ?>

<div class="max-w-8xl mx-auto px-6 pt-28 pb-10">
  <!-- Header Card -->
  <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6 mb-6">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
      <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
      <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
    </div>
    <div class="relative flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-blue-700 tracking-tight flex items-center gap-2">
          👥 All Registered Users
        </h2>
        <p class="text-gray-600 mt-1">Approve, suspend, or delete users. Filter and export as needed.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="admin_dashboard.php" class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
          ← Back to Dashboard
        </a>
        <a href="add_user.php" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 text-white px-4 py-2 hover:bg-indigo-700 shadow-sm">
          ➕ Add New User
        </a>
        <button onclick="downloadUsersPDF()" class="inline-flex items-center gap-2 rounded-lg bg-green-600 text-white px-4 py-2 hover:bg-green-700 shadow-sm">
          ⬇️ Download PDF
        </button>
        <button onclick="downloadUsersCSV()" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 text-white px-4 py-2 hover:bg-emerald-700 shadow-sm">
          ⬇️ Download CSV
        </button>
      </div>
    </div>

    <!-- Message -->
    <?php if (!empty($_GET['msg'])): ?>
      <div class="mt-4">
        <div class="relative rounded-xl px-4 py-3 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
          <button type="button" onclick="this.parentElement.remove()" class="absolute right-2 top-2 text-gray-400 hover:text-gray-600">✕</button>
          <div class="font-medium"><?= htmlspecialchars($_GET['msg']) ?></div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Grid: Sidebar + Main -->
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <?php
      $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
      $createAnnouncementLink = '#create-announcement';
      include 'components/admin_tools_sidebar.php';
    ?>

    <!-- Main column -->
    <section class="lg:col-span-9 space-y-4">
      <!-- Mobile: open tools button -->
      <div class="lg:hidden flex justify-end">
        <button id="toolsOpen"
                class="inline-flex items-center gap-2 bg-white ring-1 ring-gray-200 px-3 py-2 rounded-lg shadow-sm text-blue-700 hover:bg-blue-50 transition"
                aria-controls="toolsDrawer" aria-expanded="false" aria-label="Open admin tools">
          <span class="fa-solid fa-sliders"></span> Admin Tools
        </button>
      </div>

      <!-- Filters -->
      <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div class="relative md:col-span-2">
            <input id="searchInput" type="text" placeholder="Search by name, username or email..." class="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M8.5 3.5a5 5 0 013.905 8.132l3.231 3.232a.75.75 0 11-1.06 1.06l-3.232-3.23A5 5 0 118.5 3.5zm0 1.5a3.5 3.5 0 100 7 3.5 3.5 0 000-7z" clip-rule="evenodd"/>
            </svg>
          </div>
          <div>
            <select id="roleFilter" class="w-full py-2.5 px-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All Roles</option>
              <option value="admin">Admin</option>
              <option value="teacher">Teacher</option>
              <option value="student">Student</option>
            </select>
          </div>
          <div class="flex gap-2">
            <select id="statusFilter" class="w-full py-2.5 px-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All Statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="pending">Pending</option>
            </select>
            <button id="clearFilters" class="hidden md:inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50">Reset</button>
          </div>
        </div>
      </div>

      <!-- Table Card -->
      <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
        <div class="overflow-x-auto" id="users-table-section">
          <table id="usersTable" class="min-w-full text-left border-collapse">
            <thead>
              <tr class="text-gray-700 text-sm bg-gray-100/80">
                <th class="sticky px-4 py-3 border-b border-gray-200 whitespace-nowrap">User ID</th>
                <th class="sticky px-4 py-3 border-b border-gray-200">Username</th>
                <th class="sticky px-4 py-3 border-b border-gray-200">Role</th>
                <th class="sticky px-4 py-3 border-b border-gray-200">Full Name</th>
                <th class="sticky px-4 py-3 border-b border-gray-200">Email</th>
                <th class="sticky px-4 py-3 border-b border-gray-200">Contact</th>
                <th class="sticky px-4 py-3 border-b border-gray-200">Status</th>
                <th class="sticky px-4 py-3 border-b border-gray-200 whitespace-nowrap">Created At</th>
                <th class="sticky px-4 py-3 border-b border-gray-200">Actions</th>
              </tr>
            </thead>
            <tbody class="text-sm">
              <?php while ($user = $query->fetch_assoc()): 
                $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $fname = $user['first_name'] ?? '';
                $lname = $user['last_name'] ?? '';
                $initials = strtoupper((function($f,$l){ 
                  $a = function_exists('mb_substr'); 
                  $i1 = $a ? mb_substr($f,0,1) : substr($f,0,1); 
                  $i2 = $a ? mb_substr($l,0,1) : substr($l,0,1); 
                  return $i1.$i2; 
                })($fname,$lname));
                $role = strtolower($user['role']);
                $status = strtolower($user['status'] ?? '');
                $roleBadge = 'bg-gray-100 text-gray-700';
                if ($role === 'admin') $roleBadge = 'bg-purple-100 text-purple-700';
                elseif ($role === 'teacher') $roleBadge = 'bg-indigo-100 text-indigo-700';
                elseif ($role === 'student') $roleBadge = 'bg-emerald-100 text-emerald-700';
                $statusBadge = 'bg-gray-100 text-gray-700';
                if ($status === 'active') $statusBadge = 'bg-emerald-100 text-emerald-700';
                elseif ($status === 'suspended') $statusBadge = 'bg-amber-100 text-amber-700';
                elseif ($status === 'pending') $statusBadge = 'bg-gray-100 text-gray-700';
                elseif ($status && !in_array($status, ['active','suspended','pending'])) $statusBadge = 'bg-red-100 text-red-700';
                $createdFmt = !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : '';
                $searchKey = strtolower(trim($fullName . ' ' . $user['username'] . ' ' . ($user['email'] ?? '')));
              ?>
              <tr class="hover:bg-slate-50 odd:bg-white even:bg-slate-50"
                  data-role="<?= htmlspecialchars($role) ?>"
                  data-status="<?= htmlspecialchars($status) ?>"
                  data-key="<?= htmlspecialchars($searchKey) ?>">
                <td class="px-4 py-3 border-b border-gray-100"><?= (int)$user['user_id'] ?></td>
                <td class="px-4 py-3 border-b border-gray-100 truncate">@<?= htmlspecialchars($user['username']) ?></td>
                <td class="px-4 py-3 border-b border-gray-100">
                  <span class="inline-block text-[11px] px-2 py-0.5 rounded-full <?= $roleBadge ?>"><?= ucfirst(htmlspecialchars($role)) ?></span>
                </td>
                <td class="px-4 py-3 border-b border-gray-100">
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-blue-100 text-blue-700 font-semibold ring-1 ring-blue-200">
                      <?= htmlspecialchars($initials ?: '👤') ?>
                    </div>
                    <div class="min-w-0">
                      <div class="font-medium text-gray-900 truncate"><?= htmlspecialchars($fullName ?: '—') ?></div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3 border-b border-gray-100 break-words"><?= htmlspecialchars($user['email'] ?? '—') ?></td>
                <td class="px-4 py-3 border-b border-gray-100"><?= htmlspecialchars($user['contact_number'] ?? '—') ?></td>
                <td class="px-4 py-3 border-b border-gray-100">
                  <span class="inline-block text-[11px] px-2 py-0.5 rounded-full <?= $statusBadge ?>">
                    <?= htmlspecialchars(ucfirst($status ?: '—')) ?>
                  </span>
                </td>
                <td class="px-4 py-3 border-b border-gray-100 whitespace-nowrap"><?= htmlspecialchars($createdFmt ?: '—') ?></td>
                <td class="px-4 py-3 border-b border-gray-100 whitespace-nowrap">
                  <div class="flex flex-wrap gap-2">
                    <?php if ($user['status'] !== 'active'): ?>
                      <form method="POST" class="inline-action">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                        <button type="submit"
                          class="inline-flex items-center gap-1 text-emerald-700 hover:text-emerald-900 px-2 py-1 rounded-md ring-1 ring-emerald-200 bg-emerald-50"
                          title="Approve">✅ Approve</button>
                      </form>
                    <?php endif; ?>

                    <?php if ($user['status'] === 'active'): ?>
                      <form method="POST" class="inline-action">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                        <button type="submit"
                          class="inline-flex items-center gap-1 text-amber-700 hover:text-amber-900 px-2 py-1 rounded-md ring-1 ring-amber-200 bg-amber-50"
                          title="Suspend">⛔ Suspend</button>
                      </form>
                    <?php endif; ?>

                    <form method="POST" class="inline-action" onsubmit="return confirm('Are you sure you want to delete this user and related records?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                      <button type="submit"
                        class="inline-flex items-center gap-1 text-red-700 hover:text-red-900 px-2 py-1 rounded-md ring-1 ring-red-200 bg-red-50"
                        title="Delete">❌ Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
// PDF
function downloadUsersPDF() {
  const element = document.getElementById('users-table-section');
  const opt = {
    margin: 0.2,
    filename: 'all_users_report.pdf',
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2 },
    jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
  };
  html2pdf().set(opt).from(element).save();
}

// CSV
function downloadUsersCSV() {
  const table = document.getElementById('usersTable');
  const rows = [...table.querySelectorAll('tbody tr')].filter(r => r.style.display !== 'none');
  const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
  const data = [headers];
  rows.forEach(r => {
    const cols = [...r.children].map(td => td.innerText.replace(/\s+/g, ' ').trim());
    data.push(cols);
  });
  const csv = data.map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = 'all_users_report.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// Filters
const searchInput = document.getElementById('searchInput');
const roleFilter = document.getElementById('roleFilter');
const statusFilter = document.getElementById('statusFilter');
const clearBtn = document.getElementById('clearFilters');
const rows = [...document.querySelectorAll('#usersTable tbody tr')];

function applyFilters() {
  const q = (searchInput.value || '').toLowerCase().trim();
  const role = (roleFilter.value || '').toLowerCase();
  const status = (statusFilter.value || '').toLowerCase();

  let visible = 0;
  rows.forEach(tr => {
    const key = (tr.getAttribute('data-key') || '').toLowerCase();
    const r = (tr.getAttribute('data-role') || '').toLowerCase();
    const s = (tr.getAttribute('data-status') || '').toLowerCase();

    const matchQ = !q || key.includes(q);
    const matchR = !role || r === role;
    const matchS = !status || s === status;

    const show = matchQ && matchR && matchS;
    tr.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  clearBtn.classList.toggle('opacity-50', !q && !role && !status);
}
if (searchInput) searchInput.addEventListener('input', applyFilters);
if (roleFilter) roleFilter.addEventListener('change', applyFilters);
if (statusFilter) statusFilter.addEventListener('change', applyFilters);
if (clearBtn) clearBtn.addEventListener('click', () => {
  searchInput.value = ''; roleFilter.value = ''; statusFilter.value = '';
  applyFilters();
});
applyFilters();
</script>

<!-- Mobile tools drawer controls -->
<script>
  (function() {
    const openBtn = document.getElementById('toolsOpen');
    const closeBtn = document.getElementById('toolsClose');
    const drawer = document.getElementById('toolsDrawer');
    const overlay = document.getElementById('toolsOverlay');
    let prevFocus = null;

    function openDrawer() {
      prevFocus = document.activeElement;
      drawer.style.transform = 'translateX(0)';
      overlay.classList.remove('hidden');
      drawer.setAttribute('aria-hidden', 'false');
      openBtn?.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
      const first = drawer.querySelector('a,button');
      first && first.focus();
      document.addEventListener('keydown', onKeydown);
      overlay.addEventListener('click', closeDrawer, { once: true });
    }
    function closeDrawer() {
      drawer.style.transform = 'translateX(-100%)';
      overlay.classList.add('hidden');
      drawer.setAttribute('aria-hidden', 'true');
      openBtn?.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeydown);
      prevFocus && prevFocus.focus && prevFocus.focus();
    }
    function onKeydown(e) {
      if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); }
    }

    openBtn?.addEventListener('click', openDrawer);
    closeBtn?.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (e) => {
      const t = e.target.closest('a,button');
      if (!t) return;
      closeDrawer();
    });
    window.addEventListener('resize', () => { if (window.innerWidth >= 1024) closeDrawer(); });
  })();
</script>

</body>
</html>