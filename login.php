<?php
declare(strict_types=1);

ob_start();
require_once 'db_connect.php';

/* ---------- Security headers (send before any output) ---------- */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
// If site is on HTTPS, consider enabling HSTS globally (server/proxy level):
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

/* ---------- Secure session cookie settings ---------- */
$usingHttps = (
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
  (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);

session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => $usingHttps,
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();

$error = "";

/* ---------- Helper: redirect based on role ---------- */
function redirect_by_role(string $role): void {
  switch ($role) {
    case 'admin':
      header('Location: admin_dashboard.php'); exit;
    case 'teacher':
      header('Location: teacher_dashboard.php'); exit;
    case 'student':
      header('Location: student_dashboard.php'); exit;
    default:
      return;
  }
}

/* ---------- Auto-login from remember-me cookie (if not POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_SESSION['user_id']) && !empty($_COOKIE['remember_me'])) {
  $raw = $_COOKIE['remember_me'];

  if (strpos($raw, ':') !== false) {
    [$selector, $validator] = explode(':', $raw, 2);

    $stmt = $conn->prepare(
      "SELECT urt.user_id, urt.token_hash, UNIX_TIMESTAMP(urt.expires_at) AS exp, u.role
         FROM user_remember_tokens urt
         JOIN users u ON urt.user_id = u.user_id
        WHERE urt.selector = ?"
    );

    if ($stmt) {
      $stmt->bind_param('s', $selector);
      $stmt->execute();
      $stmt->bind_result($uid, $tokenHash, $expiresAt, $role);
      $foundToken = $stmt->fetch();
      $stmt->close();

      if ($foundToken && $expiresAt >= time()) {
        $calcHash = hash('sha256', $validator);
        if (hash_equals($tokenHash, $calcHash)) {
          session_regenerate_id(true);
          $_SESSION['user_id']   = $uid;
          $_SESSION['role']      = $role;
          $_SESSION['last_auth'] = time();
          redirect_by_role($role);
        }
      }
    }

    // If token invalid/expired → clear cookie
    setcookie('remember_me', '', [
      'expires'  => time() - 3600,
      'path'     => '/',
      'domain'   => '',
      'secure'   => $usingHttps,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}

/* ---------- If already logged in redirect ---------- */
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
  redirect_by_role($_SESSION['role']);
}

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ---------- Basic session-scoped rate limiting (per IP) ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = 'login_rate_' . $ip;
$windowSeconds = 15 * 60; // 15 minutes
$maxAttempts   = 5;

if (!isset($_SESSION[$rateKey])) {
  $_SESSION[$rateKey] = ['count' => 0, 'first' => time()];
}

