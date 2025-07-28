<?php 
ob_start(); // Enable output buffering
include 'db_connect.php'; 
session_start();

$error = ""; // default error message

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $stmt = $conn->prepare("SELECT u.user_id, u.role, p.password_hash FROM users u 
                            JOIN passwords p ON u.user_id = p.user_id AND p.is_current = 1 
                            WHERE u.username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($user_id, $role, $hash);

    if ($stmt->fetch() && password_verify($_POST['password'], $hash)) {
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $role;

        // Redirect based on role
        switch ($role) {
            case 'admin':
                header("Location: admin_dashboard.php");
                exit;
            case 'teacher':
                header("Location: teacher_dashboard.php");
                exit;
            case 'student':
                header("Location: student_dashboard.php");
                exit;
            default:
                $error = "❌ Unknown role.";
        }
    } else {
        $error = "❌ Invalid credentials";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - SynapZ</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body 
  class="bg-gray-100 flex items-center justify-center min-h-screen overflow-x-hidden"
  style="background-image: url('https://images.scheer-imc.com/wp-content/uploads/2021/10/imc_image_lms_schools_2021_10.jpg'); background-size: cover; background-position: center;"
>

  <?php include 'components/navbar.php'; ?>

  <div class="w-full max-w-md bg-white/90 backdrop-blur-sm p-8 rounded-xl shadow-lg mx-4">
    <h2 class="text-3xl font-bold mb-6 text-center text-blue-800">🔐 Login to SynapZ</h2>

    <?php if (!empty($error)): ?>
      <p class='text-red-600 font-semibold mb-4'><?= $error ?></p>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <div>
        <label class="block mb-1 font-semibold text-gray-700">Username</label>
        <input 
          type="text" 
          name="username" 
          required 
          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"
        />
      </div>

      <div>
        <label class="block mb-1 font-semibold text-gray-700">Password</label>
        <input 
          type="password" 
          name="password" 
          required 
          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"
        />
      </div>

      <div class="flex flex-col items-center space-y-4 pt-4">
        <button 
          type="submit" 
          class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition font-semibold shadow-md"
        >
          🔓 Login
        </button>
        <a href="register.php" class="text-blue-700 hover:underline text-sm">
          Don't have an account? Register here
        </a>
      </div>
    </form>
  </div>