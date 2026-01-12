<?php
include 'db_connect.php';
session_start();

$error = "";
$success = "";

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CSRF check
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $otp = trim($_POST['otp'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');
        $user_id = $_SESSION['reset_user_id'] ?? null;

        if (!$user_id) {
            $error = "Session expired. Please request OTP again.";
        } elseif ($otp === '' || !preg_match('/^\d{4,8}$/', $otp)) {
            $error = "Enter a valid OTP.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif (
            !preg_match('/[A-Z]/', $new_password) ||
            !preg_match('/[a-z]/', $new_password) ||
            !preg_match('/[0-9]/', $new_password)
        ) {
            $error = "Password must include uppercase, lowercase, and a number.";
        } else {

            // 1) Validate OTP (unused + not expired)
            $stmt = $conn->prepare("
                SELECT reset_id
                FROM password_resets
                WHERE user_id = ?
                  AND otp_code = ?
                  AND used = 0
                  AND expires_at > NOW()
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->bind_param("is", $user_id, $otp);
            $stmt->execute();
            $stmt->bind_result($reset_id);
            $otp_ok = $stmt->fetch();
            $stmt->close();

            if (!$otp_ok) {
                $error = "Invalid or expired OTP.";
            } else {
                // 2) Check password reuse (history)
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
                    // 3) Update password + mark OTP used (transaction)
                    $conn->begin_transaction();

                    try {
                        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

                        // Set old current passwords to not current
                        $stmt3 = $conn->prepare("UPDATE passwords SET is_current = 0 WHERE user_id = ?");
                        $stmt3->bind_param("i", $user_id);
                        $stmt3->execute();
                        $stmt3->close();

                        // Insert new password as current
                        $stmt4 = $conn->prepare("
                            INSERT INTO passwords (user_id, password_hash, is_current)
                            VALUES (?, ?, 1)
                        ");
                        $stmt4->bind_param("is", $user_id, $new_hash);
                        $stmt4->execute();
                        $stmt4->close();

                        // Mark OTP used (also ensures it can’t be reused even in races)
                        $stmt5 = $conn->prepare("
                            UPDATE password_resets
                            SET used = 1
                            WHERE reset_id = ? AND used = 0
                        ");
                        $stmt5->bind_param("i", $reset_id);
                        $stmt5->execute();

                        if ($stmt5->affected_rows !== 1) {
                            throw new Exception("OTP already used.");
                        }
                        $stmt5->close();

                        $conn->commit();

                        // clear only reset-related session data
                        unset($_SESSION['reset_user_id']);
                        unset($_SESSION['csrf_token']);

                        $success = "Password reset successful. You can now login.";
                    } catch (Throwable $ex) {
                        $conn->rollback();
                        $error = "Could not reset password. Please try again.";
                    }
                }
            }
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

    <?php if ($success): ?>
      <p class="text-green-600 mb-4">
        <?= e($success) ?> <a href="login.php" class="text-blue-700 underline">Login</a>
      </p>
    <?php endif; ?>

    <?php if ($error): ?>
      <p class="text-red-600 mb-4"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

      <div>
        <label class="block mb-1 font-semibold text-gray-700">OTP</label>
        <input type="text" name="otp" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg" />
      </div>

      <div>
        <label class="block mb-1 font-semibold text-gray-700">New Password</label>
        <input type="password" name="new_password" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg" />
      </div>

      <div>
        <label class="block mb-1 font-semibold text-gray-700">Confirm Password</label>
        <input type="password" name="confirm_password" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg" />
      </div>

      <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg">
        Reset Password
      </button>
    </form>

    <a href="login.php" class="text-blue-700 hover:underline text-sm block mt-4">Back to Login</a>
  </div>
</body>
</html>