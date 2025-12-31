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

// Handle actions via POST (approve, suspend, delete-request) with CSRF and prepared statements
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
        // Instead of deleting immediately, create a deletion request for CEO

        // 1. Check that the user exists and get current status & role
        if ($stmt = $conn->prepare("SELECT status, role FROM users WHERE user_id = ?")) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->bind_result($current_status, $target_role);
            if (!$stmt->fetch()) {
                $stmt->close();
                $msg = "User #$user_id not found.";
                header("Location: view_users.php?msg=" . urlencode($msg));
                exit;
            }
            $stmt->close();
        } else {
            $msg = "Failed to look up user #$user_id.";
            header("Location: view_users.php?msg=" . urlencode($msg));
            exit;
        }

        // Optional: prevent requesting deletion of CEO accounts
        if (strtolower($target_role) === 'ceo') {
            $msg = "You cannot request deletion of a CEO account.";
            header("Location: view_users.php?msg=" . urlencode($msg));
            exit;
        }

        // 2. Check if there is already a pending delete request
        if ($stmt = $conn->prepare("SELECT id FROM user_deletion_requests WHERE user_id = ? AND status = 'pending' LIMIT 1")) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $stmt->close();
                $msg = "A deletion request for user #$user_id is already pending CEO approval.";
                header("Location: view_users.php?msg=" . urlencode($msg));
                exit;
            }
            $stmt->close();
        }

        // 3. Insert a new deletion request
        $requested_by = (int)($_SESSION['user_id'] ?? 0);
        $reason = null; // later you can add a reason field to the form and send it here

        if ($stmt = $conn->prepare("
            INSERT INTO user_deletion_requests (user_id, requested_by, previous_status, reason)
            VALUES (?, ?, ?, ?)
        ")) {
            $stmt->bind_param("iiss", $user_id, $requested_by, $current_status, $reason);
            $stmt->execute();
            $stmt->close();
            $msg = "Deletion request for user #$user_id has been sent to the CEO for approval.";
        } else {
            $msg = "Failed to create deletion request for user #$user_id.";
        }
    } else {
        $msg = "Unknown action.";
    }

    header("Location: view_users.php?msg=" . urlencode($msg));
    exit;
}

// Load which users already have a pending deletion request
$pendingDeleteUsers = [];
if ($res = $conn->query("SELECT user_id FROM user_deletion_requests WHERE status = 'pending'")) {
    while ($row = $res->fetch_assoc()) {
        $pendingDeleteUsers[(int)$row['user_id']] = true;
    }
    $res->free();
}

