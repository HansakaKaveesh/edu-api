<?php
// admin_authority.php — manage user roles + role permissions (mysqli)
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php'; // must define $conn (mysqli)
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* Helpers */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function csrf_token(): string { return $_SESSION['csrf_token']; }
function verify_csrf(string $token): bool { return hash_equals($_SESSION['csrf_token'] ?? '', $token); }
function current_user_id(): ?int { return $_SESSION['user_id'] ?? null; }

function current_user_role_name(mysqli $conn): ?string {
  $uid = current_user_id();
  if (!$uid) return null;
  $stmt = $conn->prepare("
    SELECT COALESCE(r.role_name, u.role) AS role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE u.user_id = ?
  ");
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $stmt->bind_result($roleName);
  $stmt->fetch();
  $stmt->close();
  return $roleName ?: null;
}

function user_has_permission(mysqli $conn, string $perm): bool {
  $uid = current_user_id();
  if (!$uid) return false;
  $sql = "SELECT 1
          FROM users u
          JOIN roles r ON u.role_id = r.role_id
          JOIN role_permissions rp ON r.role_id = rp.role_id
          JOIN permissions p ON rp.permission_id = p.permission_id
          WHERE u.user_id = ? AND p.name = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('is', $uid, $perm);
  $stmt->execute();
  $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

function require_manage_users(mysqli $conn) {
  $role = current_user_role_name($conn);
  if (!$role) { header('Location: /login.php'); exit; }
  if ($role === 'admin' || user_has_permission($conn, 'manage_users')) return;
  http_response_code(403);
  exit('Forbidden');
}

// dynamic bind helper for IN/variable params
function stmt_bind_dynamic(mysqli_stmt $stmt, string $types, array &$values): void {
  if ($types === '' || !$values) return;
  $refs = [];
  foreach ($values as $k => $v) { $refs[$k] = &$values[$k]; }
  array_unshift($refs, $types);
  call_user_func_array([$stmt, 'bind_param'], $refs);
}

/* Access control */
require_manage_users($conn);

/* Flash */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* Handle POST: update role for a user */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_role') {
  if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid CSRF token'); }
  $targetUserId = (int)($_POST['user_id'] ?? 0);
  $newRoleId = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? (int)$_POST['role_id'] : null;

  try {
    $conn->begin_transaction();

    // Ensure user exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $targetUserId);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows === 0) throw new Exception('User not found');
    $stmt->close();

    if ($newRoleId !== null) {
      $stmt = $conn->prepare("SELECT role_id, role_name FROM roles WHERE role_id = ?");
      $stmt->bind_param('i', $newRoleId);
      $stmt->execute(); $res = $stmt->get_result(); $roleRow = $res->fetch_assoc(); $stmt->close();
      if (!$roleRow) throw new Exception('Invalid role selected');

      // Sync role_id and legacy enum users.role
      $stmt = $conn->prepare("UPDATE users SET role_id = ?, role = ? WHERE user_id = ?");
      $stmt->bind_param('isi', $roleRow['role_id'], $roleRow['role_name'], $targetUserId);
      $stmt->execute(); $stmt->close();

      // Optional: auto-provision profile rows
      $roleName = $roleRow['role_name'];
      if ($roleName === 'student') {
        $stmt = $conn->prepare("INSERT IGNORE INTO students (user_id, registered_at) VALUES (?, NOW())");
        $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();
      } elseif ($roleName === 'teacher') {
        $stmt = $conn->prepare("INSERT IGNORE INTO teachers (user_id) VALUES (?)");
        $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();
      } elseif (in_array($roleName, ['admin','ceo','cto'], true)) {
        // Use admins table as the profile holder for admin/ceo/cto
        $stmt = $conn->prepare("INSERT IGNORE INTO admins (user_id, created_at) VALUES (?, NOW())");
        $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();
      }
    } else {
      // If clearing role_id, fallback to 'student'
      $stmt = $conn->prepare("UPDATE users SET role_id = NULL, role = 'student' WHERE user_id = ?");
      $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();
      $stmt = $conn->prepare("INSERT IGNORE INTO students (user_id, registered_at) VALUES (?, NOW())");
      $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();
    }

    $conn->commit();
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Role updated successfully'];
  } catch (Throwable $ex) {
    $conn->rollback();
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Update failed: '.$ex->getMessage()];
  }
  header('Location: '.$_SERVER['REQUEST_URI']);
  exit;
}

/* Handle POST: save role permissions */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_role_perms') {
  if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid CSRF token'); }
  $roleId = (int)($_POST['role_id'] ?? 0);
  $permIds = array_values(array_filter(array_map('intval', $_POST['perm_ids'] ?? [])));

  try {
    // Validate role
    $stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ?");
    $stmt->bind_param('i', $roleId); $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows === 0) throw new Exception('Role not found');
    $stmt->close();

    $conn->begin_transaction();

    // Delete existing
    $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $stmt->bind_param('i', $roleId); $stmt->execute(); $stmt->close();

    // Insert new
    if ($permIds) {
      // Build multi-row insert
      $placeholders = implode(',', array_fill(0, count($permIds), '(?,?)'));
      $types = str_repeat('ii', count($permIds));
      $vals = [];
      foreach ($permIds as $pid) { $vals[] = $roleId; $vals[] = $pid; }

      $sql = "INSERT INTO role_permissions (role_id, permission_id) VALUES $placeholders";
      $stmt = $conn->prepare($sql);
      stmt_bind_dynamic($stmt, $types, $vals);
      $stmt->execute(); $stmt->close();
    }

    $conn->commit();
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Permissions updated for the role'];
  } catch (Throwable $ex) {
    if ($conn->errno) { $conn->rollback(); }
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Failed to update permissions: '.$ex->getMessage()];
  }

  // Keep the selected role open
  $qs = $_GET; $qs['role_for_edit'] = $roleId;
  header('Location: ?'.http_build_query($qs));
  exit;
}

