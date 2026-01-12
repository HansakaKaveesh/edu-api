<?php
include 'db_connect.php';
session_start();

/**
 * Secure password hashing with Argon2id (fallback to bcrypt if unavailable).
 * Tune options to target ~200–500ms per hash on your server.
 */
function hash_password_secure(string $passwordRaw): string {
    if (defined('PASSWORD_ARGON2ID')) {
        $options = [
            'memory_cost' => 64 * 1024, // 64 MB
            'time_cost'   => 4,         // iterations
            'threads'     => 2,         // parallelism
        ];
        return password_hash($passwordRaw, PASSWORD_ARGON2ID, $options);
    }
    // Fallback for older PHP environments
    return password_hash($passwordRaw, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Disallow passwords that match or contain the username (case-insensitive, ignoring non-alphanumerics).
 */
function password_conflicts_with_username(string $username, string $password): bool {
    $u = strtolower(preg_replace('/[^a-z0-9]+/i', '', $username));
    $p = strtolower(preg_replace('/[^a-z0-9]+/i', '', $password));
    if ($u === '') return false;
    return strpos($p, $u) !== false;
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// AJAX: Save OTP to session (more secure than a hidden input)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_otp') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$otp || !$email) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_request']);
        exit;
    }
    $_SESSION['pending_otp'] = $otp;
    $_SESSION['pending_email'] = $email;
    $_SESSION['otp_set_at'] = time();
    echo json_encode(['ok' => true]);
    exit;
}

// AJAX: Email availability check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_email') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_email']);
        exit;
    }

    $stmt = $conn->prepare("SELECT 1 FROM students WHERE email = ? UNION SELECT 1 FROM teachers WHERE email = ? LIMIT 1");
    $stmt->bind_param("ss", $email, $email);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    echo json_encode(['ok' => true, 'exists' => $exists]);
    exit;
}

// AJAX: Username availability check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_username') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_username']);
        exit;
    }
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    echo json_encode(['ok' => true, 'exists' => $exists]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register (Student) - SynapZ</title>

  <!-- Inter font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Tailwind config (LIGHT UI, no dark mode) -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] },
          boxShadow: { soft: '0 20px 60px rgba(2, 6, 23, .12)' }
        }
      }
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>

  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/emailjs-com@3/dist/email.min.js"></script>

  <style>
    .bg-grid {
      background-image: radial-gradient(circle at 1px 1px, rgba(148,163,184,.35) 1px, transparent 0);
      background-size: 26px 26px;
    }
  </style>
</head>