// Fetch users with joined role info (added accountants as 'ac', coordinators as 'cc')
$query = $conn->query("
    SELECT 
        u.user_id, u.username, u.role, u.status, u.created_at,
        COALESCE(
            s.first_name, t.first_name, a.first_name,
            c.first_name, ac.first_name, cc.first_name
        ) AS first_name,
        COALESCE(
            s.last_name, t.last_name, a.last_name,
            c.last_name, ac.last_name, cc.last_name
        ) AS last_name,
        COALESCE(
            s.email, t.email, a.email,
            c.email, ac.email, cc.email
        ) AS email,
        COALESCE(
            s.contact_number, a.contact_number,
            c.contact_number, ac.contact_number
        ) AS contact_number
    FROM users u
    LEFT JOIN students s ON u.user_id = s.user_id
    LEFT JOIN teachers t ON u.user_id = t.user_id
    LEFT JOIN admins a ON u.user_id = a.user_id
    LEFT JOIN ceo c ON u.user_id = c.user_id
    LEFT JOIN accountants ac ON u.user_id = ac.user_id
    LEFT JOIN course_coordinators cc ON u.user_id = cc.user_id
    ORDER BY u.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
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
      body {
        font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial;
        min-height: 100vh;
      }
      body::before {
        content:"";
        position:fixed;
        inset:0;
        background:
          radial-gradient(circle at 0% 0%, rgba(129,140,248,0.20) 0, transparent 55%),
          radial-gradient(circle at 100% 50%, rgba(56,189,248,0.18) 0, transparent 55%);
        pointer-events:none;
        z-index:-1;
      }
      th.sticky { position: sticky; top: 0; z-index: 10; backdrop-filter: blur(10px); }
      form.inline-action { display: inline; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 min-h-screen antialiased text-gray-800">
<?php include 'components/navbar.php'; ?>

<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-6">
  <!-- Hero / Header -->
  <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-950 via-slate-900 to-sky-900 text-white shadow-2xl mb-6">
    <div class="absolute -left-24 -top-24 w-64 h-64 bg-indigo-500/40 rounded-full blur-3xl"></div>
    <div class="absolute -right-24 top-8 w-60 h-60 bg-sky-400/40 rounded-full blur-3xl"></div>

    <div class="relative z-10 px-5 py-6 sm:px-7 sm:py-7 flex flex-col gap-4">
      <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/25">
            <i class="fa-solid fa-users-gear text-[13px]"></i>
          </span>
          <span>Admin · Users</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i class="fa-solid fa-shield-halved text-[12px]"></i>
          <span>Admin access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            All Registered Users
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            Approve, suspend or request deletion of users. Filter by role and status, and export the current view.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a href="admin_dashboard.php"
             class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white/95 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 shadow-sm">
            <i class="fa-solid fa-arrow-left"></i> Back to dashboard
          </a>
          <a href="add_user.php" id="openAddUserBtn"
             class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-indigo-700 shadow-sm active:scale-[0.99]">
            <i class="fa-solid fa-user-plus"></i> Add user
          </a>
          <button onclick="downloadUsersPDF()"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700 shadow-sm active:scale-[0.99]">
            <i class="fa-solid fa-file-pdf"></i> PDF
          </button>
          <button onclick="downloadUsersCSV()"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-teal-700 shadow-sm active:scale-[0.99]">
            <i class="fa-solid fa-file-csv"></i> CSV
          </button>
        </div>
      </div>

      <?php if (!empty($_GET['msg'])): ?>
        <div class="mt-1">
          <div class="relative inline-flex items-start gap-2 rounded-xl px-3 py-2 bg-emerald-50/95 text-emerald-800 ring-1 ring-emerald-200 text-xs">
            <button type="button" onclick="this.parentElement.remove()"
                    class="absolute right-2 top-1.5 text-emerald-400 hover:text-emerald-700"
                    aria-label="Dismiss">
              <i class="fa-solid fa-xmark text-[11px]"></i>
            </button>
            <div class="flex items-center gap-1.5">
              <i class="fa-solid fa-circle-check"></i>
              <span><?= htmlspecialchars($_GET['msg']) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Grid: Sidebar + Main -->
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
    <?php
      $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
      $createAnnouncementLink = '#create-announcement';
      include 'components/admin_tools_sidebar.php';
    ?>

    <!-- Main column -->
    <section class="lg:col-span-9 space-y-3">
      <!-- Mobile: open tools button -->
      <div class="lg:hidden flex justify-end -mt-1">
        <button id="toolsOpen"
                class="inline-flex items-center gap-2 bg-white/95 ring-1 ring-slate-200 px-3 py-1.5 rounded-lg shadow-sm text-[11px] font-medium text-blue-700 hover:bg-blue-50 transition"
                aria-controls="toolsDrawer" aria-expanded="false" aria-label="Open admin tools">
          <i class="fa-solid fa-sliders"></i> Admin tools
        </button>
      </div>

      <!-- Filters -->
      <div class="rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200 p-3">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-2.5 items-center">
          <div class="relative md:col-span-2">
            <input id="searchInput" type="text"
                   placeholder="Search name, username or email..."
                   class="w-full pl-8 pr-2.5 py-2 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   aria-label="Search">
            <i class="fa-solid fa-magnifying-glass w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"
               aria-hidden="true"></i>
          </div>
          <div>
            <select id="roleFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                    aria-label="Filter by role">
              <option value="">All roles</option>
              <option value="admin">Admin</option>
              <option value="teacher">Teacher</option>
              <option value="student">Student</option>
              <option value="ceo">CEO</option>
              <option value="accountant">Accountant</option>
              <option value="coordinator">Coordinator</option>
            </select>
          </div>
          <div class="flex gap-2">
            <select id="statusFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                    aria-label="Filter by status">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
            <button id="clearFilters"
                    class="hidden md:inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg border border-slate-200 text-[11px] sm:text-xs text-slate-600 hover:bg-slate-50"
                    type="button" aria-label="Reset filters">
              <i class="fa-solid fa-rotate-left"></i> Reset
            </button>
          </div>
        </div>
      </div>

      <!-- Table Card -->
      <div class="overflow-hidden rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200">
        <div class="overflow-x-auto" id="users-table-section">
          <table id="usersTable" class="min-w-full text-left border-collapse">
            <thead>
              <tr class="text-slate-700 text-[11px] sm:text-xs bg-slate-50/90 uppercase tracking-wide">
                <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">ID</th>
                <th class="sticky px-3 py-2 border-b border-slate-200">Username</th>
                <th class="sticky px-3 py-2 border-b border-slate-200">Role</th>
                <th class="sticky px-3 py-2 border-b border-slate-200">Full name</th>
                <th class="sticky px-3 py-2 border-b border-slate-200">Email</th>
                <th class="sticky px-3 py-2 border-b border-slate-200">Contact</th>
                <th class="sticky px-3 py-2 border-b border-slate-200">Status</th>
                <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Created</th>
                <th class="sticky px-3 py-2 border-b border-slate-200 text-center">Actions</th>
              </tr>
            </thead>
            <tbody class="text-[11px] sm:text-xs">
              <?php while ($user = $query->fetch_assoc()): 
                $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $fname = $user['first_name'] ?? '';
                $lname = $user['last_name'] ?? '';
                $initials = strtoupper((function($f,$l){ 
                  $a = function_exists('mb_substr'); 
                  $i1 = $a ? mb_substr($f,0,1) : substr($f,0,1); 
                  $i2 = $a ? mb_substr($l,0,1) : substr($l,0,1); 
                  return trim($i1.$i2) ?: '--'; 
                })($fname,$lname));
                $role = strtolower($user['role']);
                $status = strtolower($user['status'] ?? '');
                $roleBadge = 'bg-gray-100 text-gray-700';
                $roleIcon  = 'fa-user';
                if ($role === 'admin')      { $roleBadge = 'bg-purple-100 text-purple-700';   $roleIcon = 'fa-user-shield'; }
                elseif ($role === 'teacher')   { $roleBadge = 'bg-indigo-100 text-indigo-700';   $roleIcon = 'fa-chalkboard-user'; }
                elseif ($role === 'student')   { $roleBadge = 'bg-emerald-100 text-emerald-700'; $roleIcon = 'fa-user-graduate'; }
                elseif ($role === 'ceo')       { $roleBadge = 'bg-blue-100 text-blue-700';       $roleIcon = 'fa-user-tie'; }
                elseif ($role === 'accountant'){ $roleBadge = 'bg-cyan-100 text-cyan-700';       $roleIcon = 'fa-calculator'; }
                elseif ($role === 'coordinator'){ $roleBadge = 'bg-fuchsia-100 text-fuchsia-700'; $roleIcon = 'fa-user-gear'; }

                $statusBadge = 'bg-gray-100 text-gray-700';
                $statusIcon  = 'fa-circle-question';
                if ($status === 'active') {
                    $statusBadge = 'bg-emerald-100 text-emerald-700'; 
                    $statusIcon = 'fa-circle-check';
                } elseif ($status === 'suspended') {
                    $statusBadge = 'bg-amber-100 text-amber-700';    
                    $statusIcon = 'fa-ban';
                } elseif ($status === 'inactive') {
                    $statusBadge = 'bg-slate-100 text-slate-700';
                    $statusIcon = 'fa-circle-minus';
                } elseif ($status === 'pending') { // legacy support if it still exists
                    $statusBadge = 'bg-slate-100 text-slate-700';
                    $statusIcon = 'fa-hourglass-half';
                } elseif ($status && !in_array($status, ['active','inactive','suspended','pending'])) {
                    $statusBadge = 'bg-red-100 text-red-700'; 
                    $statusIcon = 'fa-triangle-exclamation';
                }

                $createdFmt = !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : '';
                $searchKey = strtolower(trim($fullName . ' ' . $user['username'] . ' ' . ($user['email'] ?? '')));
                $hasPendingDelete = !empty($pendingDeleteUsers[(int)$user['user_id']] ?? false);
              ?>
              <tr class="hover:bg-slate-50 even:bg-slate-50/40"
                  data-role="<?= htmlspecialchars($role) ?>"
                  data-status="<?= htmlspecialchars($status) ?>"
                  data-key="<?= htmlspecialchars($searchKey) ?>">
                <td class="px-3 py-2 border-b border-slate-100 text-slate-600"><?= (int)$user['user_id'] ?></td>
                <td class="px-3 py-2 border-b border-slate-100 truncate text-slate-800">@<?= htmlspecialchars($user['username']) ?></td>
                <td class="px-3 py-2 border-b border-slate-100">
                  <span class="inline-flex items-center gap-1 text-[10px] px-1.75 py-0.5 rounded-full <?= $roleBadge ?> ring-1 ring-black/5">
                    <i class="fa-solid <?= $roleIcon ?>"></i>
                    <?= ucfirst(htmlspecialchars($role)) ?>
                  </span>
                </td>
                <td class="px-3 py-2 border-b border-slate-100">
                  <div class="flex items-center gap-2.5 min-w-0">
                    <div class="flex items-center justify-center h-7 w-7 rounded-full bg-blue-100 text-blue-700 font-semibold ring-1 ring-blue-200 text-[10px]" aria-label="User initials">
                      <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="min-w-0">
                      <div class="font-medium text-slate-900 truncate"><?= htmlspecialchars($fullName ?: '—') ?></div>
                    </div>
                  </div>
                </td>
                <td class="px-3 py-2 border-b border-slate-100 break-words">
                  <span class="inline-flex items-center gap-1 text-slate-700">
                    <i class="fa-regular fa-envelope text-slate-400"></i>
                    <?= htmlspecialchars($user['email'] ?? '—') ?>
                  </span>
                </td>
                <td class="px-3 py-2 border-b border-slate-100">
                  <span class="inline-flex items-center gap-1 text-slate-700">
                    <i class="fa-solid fa-phone text-slate-400"></i>
                    <?= htmlspecialchars($user['contact_number'] ?? '—') ?>
                  </span>
                </td>
                <td class="px-3 py-2 border-b border-slate-100">
                  <span class="inline-flex items-center gap-1 text-[10px] px-1.75 py-0.5 rounded-full <?= $statusBadge ?> ring-1 ring-black/5">
                    <i class="fa-solid <?= $statusIcon ?>"></i>
                    <?= htmlspecialchars(ucfirst($status ?: '—')) ?>
                  </span>
                </td>
                <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap text-slate-600">
                  <span class="inline-flex items-center gap-1">
                    <i class="fa-regular fa-calendar-plus text-slate-400"></i>
                    <?= htmlspecialchars($createdFmt ?: '—') ?>
                  </span>
                </td>
                <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap text-center">
                  <div class="flex flex-wrap justify-center gap-1.5">
                    <?php if ($user['status'] !== 'active'): ?>
                      <form method="POST" class="inline-action">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                        <button type="submit"
                          class="inline-flex items-center gap-1 text-emerald-700 hover:text-emerald-900 px-2.5 py-0.5 rounded-md ring-1 ring-emerald-200 bg-emerald-50 text-[10px] font-medium"
                          title="Approve">
                          <i class="fa-solid fa-circle-check"></i> Approve
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if ($user['status'] === 'active'): ?>
                      <form method="POST" class="inline-action">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                        <button type="submit"
                          class="inline-flex items-center gap-1 text-amber-700 hover:text-amber-900 px-2.5 py-0.5 rounded-md ring-1 ring-amber-200 bg-amber-50 text-[10px] font-medium"
                          title="Suspend">
                          <i class="fa-solid fa-user-slash"></i> Suspend
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if ($hasPendingDelete): ?>
                      <span class="inline-flex items-center gap-1 text-rose-600 text-[10px] px-2.5 py-0.5 rounded-md bg-rose-50 ring-1 ring-rose-200"
                            title="Awaiting CEO approval">
                        <i class="fa-solid fa-hourglass-half"></i> Awaiting CEO
                      </span>
                    <?php else: ?>
                      <form method="POST" class="inline-action"
                            onsubmit="return confirm('Send a delete request for this user to the CEO for approval?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                        <button type="submit"
                          class="inline-flex items-center gap-1 text-rose-700 hover:text-rose-900 px-2.5 py-0.5 rounded-md ring-1 ring-rose-200 bg-rose-50 text-[10px] font-medium"
                          title="Request deletion (CEO must approve)">
                          <i class="fa-regular fa-trash-can"></i> Request delete
                        </button>
                      </form>
                    <?php endif; ?>
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

<!-- Add User Modal -->
<div id="addUserModal" class="fixed inset-0 z-[60] hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <!-- Backdrop -->
  <div id="addUserOverlay" class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm"></div>

  <!-- Modal -->
  <div class="absolute inset-0 flex items-start justify-center p-4 sm:p-6">
    <div class="w-full max-w-xl mt-16 sm:mt-24 rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 overflow-hidden">
      <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 bg-slate-50/70">
        <h3 id="addUserTitle" class="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
          <i class="fa-solid fa-user-plus text-indigo-600"></i>
          Add new user
        </h3>
        <button id="closeAddUserBtn" class="text-slate-400 hover:text-slate-600 p-1 rounded-md hover:bg-slate-100"
                aria-label="Close add user form">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <form action="add_user.php" method="POST" class="p-4 space-y-3" id="addUserForm" aria-labelledby="addUserTitle">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Username</label>
            <input name="username" autocomplete="off" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Password</label>
            <div class="relative mt-0.5">
              <input id="passwordInput" type="password" name="password" minlength="8" autocomplete="new-password" required
                     class="w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300 pr-8" />
              <button type="button" id="togglePwd"
                      class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                      aria-label="Toggle password visibility">
                <i class="fa-regular fa-eye"></i>
              </button>
            </div>
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">First name</label>
            <input name="first_name" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Last name</label>
            <input name="last_name" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Email</label>
            <input type="email" name="email" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Contact number</label>
            <input type="tel" name="contact_number" pattern="^[0-9+\-\s()]{7,}$"
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Role</label>
            <select name="role" required
                    class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">Select role</option>
              <option value="admin">Admin</option>
              <option value="teacher">Teacher</option>
              <option value="student">Student</option>
              <option value="ceo">CEO</option>
              <option value="accountant">Accountant</option>
              <option value="coordinator">Coordinator</option>
            </select>
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Status</label>
            <select name="status"
                    class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
        </div>

        <div class="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 mt-1">
          <button type="button"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.75 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  id="cancelAddUserBtn">
            Cancel
          </button>
          <button type="submit"
                  class="inline-flex items-center gap-1 rounded-md bg-indigo-600 text-white px-3.5 py-1.75 text-xs font-semibold hover:bg-indigo-700 shadow-sm">
            <i class="fa-solid fa-floppy-disk"></i> Create user
          </button>
        </div>
      </form>
    </div>
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

  rows.forEach(tr => {
    const key = (tr.getAttribute('data-key') || '').toLowerCase();
    const r = (tr.getAttribute('data-role') || '').toLowerCase();
    const s = (tr.getAttribute('data-status') || '').toLowerCase();

    const matchQ = !q || key.includes(q);
    const matchR = !role || r === role;
    const matchS = !status || s === status;

    tr.style.display = (matchQ && matchR && matchS) ? '' : 'none';
  });

  clearBtn.classList.toggle('opacity-50', !q && !role && !status);
}
searchInput?.addEventListener('input', applyFilters);
roleFilter?.addEventListener('change', applyFilters);
statusFilter?.addEventListener('change', applyFilters);
clearBtn?.addEventListener('click', () => {
  searchInput.value = '';
  roleFilter.value = '';
  statusFilter.value = '';
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

    function onKeydown(e) {
      if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); }
    }

    function openDrawer() {
      prevFocus = document.activeElement;
      if (!drawer) return;
      drawer.style.transform = 'translateX(0)';
      overlay && overlay.classList.remove('hidden');
      drawer.setAttribute('aria-hidden', 'false');
      openBtn?.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
      const first = drawer.querySelector('a,button');
      first && first.focus();
      document.addEventListener('keydown', onKeydown);
      overlay && overlay.addEventListener('click', closeDrawer, { once: true });
    }

    function closeDrawer() {
      if (!drawer) return;
      drawer.style.transform = 'translateX(-100%)';
      overlay && overlay.classList.add('hidden');
      drawer.setAttribute('aria-hidden', 'true');
      openBtn?.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeydown);
      prevFocus && prevFocus.focus && prevFocus.focus();
    }

    openBtn?.addEventListener('click', openDrawer);
    closeBtn?.addEventListener('click', closeDrawer);
    drawer && drawer.addEventListener('click', (e) => {
      const t = e.target.closest('a,button');
      if (!t) return;
      closeDrawer();
    });
    window.addEventListener('resize', () => { if (window.innerWidth >= 1024) closeDrawer(); });
  })();