/* Fetch roles and permissions */
$roles = [];
$res = $conn->query("SELECT role_id, role_name FROM roles ORDER BY FIELD(role_name,'admin','ceo','cto','teacher','student'), role_name");
while ($row = $res->fetch_assoc()) { $roles[] = $row; }

$permissions = [];
$res = $conn->query("SELECT permission_id, name, description FROM permissions ORDER BY name");
while ($row = $res->fetch_assoc()) { $permissions[] = $row; }

/* Role permissions for editor panel */
$roleForEdit = isset($_GET['role_for_edit']) ? (int)$_GET['role_for_edit'] : ($roles[0]['role_id'] ?? 0);
$rolePermIds = [];
if ($roleForEdit) {
  $stmt = $conn->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
  $stmt->bind_param('i', $roleForEdit);
  $stmt->execute();
  $rs = $stmt->get_result();
  while ($r = $rs->fetch_assoc()) $rolePermIds[] = (int)$r['permission_id'];
  $stmt->close();
}

/* Filters */
$q = trim($_GET['q'] ?? '');
$roleFilter = isset($_GET['role_filter']) && $_GET['role_filter'] !== '' ? (int)$_GET['role_filter'] : null;
$statusFilter = $_GET['status_filter'] ?? '';

$limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = [];
$types = '';
$values = [];

if ($q !== '') {
  $where[] = "(u.username LIKE ? OR s.email LIKE ? OR t.email LIKE ? OR a.email LIKE ?)";
  $like = "%$q%";
  for ($i=0; $i<4; $i++) { $types .= 's'; $values[] = $like; }
}
if ($roleFilter !== null) {
  $where[] = "u.role_id = ?";
  $types .= 'i'; $values[] = $roleFilter;
}
if (in_array($statusFilter, ['active','inactive','suspended'], true)) {
  $where[] = "u.status = ?";
  $types .= 's'; $values[] = $statusFilter;
}
$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* Count total */
$sqlCount = "SELECT COUNT(*) AS cnt
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.role_id
             LEFT JOIN students s ON u.user_id = s.user_id
             LEFT JOIN teachers t ON u.user_id = t.user_id
             LEFT JOIN admins a ON u.user_id = a.user_id
             $whereSql";
$stmt = $conn->prepare($sqlCount);
stmt_bind_dynamic($stmt, $types, $values);
$stmt->execute();
$stmt->bind_result($total);
$stmt->fetch();
$stmt->close();
$total = (int)$total;

/* List query */
$sqlList = "SELECT u.user_id, u.username, u.status, u.created_at,
                   r.role_id, r.role_name,
                   COALESCE(s.email, t.email, a.email) AS email
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN students s ON u.user_id = s.user_id
            LEFT JOIN teachers t ON u.user_id = t.user_id
            LEFT JOIN admins a ON u.user_id = a.user_id
            $whereSql
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?";
$typesList = $types.'ii';
$valuesList = $values;
$valuesList[] = $limit;
$valuesList[] = $offset;

