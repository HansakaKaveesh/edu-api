<?php include 'db_connect.php'; session_start(); ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen"
      style="background-image: url('https://images.scheer-imc.com/wp-content/uploads/2021/10/imc_image_lms_schools_2021_10.jpg'); background-size: cover; background-position: center;">
<?php include 'components/navbar.php'; ?>
  <div class="w-full max-w-md bg-white/90 p-8 rounded shadow">
    <h2 class="text-3xl font-bold mb-6 text-center">🔐 Login</h2>

    <?php
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

            if ($role == 'admin') {
                header("Location: admin_dashboard.php");
                exit;
            } elseif ($role == 'teacher') {
                header("Location: teacher_dashboard.php");
                exit;
            } elseif ($role == 'student') {
                header("Location: student_dashboard.php");
                exit;
            } else {
                echo "<p class='text-red-600 font-semibold mb-4'>❌ Unknown role.</p>";
            }
        } else {
            echo "<p class='text-red-600 font-semibold mb-4'>❌ Invalid credentials</p>";
        }
    }
    ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block mb-1 font-medium">Username</label>
        <input type="text" name="username" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
      </div>

      <div>
        <label class="block mb-1 font-medium">Password</label>
        <input type="password" name="password" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
      </div>

      <div class="flex flex-col items-center space-y-3">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 shadow">
          🔓 Login
        </button>
        <a href="register.php" class="text-blue-600 hover:underline">New here? Register</a>
      </div>
    </form>
  </div>

</body>
</html>