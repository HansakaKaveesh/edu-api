<?php
session_start();
include 'db_connect.php';

// Must be logged in as a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$isError = false;

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get student_id safely
$student_id = null;
if ($stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_id = $stmt->get_result()->fetch_assoc()['student_id'] ?? null;
    $stmt->close();
}
if (!$student_id) {
    die("Student profile not found.");
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $message = "Invalid request.";
        $isError = true;
    } else {
        // Collect inputs
        $first_name     = trim($_POST['first_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $dob            = trim($_POST['dob'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $newpw_raw      = (string)($_POST['password'] ?? '');

        // Basic validation
        if ($first_name === '' || $last_name === '' || $dob === '' || $email === '') {
            $message = "Please complete all required fields.";
            $isError = true;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $isError = true;
        } else {
            // Update profile
            if ($stmt = $conn->prepare("
                UPDATE students
                SET first_name=?, last_name=?, dob=?, contact_number=?, email=?
                WHERE user_id=?
            ")) {
                $stmt->bind_param("sssssi", $first_name, $last_name, $dob, $contact_number, $email, $user_id);
                if (!$stmt->execute()) {
                    $message = "Failed to update profile: " . $stmt->error;
                    $isError = true;
                }
                $stmt->close();
            } else {
                $message = "Failed to prepare profile update.";
                $isError = true;
            }

            // Update password if provided
            if (!$isError && $newpw_raw !== '') {
                $hash = password_hash($newpw_raw, PASSWORD_DEFAULT);
                $conn->begin_transaction();
                try {
                    if ($up = $conn->prepare("UPDATE passwords SET is_current=FALSE WHERE user_id=?")) {
                        $up->bind_param("i", $user_id);
                        $up->execute();
                        $up->close();
                    }
                    if ($ins = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, TRUE)")) {
                        $ins->bind_param("is", $user_id, $hash);
                        if (!$ins->execute()) {
                            throw new Exception($ins->error);
                        }
                        $ins->close();
                    }
                    $conn->commit();
                    $message = "Profile and password updated successfully!";
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Failed to update password: " . $e->getMessage();
                    $isError = true;
                }
            } elseif (!$isError) {
                $message = "Profile updated successfully!";
            }
        }
    }
}

// Fetch current student data
$studentData = [];
if ($stmt = $conn->prepare("SELECT first_name, last_name, dob, contact_number, email FROM students WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $studentData = $res->fetch_assoc() ?: [];
    $stmt->close();
}
$first = $studentData['first_name'] ?? '';
$last  = $studentData['last_name'] ?? '';
$initials = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Account Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- Ionicons -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <style>
    html { scroll-behavior:smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
    .bg-bubbles::before, .bg-bubbles::after {
      content:""; position:absolute; border-radius:9999px; filter: blur(40px); opacity:.25; z-index:0; pointer-events:none;
    }
    .bg-bubbles::before { width:420px; height:420px; background: radial-gradient(closest-side,#60a5fa,transparent 70%); top:-80px; left:-80px; }
    .bg-bubbles::after  { width:500px; height:500px; background: radial-gradient(closest-side,#a78bfa,transparent 70%); bottom:-120px; right:-120px; }
    .card { transition: box-shadow .2s ease, transform .2s ease; }
    .card:hover { box-shadow: 0 14px 28px rgba(15,23,42,.09); transform: translateY(-1px); }
    .chip { display:inline-flex; align-items:center; gap:.4rem; padding:.28rem .6rem; border-radius:9999px; font-size:.72rem; font-weight:600; border:1px solid #e2e8f0; background:#f8fafc; color:#334155; }
    .icon-input { position: relative; }
    .icon-input ion-icon { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#94a3b8; }
    .icon-input input { padding-left:2.25rem; }
  </style>
</head>
<body class="bg-gradient-to-br from-sky-50 via-white to-indigo-50 min-h-screen text-gray-800 antialiased">
  <?php include 'components/navbar.php'; ?>

  <!-- Decorative bg -->
  <div class="fixed inset-0 bg-bubbles -z-10"></div>

  <div class="max-w-8xl mx-auto px-6 py-28">
    <div class="flex flex-col lg:flex-row gap-8">
      <?php include 'components/sidebar_student.php'; ?>

      <main class="w-full space-y-6">
        <!-- Header Card -->
        <div class="relative overflow-hidden rounded-2xl bg-white/90 backdrop-blur ring-1 ring-gray-200 p-6 card">
          <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-16 -right-20 w-72 h-72 bg-indigo-200/40 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-sky-200/40 rounded-full blur-3xl"></div>
          </div>
          <div class="relative flex items-start justify-between gap-6">
            <div class="flex items-center gap-4">
              <div class="flex items-center justify-center h-14 w-14 rounded-full bg-indigo-100 text-indigo-700 font-bold text-xl ring-1 ring-indigo-200">
                <?= htmlspecialchars($initials ?: '👤') ?>
              </div>
              <div>
                <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900 tracking-tight flex items-center gap-2">
                  <ion-icon name="settings-outline" class="text-indigo-600 text-2xl"></ion-icon>
                  Account Settings
                </h2>
                <p class="text-gray-600 mt-1">Manage your personal information and password.</p>
              </div>
            </div>
            <button type="button" onclick="history.back()"
                    class="hidden sm:inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-white px-4 py-2 text-indigo-700 hover:bg-indigo-50 hover:border-indigo-300 transition">
              <ion-icon name="arrow-back-outline"></ion-icon>
              Back
            </button>
          </div>
        </div>

        <!-- Message -->
        <?php if (!empty($message)): ?>
          <div class="relative rounded-xl px-4 py-3 <?= $isError ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' ?>" role="alert" aria-live="polite">
            <button type="button" aria-label="Close"
                    onclick="this.parentElement.remove()"
                    class="absolute right-2 top-2 text-gray-400 hover:text-gray-600">
              <ion-icon name="close-outline"></ion-icon>
            </button>
            <div class="flex items-center gap-2 font-medium">
              <ion-icon name="<?= $isError ? 'alert-circle-outline' : 'checkmark-circle-outline' ?>"></ion-icon>
              <span><?= htmlspecialchars($message) ?></span>
            </div>
          </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="rounded-2xl bg-white/90 backdrop-blur ring-1 ring-gray-200 p-6 card">
          <form method="POST" class="space-y-10">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <!-- Personal Information -->
            <section>
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 inline-flex items-center gap-2">
                  <ion-icon name="person-outline" class="text-indigo-600"></ion-icon>
                  Personal Information
                </h3>
                <span class="chip">
                  <ion-icon name="shield-checkmark-outline"></ion-icon>
                  Secure
                </span>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="icon-input">
                  <label class="block font-medium mb-1">First Name</label>
                  <ion-icon name="id-card-outline"></ion-icon>
                  <input type="text" name="first_name" value="<?= htmlspecialchars($studentData['first_name'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                         autocomplete="given-name" required />
                </div>

                <div class="icon-input">
                  <label class="block font-medium mb-1">Last Name</label>
                  <ion-icon name="person-circle-outline"></ion-icon>
                  <input type="text" name="last_name" value="<?= htmlspecialchars($studentData['last_name'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                         autocomplete="family-name" required />
                </div>

                <div class="icon-input">
                  <label class="block font-medium mb-1">Date of Birth</label>
                  <ion-icon name="calendar-outline"></ion-icon>
                  <input type="date" name="dob" value="<?= htmlspecialchars($studentData['dob'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                         required />
                </div>

                <div class="icon-input">
                  <label class="block font-medium mb-1">Contact Number</label>
                  <ion-icon name="call-outline"></ion-icon>
                  <input type="tel" name="contact_number" value="<?= htmlspecialchars($studentData['contact_number'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                         placeholder="+1 555 123 4567" inputmode="tel" />
                </div>

                <div class="icon-input md:col-span-2">
                  <label class="block font-medium mb-1">Email</label>
                  <ion-icon name="mail-outline"></ion-icon>
                  <input type="email" name="email" value="<?= htmlspecialchars($studentData['email'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                         autocomplete="email" required />
                </div>
              </div>
            </section>

            <!-- Security -->
            <section>
              <h3 class="text-lg font-semibold text-gray-900 mb-4 inline-flex items-center gap-2">
                <ion-icon name="lock-closed-outline" class="text-indigo-600"></ion-icon>
                Security
              </h3>
              <div class="grid grid-cols-1 gap-5">
                <div>
                  <label class="block font-medium mb-1">
                    New Password
                    <span class="text-sm text-gray-500">(Leave blank to keep current)</span>
                  </label>
                  <div class="relative">
                    <ion-icon name="key-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                    <input id="password" type="password" name="password"
                           class="w-full pl-10 pr-24 px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                           autocomplete="new-password" />
                    <button type="button" id="togglePw"
                            class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center gap-1 text-gray-600 hover:text-gray-800 text-sm px-2 py-1 rounded">
                      <ion-icon id="pwIcon" name="eye-outline"></ion-icon>
                      <span>Show</span>
                    </button>
                  </div>
                  <div class="mt-2">
                    <div class="h-1.5 w-full bg-gray-200 rounded">
                      <div id="pwBar" class="h-1.5 rounded bg-gradient-to-r from-red-400 via-amber-400 to-emerald-500 transition-all" style="width: 0%"></div>
                    </div>
                    <div id="pwLabel" class="text-xs text-gray-500 mt-1 inline-flex items-center gap-1">
                      <ion-icon name="shield-outline" class="text-gray-400"></ion-icon>
                      Strength: —
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <!-- Actions -->
            <div class="flex items-center justify-between pt-2">
              <button type="button" onclick="history.back()"
                      class="inline-flex items-center gap-2 text-gray-600 hover:text-gray-800">
                <ion-icon name="arrow-undo-outline"></ion-icon>
                Cancel
              </button>
              <button type="submit"
                      class="inline-flex items-center gap-2 bg-indigo-600 text-white px-6 py-2.5 rounded-lg hover:bg-indigo-700 shadow-sm">
                <ion-icon name="save-outline"></ion-icon>
                Save Changes
              </button>
            </div>
          </form>
        </div>
      </main>
    </div>
  </div>

  <script>
    // Password visibility toggle + icon swap
    const pw = document.getElementById('password');
    const toggle = document.getElementById('togglePw');
    const pwIcon = document.getElementById('pwIcon');
    if (toggle && pw && pwIcon) {
      toggle.addEventListener('click', () => {
        const isText = pw.type === 'text';
        pw.type = isText ? 'password' : 'text';
        pwIcon.setAttribute('name', isText ? 'eye-outline' : 'eye-off-outline');
        toggle.querySelector('span').textContent = isText ? 'Show' : 'Hide';
      });
    }

    // Password strength meter
    const bar = document.getElementById('pwBar');
    const label = document.getElementById('pwLabel');
    if (pw && bar && label) {
      pw.addEventListener('input', () => {
        const v = pw.value || '';
        let score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v)) score++;
        if (/[a-z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        const percent = Math.min(100, (score / 5) * 100);
        bar.style.width = percent + '%';
        let text = '—';
        if (percent === 0) text = '—';
        else if (percent <= 40) text = 'Weak';
        else if (percent <= 80) text = 'Medium';
        else text = 'Strong';
        label.lastChild.textContent = 'Strength: ' + text;
      });
    }
  </script>
</body>
</html>