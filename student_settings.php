<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$message = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $dob = $conn->real_escape_string($_POST['dob']);
    $contact_number = $conn->real_escape_string($_POST['contact_number']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    // Update students table
    $conn->query("UPDATE students SET
        first_name='$first_name',
        last_name='$last_name',
        dob='$dob',
        contact_number='$contact_number',
        email='$email'
        WHERE user_id=$user_id");

    // Update password if provided
    if ($password) {
        $conn->query("UPDATE passwords SET is_current = FALSE WHERE user_id = $user_id");
        $stmt = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, TRUE)");
        $stmt->bind_param("is", $user_id, $password);
        if ($stmt->execute()) {
            $message = "✅ Profile and password updated successfully!";
        } else {
            $message = "❌ Failed to update password: " . $conn->error;
        }
    } else {
        $message = "✅ Profile updated successfully!";
    }
}

// Fetch current student data
$studentData = $conn->query("SELECT * FROM students WHERE user_id = $user_id LIMIT 1")->fetch_assoc();
$first = $studentData['first_name'] ?? '';
$last = $studentData['last_name'] ?? '';
$initials = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Account Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior:smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
  </style>
</head>
<body class="bg-gradient-to-br from-sky-50 via-white to-indigo-50 min-h-screen text-gray-800 antialiased">
  <?php include 'components/navbar.php'; ?>

  <div class="max-w-8xl mx-auto px-6 py-28">
    <div class="flex flex-col lg:flex-row gap-8">
      <?php include 'components/sidebar_student.php'; ?>

      <main class="w-full">
        <!-- Header Card -->
        <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6 mb-6">
          <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
          </div>
          <div class="relative flex items-start justify-between gap-6">
            <div class="flex items-center gap-4">
              <div class="flex items-center justify-center h-14 w-14 rounded-full bg-blue-100 text-blue-700 font-bold text-xl ring-1 ring-blue-200">
                <?= htmlspecialchars($initials ?: '👤') ?>
              </div>
              <div>
                <h2 class="text-2xl md:text-3xl font-extrabold text-blue-700 tracking-tight flex items-center gap-2">
                  ⚙️ Account Settings
                </h2>
                <p class="text-gray-600 mt-1">Manage your personal information and password.</p>
              </div>
            </div>
            <button type="button" onclick="history.back()"
                    class="hidden sm:inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
              ← Back
            </button>
          </div>
        </div>

        <!-- Message -->
        <?php if (!empty($message)) :
          $isError = strpos($message, '❌') !== false;
        ?>
          <div class="mb-6">
            <div class="relative rounded-xl px-4 py-3 <?= $isError ? 'bg-red-50 text-red-700 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' ?>">
              <button type="button" aria-label="Close"
                      onclick="this.parentElement.remove()"
                      class="absolute right-2 top-2 text-gray-400 hover:text-gray-600">
                ✕
              </button>
              <div class="flex items-center gap-2 font-medium">
                <span><?= $isError ? '⚠️' : '✅' ?></span>
                <span><?= htmlspecialchars($message) ?></span>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <form method="POST" class="space-y-10">
            <!-- Personal Information -->
            <section>
              <h3 class="text-lg font-semibold text-gray-900 mb-4">Personal Information</h3>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                  <label class="block font-medium mb-1">First Name</label>
                  <input type="text" name="first_name" value="<?= htmlspecialchars($studentData['first_name'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                         autocomplete="given-name" required />
                </div>

                <div>
                  <label class="block font-medium mb-1">Last Name</label>
                  <input type="text" name="last_name" value="<?= htmlspecialchars($studentData['last_name'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                         autocomplete="family-name" required />
                </div>

                <div>
                  <label class="block font-medium mb-1">Date of Birth</label>
                  <input type="date" name="dob" value="<?= htmlspecialchars($studentData['dob'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                         required />
                </div>

                <div>
                  <label class="block font-medium mb-1">Contact Number</label>
                  <input type="tel" name="contact_number" value="<?= htmlspecialchars($studentData['contact_number'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                         placeholder="+1 555 123 4567" inputmode="tel" />
                </div>

                <div class="md:col-span-2">
                  <label class="block font-medium mb-1">Email</label>
                  <input type="email" name="email" value="<?= htmlspecialchars($studentData['email'] ?? '') ?>"
                         class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                         autocomplete="email" required />
                </div>
              </div>
            </section>

            <!-- Security -->
            <section>
              <h3 class="text-lg font-semibold text-gray-900 mb-4">Security</h3>
              <div class="grid grid-cols-1 gap-5">
                <div>
                  <label class="block font-medium mb-1">
                    New Password
                    <span class="text-sm text-gray-500">(Leave blank to keep current)</span>
                  </label>
                  <div class="relative">
                    <input id="password" type="password" name="password"
                           class="w-full pr-24 px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                           autocomplete="new-password" />
                    <button type="button" id="togglePw"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 text-sm px-2 py-1 rounded">
                      Show
                    </button>
                  </div>
                  <div class="mt-2">
                    <div class="h-1.5 w-full bg-gray-200 rounded">
                      <div id="pwBar" class="h-1.5 rounded bg-gradient-to-r from-red-400 via-amber-400 to-emerald-500 transition-all" style="width: 0%"></div>
                    </div>
                    <div id="pwLabel" class="text-xs text-gray-500 mt-1">Strength: —</div>
                  </div>
                </div>
              </div>
            </section>

            <!-- Actions -->
            <div class="flex items-center justify-between pt-2">
              <button type="button" onclick="history.back()"
                      class="inline-flex items-center gap-2 text-gray-600 hover:text-gray-800">
                ← Cancel
              </button>
              <button type="submit"
                      class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg hover:bg-blue-700 shadow-sm">
                💾 Save Changes
              </button>
            </div>
          </form>
        </div>
      </main>
    </div>
  </div>

  <script>
    // Password visibility toggle
    const pw = document.getElementById('password');
    const toggle = document.getElementById('togglePw');
    if (toggle && pw) {
      toggle.addEventListener('click', () => {
        const isText = pw.type === 'text';
        pw.type = isText ? 'password' : 'text';
        toggle.textContent = isText ? 'Show' : 'Hide';
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
        label.textContent = 'Strength: ' + text;
      });
    }
  </script>
</body>
</html>