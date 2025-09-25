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
  <title>Register - SynapZ</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/emailjs-com@3/dist/email.min.js"></script>
  <style>
    .glass { background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); }
    .step-dot { height: 32px; width: 32px; }
    .bg-hero {
      background-image: linear-gradient(to bottom right, rgba(30,64,175,0.6), rgba(99,102,241,0.6)), url('https://glassoflearning.com/wp-content/uploads/2022/03/virtual-learning-environment-scaled.jpg');
      background-size: cover; background-position: center;
    }
  </style>
</head>
<body class="bg-hero min-h-screen">
  <?php include 'components/navbar.php'; ?>

  <div class="flex items-center justify-center px-4 py-24">
    <div x-data="registerUI()" class="glass rounded-2xl shadow-2xl p-6 sm:p-8 w-full max-w-xl text-slate-800">
      <h2 class="text-3xl font-extrabold text-center mb-2 text-blue-800">📝 Register to SynapZ</h2>
      <p class="text-center text-slate-600 mb-6">Create your account in three quick steps.</p>

      <!-- Stepper -->
      <ol class="flex items-center justify-between mb-6">
        <li class="flex-1 flex items-center">
          <div :class="step >= 1 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-600'"
               class="step-dot rounded-full flex items-center justify-center font-semibold">1</div>
          <span class="ml-3 text-sm font-medium" :class="step >= 1 ? 'text-blue-700' : 'text-gray-500'">Account</span>
        </li>
        <li class="flex-1 flex items-center">
          <div class="h-0.5 flex-1 mx-2" :class="step >= 2 ? 'bg-blue-600' : 'bg-gray-300'"></div>
          <div :class="step >= 2 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-600'"
               class="step-dot rounded-full flex items-center justify-center font-semibold">2</div>
          <span class="ml-3 text-sm font-medium" :class="step >= 2 ? 'text-blue-700' : 'text-gray-500'">Details</span>
        </li>
        <li class="flex-1 flex items-center">
          <div class="h-0.5 flex-1 mx-2" :class="step >= 3 ? 'bg-blue-600' : 'bg-gray-300'"></div>
          <div :class="step >= 3 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-600'"
               class="step-dot rounded-full flex items-center justify-center font-semibold">3</div>
          <span class="ml-3 text-sm font-medium" :class="step >= 3 ? 'text-blue-700' : 'text-gray-500'">Verify</span>
        </li>
      </ol>

      <!-- Alerts -->
      <template x-if="alert.text">
        <div :class="alert.type === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'"
             class="border rounded-lg px-3 py-2 mb-4 text-sm">
          <span x-text="alert.text"></span>
        </div>
      </template>

      <form method="POST" id="registerForm" x-ref="form" @submit.prevent="onSubmit" class="space-y-5" autocomplete="off">
        <!-- Always post 'register' even on programmatic submit -->
        <input type="hidden" name="register" value="1">
        <!-- CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <!-- STEP 1: Account -->
        <div x-show="step === 1" x-transition>
          <!-- Role -->
          <div>
            <label for="role" class="block mb-1 font-medium text-gray-700">Select Role</label>
            <select name="role" id="role" x-model="role" required
                    class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
              <option value="">-- Select --</option>
              <option value="student">Student</option>
              <option value="teacher">Teacher</option>
            </select>
          </div>

          <!-- Username -->
          <div>
            <label for="username" class="block mb-1 font-medium text-gray-700">Username</label>
            <div class="relative">
              <input
                type="text"
                id="username"
                name="username"
                x-model.trim="username"
                @blur="checkUsernameAvailability()"
                @input="usernameExists=false"
                required
                placeholder="Choose a unique username"
                class="w-full border rounded pl-3 pr-10 py-2 focus:outline-none"
                :class="usernameExists ? 'border-red-400 focus:ring-2 focus:ring-red-300' : 'border-gray-300 focus:ring-2 focus:ring-blue-400'" />
              <span class="absolute right-3 top-2.5 opacity-60">👤</span>
            </div>
            <small class="text-gray-500">4-20 chars: letters, numbers, underscores</small>
            <small x-show="checkingUsername" class="block mt-1 text-gray-500">Checking username...</small>
            <small x-show="usernameExists" class="block mt-1 text-red-600">Username already exists!</small>
          </div>

          <!-- Password -->
          <div>
            <label for="password" class="block mb-1 font-medium text-gray-700">Password</label>
            <div class="relative">
              <input :type="showPass ? 'text' : 'password'" id="password" name="password" x-model="password" required
                     placeholder="Create a strong password"
                     @input="calcStrength"
                     class="w-full border border-gray-300 rounded pl-3 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
              <button type="button" @click="showPass=!showPass" class="absolute right-2 top-1.5 text-sm text-blue-600 hover:underline">
                <span x-text="showPass ? 'Hide' : 'Show'"></span>
              </button>
            </div>
            <div class="mt-2">
              <div class="w-full h-2 rounded bg-gray-200 overflow-hidden">
                <div class="h-2 transition-all" :style="`width:${strength.percent}%; background:${strength.color}`"></div>
              </div>
              <small class="text-gray-600" x-text="strength.label"></small>
            </div>
            <small class="text-gray-500 block">Min 8 chars, include uppercase, lowercase, and a number. Avoid using your username in the password.</small>
          </div>

          <!-- Confirm Password -->
          <div>
            <label for="password_confirm" class="block mb-1 font-medium text-gray-700">Confirm Password</label>
            <input :type="showPass ? 'text' : 'password'" id="password_confirm" name="password_confirm" x-model="passwordConfirm" required
                   class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
          </div>
        </div>

        <!-- STEP 2: Details -->
        <div x-show="step === 2" x-transition>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="first_name" class="block mb-1 font-medium text-gray-700">First Name</label>
              <input type="text" id="first_name" name="first_name" x-model.trim="firstName" required
                     class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
            </div>
            <div>
              <label for="last_name" class="block mb-1 font-medium text-gray-700">Last Name</label>
              <input type="text" id="last_name" name="last_name" x-model.trim="lastName" required
                     class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
            </div>
          </div>

          <div>
            <label for="email" class="block mb-1 font-medium text-gray-700">Email</label>
            <div class="relative">
              <input
                type="email"
                id="email"
                name="email"
                x-model.trim="email"
                @blur="checkEmailAvailability()"
                @input="emailExists=false"
                required
                class="w-full border rounded pl-3 pr-10 py-2 focus:outline-none"
                :class="emailExists ? 'border-red-400 focus:ring-2 focus:ring-red-300' : 'border-gray-300 focus:ring-2 focus:ring-blue-400'"
                placeholder="you@example.com"
              />
              <span class="absolute right-3 top-2.5 opacity-60">✉️</span>
            </div>
            <small x-show="checkingEmail" class="block mt-1 text-gray-500">Checking email...</small>
            <small x-show="emailExists" class="block mt-1 text-red-600">Email already exists!</small>
          </div>

          <!-- Student-only (DOB is mandatory) -->
          <div x-show="role === 'student'" x-transition.opacity>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="dob" class="block mb-1 font-medium text-gray-700">Date of Birth</label>
                <input type="date"
                       id="dob"
                       name="dob"
                       x-model="dob"
                       :required="role === 'student'"
                       :max="new Date().toISOString().slice(0,10)"
                       class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
              </div>
            </div>
            <small class="text-gray-500">Students must be at least 13 years old.</small>
          </div>

          <!-- Contact number: required for all roles -->
          <div class="mt-4">
            <label for="contact_number" class="block mb-1 font-medium text-gray-700">Contact Number</label>
            <input
              type="tel"
              id="contact_number"
              name="contact_number"
              x-model.trim="contact"
              required
              inputmode="tel"
              pattern="^\+?\d{8,15}$"
              placeholder="+15551234567"
              class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
            <small class="text-gray-500">Use digits only with optional leading +country code (8–15 digits), e.g. +15551234567.</small>
          </div>

          <div class="flex items-center gap-2 mt-2">
            <input id="terms" type="checkbox" x-model="agree" class="h-4 w-4 border-gray-300 rounded">
            <label for="terms" class="text-sm text-gray-700">I agree to the Terms & Privacy Policy</label>
          </div>
        </div>

        <!-- STEP 3: OTP -->
        <div x-show="step === 3" x-transition>
          <label for="otp" class="block mb-1 font-medium text-gray-700">Enter the OTP sent to your email</label>
          <input
            type="text"
            id="otp"
            name="otp"
            maxlength="6"
            inputmode="numeric"
            pattern="[0-9]*"
            autocomplete="one-time-code"
            x-model="otp"
            class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"
          />
          <p class="text-sm text-gray-600 mt-2" x-text="otpMsg" aria-live="polite"></p>

          <div class="mt-3 flex items-center gap-3">
            <button type="button" @click="resendOtp" :disabled="resendIn > 0 || sending"
                    class="px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 disabled:opacity-50">
              <span x-show="resendIn === 0">Resend OTP</span>
              <span x-show="resendIn > 0" x-text="`Resend in ${resendIn}s`"></span>
            </button>
            <span x-show="sending" class="text-sm text-gray-500">Sending...</span>
          </div>
        </div>

        <!-- Actions -->
        <div class="pt-2 flex items-center justify-between">
          <button type="button" @click="prev" x-show="step > 1"
                  class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300">← Back</button>
          <div class="ml-auto">
            <button type="button" @click="next" x-show="step < 3"
                    :disabled="checkingEmail || emailExists || sending || checkingUsername || usernameExists"
                    class="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">
              Continue
            </button>
            <button type="submit" x-show="step === 3"
                    class="px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700">
              ✅ Register
            </button>
          </div>
        </div>

        <div class="text-center mt-3">
          <a href="login.php" class="text-blue-700 hover:underline">🔐 Already have an account? Login</a>
        </div>
      </form>

      <!-- PHP Registration Logic -->
      <?php
      if (isset($_POST['register'])) {
          // CSRF check
          if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
              echo "<script>alert('⛔ Invalid session. Please refresh and try again.');</script>";
              exit;
          }

          // Validate OTP (compare with session-stored OTP and email)
          $postedEmail = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
          $postedOtp = preg_replace('/\D/', '', $_POST['otp'] ?? '');
          if (!isset($_SESSION['pending_otp'], $_SESSION['pending_email']) ||
              $postedOtp !== $_SESSION['pending_otp'] ||
              $postedEmail !== $_SESSION['pending_email']) {
              echo "<script>alert('⛔ Invalid or expired OTP. Please try again.');</script>";
              exit;
          }
          // Optional: expire OTP after 10 minutes
          if (isset($_SESSION['otp_set_at']) && time() - $_SESSION['otp_set_at'] > 600) {
              echo "<script>alert('⛔ OTP expired. Please request a new one.');</script>";
              exit;
          }
          // Clear OTP from session
          unset($_SESSION['pending_otp'], $_SESSION['pending_email'], $_SESSION['otp_set_at']);

          // Sanitize and validate inputs
          $role = $_POST['role'] ?? '';
          $username = trim($_POST['username'] ?? '');
          $passwordRaw = $_POST['password'] ?? '';
          $password_confirm = $_POST['password_confirm'] ?? '';
          $first = trim($_POST['first_name'] ?? '');
          $last = trim($_POST['last_name'] ?? '');
          $email = $postedEmail;

          // Contact number (mandatory for all roles)
          $contactRaw = trim($_POST['contact_number'] ?? '');
          if ($contactRaw === '') {
              echo "<script>alert('⛔ Contact number is required.');</script>";
              exit;
          }
          // Normalize: remove spaces, hyphens, parentheses, dots; keep a single optional leading +
          $contact = preg_replace('/[\s\-().]/', '', $contactRaw);
          $contact = preg_replace('/(?!^)\+/', '', $contact); // remove any '+' not at start
          if (!preg_match('/^\+?\d{8,15}$/', $contact)) {
              echo "<script>alert('⛔ Please enter a valid phone number (8–15 digits, optional leading +).');</script>";
              exit;
          }

          if ($passwordRaw !== $password_confirm) {
              echo "<script>alert('⛔ Passwords do not match.');</script>";
              exit;
          }
          if (!$email) {
              echo "<script>alert('⛔ Invalid email address.');</script>";
              exit;
          }
          if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
              echo "<script>alert('⛔ Username must be 4-20 characters, letters/numbers/underscores only.');</script>";
              exit;
          }
          if (strlen($passwordRaw) < 8 || !preg_match('/[A-Z]/', $passwordRaw)
              || !preg_match('/[a-z]/', $passwordRaw) || !preg_match('/[0-9]/', $passwordRaw)) {
              echo "<script>alert('⛔ Password must be at least 8 characters and include upper, lower, and a number.');</script>";
              exit;
          }
          // New rule: password must not contain (or match) the username
          if (password_conflicts_with_username($username, $passwordRaw)) {
              echo "<script>alert('⛔ Password must not contain or match your username.');</script>";
              exit;
          }

          // Secure hash using Argon2id (fallback handled inside helper)
          $password = hash_password_secure($passwordRaw);

          // Email exists check in both students and teachers
          $checkEmailStmt = $conn->prepare("SELECT user_id FROM students WHERE email = ? UNION SELECT user_id FROM teachers WHERE email = ?");
          $checkEmailStmt->bind_param("ss", $email, $email);
          $checkEmailStmt->execute();
          $checkEmailStmt->store_result();

          if ($checkEmailStmt->num_rows > 0) {
              echo "<script>alert('⛔ Email already exists!');</script>";
          } else {
              // Username exists check
              $checkStmt = $conn->prepare("SELECT 1 FROM users WHERE username = ?");
              $checkStmt->bind_param("s", $username);
              $checkStmt->execute();
              $checkStmt->store_result();

              if ($checkStmt->num_rows > 0) {
                  echo "<script>alert('⛔ Username already exists!');</script>";
              } else {
                  $stmtUser = $conn->prepare("INSERT INTO users (username, role) VALUES (?, ?)");
                  $stmtUser->bind_param("ss", $username, $role);

                  if ($stmtUser->execute()) {
                      $user_id = $conn->insert_id;

                      $stmtPwd = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)");
                      $stmtPwd->bind_param("is", $user_id, $password);
                      $stmtPwd->execute();

                      if ($role === 'student') {
                          // Require DOB for students
                          $rawDob = $_POST['dob'] ?? '';
                          if (!$rawDob) {
                              echo "<script>alert('⛔ Date of Birth is required for students.');</script>";
                              exit;
                          }
                          // Validate format and age >= 13
                          $birthDate = DateTime::createFromFormat('Y-m-d', $rawDob);
                          $validDob = $birthDate && $birthDate->format('Y-m-d') === $rawDob;
                          if (!$validDob) {
                              echo "<script>alert('⛔ Invalid Date of Birth format.');</script>";
                              exit;
                          }
                          $today = new DateTime();
                          $age = $today->diff($birthDate)->y;
                          if ($age < 13) {
                              echo "<script>alert('🚫 You must be at least 13 years old to register as a student.');</script>";
                              exit;
                          }

                          $dob = $rawDob;

                          $stmtStudent = $conn->prepare("INSERT INTO students (user_id, first_name, last_name, dob, email, contact_number)
                                                  VALUES (?, ?, ?, ?, ?, ?)");
                          $stmtStudent->bind_param("isssss", $user_id, $first, $last, $dob, $email, $contact);
                          $stmtStudent->execute();

                          session_regenerate_id(true); // Session security
                          echo "<script>alert('🎉 Student registered successfully.'); window.location='login.php';</script>";

                      } elseif ($role === 'teacher') {
                          // NOTE: Requires teachers.contact_number column (VARCHAR(20))
                          $stmtTeacher = $conn->prepare("INSERT INTO teachers (user_id, first_name, last_name, email, contact_number)
                                                  VALUES (?, ?, ?, ?, ?)");
                          $stmtTeacher->bind_param("issss", $user_id, $first, $last, $email, $contact);
                          $stmtTeacher->execute();

                          session_regenerate_id(true); // Session security
                          echo "<script>alert('🎉 Teacher registered successfully.'); window.location='login.php';</script>";
                      }
                  } else {
                      echo "<script>alert('⛔ Registration failed due to an error.');</script>";
                  }
              }
          }
      }
      ?>
    </div>
  </div>

  <script>
    // EmailJS
    emailjs.init('sEOkaLKSNn_N29VQb'); // Replace with your EmailJS public key

    function registerUI() {
      return {
        step: 1,
        role: '',
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

        // Email check
        emailExists: false,
        checkingEmail: false,

        // Username check
        usernameExists: false,
        checkingUsername: false,

        async next() {
          if (this.step === 1) {
            if (!this.role) return this.setError('Please select a role.');
            if (!/^[a-zA-Z0-9_]{4,20}$/.test(this.username)) return this.setError('Username must be 4-20 chars (letters, numbers, underscores).');

            // Check username availability before proceeding
            await this.checkUsernameAvailability(true);
            if (this.usernameExists) {
              return this.setError('Username already exists!');
            }

            if (this.password !== this.passwordConfirm) return this.setError('Passwords do not match.');
            if (!(this.password.length >= 8 && /[A-Z]/.test(this.password) && /[a-z]/.test(this.password) && /[0-9]/.test(this.password)))
              return this.setError('Password must be at least 8 chars and include upper, lower, and a number.');

            // New client-side rule
            if (this.passwordContainsUsername()) {
              return this.setError('Password must not contain your username.');
            }

            this.clearAlert();
            this.step = 2;
          } else if (this.step === 2) {
            if (!this.firstName || !this.lastName) return this.setError('Please enter your full name.');
            if (!this.validEmail(this.email)) return this.setError('Please enter a valid email.');
            if (!this.agree) return this.setError('Please agree to the Terms & Privacy Policy.');

            // Require DOB for students + age >= 13
            if (this.role === 'student') {
              if (!this.dob) return this.setError('Date of Birth is required.');
              const age = this.calcAge(this.dob);
              if (Number.isNaN(age)) return this.setError('Please enter a valid Date of Birth.');
              if (age < 13) return this.setError('You must be at least 13 to register as a student.');
            }

            // Contact number required for all roles
            if (!this.validPhone(this.contact)) {
              return this.setError('Please enter a valid phone number (8–15 digits, optional leading +).');
            }

            // Check email availability before proceeding to OTP
            await this.checkEmailAvailability(true);
            if (this.emailExists) {
              return this.setError('Email already exists!');
            }

            this.clearAlert();
            this.sendOtpFlow();
          }
        },
        prev() { if (this.step > 1) { this.clearAlert(); this.step--; } },

        // Check if password contains username (case-insensitive, ignoring non-alphanumerics)
        passwordContainsUsername() {
          const u = (this.username || '').toLowerCase().replace(/[^a-z0-9]/g, '');
          const p = (this.password || '').toLowerCase().replace(/[^a-z0-9]/g, '');
          return u && p.includes(u);
        },

        async sendOtpFlow() {
          try {
            this.sending = true;
            const otp = (Math.floor(100000 + Math.random() * 900000)).toString();
            // Save OTP to session
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

            // Send via EmailJS
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

        // Email availability check
        async checkEmailAvailability(force = false) {
          if (!this.validEmail(this.email)) {
            this.emailExists = false;
            return;
          }
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
          } catch (e) {
            console.error(e);
            this.emailExists = false; // fail open
          } finally {
            this.checkingEmail = false;
          }
        },

        // Username availability check
        async checkUsernameAvailability(force = false) {
          if (!/^[a-zA-Z0-9_]{4,20}$/.test(this.username)) {
            this.usernameExists = false;
            return;
          }
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
          } catch (e) {
            console.error(e);
            this.usernameExists = false; // fail open
          } finally {
            this.checkingUsername = false;
          }
        },
      };
    }
  </script>
</body>
</html>