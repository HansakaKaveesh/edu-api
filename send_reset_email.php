<?php
session_start();
if (!isset($_SESSION['reset_token']) || !isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}
$reset_link = "https://yourdomain.com/reset_password.php?token=" . $_SESSION['reset_token'];
$email = $_SESSION['reset_email'];
$username = $_SESSION['reset_user'];
?>
<!DOCTYPE html>
<html>
<head>
  <title>Send Reset Email</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.emailjs.com/dist/email.min.js"></script>
  <script>
    window.onload = function() {
      emailjs.init("Ccq9mDLsWERzsXi_I");
      emailjs.send("service_8du74v5", "template_6kd84wc", {
        to_email: "<?= $email ?>",
        username: "<?= $username ?>",
        reset_link: "<?= $reset_link ?>"
      }).then(function(response) {
        document.getElementById('msg').innerText = "Reset link sent! Please check your email.";
      }, function(error) {
        document.getElementById('msg').innerText = "Failed to send email. Please contact support.";
      });
    }
  </script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <div class="w-full max-w-md bg-white/90 p-8 rounded-xl shadow-lg mx-4 text-center">
    <h2 class="text-2xl font-bold mb-6 text-blue-800">Sending Reset Email...</h2>
    <p id="msg" class="text-blue-700">Please wait...</p>
  </div>
</body>
</html>
<?php
unset($_SESSION['reset_token'], $_SESSION['reset_email'], $_SESSION['reset_user']);
?>