/* ---------- Handle POST (login attempt) ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $postedToken = $_POST['csrf_token'] ?? '';
  if (!hash_equals($csrfToken, $postedToken)) {
    $error = "❌ Invalid request.";
  } else {
    if (time() - $_SESSION[$rateKey]['first'] > $windowSeconds) {
      $_SESSION[$rateKey] = ['count' => 0, 'first' => time()];
    }

    if ($_SESSION[$rateKey]['count'] >= $maxAttempts) {
      $error = "❌ Too many attempts. Try again in a few minutes.";
    } else {
      $username = trim($_POST['username'] ?? '');
      $username = mb_substr($username, 0, 150);
      $password = $_POST['password'] ?? '';
      $remember = !empty($_POST['remember']);

      $stmt = $conn->prepare(
        "SELECT u.user_id, u.role, p.password_hash
           FROM users u
           JOIN passwords p ON u.user_id = p.user_id AND p.is_current = 1
          WHERE u.username = ?"
      );

      if ($stmt === false) {
        error_log('Login prepare failed: ' . $conn->error);
        $error = "❌ Something went wrong. Please try again.";
      } else {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($user_id, $role, $hash);

        $found = $stmt->fetch();
        $stmt->close();

        $dummyHash = '$2y$10$KIX/CR0iYc6eZ/EuKQGhFeD69L0/MO6c.pwSdN1JPBUuJIUl0P8y6';
        $verified = password_verify($password, $found ? $hash : $dummyHash);

        if ($found && $verified) {
          session_regenerate_id(true);
          $_SESSION['user_id']   = $user_id;
          $_SESSION['role']      = $role;
          $_SESSION['last_auth'] = time();
          unset($_SESSION[$rateKey]);

          /* ---------- Remember-me ---------- */
          if ($remember) {
            $del = $conn->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?");
            if ($del) {
              $del->bind_param('i', $user_id);
              $del->execute();
              $del->close();
            }

            $selector  = bin2hex(random_bytes(9));
            $validator = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $validator);
            $expires   = time() + 60 * 60 * 24 * 30;

            $ins = $conn->prepare(
              "INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at)
               VALUES (?, ?, ?, FROM_UNIXTIME(?))"
            );
            if ($ins) {
              $ins->bind_param('issi', $user_id, $selector, $tokenHash, $expires);
              $ins->execute();
              $ins->close();

              $cookieValue = $selector . ':' . $validator;
              setcookie('remember_me', $cookieValue, [
                'expires'  => $expires,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $usingHttps,
                'httponly' => true,
                'samesite' => 'Lax',
              ]);
            }
          } else {
            if (!empty($_COOKIE['remember_me'])) {
              setcookie('remember_me', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $usingHttps,
                'httponly' => true,
                'samesite' => 'Lax',
              ]);
            }
          }

          redirect_by_role($role);
        } else {
          $_SESSION[$rateKey]['count']++;
          $error = "❌ Invalid credentials";
          usleep(300000);
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - SynapZ</title>
  <meta name="theme-color" content="#4f46e5">

  <!-- Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Tailwind config must be BEFORE tailwindcdn -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica', 'Arial']
          },
          boxShadow: {
            soft: '0 24px 70px rgba(2, 6, 23, 0.12)'
          },
          colors: {
            synap: { 50:'#eef2ff', 100:'#e0e7ff', 200:'#c7d2fe', 300:'#a5b4fc', 400:'#818cf8', 500:'#6366f1', 600:'#4f46e5', 700:'#4338ca', 800:'#3730a3', 900:'#312e81' }
          }
        }
      }
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; }
    .bg-grid {
      background-image: radial-gradient(circle at 1px 1px, rgba(99, 102, 241, .18) 1px, transparent 0);
      background-size: 26px 26px;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .fade-in { animation: fadeIn .6s ease-out both; }
  </style>
</head>

<body class="min-h-screen overflow-x-hidden bg-gradient-to-b from-white via-synap-50/60 to-white text-slate-900">
  <?php include 'components/navbar.php'; ?>

  <!-- Background decoration (LIGHT, modern) -->
  <div class="fixed inset-0 -z-10">
    <div class="absolute inset-0 bg-grid opacity-40"></div>
    <div class="absolute -top-28 -left-28 h-96 w-96 rounded-full bg-indigo-300/40 blur-3xl"></div>
    <div class="absolute top-10 -right-40 h-[32rem] w-[32rem] rounded-full bg-sky-300/35 blur-3xl"></div>
    <div class="absolute bottom-0 left-1/3 h-[28rem] w-[28rem] rounded-full bg-violet-300/25 blur-3xl"></div>
  </div>

  <main class="mx-auto max-w-6xl px-4 py-32 sm:py-32">
    <div class="grid items-center gap-10 lg:grid-cols-2">
      <!-- Left panel (modern marketing block) -->
      <section class="hidden lg:block fade-in">
        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-white/70 px-3 py-1 text-xs font-semibold text-indigo-700">
          Secure Login
        </span>

        <h1 class="mt-4 text-4xl font-extrabold tracking-tight text-slate-900">
          Welcome back to SynapZ
        </h1>

        <p class="mt-4 max-w-md text-slate-600 leading-relaxed">
          Sign in to continue learning. Your session is protected with CSRF checks, rate limiting, and optional “remember me”.
        </p>

        <div class="mt-8 grid max-w-md grid-cols-1 gap-3 text-sm text-slate-600">
          <div class="rounded-2xl border border-slate-200 bg-white/70 p-4">
            <p class="font-semibold text-slate-900">Fast access</p>
            <p class="mt-1 text-slate-600">Clean UI optimized for mobile + desktop.</p>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white/70 p-4">
            <p class="font-semibold text-slate-900">Safer sessions</p>
            <p class="mt-1 text-slate-600">HTTPOnly cookies + secure headers.</p>
          </div>
        </div>
      </section>

      <!-- Right panel (login card) -->
      <section class="fade-in">
        <div class="mx-auto w-full max-w-md">
          <div class="rounded-3xl border border-slate-200/70 bg-white/75 backdrop-blur-xl shadow-soft">
            <div class="p-7 sm:p-8">
              <div class="text-center">
                <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-tr from-indigo-600 to-sky-500 text-white shadow-lg shadow-indigo-600/20">
                  <!-- lock icon -->
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M16.5 10.5V7.5a4.5 4.5 0 10-9 0v3M5.25 10.5h13.5a1.5 1.5 0 011.5 1.5v7.5a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5V12a1.5 1.5 0 011.5-1.5z" />
                  </svg>
                </div>

                <h2 class="mt-4 text-3xl font-extrabold tracking-tight text-slate-900">Sign in</h2>
                <p class="mt-2 text-sm text-slate-600">Enter your username and password to continue.</p>
              </div>

              <?php if (!empty($error)): ?>
                <div class="mt-6 rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 flex items-start gap-3" role="alert" aria-live="polite">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mt-0.5 flex-none text-red-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM9 13a1 1 0 102 0 1 1 0 00-2 0zm1-8a.75.75 0 01.75.75v5.5a.75.75 0 01-1.5 0v-5.5A.75.75 0 0110 5z" clip-rule="evenodd" />
                  </svg>
                  <p class="text-sm font-semibold"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
              <?php endif; ?>

              <form method="POST" class="mt-6 space-y-4" onsubmit="return handleSubmit(this)">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <!-- Username -->
                <div>
                  <label for="username" class="block text-sm font-semibold text-slate-700">Username</label>
                  <div class="mt-1 relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 12a5 5 0 100-10 5 5 0 000 10z" />
                        <path d="M4 20c0-4.418 3.582-8 8-8s8 3.582 8 8H4z" />
                      </svg>
                    </span>

                    <input
                      id="username"
                      type="text"
                      name="username"
                      required
                      autocomplete="username"
                      inputmode="text"
                      class="w-full rounded-xl border border-slate-200 bg-white px-10 py-3 text-sm text-slate-900 placeholder:text-slate-400
                             focus:outline-none focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-400 transition"
                      placeholder="Enter your username"
                    />
                  </div>
                </div>

                <!-- Password -->
                <div>
                  <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                  <div class="mt-1 relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M21 10a4 4 0 10-7.031 2.5L9 17.469V20h2.531l4.969-4.969A4 4 0 0021 10zM5 20a2 2 0 110-4 2 2 0 010 4z" />
                      </svg>
                    </span>

                    <input
                      id="password"
                      type="password"
                      name="password"
                      required
                      autocomplete="current-password"
                      class="w-full rounded-xl border border-slate-200 bg-white px-10 pr-12 py-3 text-sm text-slate-900 placeholder:text-slate-400
                             focus:outline-none focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-400 transition"
                      placeholder="Enter your password"
                      onpaste="return false;"
                      ondrop="return false;"
                    />

                    <button
                      type="button"
                      onclick="togglePassword()"
                      class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition"
                      aria-label="Toggle password visibility"
                      aria-pressed="false"
                      id="togglePwdBtn"
                    >
                      <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 5c-7 0-11 7-11 7s4 7 11 7 11-7 11-7-4-7-11-7zm0 12a5 5 0 110-10 5 5 0 010 10z"/>
                      </svg>
                    </button>
                  </div>
                </div>

                <!-- Remember + Forgot -->
                <div class="flex items-center justify-between gap-3 pt-1">
                  <!-- switch -->
                  <label for="remember" class="flex items-center gap-3 cursor-pointer select-none">
                    <input
                      id="remember"
                      type="checkbox"
                      name="remember"
                      class="sr-only peer"
                      <?= (!empty($_POST) && !empty($_POST['remember'])) ? 'checked' : '' ?>
                    >
                    <span class="relative inline-flex h-6 w-11 items-center rounded-full bg-slate-200 transition peer-checked:bg-indigo-600">
                      <span class="inline-block h-5 w-5 translate-x-0.5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                    <span class="text-sm font-medium text-slate-700">Remember me</span>
                  </label>

                  <a href="forgot_password.php" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800 hover:underline">
                    Forgot password?
                  </a>
                </div>

                <!-- Submit -->
                <button
                  type="submit"
                  class="group mt-2 w-full inline-flex items-center justify-center gap-2 rounded-xl
                         bg-gradient-to-r from-indigo-600 to-sky-500 px-4 py-3 text-sm font-semibold text-white
                         shadow-lg shadow-indigo-600/20 hover:shadow-indigo-600/30
                         focus:outline-none focus:ring-4 focus:ring-indigo-500/20 transition"
                  id="loginButton"
                >
                  <span class="transition-transform group-hover:-translate-y-0.5">Sign in</span>
                  <svg class="h-5 w-5 opacity-90 transition-transform group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                  </svg>
                </button>

                <p class="pt-2 text-center text-sm text-slate-600">
                  Don’t have an account?
                  <a href="register.php" class="font-semibold text-indigo-700 hover:underline">Create one</a>
                </p>
              </form>
            </div>
          </div>

          <p class="mt-6 text-center text-xs text-slate-500">© <?= date('Y') ?> SynapZ. All rights reserved.</p>
        </div>
      </section>
    </div>
  </main>

  <script>
    function togglePassword() {
      const input = document.getElementById('password');
      const btn = document.getElementById('togglePwdBtn');
      const isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';
      btn.setAttribute('aria-pressed', isPass ? 'true' : 'false');
    }

    function handleSubmit() {
      const btn = document.getElementById('loginButton');
      btn.disabled = true;
      btn.classList.add('opacity-70', 'cursor-not-allowed');
      btn.innerHTML =
        '<svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">' +
        '<circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>' +
        '<path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>' +
        '</svg><span>Signing in...</span>';
      return true;
    }

    // Extra protection: disable paste & drop into password field via JS
    document.addEventListener('DOMContentLoaded', () => {
      const pwd = document.getElementById('password');
      if (!pwd) return;
      pwd.addEventListener('paste', e => e.preventDefault());
      pwd.addEventListener('drop', e => e.preventDefault());
    });
  </script>
</body>
</html>
<?php
ob_end_flush();