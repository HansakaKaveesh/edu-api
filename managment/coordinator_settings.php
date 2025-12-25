<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'coordinator') {
    http_response_code(403);
    die("Access denied.");
}

$user_id = (int)$_SESSION['user_id'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

/* --------------------------------
   Load coordinator + user info
-----------------------------------*/
$stmt = $conn->prepare("
  SELECT 
    u.username, u.status, u.created_at,
    cc.first_name, cc.last_name, cc.email
  FROM users u
  LEFT JOIN course_coordinators cc ON cc.user_id = u.user_id
  WHERE u.user_id = ?
  LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$info) {
    http_response_code(403);
    die("Coordinator account not found.");
}

$first_name = $info['first_name'] ?? '';
$last_name  = $info['last_name'] ?? '';
$email      = $info['email'] ?? '';
$username   = $info['username'] ?? '';
$status     = $info['status'] ?? '';
$created_at = $info['created_at'] ?? null;
$fullName   = trim(($first_name ?: '') . ' ' . ($last_name ?: ''));

/* --------------------------------
   Handle POST actions
-----------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        header("Location: coordinator_settings.php?err=" . urlencode('Invalid session. Please refresh and try again.'));
        exit;
    }

    if ($action === 'update_profile') {
        $fn = trim($_POST['first_name'] ?? '');
        $ln = trim($_POST['last_name'] ?? '');
        $em = trim($_POST['email'] ?? '');

        $errors = [];
        if ($fn === '') $errors[] = 'First name is required.';
        if ($ln === '') $errors[] = 'Last name is required.';
        if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }

        if (!empty($errors)) {
            header("Location: coordinator_settings.php?err=" . urlencode(implode(' ', $errors)));
            exit;
        }

        $stmt = $conn->prepare("
          UPDATE course_coordinators
             SET first_name = ?, last_name = ?, email = ?
           WHERE user_id = ?
        ");
        if ($stmt === false) {
            header("Location: coordinator_settings.php?err=" . urlencode('Failed to prepare update statement.'));
            exit;
        }
        $stmt->bind_param("sssi", $fn, $ln, $em, $user_id);
        if ($stmt->execute()) {
            $stmt->close();
            // Optionally update session display name
            $_SESSION['full_name'] = $fn . ' ' . $ln;
            header("Location: coordinator_settings.php?msg=" . urlencode('Profile updated successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: coordinator_settings.php?err=" . urlencode('Failed to update profile.'));
            exit;
        }

    } elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $errors = [];

        if ($current === '') $errors[] = 'Current password is required.';
        if ($new === '')     $errors[] = 'New password is required.';
        if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
        if ($new !== $confirm) $errors[] = 'New password and confirmation do not match.';

        if (!defined('PASSWORD_ARGON2ID')) {
            $errors[] = 'Server does not support Argon2id.';
        }

        // Fetch current hash
        if (empty($errors)) {
            $stmt = $conn->prepare("
              SELECT password_hash
              FROM passwords
              WHERE user_id = ? AND is_current = 1
              LIMIT 1
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->bind_result($hash);
            $found = $stmt->fetch();
            $stmt->close();

            if (!$found || !password_verify($current, $hash)) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        if (!empty($errors)) {
            header("Location: coordinator_settings.php?err=" . urlencode(implode(' ', $errors)));
            exit;
        }

        // Update password in transaction
        $conn->begin_transaction();
        try {
            // Invalidate old
            $stmt = $conn->prepare("UPDATE passwords SET is_current = 0 WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            // New hash
            $argonOpts = [
                'memory_cost' => 64 * 1024, // 64 MB
                'time_cost'   => 4,
                'threads'     => 2,
            ];
            $new_hash = password_hash($new, PASSWORD_ARGON2ID, $argonOpts);
            if ($new_hash === false) {
                throw new Exception('Failed to hash new password.');
            }

            $stmt = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)");
            $stmt->bind_param("is", $user_id, $new_hash);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            header("Location: coordinator_settings.php?msg=" . urlencode('Password updated successfully.'));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            header("Location: coordinator_settings.php?err=" . urlencode('Failed to update password. Please try again.'));
            exit;
        }
    }

    header("Location: coordinator_settings.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Coordinator Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body {
      font-family: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI",
                   Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      min-height: 100vh;
    }
    body::before {
      content:"";
      position:fixed;
      inset:0;
      background:
        radial-gradient(circle at 0% 0%, rgba(56,189,248,0.16) 0, transparent 55%),
        radial-gradient(circle at 100% 100%, rgba(129,140,248,0.20) 0, transparent 55%);
      pointer-events:none;
      z-index:-1;
    }
    .glass-card {
      background: linear-gradient(to bottom right, rgba(255,255,255,0.96), rgba(248,250,252,0.95));
      border: 1px solid rgba(226,232,240,0.9);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow: 0 18px 40px rgba(15,23,42,0.06);
    }
    .soft-card {
      background: linear-gradient(to bottom right, rgba(248,250,252,0.96), rgba(239,246,255,0.96));
      border: 1px solid rgba(222,231,255,0.9);
      box-shadow: 0 14px 30px rgba(15,23,42,0.05);
    }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<!-- Main Container -->
<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 flex flex-col lg:flex-row gap-8">
 
<!-- Sidebar --> 
<?php include 'components/sidebar_coordinator.php'; ?>

  <main class="w-full space-y-10 animate-fadeUp">
  <!-- Header -->
  <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-indigo-950 via-slate-900 to-sky-900 text-white shadow-2xl mb-6">
    <div class="absolute -left-24 -top-24 w-64 h-64 bg-indigo-500/40 rounded-full blur-3xl"></div>
    <div class="absolute -right-24 top-10 w-60 h-60 bg-sky-400/40 rounded-full blur-3xl"></div>

    <div class="relative z-10 px-5 py-6 sm:px-7 sm:py-7 flex flex-col gap-4">
      <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/25">
            <i data-lucide="settings" class="w-3.5 h-3.5"></i>
          </span>
          <span>Coordinator · Settings</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="user-check" class="w-3.5 h-3.5"></i>
          <span>Course Coordinator</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Account Settings
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            Update your profile details and change your password.
          </p>
        </div>

        <div class="flex flex-col items-end gap-1 text-xs text-sky-100/80">
          <p><span class="font-semibold">Username:</span> @<?= htmlspecialchars($username) ?></p>
          <p>
            <span class="font-semibold">Status:</span>
            <?= htmlspecialchars(ucfirst($status ?: '—')) ?>
          </p>
          <?php if ($created_at): ?>
            <p>
              <span class="font-semibold">Member since:</span>
              <?= htmlspecialchars(date('M j, Y', strtotime($created_at))) ?>
            </p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="mt-1">
          <div class="relative inline-flex items-start gap-2 rounded-xl px-3 py-2 bg-emerald-50/95 text-emerald-800 ring-1 ring-emerald-200 text-xs">
            <button type="button" onclick="this.parentElement.remove()"
                    class="absolute right-2 top-1.5 text-emerald-400 hover:text-emerald-700"
                    aria-label="Dismiss">
              <i data-lucide="x" class="w-3 h-3"></i>
            </button>
            <div class="flex items-center gap-1.5">
              <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
              <span><?= htmlspecialchars($msg) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="mt-1">
          <div class="relative inline-flex items-start gap-2 rounded-xl px-3 py-2 bg-rose-50/95 text-rose-800 ring-1 ring-rose-200 text-xs">
            <button type="button" onclick="this.parentElement.remove()"
                    class="absolute right-2 top-1.5 text-rose-400 hover:text-rose-700"
                    aria-label="Dismiss">
              <i data-lucide="x" class="w-3 h-3"></i>
            </button>
            <div class="flex items-center gap-1.5">
              <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
              <span><?= htmlspecialchars($err) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Grid: Sidebar + Main -->
  <div class="grid grid-cols-2 lg:grid-cols-14 gap-4">
    

    <!-- Main Column -->
    <section class="lg:col-span-9 space-y-4 ">
      <!-- Profile card -->
      <div class="glass-card rounded-2xl p-5 sm:p-6">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700">
              <i data-lucide="user" class="w-4 h-4"></i>
            </span>
            <div>
              <h2 class="text-sm sm:text-base font-semibold text-slate-800">
                Profile information
              </h2>
              <p class="text-[11px] sm:text-xs text-slate-500">
                Your public name and email used for communication.
              </p>
            </div>
          </div>
        </div>

        <form method="POST" class="space-y-3 max-w-xl">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="update_profile">

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
                First name
              </label>
              <input name="first_name" required
                     value="<?= htmlspecialchars($first_name) ?>"
                     class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
            </div>
            <div>
              <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
                Last name
              </label>
              <input name="last_name" required
                     value="<?= htmlspecialchars($last_name) ?>"
                     class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
            </div>
          </div>

          <div class="max-w-md">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Email address
            </label>
            <input type="email" name="email" required
                   value="<?= htmlspecialchars($email) ?>"
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div class="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 mt-2">
            <button type="submit"
                    class="inline-flex items-center gap-1 rounded-md bg-indigo-600 text-white px-3.5 py-1.75 text-xs font-semibold hover:bg-indigo-700 shadow-sm">
              <i data-lucide="save" class="w-3.5 h-3.5"></i>
              Save profile
            </button>
          </div>
        </form>
      </div>

      <!-- Password card -->
      <div class="glass-card rounded-2xl p-5 sm:p-6">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-rose-100 text-rose-700">
              <i data-lucide="lock" class="w-4 h-4"></i>
            </span>
            <div>
              <h2 class="text-sm sm:text-base font-semibold text-slate-800">
                Change password
              </h2>
              <p class="text-[11px] sm:text-xs text-slate-500">
                Use a strong, unique password that you don’t reuse elsewhere.
              </p>
            </div>
          </div>
        </div>

        <form method="POST" class="space-y-3 max-w-md">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="change_password">

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Current password
            </label>
            <input type="password" name="current_password" required autocomplete="current-password"
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              New password
            </label>
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password"
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Confirm new password
            </label>
            <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div class="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 mt-2">
            <button type="submit"
                    class="inline-flex items-center gap-1 rounded-md bg-rose-600 text-white px-3.5 py-1.75 text-xs font-semibold hover:bg-rose-700 shadow-sm">
              <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
              Update password
            </button>
          </div>
        </form>
      </div>
    </section>
  </div>
</div>
      </main>
<script>
  if (window.lucide) {
    window.lucide.createIcons();
  }
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>