$stmt = $conn->prepare($sqlList);
stmt_bind_dynamic($stmt, $typesList, $valuesList);
$stmt->execute();
$result = $stmt->get_result(); // requires mysqlnd (WAMP includes it)
$users = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages = (int)ceil($total / $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>User Authority | Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
  <div class="max-w-6xl mx-auto p-4 md:p-8">
    <header class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Assign Roles & Permissions</h1>
      <div class="text-sm text-gray-500">Signed in as: <?= e(current_user_role_name($conn) ?? 'Unknown') ?></div>
    </header>

    <?php if ($flash): ?>
      <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
        <?= e($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- Role permissions editor -->
    <section class="mb-6 bg-white rounded-md shadow p-4">
      <form method="get" class="flex items-end gap-3 mb-4">
        <div class="flex-1">
          <label class="block text-sm font-medium text-gray-700">Select Role to Edit</label>
          <select name="role_for_edit" class="mt-1 w-full rounded border-gray-300 focus:ring-indigo-500 focus:border-indigo-500" onchange="this.form.submit()">
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['role_id'] ?>" <?= ($roleForEdit === (int)$r['role_id']) ? 'selected' : '' ?>>
                <?= e(ucfirst($r['role_name'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <button class="px-4 py-2 rounded border bg-white hover:bg-gray-50">Load</button>
        </div>
      </form>

      <?php if (!$permissions): ?>
        <p class="text-gray-600">No permissions defined yet. Seed the permissions table first.</p>
      <?php else: ?>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
          <input type="hidden" name="action" value="save_role_perms" />
          <input type="hidden" name="role_id" value="<?= (int)$roleForEdit ?>" />
          <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
            <?php foreach ($permissions as $p): ?>
              <label class="inline-flex items-center gap-2 p-2 rounded hover:bg-gray-50 border border-transparent hover:border-gray-200">
                <input type="checkbox" name="perm_ids[]" value="<?= (int)$p['permission_id'] ?>"
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                       <?= in_array((int)$p['permission_id'], $rolePermIds, true) ? 'checked' : '' ?>>
                <span>
                  <span class="font-medium text-gray-900"><?= e($p['name']) ?></span>
                  <?php if ($p['description']): ?>
                    <span class="block text-xs text-gray-500"><?= e($p['description']) ?></span>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <button class="px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">Save Permissions</button>
        </form>
      <?php endif; ?>
    </section>

    <!-- Filters -->
    <section class="mb-6">
      <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 bg-white p-4 rounded-md shadow">
        <div>
          <label class="block text-sm font-medium text-gray-700">Search</label>
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="username or email" class="mt-1 w-full rounded border-gray-300 focus:ring-indigo-500 focus:border-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Role</label>
          <select name="role_filter" class="mt-1 w-full rounded border-gray-300 focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">All</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['role_id'] ?>" <?= ($roleFilter === (int)$r['role_id']) ? 'selected' : '' ?>>
                <?= e(ucfirst($r['role_name'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Status</label>
          <select name="status_filter" class="mt-1 w-full rounded border-gray-300 focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">All</option>
            <?php foreach (['active','inactive','suspended'] as $st): ?>
              <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex items-end">
          <button class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">Filter</button>
        </div>
      </form>
    </section>

    <!-- Users table -->
    <section class="bg-white rounded-md shadow overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Current Role</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <?php if (!$users): ?>
              <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No users found.</td></tr>
            <?php else: ?>
              <?php foreach ($users as $u): ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-4 py-3">
                    <div class="font-medium text-gray-900">#<?= (int)$u['user_id'] ?> — <?= e($u['username']) ?></div>
                  </td>
                  <td class="px-4 py-3 text-gray-700"><?= e($u['email'] ?? '-') ?></td>
                  <td class="px-4 py-3">
                    <form method="post" class="flex items-center gap-2">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                      <input type="hidden" name="action" value="update_role" />
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>" />
                      <select name="role_id" class="rounded border-gray-300 focus:ring-indigo-500 focus:border-indigo-500">
                        <?php foreach ($roles as $r): ?>
                          <option value="<?= (int)$r['role_id'] ?>" <?= ((int)($u['role_id'] ?? 0) === (int)$r['role_id']) ? 'selected' : '' ?>>
                            <?= e(ucfirst($r['role_name'])) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button class="px-3 py-1.5 text-sm rounded bg-emerald-600 text-white hover:bg-emerald-700">Save</button>
                    </form>
                  </td>
                  <td class="px-4 py-3">
                    <?php
                      $status = $u['status'];
                      $badge = $status === 'active' ? 'bg-green-100 text-green-800'
                        : ($status==='suspended' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800');
                    ?>
                    <span class="px-2.5 py-1 rounded text-xs font-medium <?= $badge ?>"><?= ucfirst($status) ?></span>
                  </td>
                  <td class="px-4 py-3 text-gray-600 text-sm"><?= e($u['created_at']) ?></td>
                  <td class="px-4 py-3 text-right"></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="flex items-center justify-between px-4 py-3 bg-gray-50">
        <div class="text-sm text-gray-600">
          Showing <?= ($offset+1) ?>–<?= min($offset+$limit, $total) ?> of <?= $total ?>
        </div>
        <div class="flex gap-2">
          <?php
            $baseQuery = $_GET;
            for ($p=1; $p <= $totalPages; $p++):
              $baseQuery['page'] = $p;
              $url = '?'.http_build_query($baseQuery);
          ?>
            <a class="px-3 py-1.5 rounded border text-sm <?= $p===$page ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100' ?>" href="<?= e($url) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <p class="mt-6 text-xs text-gray-500">
      Uses users.role_id with roles table and keeps users.role (ENUM) in sync for backwards compatibility.
    </p>
  </div>
</body>
</html>