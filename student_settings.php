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

// Uploads directory
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

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
        // Require and verify current password before allowing any changes
        $current_pw = (string)($_POST['current_password'] ?? '');

        if ($current_pw === '') {
            $message = "Please enter your current password to save changes.";
            $isError = true;
        } else {
            if ($ps = $conn->prepare("
                SELECT password_hash 
                FROM passwords 
                WHERE user_id = ? AND is_current = TRUE 
                LIMIT 1
            ")) {
                $ps->bind_param("i", $user_id);
                $ps->execute();
                $res = $ps->get_result();
                $row = $res->fetch_assoc() ?: null;
                $ps->close();

                if (!$row || !password_verify($current_pw, $row['password_hash'])) {
                    $message = "The current password you entered is incorrect.";
                    $isError = true;
                }
            } else {
                $message = "Unable to verify password.";
                $isError = true;
            }
        }

        // Collect inputs
        $first_name     = trim($_POST['first_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $dob            = trim($_POST['dob'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $newpw_raw      = (string)($_POST['password'] ?? '');

        // Basic validation
        if (
            !$isError &&
            ($first_name === '' || $last_name === '' || $dob === '' || $email === '')
        ) {
            $message = "Please complete all required fields.";
            $isError = true;

        } elseif (!$isError && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $isError = true;

        } elseif (!$isError) {

            // Handle profile picture upload
            $profilePicPath = null;
            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['profile_pic']['tmp_name'];
                $fileName = basename($_FILES['profile_pic']['name']);
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                if (in_array($fileExt, $allowed)) {
                    $newFileName = 'profile_' . $user_id . '_' . time() . '.' . $fileExt;
                    $fileDest = $uploadDir . $newFileName;
                    if (move_uploaded_file($fileTmp, $fileDest)) {
                        $profilePicPath = $fileDest;
                        $stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE user_id = ?");
                        $stmt->bind_param("si", $profilePicPath, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $message = "Invalid file type. Please upload JPG, PNG, or GIF.";
                    $isError = true;
                }
            }

            // Update profile
            if (
                !$isError &&
                ($stmt = $conn->prepare("
                    UPDATE students
                    SET first_name=?, last_name=?, dob=?, contact_number=?, email=?
                    WHERE user_id=?
                "))
            ) {
                $stmt->bind_param("sssssi", $first_name, $last_name, $dob, $contact_number, $email, $user_id);
                if (!$stmt->execute()) {
                    $message = "Failed to update profile: " . $stmt->error;
                    $isError = true;
                }
                $stmt->close();
            } elseif (!$isError) {
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
                    if ($ins = $conn->prepare("
                        INSERT INTO passwords (user_id, password_hash, is_current) 
                        VALUES (?, ?, TRUE)
                    ")) {
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

// Fetch profile picture
$profile_pic = '';
if ($stmt = $conn->prepare("SELECT profile_pic FROM users WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile_pic = $stmt->get_result()->fetch_assoc()['profile_pic'] ?? '';
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Account Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
  <div class="fixed inset-0 bg-bubbles -z-10"></div>

  <div class="max-w-8xl mx-auto px-6 py-28">
    <div class="flex flex-col lg:flex-row gap-8">
      <?php include 'components/sidebar_student.php'; ?>

      <main class="w-full space-y-6">
        <div class="relative overflow-hidden rounded-2xl bg-white/90 backdrop-blur ring-1 ring-gray-200 p-6 card">
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
          </div>
        </div>

        <?php if (!empty($message)): ?>
          <div class="relative rounded-xl px-4 py-3 <?= $isError ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' ?>" role="alert">
            <button type="button" onclick="this.parentElement.remove()" class="absolute right-2 top-2 text-gray-400 hover:text-gray-600">
              <ion-icon name="close-outline"></ion-icon>
            </button>
            <div class="flex items-center gap-2 font-medium">
              <ion-icon name="<?= $isError ? 'alert-circle-outline' : 'checkmark-circle-outline' ?>"></ion-icon>
              <span><?= htmlspecialchars($message) ?></span>
            </div>
          </div>
        <?php endif; ?>

        <div class="rounded-2xl bg-white/90 backdrop-blur ring-1 ring-gray-200 p-6 card">
          <form method="POST" enctype="multipart/form-data" class="space-y-10">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <!-- Profile Picture -->
            <section>
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 inline-flex items-center gap-2">
                  <ion-icon name="image-outline" class="text-indigo-600"></ion-icon>
                  Profile Picture
                </h3>
              </div>
              <div class="flex items-center gap-6">
                <div class="relative">
                  <img id="previewImg" src="<?= htmlspecialchars($profile_pic ?: 'uploads/default.png') ?>" alt="Profile Picture" class="h-24 w-24 rounded-full object-cover ring-2 ring-indigo-200 shadow-sm">
                </div>
                <div>
                  <label class="block font-medium mb-1">Change Photo</label>
                  <input
                    type="file"
                    name="profile_pic"
                    accept="image/*"
                    data-lockable="1"
                    class="block w-full text-sm text-gray-700 border border-gray-200 rounded-lg cursor-pointer bg-gray-50 focus:outline-none"
                    onchange="previewFile(this)"
                  >
                  <p class="text-xs text-gray-500 mt-1">Allowed: JPG, PNG, GIF (max 2MB)</p>
                </div>
              </div>
            </section>

            <!-- Personal Information -->
            <section>
              <h3 class="text-lg font-semibold text-gray-900 inline-flex items-center gap-2">
                <ion-icon name="person-outline" class="text-indigo-600"></ion-icon>
                Personal Information
              </h3>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-4">
                <div class="icon-input">
                  <label class="block font-medium mb-1">First Name</label>
                  <ion-icon name="id-card-outline"></ion-icon>
                  <input
                    type="text"
                    name="first_name"
                    value="<?= htmlspecialchars($studentData['first_name'] ?? '') ?>"
                    required
                    data-lockable="1"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                  />
                </div>

                <div class="icon-input">
                  <label class="block font-medium mb-1">Last Name</label>
                  <ion-icon name="person-circle-outline"></ion-icon>
                  <input
                    type="text"
                    name="last_name"
                    value="<?= htmlspecialchars($studentData['last_name'] ?? '') ?>"
                    required
                    data-lockable="1"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                  />
                </div>

                <div class="icon-input">
                  <label class="block font-medium mb-1">Date of Birth</label>
                  <ion-icon name="calendar-outline"></ion-icon>
                  <input
                    type="date"
                    name="dob"
                    value="<?= htmlspecialchars($studentData['dob'] ?? '') ?>"
                    required
                    data-lockable="1"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                  />
                </div>

                <div class="icon-input">
                  <label class="block font-medium mb-1">Contact Number</label>
                  <ion-icon name="call-outline"></ion-icon>
                  <input
                    type="tel"
                    name="contact_number"
                    value="<?= htmlspecialchars($studentData['contact_number'] ?? '') ?>"
                    data-lockable="1"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                  />
                </div>

                <div class="icon-input md:col-span-2">
                  <label class="block font-medium mb-1">Email</label>
                  <ion-icon name="mail-outline"></ion-icon>
                  <input
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars($studentData['email'] ?? '') ?>"
                    required
                    data-lockable="1"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                  />
                </div>
              </div>
            </section>

            <!-- Security -->
            <section>
              <h3 class="text-lg font-semibold text-gray-900 mb-4 inline-flex items-center gap-2">
                <ion-icon name="lock-closed-outline" class="text-indigo-600"></ion-icon>
                Security
              </h3>

              <div class="space-y-4">
                <!-- Current password (required to edit and save) -->
                <div>
                  <label class="block font-medium mb-1">
                    Current Password <span class="text-rose-500">*</span>
                  </label>
                  <div class="relative">
                    <ion-icon name="key-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                    <input
                      type="password"
                      name="current_password"
                      required
                      class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                    />
                  </div>
                  <p class="text-xs text-gray-500 mt-1">
                    Enter your current password to unlock and save changes.
                  </p>
                </div>

                <!-- New password (optional) -->
                <div>
                  <label class="block font-medium mb-1">New Password (optional)</label>
                  <div class="relative">
                    <ion-icon name="key-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                    <input
                      id="password"
                      type="password"
                      name="password"
                      data-lockable="1"
                      class="w-full pl-10 pr-24 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                    />
                    <button
                      type="button"
                      id="togglePw"
                      data-lockable="1"
                      class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center gap-1 text-gray-600 hover:text-gray-800 text-sm px-2 py-1 rounded"
                    >
                      <ion-icon id="pwIcon" name="eye-outline"></ion-icon>
                      <span>Show</span>
                    </button>
                  </div>
                </div>
              </div>
            </section>

            <div class="flex items-center justify-between pt-2">
              <button type="button" onclick="history.back()" class="inline-flex items-center gap-2 text-gray-600 hover:text-gray-800">
                <ion-icon name="arrow-undo-outline"></ion-icon>
                Cancel
              </button>
              <button
                type="submit"
                data-lockable="1"
                class="inline-flex items-center gap-2 bg-indigo-600 text-white px-6 py-2.5 rounded-lg hover:bg-indigo-700 shadow-sm"
              >
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
    // Password toggle (new password)
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

    // Preview uploaded image
    function previewFile(input) {
      const file = input.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = e => document.getElementById('previewImg').src = e.target.result;
        reader.readAsDataURL(file);
      }
    }

    // Lock/unlock editable fields until current password is entered
    const currentPwInput = document.querySelector('input[name="current_password"]');
    const lockables = document.querySelectorAll('[data-lockable="1"]');

    function toggleLock() {
      const hasValue = currentPwInput && currentPwInput.value.trim().length > 0;
      lockables.forEach(el => {
        el.disabled = !hasValue;
        if (!hasValue) {
          el.classList.add('cursor-not-allowed', 'bg-gray-50');
        } else {
          el.classList.remove('cursor-not-allowed', 'bg-gray-50');
        }
      });
    }

    if (currentPwInput) {
      toggleLock();
      currentPwInput.addEventListener('input', toggleLock);
    }
  </script>
  <?php include 'components/footer.php'; ?>
</body>
</html>