</script>

<!-- Add User Modal logic -->
<script>
  (function () {
    const openBtn = document.getElementById('openAddUserBtn');
    const modal = document.getElementById('addUserModal');
    const overlay = document.getElementById('addUserOverlay');
    const closeBtn = document.getElementById('closeAddUserBtn');
    const cancelBtn = document.getElementById('cancelAddUserBtn');
    const form = document.getElementById('addUserForm');
    const firstField = form?.querySelector('input[name="username"]');
    const pwd = document.getElementById('passwordInput');
    const togglePwd = document.getElementById('togglePwd');

    let prevFocus = null;

    function openModal(e) {
      if (e) e.preventDefault(); // fallback to add_user.php if JS disabled
      if (!modal) return;
      prevFocus = document.activeElement;
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(() => firstField?.focus(), 20);
      document.addEventListener('keydown', onKeydown);
    }

    function closeModal() {
      modal?.classList.add('hidden');
      modal?.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeydown);
      prevFocus && prevFocus.focus && prevFocus.focus();
    }

    function onKeydown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal();
      }
    }

    openBtn?.addEventListener('click', openModal);
    overlay?.addEventListener('click', closeModal);
    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);

    // Toggle password visibility
    togglePwd?.addEventListener('click', () => {
      if (!pwd) return;
      const isHidden = pwd.type === 'password';
      pwd.type = isHidden ? 'text' : 'password';
      togglePwd.innerHTML = isHidden
        ? '<i class="fa-regular fa-eye-slash"></i>'
        : '<i class="fa-regular fa-eye"></i>';
    });
  })();
</script>

</body>
</html>