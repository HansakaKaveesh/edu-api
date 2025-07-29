<?php
include 'db_connect.php';
session_start();
$error = $success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];

    // Find user by email in all profiles
    $stmt = $conn->prepare("
        SELECT user_id FROM students WHERE email = ?
        UNION
        SELECT user_id FROM teachers WHERE email = ?
        UNION
        SELECT user_id FROM admins WHERE email = ?
    ");
    $stmt->bind_param("sss", $email, $email, $email);
    $stmt->execute();
    $stmt->bind_result($user_id);

    if ($stmt->fetch()) {
        $stmt->close();

        // Generate OTP
        $otp = rand(100000, 999999);
        $expires = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        // Store OTP
        $stmt2 = $conn->prepare("INSERT INTO password_resets (user_id, otp_code, expires_at) VALUES (?, ?, ?)");
        $stmt2->bind_param("iss", $user_id, $otp, $expires);
        $stmt2->execute();
        $stmt2->close();

        // Store for next step
        $_SESSION['reset_user_id'] = $user_id;
        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_otp'] = $otp; // For demo/testing only, remove in production!

        // Redirect to reset_password.php
        header("Location: reset_password.php");
        exit;
    } else {
        $stmt->close();
        $error = "No user found with that email.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Forgot Password</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/emailjs-com@3/dist/email.min.js"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <div class="w-full max-w-md bg-white/90 p-8 rounded-xl shadow-lg mx-4">
    <h2 class="text-2xl font-bold mb-6 text-center text-blue-800">Forgot Password</h2>
    <?php if ($error) echo "<p class='text-red-600 mb-4'>$error</p>"; ?>
    <form method="POST" class="space-y-5" id="otpForm">
      <label class="block mb-1 font-semibold text-gray-700">Email</label>
      <input type="email" name="email" required class="w-full px-4 py-2 border border-gray-300 rounded-lg" />
      <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg">Send OTP</button>
    </form>
    <a href="login.php" class="text-blue-700 hover:underline text-sm block mt-4">Back to Login</a>
  </div>
  <?php
  // Only send OTP if redirected from POST and session is set
  if (isset($_SESSION['reset_otp']) && isset($_SESSION['reset_email'])): ?>
  <script>
    emailjs.init('sEOkaLKSNn_N29VQb'); // <-- Replace with your EmailJS public key
    emailjs.send('service_lcnrnrg', 'template_cv2r0wr', {
      to_email: "<?= $_SESSION['reset_email'] ?>",
      otp: "<?= $_SESSION['reset_otp'] ?>"
    }).then(function(response) {
      // Optionally show a message
    }, function(error) {
      alert("Failed to send OTP email.");
    });
  </script>
  <?php
    // Unset OTP so it doesn't resend on refresh
    unset($_SESSION['reset_otp']);
    unset($_SESSION['reset_email']);
  endif;
  ?>
</body>
</html>