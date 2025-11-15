<?php
require_once __DIR__.'/db_connect.php'; // exposes $conn (mysqli)
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* Helpers */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function csrf_token(): string { return $_SESSION['csrf_token']; }
function verify_csrf(string $t): bool { return hash_equals($_SESSION['csrf_token'] ?? '', $t); }

/* If already logged in as accountant, go to dashboard */
if (!empty($_SESSION['user_id'])) {
  $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
  $stmt->bind_param('i', $_SESSION['user_id']);
  $stmt->execute();
  $stmt->bind_result($roleName);
  $stmt->fetch();
  $stmt->close();
  
  if ($roleName === 'accountant') {
    header('Location: accountant_dashboard.php');
    exit;
  }
}

/* Form handling */
$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Invalid request. Please refresh and try again.';
  } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
      $error = 'Please enter both username and password.';
    } else {
      // Fetch user + current password hash
      $sql = "SELECT u.user_id, u.status, u.role, p.password_hash
              FROM users u
              JOIN passwords p ON p.user_id = u.user_id AND p.is_current = 1
              WHERE u.username = ?
              LIMIT 1";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param('s', $username);
      $stmt->execute();
      $stmt->store_result();

      if ($stmt->num_rows === 0) {
        $error = 'Invalid username or password.';
      } else {
        $stmt->bind_result($uid, $status, $roleName, $hash);
        $stmt->fetch();

        if (!password_verify($password, $hash)) {
          $error = 'Invalid username or password.';
        } elseif ($status !== 'active') {
          $error = 'Your account is not active.';
        } elseif ($roleName !== 'accountant') {
          $error = 'Only accountants can log in here.';
        } else {
          // Good to go — log in
          $_SESSION['user_id'] = (int)$uid;
          $_SESSION['role'] = $roleName;

          // Optional: ensure an accountants profile row exists
          try {
            $ins = $conn->prepare("INSERT IGNORE INTO accountants (user_id) VALUES (?)");
            $ins->bind_param('i', $uid);
            $ins->execute();
            $ins->close();
          } catch (Throwable $e) { /* ignore */ }

          header('Location: accountant_dashboard.php');
          exit;
        }
      }
      $stmt->close();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Accountant Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Fonts + Tailwind -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>
    tailwind = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial'] },
          colors: {
            primary: {
              50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',300:'#a5b4fc',
              400:'#818cf8',500:'#6366f1',600:'#4f46e5',700:'#4338ca',
              800:'#3730a3',900:'#312e81'
            }
          },
          boxShadow: {
            soft: '0 10px 30px -12px rgba(99,102,241,.25)',
          },
          keyframes: {
            shake: {
              '0%,100%': { transform: 'translateX(0)' },
              '20%': { transform: 'translateX(-6px)' },
              '40%': { transform: 'translateX(6px)' },
              '60%': { transform: 'translateX(-4px)' },
              '80%': { transform: 'translateX(4px)' },
            }
          },
          animation: {
            shake: 'shake .4s ease-in-out',
          }
        }
      }
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50 text-gray-800 font-sans flex items-center justify-center p-6">
  <div class="w-full max-w-md">
    <div class="relative bg-white/90 backdrop-blur border border-slate-200 rounded-2xl shadow-xl">
      <!-- Header -->
      <div class="p-6 pb-4 text-center">
        <div class="mx-auto h-12 w-12 rounded-xl bg-gradient-to-br from-primary-600 to-indigo-600 text-white grid place-items-center shadow-soft">
          <i class="ph ph-coins text-xl"></i>
        </div>
        <h1 class="mt-3 text-2xl font-extrabold tracking-tight">Accountant Login</h1>
        <p class="text-sm text-slate-500 mt-1">Access the finance dashboard</p>
      </div>

      <!-- Error -->
      <?php if ($error): ?>
        <div class="mx-6 mb-4 p-3 rounded-lg bg-red-50 text-red-700 border border-red-200 flex items-start gap-2 animate-shake" role="alert" aria-live="polite">
          <i class="ph ph-warning-circle text-lg"></i>
          <div><?= e($error) ?></div>
        </div>
      <?php endif; ?>

      <!-- Form -->
      <form method="post" class="px-6 pb-6 space-y-4" autocomplete="on" onsubmit="return lockSubmit()">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />

        <div>
          <label for="username" class="block text-sm font-medium text-slate-700">Username</label>
          <div class="relative mt-1">
            <i class="ph ph-user absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input
              id="username"
              type="text"
              name="username"
              value="<?= e($username) ?>"
              autocomplete="username"
              required
              class="w-full rounded-lg border border-slate-300 bg-white pl-10 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              placeholder="your.username"
            />
          </div>
        </div>

        <div>
          <label for="pwd" class="block text-sm font-medium text-slate-700">Password</label>
          <div class="relative mt-1">
            <i class="ph ph-lock-key absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input
              id="pwd"
              type="password"
              name="password"
              autocomplete="current-password"
              required
              class="w-full rounded-lg border border-slate-300 bg-white pl-10 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              placeholder="••••••••"
              aria-describedby="capsNotice"
            />
            <button type="button" onclick="togglePwd(this)" aria-label="Show password"
                    class="absolute inset-y-0 right-0 px-3 text-slate-500 hover:text-slate-700">
              <i class="ph ph-eye"></i>
            </button>
          </div>
          <div id="capsNotice" class="hidden mt-1 text-xs text-amber-600 inline-flex items-center gap-1">
            <i class="ph ph-warning"></i> Caps Lock is on
          </div>
        </div>

       <button id="submitBtn" type="submit"
  class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg
         bg-indigo-600 hover:bg-indigo-700 text-white font-medium
         shadow-md transition">
  <span>Sign in</span>
</button>
      </form>

      <!-- Footer links -->
      <div class="px-6 pb-6 flex items-center justify-between text-sm">
        <a class="text-primary-700 hover:underline inline-flex items-center gap-1" href="forgot_password.php">
          <i class="ph ph-key"></i> Forgot password?
        </a>
        <a class="text-slate-600 hover:underline inline-flex items-center gap-1" href="index.php">
          <i class="ph ph-arrow-left"></i> Back to site
        </a>
      </div>

      <p class="px-6 pb-6 text-xs text-slate-500">
        Only users with the accountant role can sign in here.
      </p>
    </div>
  </div>

  <script>
    // Toggle password visibility
    function togglePwd(btn){
      const input = document.getElementById('pwd');
      const icon  = btn.querySelector('i');
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      icon.className = show ? 'ph ph-eye-slash' : 'ph ph-eye';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      input.focus();
    }

    // Caps Lock indicator
    (function capsLockWatcher(){
      const pwd = document.getElementById('pwd');
      const tip = document.getElementById('capsNotice');
      function update(e){
        const on = e.getModifierState && e.getModifierState('CapsLock');
        tip.classList.toggle('hidden', !on);
      }
      ['keydown','keyup'].forEach(evt => pwd.addEventListener(evt, update));
      pwd.addEventListener('blur', () => tip.classList.add('hidden'));
    })();

    // Prevent double submit + show spinner
    function lockSubmit(){
      const btn = document.getElementById('submitBtn');
      if (btn.dataset.loading === '1') return false;
      btn.dataset.loading = '1';
      btn.disabled = true;
      btn.classList.add('opacity-90', 'cursor-not-allowed');
      btn.innerHTML = '<span class="inline-block h-4 w-4 rounded-full border-2 border-white/40 border-t-white animate-spin"></span><span>Signing in…</span>';
      return true;
    }
  </script>
</body>
</html>