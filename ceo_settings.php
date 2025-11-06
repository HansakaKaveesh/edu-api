<?php
// ceo_settings.php

session_start();
include 'db_connect.php';

/* Allow CEO or Admin */
if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['ceo', 'admin'], true)) {
  header("Location: login.php");
  exit;
}

$userId = (int)$_SESSION['user_id'];

/* ===== Helpers ===== */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function valid_phone($s){ return $s === '' || preg_match('/^[0-9+\-\s()]{6,20}$/', $s); }

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* Load current CEO profile (if any) */
$ceo = ['first_name'=>'', 'last_name'=>'', 'email'=>'', 'contact_number'=>''];
if ($stmt = $conn->prepare("SELECT first_name, last_name, email, contact_number FROM ceo WHERE user_id = ? LIMIT 1")) {
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) $ceo = $row;
  $stmt->close();
}

/* Messages */
$flash = []; // [ ['type'=>'success|error|info', 'text'=>'...'], ... ]

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $postedCsrf = $_POST['csrf'] ?? '';

  if (!hash_equals($csrf, $postedCsrf)) {
    $flash[] = ['type'=>'error', 'text'=>'Invalid request. Please refresh and try again.'];
  } else if ($action === 'update_profile') {
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['contact_number'] ?? '');

    $errs = [];
    if ($first === '' || $last === '') $errs[] = 'First and last name are required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Please enter a valid email.';
    if (!valid_phone($phone)) $errs[] = 'Please enter a valid contact number.';

    if ($errs) {
      $flash[] = ['type'=>'error', 'text'=>implode(' ', $errs)];
      $ceo = ['first_name'=>$first,'last_name'=>$last,'email'=>$email,'contact_number'=>$phone];
    } else {
      $conn->begin_transaction();
      try {
        // Does row exist?
        $exists = false;
        if ($st = $conn->prepare("SELECT 1 FROM ceo WHERE user_id = ? LIMIT 1")) {
          $st->bind_param('i', $userId);
          $st->execute(); $st->store_result();
          $exists = $st->num_rows > 0;
          $st->close();
        }
        if ($exists) {
          $st = $conn->prepare("UPDATE ceo SET first_name=?, last_name=?, email=?, contact_number=? WHERE user_id=?");
          $st->bind_param('ssssi', $first, $last, $email, $phone, $userId);
          if (!$st->execute()) throw new Exception('Failed to update profile.');
          $st->close();
        } else {
          $st = $conn->prepare("INSERT INTO ceo (user_id, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
          $st->bind_param('issss', $userId, $first, $last, $email, $phone);
          if (!$st->execute()) throw new Exception('Failed to create profile.');
          $st->close();
        }
        $conn->commit();
        $flash[] = ['type'=>'success', 'text'=>'Profile updated successfully.'];
        $ceo = ['first_name'=>$first,'last_name'=>$last,'email'=>$email,'contact_number'=>$phone];
      } catch (Throwable $e) {
        $conn->rollback();
        $flash[] = ['type'=>'error', 'text'=>'Error updating profile.'];
      }
    }
  } else if ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $errs = [];
    // Fetch current hash
    $hash = null;
    if ($st = $conn->prepare("SELECT password_hash FROM passwords WHERE user_id = ? AND is_current = 1 LIMIT 1")) {
      $st->bind_param('i', $userId);
      $st->execute();
      $st->bind_result($hash);
      $st->fetch();
      $st->close();
    }
    if (!$hash || !password_verify($current, $hash)) {
      $errs[] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) $errs[] = 'New password must be at least 8 characters.';
    if ($new !== $confirm) $errs[] = 'New password and confirm do not match.';
    if ($hash && password_verify($new, $hash)) $errs[] = 'New password must be different from current password.';

    if ($errs) {
      $flash[] = ['type'=>'error', 'text'=>implode(' ', $errs)];
    } else {
      $newHash = password_hash($new, PASSWORD_BCRYPT);

      $conn->begin_transaction();
      try {
        if ($st = $conn->prepare("UPDATE passwords SET is_current = 0 WHERE user_id = ?")) {
          $st->bind_param('i', $userId);
          if (!$st->execute()) throw new Exception('Failed to update old passwords.');
          $st->close();
        }
        if ($st = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)")) {
          $st->bind_param('is', $userId, $newHash);
          if (!$st->execute()) throw new Exception('Failed to save new password.');
          $st->close();
        }
        $conn->commit();
        session_regenerate_id(true);
        $flash[] = ['type'=>'success', 'text'=>'Password changed successfully.'];
      } catch (Throwable $e) {
        $conn->rollback();
        $flash[] = ['type'=>'error', 'text'=>'Error changing password.'];
      }
    }
  }
}

