<?php
// teacher_settings.php — profile/account settings for teacher

session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: login.php");
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Strong hash helper (same approach as in register)
function hash_password_secure(string $passwordRaw): string {
    if (defined('PASSWORD_ARGON2ID')) {
        $options = [
            'memory_cost' => 64 * 1024, // 64 MB
            'time_cost'   => 4,
            'threads'     => 2,
        ];
        return password_hash($passwordRaw, PASSWORD_ARGON2ID, $options);
    }
    return password_hash($passwordRaw, PASSWORD_BCRYPT, ['cost' => 12]);
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$user_id = (int)$_SESSION['user_id'];

/* fetch teacher record with id + name */
$teacher_id = 0;
$teacher_name = 'Teacher';
if ($stmt = $conn->prepare("SELECT teacher_id, first_name, last_name FROM teachers WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $teacher_id = (int)$row['teacher_id'];
        $teacher_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Teacher';
    }
    $stmt->close();
}
if ($teacher_id <= 0) { header("Location: login.php"); exit; }

/* Load current profile + account */
$profile = ['first_name'=>'','last_name'=>'','email'=>'','contact_number'=>''];
if ($stmt = $conn->prepare("SELECT first_name, last_name, email, contact_number FROM teachers WHERE teacher_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc() ?: $profile;
    $stmt->close();
}
$account = ['username'=>''];
if ($stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc() ?: $account;
    $stmt->close();
}

/* flash */
$flash = $_SESSION['flash'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash'], $_SESSION['flash_type']);

/* Handle POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'Invalid session. Please refresh and try again.';
        $_SESSION['flash_type'] = 'error';
        header("Location: teacher_settings.php"); exit;
    }

    if ($action === 'update_profile') {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $contact = trim($_POST['contact_number'] ?? '');

        if ($first === '' || $last === '' || !$email) {
            $_SESSION['flash'] = 'Please provide first name, last name, and a valid email.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }
        // Validate phone: optional, if present ensure digits only with optional +
        if ($contact !== '') {
            // FIX: correct normalization (remove spaces, hyphens, parentheses, dots; keep only leading +)
            $normalized = preg_replace('/[\s\-KATEX_INLINE_OPENKATEX_INLINE_CLOSE\.]/', '', $contact);
            $normalized = preg_replace('/(?!^)\+/', '', $normalized);
            if (!preg_match('/^\+?\d{8,15}$/', $normalized)) {
                $_SESSION['flash'] = 'Please enter a valid phone number (8–15 digits, optional leading +).';
                $_SESSION['flash_type'] = 'error';
                header("Location: teacher_settings.php"); exit;
            }
            $contact = $normalized;
        } else {
            $contact = null;
        }

        // Email uniqueness across students + other teachers
        $exists = false;
        if ($stmt = $conn->prepare("SELECT 1 FROM students WHERE email = ? LIMIT 1")) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $exists = true;
            $stmt->close();
        }
        if (!$exists && $stmt = $conn->prepare("SELECT 1 FROM teachers WHERE email = ? AND teacher_id <> ? LIMIT 1")) {
            $stmt->bind_param("si", $email, $teacher_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $exists = true;
            $stmt->close();
        }
        if ($exists) {
            $_SESSION['flash'] = 'Email address is already in use.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }

        if ($stmt = $conn->prepare("UPDATE teachers SET first_name=?, last_name=?, email=?, contact_number=? WHERE teacher_id=?")) {
            $stmt->bind_param("ssssi", $first, $last, $email, $contact, $teacher_id);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $ok ? 'Profile updated successfully.' : 'Failed to update profile.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'error';
        } else {
            $_SESSION['flash'] = 'Failed to prepare update.';
            $_SESSION['flash_type'] = 'error';
        }

        header("Location: teacher_settings.php"); exit;
    }

    if ($action === 'update_username') {
        $username = trim($_POST['username'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
            $_SESSION['flash'] = 'Username must be 4–20 characters (letters, numbers, underscores).';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }
        // uniqueness
        $exists = false;
        if ($stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ? AND user_id <> ? LIMIT 1")) {
            $stmt->bind_param("si", $username, $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $exists = true;
            $stmt->close();
        }
        if ($exists) {
            $_SESSION['flash'] = 'Username is already taken.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }
        if ($stmt = $conn->prepare("UPDATE users SET username = ? WHERE user_id = ?")) {
            $stmt->bind_param("si", $username, $user_id);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $ok ? 'Username updated.' : 'Failed to update username.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'error';
        } else {
            $_SESSION['flash'] = 'Failed to prepare update.';
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: teacher_settings.php"); exit;
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $_SESSION['flash'] = 'New passwords do not match.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }
        // basic rules
        $username = $account['username'] ?? '';
        if (strlen($new) < 8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[a-z]/',$new) || !preg_match('/[0-9]/',$new)) {
            $_SESSION['flash'] = 'Password must be at least 8 characters and include upper, lower, and a number.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }
        if (strcasecmp($new, $username) === 0) {
            $_SESSION['flash'] = 'Password cannot be the same as your username.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }

        // fetch current hash
        $hash = null;
        if ($stmt = $conn->prepare("SELECT password_hash FROM passwords WHERE user_id = ? AND is_current = 1 LIMIT 1")) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) $hash = $r['password_hash'];
            $stmt->close();
        }
        if (!$hash) {
            $_SESSION['flash'] = 'No current password record found.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }
        if (!password_verify($current, $hash)) {
            $_SESSION['flash'] = 'Current password is incorrect.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_settings.php"); exit;
        }

        $newHash = hash_password_secure($new);

        // transaction to rotate password
        $conn->begin_transaction();
        try {
            if ($stmt = $conn->prepare("UPDATE passwords SET is_current = 0 WHERE user_id = ?")) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)")) {
                $stmt->bind_param("is", $user_id, $newHash);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
            $_SESSION['flash'] = 'Password changed successfully.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash'] = 'Failed to change password. Please try again.';
            $_SESSION['flash_type'] = 'error';
        }

        header("Location: teacher_settings.php"); exit;
    }
}

/* Refresh values after potential update */
if ($stmt = $conn->prepare("SELECT first_name, last_name, email, contact_number FROM teachers WHERE teacher_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc() ?: $profile;
    $stmt->close();
}
if ($stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc() ?: $account;
    $stmt->close();
}

// Avatar initials
$initials = '';
if (!empty($profile['first_name']) || !empty($profile['last_name'])) {
    $initials = strtoupper(mb_substr($profile['first_name'] ?? '',0,1).mb_substr($profile['last_name'] ?? '',0,1));
} else {
    $parts = explode(' ', $teacher_name);
    $initials = strtoupper(mb_substr($parts[0] ?? 'T',0,1).mb_substr($parts[1] ?? '',0,1));
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <title>Settings — Teacher</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: { 50:'#eef2ff', 100:'#e0e7ff', 200:'#c7d2fe', 300:'#a5b4fc', 400:'#818cf8', 500:'#6366f1', 600:'#4f46e5', 700:'#4338ca', 800:'#3730a3', 900:'#312e81' }
          },
          boxShadow: {
            glow: '0 0 0 3px rgba(99,102,241,.12), 0 16px 32px rgba(2,6,23,.10)'
          },
          keyframes: { 'fade-in-up': { '0%': { opacity: 0, transform: 'translateY(8px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } } },
          animation: { 'fade-in-up': 'fade-in-up .5s ease-out both' }
        }
      }
    }
  </script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI'; }
    html { scroll-behavior: smooth; }
    .hover-raise { transition:.2s ease; }
    .hover-raise:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(2,6,23,.08); }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50 dark:from-slate-950 dark:via-slate-950 dark:to-slate-900 text-slate-900 dark:text-slate-100 min-h-screen">
<?php include 'components/navbar.php'; ?>

<!-- Layout -->
<div class="max-w-8xl mx-auto px-6 mt-24 relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-6 mb-20">
  <!-- Sidebar -->
  <aside class="hidden lg:block lg:col-span-3">
    <?php
      $active = 'settings';
      include __DIR__ . '/components/teacher_sidebar.php';
    ?>
    
  </aside>

  <!-- Main -->
  <main class="lg:col-span-9 space-y-6">
    <!-- Hero moved inside main -->
    <section class="relative overflow-hidden rounded-2xl ring-1 ring-indigo-100 shadow-sm">
      <div aria-hidden="true" class="absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?q=80&w=1600&auto=format&fit=crop')] bg-cover bg-center"></div>
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/90 via-blue-900/80 to-sky-900/80"></div>
      </div>

      <div class="px-6 py-10">
        <div class="flex flex-wrap items-center justify-between gap-6 text-white">
          <div class="flex items-center gap-4">
            <div class="h-14 w-14 rounded-2xl bg-white/15 ring-1 ring-white/30 text-white flex items-center justify-center text-xl font-semibold">
              <?= e($initials ?: 'T') ?>
            </div>
            <div>
              <h1 class="text-3xl md:text-4xl font-extrabold flex items-center gap-2">
                <i class="ph ph-gear-six"></i> Settings
              </h1>
              <p class="text-white/90">Manage your profile, account, and password.</p>
              <div class="mt-3 flex items-center gap-2">
                <a href="teacher_dashboard.php#dashboard" class="inline-flex items-center gap-2 bg-white/20 px-4 py-2 rounded-lg font-medium text-white hover:bg-white/30 transition">
                  <i class="ph ph-arrow-left"></i> Back to Dashboard
                </a>
                <a href="teacher_sidebar_page.php?active=settings" class="inline-flex lg:hidden items-center gap-2 bg-white/10 px-4 py-2 rounded-lg border border-white/20 text-white hover:bg-white/20 transition">
                  <i class="ph ph-list"></i> Menu
                </a>
              </div>
            </div>
          </div>
          <div class="hidden sm:block text-white/90">
            <div class="rounded-xl border border-white/20 bg-white/10 backdrop-blur px-4 py-3">
              <?= date('l, d M Y') ?><br>
              <span class="text-white/70"><?= date('h:i A') ?></span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Toast / Flash -->
    <?php if (!empty($flash)): 
      $cls = $flash_type === 'success' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' :
             ($flash_type === 'error' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-indigo-50 text-indigo-700 ring-indigo-200'); ?>
      <div id="toast" class="animate-fade-in-up rounded-xl px-4 py-3 ring-1 <?= $cls ?> shadow-sm flex items-start justify-between">
        <span><?= e($flash) ?></span>
        <button type="button" onclick="document.getElementById('toast').remove()" class="ml-3 text-current/60 hover:text-current">Dismiss</button>
      </div>
      <script>
        setTimeout(()=>{ document.getElementById('toast')?.remove(); }, 3500);
      </script>
    <?php endif; ?>

    <!-- Profile -->
    <section id="profile" class="rounded-2xl bg-white/80 dark:bg-slate-900/60 ring-1 ring-slate-200 dark:ring-slate-800 shadow-sm p-6 hover-raise">
      <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
        <i class="ph ph-user-gear text-indigo-600"></i> Profile Information
      </h2>
      <form method="post" class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update_profile">
        <label class="block">
          <span class="text-sm text-slate-700 dark:text-slate-200">First name</span>
          <input name="first_name" value="<?= e($profile['first_name'] ?? '') ?>" required
                 class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
        </label>
        <label class="block">
          <span class="text-sm text-slate-700 dark:text-slate-200">Last name</span>
          <input name="last_name" value="<?= e($profile['last_name'] ?? '') ?>" required
                 class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
        </label>
        <label class="block">
          <span class="text-sm text-slate-700 dark:text-slate-200">Email</span>
          <input type="email" name="email" value="<?= e($profile['email'] ?? '') ?>" required
                 class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
        </label>
        <label class="block">
          <span class="text-sm text-slate-700 dark:text-slate-200">Contact number</span>
          <input type="tel" name="contact_number" value="<?= e($profile['contact_number'] ?? '') ?>"
                 placeholder="+15551234567"
                 class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
          <span class="text-xs text-slate-500 dark:text-slate-400">8–15 digits, optional leading +</span>
        </label>
        <div class="md:col-span-2">
          <button class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg shadow">
            <i class="ph ph-check"></i> Save Profile
          </button>
        </div>
      </form>
    </section>

    <!-- Account -->
    <section id="account" class="rounded-2xl bg-white/80 dark:bg-slate-900/60 ring-1 ring-slate-200 dark:ring-slate-800 shadow-sm p-6 hover-raise">
      <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
        <i class="ph ph-identification-badge text-indigo-600"></i> Account
      </h2>
      <form method="post" class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update_username">
        <label class="block md:col-span-2">
          <span class="text-sm text-slate-700 dark:text-slate-200">Username</span>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">@</span>
            <input name="username" value="<?= e($account['username'] ?? '') ?>" required
                   class="mt-1 w-full pl-7 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
          </div>
          <span class="text-xs text-slate-500 dark:text-slate-400">4–20 chars: letters, numbers, underscores.</span>
        </label>
        <div class="md:col-span-2">
          <button class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg shadow">
            <i class="ph ph-check"></i> Save Username
          </button>
        </div>
      </form>
    </section>

    <!-- Password -->
    <section id="security" class="rounded-2xl bg-white/80 dark:bg-slate-900/60 ring-1 ring-slate-200 dark:ring-slate-800 shadow-sm p-6 hover-raise">
      <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
        <i class="ph ph-keyhole text-indigo-600"></i> Change Password
      </h2>
      <form method="post" class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="change_password">
        <label class="block">
          <span class="text-sm text-slate-700 dark:text-slate-200">Current password</span>
          <div class="relative">
            <input id="cpw" type="password" name="current_password" required
                   class="mt-1 w-full pr-10 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400" onclick="togglePw('cpw')"><i class="ph ph-eye"></i></button>
          </div>
        </label>
        <div></div>
        <label class="block">
          <span class="text-sm text-slate-700 dark:text-slate-200">New password</span>
          <div class="relative">
            <input id="npw" type="password" name="new_password" required
                   class="mt-1 w-full pr-10 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400" onclick="togglePw('npw')"><i class="ph ph-eye"></i></button>
          </div>
        </label>
        <label class="block">
          <span class="text-sm text-slate-700 dark:text-slate-200">Confirm new password</span>
          <div class="relative">
            <input id="cpw2" type="password" name="confirm_password" required
                   class="mt-1 w-full pr-10 rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400" onclick="togglePw('cpw2')"><i class="ph ph-eye"></i></button>
          </div>
        </label>
        <div class="md:col-span-2 text-xs text-slate-500 dark:text-slate-400">
          Must be at least 8 characters and include uppercase, lowercase, and a number. It must not match your username.
        </div>
        <div class="md:col-span-2">
          <button class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg shadow">
            <i class="ph ph-check"></i> Update Password
          </button>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
function togglePw(id){
  const el = document.getElementById(id);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>