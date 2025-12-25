<?php
declare(strict_types=1);

require_once 'db_connect.php';

$usingHttps = (
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
  (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);

session_start();

if (!empty($_COOKIE['remember_me'])) {
  $raw = $_COOKIE['remember_me'];
  if (strpos($raw, ':') !== false) {
    [$selector] = explode(':', $raw, 2);

    $stmt = $conn->prepare("DELETE FROM user_remember_tokens WHERE selector = ?");
    if ($stmt) {
      $stmt->bind_param('s', $selector);
      $stmt->execute();
      $stmt->close();
    }
  }

  setcookie('remember_me', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $usingHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

// Clear session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"], $params["domain"],
    $params["secure"], $params["httponly"]
  );
}
session_destroy();

header('Location: login.php');
exit;