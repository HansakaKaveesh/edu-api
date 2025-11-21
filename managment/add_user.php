<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied.");
}

// Ensure CSRF token exists for form rendering
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verify
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid session. Please refresh and try again.";
    }

    // Trim inputs and sanitize
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';
    $status = $_POST['status'] ?? 'active';

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $dob = $_POST['dob'] ?? null;

    $password = (string)($_POST['password'] ?? '');

    // Normalize, allowed values
    $allowedRoles = ['student', 'teacher', 'admin', 'ceo', 'accountant']; // extended
    $allowedStatuses = ['active', 'pending', 'suspended'];

    // If "inactive" comes from older UI, map it safely
    if ($status === 'inactive') $status = 'suspended';

    // Validation
    if ($username === '') $errors[] = "Username is required.";
    elseif (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) $errors[] = "Username must be 3–32 chars (letters, numbers, _ . -).";

    if (!in_array($role, $allowedRoles, true)) $errors[] = "Invalid role selected.";
    if ($first_name === '') $errors[] = "First name is required.";
    if ($last_name === '') $errors[] = "Last name is required.";
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if ($role === 'student' && !$dob) $errors[] = "Date of birth is required for students.";

    $minLen = 8; // recommend 12+ in policy
    if ($password === '' || strlen($password) < $minLen) $errors[] = "Password is required (min {$minLen} characters).";

    if (!in_array($status, $allowedStatuses, true)) $errors[] = "Invalid status.";

    // Check username uniqueness
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Username already exists.";
        }
        $stmt->close();
    }

    // Ensure Argon2id support
    if (empty($errors) && !defined('PASSWORD_ARGON2ID')) {
        $errors[] = "Server does not support Argon2id.";
    }

    if (empty($errors)) {
        // Begin transaction for data integrity
        $conn->begin_transaction();

        try {
            // Insert into users
            $stmt = $conn->prepare("INSERT INTO users (username, role, status) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $role, $status);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            $stmt->close();

            // Argon2id hashing
            $argonOpts = [
                'memory_cost' => 64 * 1024, // 64 MB
                'time_cost'   => 4,
                'threads'     => 2,
            ];
            $password_hash = password_hash($password, PASSWORD_ARGON2ID, $argonOpts);
            if ($password_hash === false) {
                throw new Exception('Failed to hash password.');
            }

            // Insert into passwords table (current)
            $stmt = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)");
            $stmt->bind_param("is", $user_id, $password_hash);
            $stmt->execute();
            $stmt->close();

            // Insert into role-specific table
            if ($role === 'student') {
                $stmt = $conn->prepare("INSERT INTO students (user_id, first_name, last_name, dob, contact_number, email) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssss", $user_id, $first_name, $last_name, $dob, $contact_number, $email);
                $stmt->execute();
                $stmt->close();
            } elseif ($role === 'teacher') {
                $stmt = $conn->prepare("INSERT INTO teachers (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $user_id, $first_name, $last_name, $email);
                $stmt->execute();
                $stmt->close();
            } elseif ($role === 'admin') {
                $stmt = $conn->prepare("INSERT INTO admins (user_id, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $user_id, $first_name, $last_name, $email, $contact_number);
                $stmt->execute();
                $stmt->close();
            } elseif ($role === 'ceo') {
                $stmt = $conn->prepare("INSERT INTO ceo (user_id, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $user_id, $first_name, $last_name, $email, $contact_number);
                $stmt->execute();
                $stmt->close();
            } elseif ($role === 'accountant') {
                $stmt = $conn->prepare("INSERT INTO accountants (user_id, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $user_id, $first_name, $last_name, $email, $contact_number);
                $stmt->execute();
                $stmt->close();
            }

            // Commit all changes
            $conn->commit();

            // Redirect to users list on success
            header("Location: view_users.php?msg=" . urlencode("User added successfully"));
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = "Error occurred while adding user: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Add User (Admin)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        function onRoleChange() {
            const role = document.getElementById('role').value;
            document.getElementById('dob-field').style.display = role === 'student' ? 'block' : 'none';
        }
        window.onload = onRoleChange;
    </script>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col items-center p-6">
<?php include 'components/navbar.php'; ?>
<div class="w-full max-w-md bg-white rounded-lg shadow p-6 mt-12">
    <h2 class="text-2xl font-semibold mb-4">➕ Add New User</h2>

    <?php if (!empty($errors)): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div>
            <label for="username" class="block mb-1 font-medium">Username *</label>
            <input type="text" id="username" name="username" required
                   class="w-full border rounded px-3 py-2"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>

        <div>
            <label for="role" class="block mb-1 font-medium">Role *</label>
            <select id="role" name="role" onchange="onRoleChange()" required
                    class="w-full border rounded px-3 py-2">
                <option value="student" <?= (($_POST['role'] ?? '') === 'student') ? 'selected' : '' ?>>Student</option>
                <option value="teacher" <?= (($_POST['role'] ?? '') === 'teacher') ? 'selected' : '' ?>>Teacher</option>
                <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                <option value="ceo" <?= (($_POST['role'] ?? '') === 'ceo') ? 'selected' : '' ?>>CEO</option>
                <option value="accountant" <?= (($_POST['role'] ?? '') === 'accountant') ? 'selected' : '' ?>>Accountant</option>
            </select>
        </div>

        <div>
            <label for="status" class="block mb-1 font-medium">Status *</label>
            <select id="status" name="status" required class="w-full border rounded px-3 py-2">
                <option value="active" <?= (($_POST['status'] ?? '') === 'active') ? 'selected' : '' ?>>Active</option>
                <option value="pending" <?= (($_POST['status'] ?? '') === 'pending') ? 'selected' : '' ?>>Pending</option>
                <option value="suspended" <?= (($_POST['status'] ?? '') === 'suspended') ? 'selected' : '' ?>>Suspended</option>
            </select>
        </div>

        <div>
            <label for="first_name" class="block mb-1 font-medium">First Name *</label>
            <input type="text" id="first_name" name="first_name" required
                   class="w-full border rounded px-3 py-2"
                   value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
        </div>

        <div>
            <label for="last_name" class="block mb-1 font-medium">Last Name *</label>
            <input type="text" id="last_name" name="last_name" required
                   class="w-full border rounded px-3 py-2"
                   value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
        </div>

        <div id="dob-field" style="display:none;">
            <label for="dob" class="block mb-1 font-medium">Date of Birth (for Students) *</label>
            <input type="date" id="dob" name="dob"
                   class="w-full border rounded px-3 py-2"
                   value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>">
        </div>

        <div>
            <label for="email" class="block mb-1 font-medium">Email *</label>
            <input type="email" id="email" name="email" required
                   class="w-full border rounded px-3 py-2"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div>
            <label for="contact_number" class="block mb-1 font-medium">Contact Number</label>
            <input type="text" id="contact_number" name="contact_number"
                   class="w-full border rounded px-3 py-2"
                   value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
        </div>

        <div>
            <label for="password" class="block mb-1 font-medium">Password *</label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"
                   class="w-full border rounded px-3 py-2">
        </div>

        <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition">
            Add User
        </button>
    </form>
</div>
</body>
</html>