<body class="min-h-screen font-sans bg-gradient-to-b from-slate-50 via-indigo-50/40 to-white text-slate-900">
  <?php include 'components/navbar.php'; ?>

  <!-- Background decoration (light) -->
  <div class="fixed inset-0 -z-10">
    <div class="absolute inset-0 bg-grid opacity-30"></div>
    <div class="absolute -top-32 -left-32 h-96 w-96 rounded-full bg-indigo-300/40 blur-3xl"></div>
    <div class="absolute top-20 -right-40 h-[32rem] w-[32rem] rounded-full bg-sky-300/35 blur-3xl"></div>
    <div class="absolute bottom-0 left-1/3 h-[28rem] w-[28rem] rounded-full bg-violet-300/25 blur-3xl"></div>
  </div>

  <div class="mx-auto max-w-6xl px-4 py-28 sm:py-32">
    <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
      <!-- Left panel (modern marketing block) -->
      <div class="hidden lg:block">
        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-white/70 px-3 py-1 text-xs font-semibold text-indigo-700">
          Student registration
        </span>

        <h1 class="mt-4 text-4xl font-extrabold tracking-tight text-slate-900">
          Create your student account
        </h1>
        <p class="mt-4 text-slate-600 leading-relaxed max-w-md">
          Sign up in minutes with email OTP verification. Password rules and CSRF protection are enabled.
        </p>

        <div class="mt-8 space-y-3 text-sm text-slate-600">
          <div class="flex gap-3">
            <div class="mt-1.5 h-2 w-2 rounded-full bg-emerald-500"></div>
            <p>Pick a username & strong password</p>
          </div>
          <div class="flex gap-3">
            <div class="mt-1.5 h-2 w-2 rounded-full bg-sky-500"></div>
            <p>Fill in details (DOB 13+ required)</p>
          </div>
          <div class="flex gap-3">
            <div class="mt-1.5 h-2 w-2 rounded-full bg-violet-500"></div>
            <p>Verify your email with an OTP</p>
          </div>
        </div>
      </div>

      <!-- Form card (light glass) -->
      <div x-data="registerUI()"
           class="rounded-3xl border border-slate-200/70 bg-white/70 backdrop-blur-xl shadow-soft">
        <div class="p-6 sm:p-10">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">
                Register (Student)
              </h2>
              <p class="mt-1 text-sm text-slate-600">
                Three steps. OTP required.
              </p>
            </div>
          </div>

          <!-- Stepper -->
          <div class="mt-6">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
              <span :class="step>=1 ? 'text-slate-900' : 'text-slate-400'">Account</span>
              <span :class="step>=2 ? 'text-slate-900' : 'text-slate-400'">Details</span>
              <span :class="step>=3 ? 'text-slate-900' : 'text-slate-400'">Verify</span>
            </div>

            <div class="mt-2 h-2 w-full rounded-full bg-slate-200/70 overflow-hidden">
              <div class="h-2 rounded-full bg-gradient-to-r from-sky-500 to-indigo-600 transition-all duration-300"
                   :style="`width: ${step===1?33:step===2?66:100}%`"></div>
            </div>
          </div>

          <!-- Alerts -->
          <template x-if="alert.text">
            <div class="mt-6 rounded-xl border px-4 py-3 text-sm"
                 :class="alert.type==='error'
                   ? 'border-red-200 bg-red-50 text-red-700'
                   : 'border-emerald-200 bg-emerald-50 text-emerald-800'">
              <span x-text="alert.text"></span>
            </div>
          </template>

          <form method="POST" id="registerForm" x-ref="form" @submit.prevent="onSubmit"
                class="mt-6 space-y-5" autocomplete="off">

            <input type="hidden" name="register" value="1">
            <input type="hidden" name="role" value="student">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <!-- Reusable input style:
              class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                     focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400"
            -->

            <!-- STEP 1 -->
            <div x-show="step === 1" x-transition>
              <div>
                <label for="username" class="text-sm font-medium text-slate-700">Username</label>
                <div class="mt-1">
                  <input
                    type="text"
                    id="username"
                    name="username"
                    x-model.trim="username"
                    @blur="checkUsernameAvailability()"
                    @input="usernameExists=false"
                    required
                    placeholder="e.g. alex_2026"
                    class="w-full rounded-xl border px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2
                           bg-white border-slate-200 focus:ring-indigo-500/30 focus:border-indigo-400"
                    :class="usernameExists ? 'border-red-300 focus:ring-red-500/20 focus:border-red-400' : ''" />
                </div>
                <div class="mt-1 flex items-center justify-between gap-3">
                  <p class="text-xs text-slate-500">4–20 chars, letters/numbers/_</p>
                  <p x-show="checkingUsername" class="text-xs text-slate-500">Checking…</p>
                  <p x-show="usernameExists" class="text-xs text-red-600">Already taken</p>
                </div>
              </div>

              <div class="mt-5">
                <label for="password" class="text-sm font-medium text-slate-700">Password</label>
                <div class="mt-1 relative">
                  <input
                    :type="showPass ? 'text' : 'password'"
                    id="password"
                    name="password"
                    x-model="password"
                    required
                    placeholder="Create a strong password"
                    @input="calcStrength"
                    class="w-full rounded-xl border bg-white border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                           focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400" />
                  <button type="button" @click="showPass=!showPass"
                          class="absolute right-2 top-2 rounded-lg px-2 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">
                    <span x-text="showPass ? 'Hide' : 'Show'"></span>
                  </button>
                </div>

                <div class="mt-3">
                  <div class="h-2 w-full rounded-full bg-slate-200/70 overflow-hidden">
                    <div class="h-2 transition-all" :style="`width:${strength.percent}%; background:${strength.color}`"></div>
                  </div>
                  <p class="mt-1 text-xs text-slate-600" x-text="strength.label"></p>
                  <p class="mt-1 text-xs text-slate-500">
                    Min 8 chars + upper/lower/number. Don’t include username.
                  </p>
                </div>
              </div>

              <div class="mt-5">
                <label for="password_confirm" class="text-sm font-medium text-slate-700">Confirm Password</label>
                <input
                  :type="showPass ? 'text' : 'password'"
                  id="password_confirm"
                  name="password_confirm"
                  x-model="passwordConfirm"
                  required
                  class="mt-1 w-full rounded-xl border bg-white border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400" />
              </div>
            </div>

            <!-- STEP 2 -->
            <div x-show="step === 2" x-transition>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label for="first_name" class="text-sm font-medium text-slate-700">First Name</label>
                  <input
                    type="text"
                    id="first_name"
                    name="first_name"
                    x-model.trim="firstName"
                    required
                    class="mt-1 w-full rounded-xl border bg-white border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                           focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400" />
                </div>
                <div>
                  <label for="last_name" class="text-sm font-medium text-slate-700">Last Name</label>
                  <input
                    type="text"
                    id="last_name"
                    name="last_name"
                    x-model.trim="lastName"
                    required
                    class="mt-1 w-full rounded-xl border bg-white border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                           focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400" />
                </div>
              </div>

              <div class="mt-5">
                <label for="email" class="text-sm font-medium text-slate-700">Email</label>
                <input
                  type="email"
                  id="email"
                  name="email"
                  x-model.trim="email"
                  @blur="checkEmailAvailability()"
                  @input="emailExists=false"
                  required
                  placeholder="you@example.com"
                  class="mt-1 w-full rounded-xl border px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2
                         bg-white border-slate-200 focus:ring-indigo-500/30 focus:border-indigo-400"
                  :class="emailExists ? 'border-red-300 focus:ring-red-500/20 focus:border-red-400' : ''"
                />
                <div class="mt-1 flex items-center justify-between gap-3">
                  <p x-show="checkingEmail" class="text-xs text-slate-500">Checking…</p>
                  <p x-show="emailExists" class="text-xs text-red-600">Email already exists</p>
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label for="dob" class="text-sm font-medium text-slate-700">Date of Birth</label>
                  <input
                    type="date"
                    id="dob"
                    name="dob"
                    x-model="dob"
                    required
                    :max="new Date().toISOString().slice(0,10)"
                    class="mt-1 w-full rounded-xl border bg-white border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                           focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400" />
                  <p class="mt-1 text-xs text-slate-500">Students must be at least 13 years old.</p>
                </div>
              </div>

              <div class="mt-5">
                <label for="contact_number" class="text-sm font-medium text-slate-700">Contact Number</label>
                <input
                  type="tel"
                  id="contact_number"
                  name="contact_number"
                  x-model.trim="contact"
                  required
                  inputmode="tel"
                  pattern="^\+?\d{8,15}$"
                  placeholder="+15551234567"
                  class="mt-1 w-full rounded-xl border bg-white border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400" />
                <p class="mt-1 text-xs text-slate-500">Digits only, optional leading + (8–15 digits).</p>
              </div>

              <label class="mt-5 flex items-center gap-2 rounded-xl border border-slate-200 bg-white/70 px-3 py-3">
                <input id="terms" type="checkbox" x-model="agree"
                       class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30">
                <span class="text-sm text-slate-700">I agree to the Terms & Privacy Policy</span>
              </label>
            </div>

            <!-- STEP 3 -->
            <div x-show="step === 3" x-transition>
              <div class="rounded-2xl border border-slate-200 bg-white/70 p-4">
                <label for="otp" class="text-sm font-medium text-slate-700">Enter the OTP sent to your email</label>
                <input
                  type="text"
                  id="otp"
                  name="otp"
                  maxlength="6"
                  inputmode="numeric"
                  pattern="[0-9]*"
                  autocomplete="one-time-code"
                  x-model="otp"
                  placeholder="6-digit code"
                  class="mt-2 w-full rounded-xl border bg-white border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400"
                />
                <p class="mt-2 text-xs text-slate-600" x-text="otpMsg" aria-live="polite"></p>

                <div class="mt-3 flex items-center gap-3">
                  <button type="button" @click="resendOtp" :disabled="resendIn > 0 || sending"
                          class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700
                                 hover:bg-slate-50 disabled:opacity-50">
                    <span x-show="resendIn === 0">Resend OTP</span>
                    <span x-show="resendIn > 0" x-text="`Resend in ${resendIn}s`"></span>
                  </button>
                  <span x-show="sending" class="text-xs text-slate-500">Sending…</span>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="pt-2 flex items-center justify-between">
              <button type="button" @click="prev" x-show="step > 1"
                      class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Back
              </button>

              <div class="ml-auto flex items-center gap-3">
                <button type="button" @click="next" x-show="step < 3"
                        :disabled="checkingEmail || emailExists || sending || checkingUsername || usernameExists"
                        class="rounded-xl bg-gradient-to-r from-sky-500 to-indigo-600 px-5 py-2 text-sm font-semibold text-white
                               hover:from-sky-400 hover:to-indigo-500 disabled:opacity-50">
                  Continue
                </button>

                <button type="submit" x-show="step === 3"
                        class="rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 px-5 py-2 text-sm font-semibold text-white
                               hover:from-emerald-400 hover:to-teal-400">
                  Create account
                </button>
              </div>
            </div>

            <div class="text-center pt-2">
              <a href="login.php"
                 class="text-sm font-semibold text-indigo-700 hover:text-indigo-800 underline underline-offset-4 decoration-indigo-200">
                Already have an account? Sign in
              </a>
            </div>
          </form>

          <!-- PHP Registration Logic -->
          <?php
          if (isset($_POST['register'])) {
              // CSRF check
              if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                  echo "<script>alert('Invalid session. Please refresh and try again.');</script>";
                  exit;
              }

              // Validate OTP (compare with session-stored OTP and email)
              $postedEmail = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
              $postedOtp = preg_replace('/\D/', '', $_POST['otp'] ?? '');
              if (!isset($_SESSION['pending_otp'], $_SESSION['pending_email']) ||
                  $postedOtp !== $_SESSION['pending_otp'] ||
                  $postedEmail !== $_SESSION['pending_email']) {
                  echo "<script>alert('Invalid or expired OTP. Please try again.');</script>";
                  exit;
              }
              // Optional: expire OTP after 10 minutes
              if (isset($_SESSION['otp_set_at']) && time() - $_SESSION['otp_set_at'] > 600) {
                  echo "<script>alert('OTP expired. Please request a new one.');</script>";
                  exit;
              }
              // Clear OTP from session
              unset($_SESSION['pending_otp'], $_SESSION['pending_email'], $_SESSION['otp_set_at']);

              // Sanitize and validate inputs
              $role = 'student'; // force student role
              $username = trim($_POST['username'] ?? '');
              $passwordRaw = $_POST['password'] ?? '';
              $password_confirm = $_POST['password_confirm'] ?? '';
              $first = trim($_POST['first_name'] ?? '');
              $last = trim($_POST['last_name'] ?? '');
              $email = $postedEmail;

              // Contact number (mandatory)
              $contactRaw = trim($_POST['contact_number'] ?? '');
              if ($contactRaw === '') {
                  echo "<script>alert('Contact number is required.');</script>";
                  exit;
              }
              // Normalize phone
              $contact = preg_replace('/[\s\-().]/', '', $contactRaw);
              $contact = preg_replace('/(?!^)\+/', '', $contact);
              if (!preg_match('/^\+?\d{8,15}$/', $contact)) {
                  echo "<script>alert('Please enter a valid phone number (8–15 digits, optional leading +).');</script>";
                  exit;
              }

              if ($passwordRaw !== $password_confirm) {
                  echo "<script>alert('Passwords do not match.');</script>";
                  exit;
              }
              if (!$email) {
                  echo "<script>alert('Invalid email address.');</script>";
                  exit;
              }
              if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
                  echo "<script>alert('Username must be 4-20 characters, letters/numbers/underscores only.');</script>";
                  exit;
              }
              if (strlen($passwordRaw) < 8 || !preg_match('/[A-Z]/', $passwordRaw)
                  || !preg_match('/[a-z]/', $passwordRaw) || !preg_match('/[0-9]/', $passwordRaw)) {
                  echo "<script>alert('Password must be at least 8 characters and include upper, lower, and a number.');</script>";
                  exit;
              }
              if (password_conflicts_with_username($username, $passwordRaw)) {
                  echo "<script>alert('Password must not contain or match your username.');</script>";
                  exit;
              }

              // Secure hash
              $password = hash_password_secure($passwordRaw);

              // Email exists check (students or teachers)
              $checkEmailStmt = $conn->prepare("SELECT user_id FROM students WHERE email = ? UNION SELECT user_id FROM teachers WHERE email = ?");
              $checkEmailStmt->bind_param("ss", $email, $email);
              $checkEmailStmt->execute();
              $checkEmailStmt->store_result();

              if ($checkEmailStmt->num_rows > 0) {
                  echo "<script>alert('Email already exists!');</script>";
              } else {
                  // Username exists check
                  $checkStmt = $conn->prepare("SELECT 1 FROM users WHERE username = ?");
                  $checkStmt->bind_param("s", $username);
                  $checkStmt->execute();
                  $checkStmt->store_result();

                  if ($checkStmt->num_rows > 0) {
                      echo "<script>alert('Username already exists!');</script>";
                  } else {
                      // Create user
                      $stmtUser = $conn->prepare("INSERT INTO users (username, role) VALUES (?, ?)");
                      $stmtUser->bind_param("ss", $username, $role);

                      if ($stmtUser->execute()) {
                          $user_id = $conn->insert_id;

                          // Save password
                          $stmtPwd = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)");
                          $stmtPwd->bind_param("is", $user_id, $password);
                          $stmtPwd->execute();

                          // Student-specific: require DOB and age >= 13
                          $rawDob = $_POST['dob'] ?? '';
                          if (!$rawDob) {
                              echo "<script>alert('Date of Birth is required for students.');</script>";
                              exit;
                          }
                          $birthDate = DateTime::createFromFormat('Y-m-d', $rawDob);
                          $validDob = $birthDate && $birthDate->format('Y-m-d') === $rawDob;
                          if (!$validDob) {
                              echo "<script>alert('Invalid Date of Birth format.');</script>";
                              exit;
                          }
                          $today = new DateTime();
                          $age = $today->diff($birthDate)->y;
                          if ($age < 13) {
                              echo "<script>alert('You must be at least 13 years old to register as a student.');</script>";
                              exit;
                          }

                          $dob = $rawDob;

                          $stmtStudent = $conn->prepare("INSERT INTO students (user_id, first_name, last_name, dob, email, contact_number)
                                                  VALUES (?, ?, ?, ?, ?, ?)");
                          $stmtStudent->bind_param("isssss", $user_id, $first, $last, $dob, $email, $contact);
                          $stmtStudent->execute();

                          session_regenerate_id(true);
                          echo "<script>alert('Student registered successfully.'); window.location='login.php';</script>";
                      } else {
                          echo "<script>alert('Registration failed due to an error.');</script>";
                      }
                  }
              }
          }
          ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    // EmailJS
    emailjs.init('sEOkaLKSNn_N29VQb'); // Replace with your EmailJS public key

    function registerUI() {
      return {
        step: 1,
        username: '',
        password: '',
        passwordConfirm: '',
        firstName: '',
        lastName: '',
        email: '',
        dob: '',
        contact: '',
        agree: false,
        otp: '',
        otpMsg: '',
        sending: false,
        resendIn: 0,
        showPass: false,
        alert: { type: '', text: '' },
        strength: { percent: 0, label: 'Strength: —', color: '#e5e7eb' },

        // Email/Username checks
        emailExists: false,
        checkingEmail: false,
        usernameExists: false,
        checkingUsername: false,

        async next() {
          if (this.step === 1) {
            if (!/^[a-zA-Z0-9_]{4,20}$/.test(this.username)) return this.setError('Username must be 4-20 chars (letters, numbers, underscores).');

            await this.checkUsernameAvailability();
            if (this.usernameExists) return this.setError('Username already exists!');

            if (this.password !== this.passwordConfirm) return this.setError('Passwords do not match.');
            if (!(this.password.length >= 8 && /[A-Z]/.test(this.password) && /[a-z]/.test(this.password) && /[0-9]/.test(this.password)))
              return this.setError('Password must be at least 8 chars and include upper, lower, and a number.');
            if (this.passwordContainsUsername()) return this.setError('Password must not contain your username.');

            this.clearAlert();
            this.step = 2;
          } else if (this.step === 2) {
            if (!this.firstName || !this.lastName) return this.setError('Please enter your full name.');
            if (!this.validEmail(this.email)) return this.setError('Please enter a valid email.');
            if (!this.dob) return this.setError('Date of Birth is required.');
            const age = this.calcAge(this.dob);
            if (Number.isNaN(age)) return this.setError('Please enter a valid Date of Birth.');
            if (age < 13) return this.setError('You must be at least 13 to register as a student.');
            if (!this.validPhone(this.contact)) return this.setError('Please enter a valid phone number (8–15 digits, optional leading +).');
            if (!this.agree) return this.setError('Please agree to the Terms & Privacy Policy.');

            await this.checkEmailAvailability();
            if (this.emailExists) return this.setError('Email already exists!');

            this.clearAlert();
            this.sendOtpFlow();
          }
        },
        prev() { if (this.step > 1) { this.clearAlert(); this.step--; } },

        passwordContainsUsername() {
          const u = (this.username || '').toLowerCase().replace(/[^a-z0-9]/g, '');
          const p = (this.password || '').toLowerCase().replace(/[^a-z0-9]/g, '');
          return u && p.includes(u);
        },

        async sendOtpFlow() {
          try {
            this.sending = true;
            const otp = (Math.floor(100000 + Math.random() * 900000)).toString();

            const formData = new URLSearchParams();
            formData.append('action', 'save_otp');
            formData.append('otp', otp);
            formData.append('email', this.email);
            formData.append('csrf_token', document.querySelector('input[name=csrf_token]').value);

            const res = await fetch(window.location.href, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: formData.toString()
            });
            const data = await res.json();
            if (!data.ok) throw new Error('Failed to store OTP');

            await emailjs.send('service_lcnrnrg', 'template_2e53yom', {
              to_email: this.email,
              to_name: this.firstName || 'there',
              otp: otp
            });

            this.otpMsg = 'OTP sent! Please check your inbox (and spam).';
            this.step = 3;
            this.startResendTimer();
            this.clearAlert();
          } catch (e) {
            console.error(e);
            this.setError('Failed to send OTP. Check the email address and try again.');
          } finally {
            this.sending = false;
          }
        },

        async resendOtp() { if (this.resendIn === 0) this.sendOtpFlow(); },
        startResendTimer() {
          this.resendIn = 45;
          const t = setInterval(() => {
            this.resendIn--;
            if (this.resendIn <= 0) clearInterval(t);
          }, 1000);
        },

        async onSubmit() {
          if (this.step < 3) { await this.next(); return; }
          if (!/^\d{6}$/.test(this.otp)) return this.setError('Please enter the 6-digit OTP.');
          this.clearAlert();
          this.$refs.form.submit();
        },

        // Helpers
        setError(msg) { this.alert = { type: 'error', text: msg }; },
        clearAlert() { this.alert = { type: '', text: '' }; },
        validEmail(e) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); },
        validPhone(s) {
          if (!s) return false;
          const cleaned = s.replace(/[\s\-().]/g, '');
          return /^\+?\d{8,15}$/.test(cleaned);
        },
        calcAge(d) {
          const birth = new Date(d), now = new Date();
          let age = now.getFullYear() - birth.getFullYear();
          const m = now.getMonth() - birth.getMonth();
          if (m < 0 || (m === 0 && now.getDate() < birth.getDate())) age--;
          return age;
        },
        calcStrength() {
          let score = 0;
          const p = this.password || '';
          if (p.length >= 8) score++;
          if (/[A-Z]/.test(p)) score++;
          if (/[a-z]/.test(p)) score++;
          if (/[0-9]/.test(p)) score++;
          if (/[^A-Za-z0-9]/.test(p)) score++;
          const percent = [0, 25, 50, 75, 100][score] || 0;
          const label = ['Very weak', 'Weak', 'Okay', 'Good', 'Strong'][score-1] || 'Strength: —';
          const color = ['#ef4444', '#f59e0b', '#eab308', '#10b981', '#059669'][score-1] || '#e5e7eb';
          this.strength = { percent, label: 'Strength: ' + label, color };
        },

        async checkEmailAvailability() {
          if (!this.validEmail(this.email)) { this.emailExists = false; return; }
          this.checkingEmail = true;
          try {
            const formData = new URLSearchParams();
            formData.append('action', 'check_email');
            formData.append('email', this.email);
            formData.append('csrf_token', document.querySelector('input[name=csrf_token]').value);
            const res = await fetch(window.location.href, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: formData.toString()
            });
            const data = await res.json();
            this.emailExists = !!(data.ok && data.exists);
          } catch (e) { console.error(e); this.emailExists = false; }
          finally { this.checkingEmail = false; }
        },

        async checkUsernameAvailability() {
          if (!/^[a-zA-Z0-9_]{4,20}$/.test(this.username)) { this.usernameExists = false; return; }
          this.checkingUsername = true;
          try {
            const formData = new URLSearchParams();
            formData.append('action', 'check_username');
            formData.append('username', this.username);
            formData.append('csrf_token', document.querySelector('input[name=csrf_token]').value);
            const res = await fetch(window.location.href, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: formData.toString()
            });
            const data = await res.json();
            this.usernameExists = !!(data.ok && data.exists);
          } catch (e) { console.error(e); this.usernameExists = false; }
          finally { this.checkingUsername = false; }
        },
      };
    }
  </script>
</body>
</html>