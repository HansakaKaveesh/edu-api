<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // For demo: check code from localStorage (in real apps, check from DB)
    echo "<script>
      var storedEmail = localStorage.getItem('reset_email');
      var storedCode = localStorage.getItem('reset_code');
      if ('{$_POST['email']}' === storedEmail && '{$_POST['code']}' === storedCode) {
        alert('Password reset successful! Now you can login.');
        // Here you would send an AJAX request to update the password in your DB
        window.location.href = 'login.php';
      } else {
        alert('Invalid code or email.');
      }
    </script>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Reset Password</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <form method="POST" class="bg-white p-8 rounded shadow max-w-md w-full space-y-4">
    <h2 class="text-2xl font-bold mb-4 text-blue-700">Reset Password</h2>
    <input type="email" name="email" placeholder="Your email" required class="w-full border px-3 py-2 rounded" />
    <input type="text" name="code" placeholder="Reset code" required class="w-full border px-3 py-2 rounded" />
    <input type="password" name="new_password" placeholder="New password" required class="w-full border px-3 py-2 rounded" />
    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Reset Password</button>
  </form>
</body>
</html>