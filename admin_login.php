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

/* ---------- Portal config ---------- */
$REQUIRED_ROLE = 'admin';
$PORTAL_NAME   = 'Admin';
$TITLE         = 'Admin Login - SynapZ';

$error = "";

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ---------- Basic session-scoped rate limiting (per IP + portal) ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = 'login_rate_' . $REQUIRED_ROLE . '_' . $ip;
$windowSeconds = 15 * 60; // 15 minutes
$maxAttempts   = 5;

if (!isset($_SESSION[$rateKey])) {
  $_SESSION[$rateKey] = ['count' => 0, 'first' => time()];
}

/* ---------- Handle POST ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  // CSRF validation
  $postedToken = $_POST['csrf_token'] ?? '';
  if (!hash_equals($csrfToken, $postedToken)) {
    $error = "❌ Invalid request.";
  } else {
    // Reset window if expired
    if (time() - $_SESSION[$rateKey]['first'] > $windowSeconds) {
      $_SESSION[$rateKey] = ['count' => 0, 'first' => time()];
    }

    if ($_SESSION[$rateKey]['count'] >= $maxAttempts) {
      $error = "❌ Too many attempts. Try again in a few minutes.";
    } else {
      $username = trim($_POST['username'] ?? '');
      $username = mb_substr($username, 0, 150); // sanity limit
      $password = $_POST['password'] ?? '';

      $stmt = $conn->prepare(
        "SELECT u.user_id, u.role, p.password_hash
           FROM users u
           JOIN passwords p ON u.user_id = p.user_id AND p.is_current = 1
          WHERE u.username = ?"
      );

      if ($stmt === false) {
        error_log('Admin login prepare failed: ' . $conn->error);
        $error = "❌ Something went wrong. Please try again.";
      } else {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($user_id, $role, $hash);
        $found = $stmt->fetch();
        $stmt->close();

        // Constant-time style verification to reduce username enumeration
        $dummyHash = '$2y$10$KIX/CR0iYc6eZ/EuKQGhFeD69L0/MO6c.pwSdN1JPBUuJIUl0P8y6'; // bcrypt of "dummy_password"
        $verified = password_verify($password, $found ? $hash : $dummyHash);

        if ($found && $verified) {
          if ($role !== $REQUIRED_ROLE) {
            $error = "❌ Please use the {$role} portal.";
            usleep(300000);
          } else {
            session_regenerate_id(true);
            $_SESSION['user_id']   = (int)$user_id;
            $_SESSION['role']      = $role;
            $_SESSION['last_auth'] = time();
            unset($_SESSION[$rateKey]); // reset attempts
            header("Location: admin_dashboard.php");
            exit;
          }
        } else {
          $_SESSION[$rateKey]['count']++;
          $error = "❌ Invalid credentials";
          usleep(300000); // 300ms slow-down
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
  <title><?= htmlspecialchars($TITLE, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="theme-color" content="#1e3a8a">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root { --bg-img: url('https://img.freepik.com/free-photo/top-view-desk-concept-with-laptop_23-2148236850.jpg?semt=ais_hybrid&w=740&q=80'); }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, 'Apple Color Emoji', 'Segoe UI Emoji'; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px);} to { opacity: 1; transform: translateY(0);} }
    .fade-in { animation: fadeIn .6s ease-out both; }
  </style>
</head>
<body class="min-h-screen overflow-x-hidden relative">

  <!-- Background -->
  <div class="absolute inset-0 -z-10">
    <div class="absolute inset-0" style="background-image: linear-gradient(to bottom right, rgba(30,58,138,.5), rgba(147,51,234,.35)), var(--bg-img); background-size: cover; background-position: center;"></div>
    <div class="absolute inset-0 bg-white/30 backdrop-blur-[2px]"></div>
  </div>

  <?php include 'components/navbar.php'; ?>

  <main class="flex items-center justify-center py-16 px-4">
    <div class="w-full max-w-md fade-in mt-16">
      <div class="relative p-[2px] rounded-2xl bg-gradient-to-b from-white/70 to-white/20 shadow-2xl shadow-blue-900/10">
        <div class="rounded-2xl bg-white/80 backdrop-blur-xl ring-1 ring-white/60 p-8">
          <div class="mb-6 text-center">
            <h1 class="text-3xl font-extrabold text-blue-900 tracking-tight"><?= htmlspecialchars($PORTAL_NAME) ?> Login</h1>
            <p class="text-blue-900/70 text-sm mt-2">Welcome back! Please sign in to continue.</p>
          </div>

          <?php if (!empty($error)): ?>
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 text-red-700 px-4 py-3" role="alert" aria-live="polite">
              <p class="text-sm font-medium"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          <?php endif; ?>

          <form method="POST" class="space-y-5" onsubmit="return handleSubmit(this)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="relative">
              <label for="username" class="sr-only">Username</label>
              <input
                id="username"
                type="text"
                name="username"
                required
                autocomplete="username"
                inputmode="text"
                class="w-full pl-3 pr-3 py-3 rounded-xl border border-blue-900/10 bg-white/70 backdrop-blur placeholder:text-blue-900/40 text-blue-900 shadow-inner focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition"
                placeholder="Username"
              />
            </div>

            <div class="relative">
              <label for="password" class="sr-only">Password</label>
              <input
                id="password"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                class="w-full pl-3 pr-12 py-3 rounded-xl border border-blue-900/10 bg-white/70 backdrop-blur placeholder:text-blue-900/40 text-blue-900 shadow-inner focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition"
                placeholder="Password"
              />
              <button type="button" onclick="togglePassword()" class="absolute right-3 top-1/2 -translate-y-1/2 text-blue-900/50 hover:text-blue-900/80 transition" aria-label="Toggle password visibility">
                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M12 5c-7 0-11 7-11 7s4 7 11 7 11-7 11-7-4-7-11-7zm0 12a5 5 0 110-10 5 5 0 010 10z"/>
                </svg>
              </button>
            </div>

            <div class="flex items-center justify-between text-sm">
              <label class="inline-flex items-center gap-2 select-none">
                <input type="checkbox" name="remember" class="sr-only peer">
                <span class="w-10 h-6 rounded-full bg-blue-900/10 relative transition peer-checked:bg-blue-600/90">
                  <span class="absolute top-0.5 left-0.5 h-5 w-5 bg-white rounded-full shadow-sm transition peer-checked:translate-x-4"></span>
                </span>
                <span class="text-blue-900/80">Remember me</span>
              </label>
              <a href="forgot_password.php" class="font-medium text-blue-700 hover:text-blue-800 hover:underline">Forgot password?</a>
            </div>

            <button
              type="submit"
              class="group w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold py-3 rounded-xl shadow-lg shadow-indigo-600/20 hover:shadow-indigo-600/40 transition focus:outline-none focus:ring-4 focus:ring-indigo-500/30"
              id="loginButton"
            >
              <span class="inline-block transition-transform group-hover:-translate-y-0.5">Login</span>
              <svg class="h-5 w-5 opacity-90 transition-transform group-hover:translate-x-0.5" xmlns="http://www.w3.org/0/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
              </svg>
            </button>

            <p class="text-center text-sm text-blue-900/70">
              Not an <?= htmlspecialchars($PORTAL_NAME) ?>?
              <a href="teacher_login.php" class="text-blue-800 font-semibold hover:underline">Teacher login</a> ·
              <a href="login.php" class="text-blue-800 font-semibold hover:underline">Student login</a>
            </p>
          </form>
        </div>
      </div>

      <p class="mt-6 text-center text-xs text-blue-900/60">© <?= date('Y') ?> SynapZ. All rights reserved.</p>
    </div>
  </main>

  <script>
    function togglePassword() {
      const input = document.getElementById('password');
      const eye = document.getElementById('eyeIcon');
      const isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';
      eye.setAttribute('fill', isPass ? '#1f2937' : '#111827');
    }
    function handleSubmit(form) {
      const btn = document.getElementById('loginButton');
      btn.disabled = true;
      btn.classList.add('opacity-70', 'cursor-not-allowed');
      btn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg><span class="ml-2">Signing in...</span>';
      return true;
    }
  </script>
</body>
</html>
<?php
ob_end_flush();