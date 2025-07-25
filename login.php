<?php 
ob_start();
include 'db_connect.php'; 
session_start();
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username'], $_POST['password'])) {
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
        switch ($role) {
            case 'admin': header("Location: admin_dashboard.php"); exit;
            case 'teacher': header("Location: teacher_dashboard.php"); exit;
            case 'student': header("Location: student_dashboard.php"); exit;
            default: $error = "❌ Unknown role.";
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
  <script src="https://cdn.jsdelivr.net/npm/emailjs-com@3/dist/email.min.js"></script>
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
        <a href="#" id="forgotPasswordLink" class="text-blue-600 hover:underline text-sm mt-2">Forgot password?</a>
      </div>
    </form>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 hidden">
      <div class="bg-white rounded-lg shadow-lg p-8 w-full max-w-sm relative">
        <button id="closeModal" class="absolute top-2 right-3 text-2xl text-gray-400 hover:text-red-500">&times;</button>
        <h3 class="text-xl font-bold mb-4 text-blue-700">Reset Password</h3>
        <form id="forgotForm" class="space-y-4">
          <div>
            <label for="forgotEmail" class="block mb-1 font-medium text-gray-700">Enter your email:</label>
            <input type="email" id="forgotEmail" required class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
          </div>
          <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded shadow transition">Send Reset Code</button>
          <div class="text-center mt-2" id="forgotMsg"></div>
        </form>
      </div>
    </div>
  </div>
  <script>
    emailjs.init('Ccq9mDLsWERzsXi_I'); // <-- Replace with your EmailJS public key

    const forgotLink = document.getElementById('forgotPasswordLink');
    const forgotModal = document.getElementById('forgotModal');
    const closeModal = document.getElementById('closeModal');
    const forgotForm = document.getElementById('forgotForm');
    const forgotMsg = document.getElementById('forgotMsg');

    forgotLink.onclick = function(e) {
      e.preventDefault();
      forgotModal.classList.remove('hidden');
      forgotMsg.textContent = '';
    };
    closeModal.onclick = function() {
      forgotModal.classList.add('hidden');
    };

    forgotForm.onsubmit = function(e) {
      e.preventDefault();
      const email = document.getElementById('forgotEmail').value;
      forgotMsg.textContent = "Sending reset code...";

      // Generate a random code
      const code = Math.floor(100000 + Math.random() * 900000);

      // Send email via EmailJS
      emailjs.send('service_8du74v5', 'template_3trwdxy', {
        to_email: email,
        code: code
      }).then(function(response) {
        forgotMsg.textContent = "✅ Reset code sent! Please check your email.";
        // Store code/email in localStorage for demo (for real apps, use backend)
        localStorage.setItem('reset_email', email);
        localStorage.setItem('reset_code', code);
        // Optionally, redirect to reset_password.php
        setTimeout(function() {
          window.location.href = 'reset_password.php';
        }, 2000);
      }, function(error) {
        forgotMsg.textContent = "⛔ Failed to send email. Please try again.";
      });
    };
  </script>
</body>
</html>