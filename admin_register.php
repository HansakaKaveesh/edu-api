<?php
declare(strict_types=1);

session_start();
require_once 'db_connect.php';

/* Helper */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* Optional: restrict access to logged-in admins only (uncomment to enforce) */
// if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
//   header('Location: admin_login.php');
//   exit;
// }

$successMsg = '';
$errorMsg   = '';
$postedRole = $_POST['role'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $errorMsg = 'Invalid request. Please refresh and try again.';
  } else {
    $role     = strtolower(trim($_POST['role'] ?? 'admin'));
    $allowed  = ['admin','ceo'];
    if (!in_array($role, $allowed, true)) $role = 'admin';

    // Collect inputs
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $contact  = trim($_POST['contact_number'] ?? '');

    // Basic validation
    $errors = [];
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
      $errors[] = 'Username must be 3–32 chars (letters, numbers, dot, underscore, hyphen).';
    }
    if (strlen($password) < 8) {
      $errors[] = 'Password must be at least 8 characters.';
    }
    if ($first === '' || $last === '') {
      $errors[] = 'First name and last name are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Invalid email address.';
    }
    if ($contact !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $contact)) {
      $errors[] = 'Invalid contact number.';
    }

    // Uniqueness checks
    if (!$errors) {
      $stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $stmt->store_result();
      if ($stmt->num_rows > 0) $errors[] = 'Username is already taken.';
      $stmt->close();

      // Check email across both profile tables to avoid duplicates
      // (Remove one of these if you prefer per-role uniqueness)
      $stmt = $conn->prepare("SELECT 1 FROM admins WHERE email = ? LIMIT 1");
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $stmt->store_result();
      $adminEmailExists = $stmt->num_rows > 0;
      $stmt->close();

      $stmt = $conn->prepare("SELECT 1 FROM ceo WHERE email = ? LIMIT 1");
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $stmt->store_result();
      $ceoEmailExists = $stmt->num_rows > 0;
      $stmt->close();

      if ($adminEmailExists || $ceoEmailExists) {
        $errors[] = 'Email is already used.';
      }
    }

    if ($errors) {
      $errorMsg = '⚠️ ' . implode(' ', $errors);
    } else {
      $hash = password_hash($password, PASSWORD_BCRYPT);

      // Transaction: users -> passwords -> profile table (admins|ceo)
      $conn->begin_transaction();
      try {
        // 1) users
        $stmt = $conn->prepare("INSERT INTO users (username, role) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $role);
        if (!$stmt->execute()) throw new Exception('Failed to create user: ' . $stmt->error);
        $user_id = (int)$conn->insert_id;
        $stmt->close();

        // 2) passwords
        $stmt = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)");
        $stmt->bind_param("is", $user_id, $hash);
        if (!$stmt->execute()) throw new Exception('Failed to save password: ' . $stmt->error);
        $stmt->close();

        // 3) profile
        if ($role === 'admin') {
          // Ensure you have this table:
          // CREATE TABLE admins (admin_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT UNIQUE NOT NULL, first_name VARCHAR(50), last_name VARCHAR(50), email VARCHAR(100), contact_number VARCHAR(15), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id));
          $stmt = $conn->prepare("INSERT INTO admins (user_id, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
        } else {
          // ceo table was provided in your schema
          $stmt = $conn->prepare("INSERT INTO ceo (user_id, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
        }
        $stmt->bind_param("issss", $user_id, $first, $last, $email, $contact);
        if (!$stmt->execute()) throw new Exception('Failed to create profile: ' . $stmt->error);
        $stmt->close();

        $conn->commit();
        $successMsg = ($role === 'admin') ? '✅ Admin registered successfully!' : '✅ CEO registered successfully!';
        // Clear form values on success
        $_POST = [];
        $postedRole = $role;
      } catch (Throwable $e) {
        $conn->rollback();
        $errorMsg = '⚠️ Error: ' . $e->getMessage();
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register Admin or CEO</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[url('https://images.unsplash.com/photo-1542744173-8e7e53415bb0?auto=format&fit=crop&w=1470&q=80')] bg-cover bg-center min-h-screen flex items-center justify-center text-gray-800">

  <div class="bg-white bg-opacity-90 shadow-xl rounded-lg p-8 w-full max-w-md backdrop-blur">
    <h2 id="formTitle" class="text-2xl font-bold mb-6 text-center">
      <?= ($postedRole === 'ceo') ? 'Register a CEO' : 'Register an Administrator' ?>
    </h2>

    <?php if ($successMsg): ?>
      <p class="mb-4 text-green-700 text-center font-medium"><?= e($successMsg) ?></p>
    <?php elseif ($errorMsg): ?>
      <p class="mb-4 text-red-700 text-center font-medium"><?= e($errorMsg) ?></p>
    <?php endif; ?>

    <form method="POST" class="space-y-4" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <!-- Role selector -->
      <div>
        <span class="block text-sm font-medium mb-1">Role</span>
        <div class="flex gap-3">
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="role" value="admin" class="sr-only peer" <?= ($postedRole === 'admin') ? 'checked' : '' ?> />
            <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200">
              Admin
            </span>
          </label>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="role" value="ceo" class="sr-only peer" <?= ($postedRole === 'ceo') ? 'checked' : '' ?> />
            <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200">
              CEO
            </span>
          </label>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Username</label>
        <input type="text" name="username" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200" value="<?= e($_POST['username'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Password</label>
        <input type="password" name="password" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200" placeholder="Min 8 characters">
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium mb-1">First Name</label>
          <input type="text" name="first_name" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200" value="<?= e($_POST['first_name'] ?? '') ?>">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Last Name</label>
          <input type="text" name="last_name" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200" value="<?= e($_POST['last_name'] ?? '') ?>">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Contact Number</label>
        <input type="text" name="contact_number" class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200" value="<?= e($_POST['contact_number'] ?? '') ?>">
      </div>
      <div>
        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md shadow">
          Register
        </button>
      </div>
    </form>

    <div class="mt-6 text-center text-sm">
      <span>Go to: </span>
      <a href="admin_login.php" class="text-blue-700 hover:underline">Admin login</a>
      <span class="mx-1">·</span>
      <a href="ceo_login.php" class="text-blue-700 hover:underline">CEO login</a>
    </div>
  </div>

  <script>
    // Update title on role change
    document.addEventListener('change', (e) => {
      if (e.target.name === 'role') {
        const title = document.getElementById('formTitle');
        title.textContent = e.target.value === 'ceo' ? 'Register a CEO' : 'Register an Administrator';
      }
    });
  </script>
</body>
</html>