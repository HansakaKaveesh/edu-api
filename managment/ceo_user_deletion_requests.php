<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ceo') {
    die("Access Denied.");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle CEO actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action = $_POST['action'];
    $request_id = (int)$_POST['request_id'];
    $msg = '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid session. Please refresh and try again.';
        header("Location: ceo_user_deletion_requests.php?msg=" . urlencode($msg));
        exit;
    }

    $ceo_id = (int)($_SESSION['user_id'] ?? 0);

    if (!in_array($action, ['approve', 'reject'], true)) {
        $msg = 'Unknown action.';
        header("Location: ceo_user_deletion_requests.php?msg=" . urlencode($msg));
        exit;
    }

    $conn->begin_transaction();
    try {
        // Lock the request row
        if ($stmt = $conn->prepare("SELECT user_id, status, previous_status FROM user_deletion_requests WHERE id = ? FOR UPDATE")) {
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $stmt->bind_result($user_id, $req_status, $previous_status);
            if (!$stmt->fetch()) {
                $stmt->close();
                throw new Exception("Request not found.");
            }
            $stmt->close();
        } else {
            throw new Exception("Failed to load request.");
        }

        if ($req_status !== 'pending') {
            throw new Exception("Request is no longer pending.");
        }

        if ($action === 'approve') {
            // Manual cascade deletion, similar to admin code
            if ($stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->bind_result($role);
                if ($stmt->fetch() && strtolower($role) === 'ceo') {
                    $stmt->close();
                    throw new Exception("Cannot delete CEO accounts.");
                }
                $stmt->close();
            }

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
            if ($stmt = $conn->prepare("DELETE FROM ceo WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $conn->prepare("DELETE FROM accountants WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $conn->prepare("DELETE FROM course_coordinators WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }

            // Mark request as approved
            if ($stmt = $conn->prepare("
                UPDATE user_deletion_requests
                SET status = 'approved', decided_at = NOW(), decided_by = ?
                WHERE id = ?
            ")) {
                $stmt->bind_param("ii", $ceo_id, $request_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $msg = "Deletion approved. User #$user_id has been deleted.";
        } else { // reject
            // Optional: restore status, if you changed it before
            if ($stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?")) {
                $stmt->bind_param("si", $previous_status, $user_id);
                $stmt->execute();
                $stmt->close();
            }

            if ($stmt = $conn->prepare("
                UPDATE user_deletion_requests
                SET status = 'rejected', decided_at = NOW(), decided_by = ?
                WHERE id = ?
            ")) {
                $stmt->bind_param("ii", $ceo_id, $request_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $msg = "Deletion request rejected. User #$user_id remains in the system.";
        }
    } catch (Throwable $e) {
        $conn->rollback();
        $msg = "Operation failed: " . $e->getMessage();
    }

    header("Location: ceo_user_deletion_requests.php?msg=" . urlencode($msg));
    exit;
}

// Load pending requests for display
$sql = "
    SELECT
        r.id AS request_id,
        r.user_id,
        r.requested_by,
        r.status,
        r.created_at,
        r.previous_status,
        r.reason,
        u.username AS user_username,
        u.role     AS user_role,
        rb.username AS requested_by_username
    FROM user_deletion_requests r
    JOIN users u  ON r.user_id = u.user_id
    JOIN users rb ON r.requested_by = rb.user_id
    WHERE r.status = 'pending'
    ORDER BY r.created_at DESC
";
$requests = $conn->query($sql);

// Count for sidebar badge
$pendingCount = 0;
if ($requests) $pendingCount = $requests->num_rows;

// Prepare custom nav for CEO sidebar, including this page
$navItems = [
    ['label' => 'Dashboard',         'href' => 'ceo_dashboard.php',                'icon' => 'grid-outline'],
    ['label' => 'Payments',          'href' => 'ceo_payments.php',                 'icon' => 'card-outline'],
    ['label' => 'Teachers',          'href' => 'ceo_teachers.php',                 'icon' => 'easel-outline'],
    ['label' => 'Students',          'href' => 'ceo_students.php',                 'icon' => 'school-outline'],
    [
        'label'      => 'Deletion Requests',
        'href'       => 'ceo_user_deletion_requests.php',
        'icon'       => 'trash-outline',
        'badge'      => $pendingCount ?: '',
        'badgeClass' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
    ],
    ['label' => 'Top Courses',       'href' => 'ceo_courses.php',                  'icon' => 'trophy-outline'],
    ['label' => 'Settings',          'href' => 'ceo_settings.php',                 'icon' => 'settings-outline'],
    ['label' => 'Logout',            'href' => 'logout.php',                       'icon' => 'log-out-outline'],
];

$currentPath = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Deletion Requests (CEO)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>
<body class="bg-slate-50 min-h-screen">
<?php include 'components/navbar.php'; ?>

<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-8 grid grid-cols-1 lg:grid-cols-12 gap-4">
  <aside class="lg:col-span-3">
    <?php include 'components/ceo_sidebar.php'; ?>
  </aside>

  <main class="lg:col-span-9 space-y-4">
    <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-4">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
            <ion-icon name="trash-outline" class="text-xl text-rose-500"></ion-icon>
            User Deletion Requests
          </h1>
          <p class="text-xs text-slate-500">
            Approve or reject requests sent by admins before a user is permanently removed.
          </p>
        </div>
      </div>

      <?php if (!empty($_GET['msg'])): ?>
        <div class="mb-3 text-xs px-3 py-2 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200">
          <?= htmlspecialchars($_GET['msg']) ?>
        </div>
      <?php endif; ?>

      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-xs">
          <thead>
            <tr class="bg-slate-50 text-slate-600 uppercase tracking-wide text-[11px]">
              <th class="px-3 py-2 border-b">Req ID</th>
              <th class="px-3 py-2 border-b">User</th>
              <th class="px-3 py-2 border-b">Role</th>
              <th class="px-3 py-2 border-b">Requested by</th>
              <th class="px-3 py-2 border-b">Requested at</th>
              <th class="px-3 py-2 border-b text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($requests && $requests->num_rows > 0): ?>
            <?php while ($r = $requests->fetch_assoc()): ?>
              <tr class="border-b last:border-0 hover:bg-slate-50">
                <td class="px-3 py-2 text-slate-600">#<?= (int)$r['request_id'] ?></td>
                <td class="px-3 py-2">
                  <div class="font-medium text-slate-900">@<?= htmlspecialchars($r['user_username']) ?></div>
                  <div class="text-[11px] text-slate-500">User ID: <?= (int)$r['user_id'] ?></div>
                </td>
                <td class="px-3 py-2 text-slate-700">
                  <?= htmlspecialchars(ucfirst(strtolower($r['user_role']))) ?>
                </td>
                <td class="px-3 py-2 text-slate-700">
                  @<?= htmlspecialchars($r['requested_by_username']) ?>
                </td>
                <td class="px-3 py-2 text-slate-600">
                  <?= htmlspecialchars(date('M j, Y H:i', strtotime($r['created_at']))) ?>
                </td>
                <td class="px-3 py-2 text-center">
                  <div class="inline-flex gap-1.5">
                    <form method="POST" onsubmit="return confirm('Approve and permanently delete this user?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                      <button type="submit"
                              class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px]">
                        <ion-icon name="checkmark-circle-outline"></ion-icon> Approve
                      </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Reject this deletion request?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                      <button type="submit"
                              class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-amber-50 text-amber-700 border border-amber-200 text-[11px]">
                        <ion-icon name="close-circle-outline"></ion-icon> Reject
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="px-3 py-4 text-center text-slate-500 text-xs">
                No pending deletion requests.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>
</body>
</html>