/* Sidebar component setup (optional customizations) */
$keepQuery    = []; // nothing to preserve on settings links
$sidebarId    = 'ceoSidebar';
$toggleId     = 'ceoSbToggle';
$sidebarTitle = 'Menu';

// Small helpers for header chips
$fullName = trim(($ceo['first_name'] ?? '') . ' ' . ($ceo['last_name'] ?? ''));
$lastAuth = isset($_SESSION['last_auth']) ? date('Y-m-d H:i', (int)$_SESSION['last_auth']) : '—';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEO · Settings</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen flex flex-col antialiased">
<?php include 'components/navbar.php'; ?>

<main class="max-w-8xl mx-auto px-6 py-28 flex-grow">
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Sidebar -->
    <aside class="lg:col-span-3 lg:sticky lg:top-28 self-start">
      <?php include 'components/ceo_sidebar.php'; ?>
    </aside>

    <!-- Content -->
    <section class="lg:col-span-9 space-y-6">
      <!-- Header + Flash -->
      <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
          <div class="absolute -top-20 -right-16 w-64 h-64 rounded-full bg-blue-200/40 blur-3xl"></div>
          <div class="absolute -bottom-24 -left-20 w-80 h-80 rounded-full bg-indigo-200/40 blur-3xl"></div>
        </div>
        <div class="relative">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
              <h1 class="text-2xl sm:text-3xl font-extrabold text-blue-700 inline-flex items-center gap-2">
                <ion-icon name="settings-outline"></ion-icon> Settings
              </h1>
              <p class="text-gray-600 mt-1 text-sm">Manage your profile and security.</p>

              <!-- Quick chips -->
              <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <?php if ($fullName !== ''): ?>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                    <ion-icon name="person-circle-outline"></ion-icon> <?= e($fullName) ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($ceo['email'])): ?>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                    <ion-icon name="mail-outline"></ion-icon> <?= e($ceo['email']) ?>
                  </span>
                <?php endif; ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-violet-50 text-violet-700 ring-1 ring-violet-200">
                  <ion-icon name="time-outline"></ion-icon> Last auth: <?= e($lastAuth) ?>
                </span>
              </div>
            </div>
          </div>

          <?php if ($flash): ?>
            <div class="mt-4 space-y-2">
              <?php foreach ($flash as $msg): ?>
                <div class="rounded-lg px-4 py-3 ring-1 flex items-center gap-2
                            <?= $msg['type']==='success' ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : ($msg['type']==='error' ? 'bg-rose-50 text-rose-800 ring-rose-200' : 'bg-blue-50 text-blue-800 ring-blue-200') ?>">
                  <ion-icon name="<?= $msg['type']==='success' ? 'checkmark-circle-outline' : ($msg['type']==='error' ? 'alert-circle-outline' : 'information-circle-outline') ?>"></ion-icon>
                  <span><?= e($msg['text']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Forms -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Profile -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <h2 class="text-lg font-bold text-gray-900 inline-flex items-center gap-2">
            <ion-icon name="person-circle-outline" class="text-blue-600"></ion-icon> Profile
          </h2>

          <form method="post" class="mt-4 space-y-4" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="update_profile">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                <div class="relative">
                  <ion-icon name="person-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                  <input name="first_name" required value="<?= e($ceo['first_name']) ?>"
                         class="w-full border border-gray-200 rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                <div class="relative">
                  <ion-icon name="person-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                  <input name="last_name" required value="<?= e($ceo['last_name']) ?>"
                         class="w-full border border-gray-200 rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                </div>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
              <div class="relative">
                <ion-icon name="mail-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                <input type="email" name="email" required value="<?= e($ceo['email']) ?>"
                       class="w-full border border-gray-200 rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
              <div class="relative">
                <ion-icon name="call-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                <input name="contact_number" value="<?= e($ceo['contact_number']) ?>"
                       class="w-full border border-gray-200 rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
              </div>
            </div>

            <div class="pt-2">
              <button class="inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-5 py-2.5 rounded-lg hover:from-blue-600 hover:to-indigo-700 shadow-sm">
                <ion-icon name="save-outline"></ion-icon> Save changes
              </button>
            </div>
          </form>
        </div>

        <!-- Security -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <h2 class="text-lg font-bold text-gray-900 inline-flex items-center gap-2">
            <ion-icon name="shield-checkmark-outline" class="text-emerald-600"></ion-icon> Security
          </h2>

          <form method="post" class="mt-4 space-y-4" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="change_password">

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
              <div class="relative">
                <ion-icon name="lock-closed-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                <input id="pw_current" type="password" name="current_password" required
                       class="w-full border border-gray-200 rounded-lg pl-9 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                <button type="button" data-toggle="#pw_current" class="pw-toggle absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700" aria-label="Toggle password">
                  <ion-icon name="eye-outline"></ion-icon>
                </button>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
              <div class="relative">
                <ion-icon name="key-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                <input id="pw_new" type="password" name="new_password" required minlength="8"
                       class="w-full border border-gray-200 rounded-lg pl-9 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                <button type="button" data-toggle="#pw_new" class="pw-toggle absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700" aria-label="Toggle password">
                  <ion-icon name="eye-outline"></ion-icon>
                </button>
              </div>
              <!-- Strength meter -->
              <div class="mt-2">
                <div class="h-1.5 w-full bg-gray-100 rounded">
                  <div id="pw_strength" class="h-1.5 rounded bg-gray-300" style="width:0%"></div>
                </div>
                <div id="pw_strength_text" class="mt-1 text-[11px] text-gray-500">Strength: —</div>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
              <div class="relative">
                <ion-icon name="checkmark-done-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                <input id="pw_confirm" type="password" name="confirm_password" required minlength="8"
                       class="w-full border border-gray-200 rounded-lg pl-9 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                <button type="button" data-toggle="#pw_confirm" class="pw-toggle absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700" aria-label="Toggle password">
                  <ion-icon name="eye-outline"></ion-icon>
                </button>
              </div>
              <div id="pw_match" class="mt-1 text-[11px] text-gray-500">Match: —</div>
            </div>

            <div class="pt-2">
              <button class="inline-flex items-center gap-2 bg-gradient-to-r from-emerald-600 to-green-600 text-white px-5 py-2.5 rounded-lg hover:from-emerald-600 hover:to-green-700 shadow-sm">
                <ion-icon name="key-outline"></ion-icon> Update password
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Danger zone -->
      <div class="rounded-2xl bg-white shadow-sm ring-1 ring-rose-200/60 p-6 relative overflow-hidden">
        <div aria-hidden="true" class="pointer-events-none absolute -right-10 -bottom-10 w-48 h-48 rounded-full bg-rose-200/40 blur-3xl"></div>
        <h2 class="text-lg font-bold text-rose-700 inline-flex items-center gap-2">
          <ion-icon name="warning-outline"></ion-icon> Danger zone
        </h2>
        <p class="text-sm text-rose-700/90 mt-1">Changed your password recently? For best security, sign out from other devices.</p>
        <div class="mt-3">
          <a href="logout.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-rose-600 text-white hover:bg-rose-700">
            <ion-icon name="log-out-outline"></ion-icon> Sign out
          </a>
        </div>
      </div>
    </section>
  </div>
</main>

<?php include 'components/footer.php'; ?>

<script>
  // Password visibility toggles
  document.querySelectorAll('.pw-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const sel = btn.getAttribute('data-toggle');
      const input = document.querySelector(sel);
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      btn.innerHTML = `<ion-icon name="${input.type === 'password' ? 'eye-outline':'eye-off-outline'}"></ion-icon>`;
    });
  });

  // Password strength and match check
  (function(){
    const newPw = document.getElementById('pw_new');
    const conf  = document.getElementById('pw_confirm');
    const bar   = document.getElementById('pw_strength');
    const txt   = document.getElementById('pw_strength_text');
    const match = document.getElementById('pw_match');

    function score(s) {
      let n = 0;
      if (!s) return 0;
      if (s.length >= 8) n++;
      if (s.length >= 12) n++;
      if (/[a-z]/.test(s) && /[A-Z]/.test(s)) n++;
      if (/\d/.test(s)) n++;
      if (/[^A-Za-z0-9]/.test(s)) n++;
      return Math.min(n, 5);
    }
    function render() {
      const val = newPw?.value || '';
      const sc  = score(val);
      const pct = [0, 20, 40, 60, 80, 100][sc] || 0;
      const colors = ['bg-gray-300','bg-rose-400','bg-amber-400','bg-yellow-400','bg-emerald-400','bg-emerald-600'];
      bar.style.width = pct + '%';
      colors.forEach(c => bar.classList.remove(c));
      bar.classList.add(colors[sc] || 'bg-gray-300');
      const labels = ['—','Very weak','Weak','Fair','Good','Strong'];
      txt.textContent = 'Strength: ' + (labels[sc] || '—');

      const confVal = conf?.value || '';
      if (confVal.length === 0) {
        match.textContent = 'Match: —';
        match.className = 'mt-1 text-[11px] text-gray-500';
      } else if (confVal === val) {
        match.textContent = 'Match: yes';
        match.className = 'mt-1 text-[11px] text-emerald-600';
      } else {
        match.textContent = 'Match: no';
        match.className = 'mt-1 text-[11px] text-rose-600';
      }
    }
    newPw && newPw.addEventListener('input', render);
    conf && conf.addEventListener('input', render);
  })();
</script>
</body>
</html>