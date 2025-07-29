<?php
include 'db_connect.php';
session_start();
$error = $success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $otp = $_POST['otp'];
    $new_password = $_POST['new_password'];
    $user_id = $_SESSION['reset_user_id'] ?? null;

    if (!$user_id) {
        $error = "Session expired. Please request OTP again.";
    } else {
        // Check OTP
        $stmt = $conn->prepare("SELECT reset_id, expires_at, used FROM password_resets WHERE user_id = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("is", $user_id, $otp);
        $stmt->execute();
        $stmt->bind_result($reset_id, $expires_at, $used);
        if ($stmt->fetch()) {
            $stmt->close(); // CLOSE before next query

            if ($used) {
                $error = "OTP already used.";
            } elseif (strtotime($expires_at) < time()) {
                $error = "OTP expired.";
            } else {
                // Check password reuse
                $stmt2 = $conn->prepare("SELECT password_hash FROM passwords WHERE user_id = ?");
                $stmt2->bind_param("i", $user_id);
                $stmt2->execute();
                $stmt2->bind_result($old_hash);
                $reuse = false;
                while ($stmt2->fetch()) {
                    if (password_verify($new_password, $old_hash)) {
                        $reuse = true;
                        break;
                    }
                }
                $stmt2->close();

                if ($reuse) {
                    $error = "You cannot reuse a previous password.";
                } else {
                    // Update password
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $conn->query("UPDATE passwords SET is_current = 0 WHERE user_id = $user_id");
                    $stmt3 = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)");
                    $stmt3->bind_param("is", $user_id, $new_hash);
                    $stmt3->execute();
                    $stmt3->close();
                    // Mark OTP as used
                    $conn->query("UPDATE password_resets SET used = 1 WHERE reset_id = $reset_id");
                    $success = "Password reset successful. You can now <a href='login.php' class='text-blue-700 underline'>login</a>.";
                    session_unset();
                    session_destroy();
                }
            }
        } else {
            $stmt->close();
            $error = "Invalid OTP.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Reset Password</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <div class="w-full max-w-md bg-white/90 p-8 rounded-xl shadow-lg mx-4">
    <h2 class="text-2xl font-bold mb-6 text-center text-blue-800">Reset Password</h2>
    <?php if ($success) echo "<p class='text-green-600 mb-4'>$success</p>"; ?>
    <?php if ($error) echo "<p class='text-red-600 mb-4'>$error</p>"; ?>
    <form method="POST" class="space-y-5">
      <label class="block mb-1 font-semibold text-gray-700">OTP</label>
      <input type="text" name="otp" required class="w-full px-4 py-2 border border-gray-300 rounded-lg" />
      <label class="block mb-1 font-semibold text-gray-700">New Password</label>
      <input type="password" name="new_password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg" />
      <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg">Reset Password</button>
    </form>
    <a href="login.php" class="text-blue-700 hover:underline text-sm block mt-4">Back to Login</a>
  </div>
</body>
</html>