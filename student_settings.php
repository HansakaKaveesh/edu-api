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
        // Set all old passwords as not current
        $conn->query("UPDATE passwords SET is_current = FALSE WHERE user_id = $user_id");

        // Insert new password hash
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Account Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen font-sans text-gray-800">
<?php include 'components/navbar.php'; ?>
  <!-- Sidebar -->
<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-8 py-28 gap-8">
    <?php include 'components/sidebar_student.php'; ?>
  

  <!-- Main content -->
  <main class=" w-full space-y-10  max-w-3x2 bg-white p-8 rounded-lg shadow overflow-auto">
    <h2 class="text-2xl font-bold mb-6 text-gray-800 flex items-center gap-2">⚙️ Account Settings</h2>

    <?php if (!empty($message)) : ?>
      <div class="mb-4 text-green-600 font-semibold"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <div>
        <label class="block font-medium mb-1">First Name</label>
        <input type="text" name="first_name" value="<?= htmlspecialchars($studentData['first_name'] ?? '') ?>" class="w-full px-4 py-2 border rounded-md" required />
      </div>

      <div>
        <label class="block font-medium mb-1">Last Name</label>
        <input type="text" name="last_name" value="<?= htmlspecialchars($studentData['last_name'] ?? '') ?>" class="w-full px-4 py-2 border rounded-md" required />
      </div>

      <div>
        <label class="block font-medium mb-1">Date of Birth</label>
        <input type="date" name="dob" value="<?= htmlspecialchars($studentData['dob'] ?? '') ?>" class="w-full px-4 py-2 border rounded-md" required />
      </div>

      <div>
        <label class="block font-medium mb-1">Contact Number</label>
        <input type="text" name="contact_number" value="<?= htmlspecialchars($studentData['contact_number'] ?? '') ?>" class="w-full px-4 py-2 border rounded-md" />
      </div>

      <div>
        <label class="block font-medium mb-1">Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($studentData['email'] ?? '') ?>" class="w-full px-4 py-2 border rounded-md" required />
      </div>

      <div>
        <label class="block font-medium mb-1">New Password <span class="text-sm text-gray-500">(Leave blank to keep current)</span></label>
        <input type="password" name="password" class="w-full px-4 py-2 border rounded-md" />
      </div>

      <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition">
        💾 Save Changes
      </button>
    </form>
  </main>

</body>
</html>
