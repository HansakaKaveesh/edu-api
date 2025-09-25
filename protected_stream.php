<?php
// protected_stream.php
session_start();
require 'db_connect.php';

header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$user_id  = (int)$_SESSION['user_id'];
$role     = $_SESSION['role'] ?? '';
$content_id = isset($_GET['content_id']) ? (int)$_GET['content_id'] : 0;
if (!$content_id) { http_response_code(400); exit('Missing content_id'); }

// Fetch content and course
$stmt = $conn->prepare("
  SELECT c.content_id, c.course_id, c.file_url, c.type
  FROM contents c
  WHERE c.content_id = ?
  LIMIT 1
");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$content = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$content) { http_response_code(404); exit('Not found'); }

$course_id = (int)$content['course_id'];
$type = strtolower($content['type'] ?? '');
$file_url = $content['file_url'] ?? '';
if (!$file_url) { http_response_code(404); exit('File missing'); }

// Authorize
$authorized = false;
if ($role === 'teacher') {
  $trow = $conn->query("SELECT teacher_id FROM teachers WHERE user_id = $user_id LIMIT 1")->fetch_assoc();
  $teacher_id = (int)($trow['teacher_id'] ?? 0);
  if ($teacher_id) {
    $chk = $conn->query("SELECT 1 FROM teacher_courses WHERE teacher_id = $teacher_id AND course_id = $course_id LIMIT 1");
    $authorized = $chk && $chk->num_rows > 0;
  }
} elseif ($role === 'student') {
  $chk = $conn->query("SELECT 1 FROM enrollments WHERE user_id = $user_id AND course_id = $course_id AND status = 'active' LIMIT 1");
  $authorized = $chk && $chk->num_rows > 0;
}
if (!$authorized) { http_response_code(403); exit('Unauthorized'); }

// Map URL to absolute path if local
function is_remote($u) { return (bool)preg_match('#^https?://#i', $u); }
$abs = '';
if (!is_remote($file_url)) {
  $path = ltrim($file_url, '/\\');
  $abs_candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . $path);
  $root = realpath(__DIR__);
  if ($abs_candidate && strpos($abs_candidate, $root) === 0 && is_file($abs_candidate)) {
    $abs = $abs_candidate;
  }
}

// Determine mime
function mime_from_ext($p) {
  $ext = strtolower(pathinfo(parse_url($p, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
  return match ($ext) {
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'ogg'  => 'video/ogg',
    'pdf'  => 'application/pdf',
    'html','htm' => 'text/html; charset=UTF-8',
    default => 'application/octet-stream',
  };
}

// If remote (http[s])
if (is_remote($file_url)) {
  if (ini_get('allow_url_fopen')) {
    $mime = mime_from_ext($file_url);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename(parse_url($file_url, PHP_URL_PATH)) . '"');
    header('Cache-Control: no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "User-Agent: PHP\r\n"]]);
    $fp = fopen($file_url, 'rb', false, $ctx);
    if (!$fp) { http_response_code(502); exit('Upstream error'); }
    while (!feof($fp)) {
      echo fread($fp, 8192);
      flush();
    }
    fclose($fp);
    exit;
  } else {
    header('Location: ' . $file_url);
    exit;
  }
}

// If local but not found
if (!$abs) { http_response_code(404); exit('File missing'); }

$size = filesize($abs);
$mime = mime_from_ext($abs);
$basename = basename($abs);

// Range support (for video)
$range = null;
if (isset($_SERVER['HTTP_RANGE'])) {
  if (preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m)) {
    $start = ($m[1] !== '') ? (int)$m[1] : 0;
    $end   = ($m[2] !== '') ? (int)$m[2] : ($size - 1);
    if ($start <= $end && $end < $size) { $range = [$start, $end]; }
  }
}

$fp = fopen($abs, 'rb');
if (!$fp) { http_response_code(500); exit('Cannot open'); }

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $basename . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($range) {
  [$start, $end] = $range;
  $length = $end - $start + 1;
  header('HTTP/1.1 206 Partial Content');
  header("Content-Range: bytes $start-$end/$size");
  header("Content-Length: $length");
  fseek($fp, $start);
  $bytes_left = $length;
  while ($bytes_left > 0 && !feof($fp)) {
    $read = ($bytes_left > 8192) ? 8192 : $bytes_left;
    echo fread($fp, $read);
    $bytes_left -= $read;
    flush();
  }
} else {
  header("Content-Length: $size");
  while (!feof($fp)) {
    echo fread($fp, 8192);
    flush();
  }
}

fclose($fp);
exit;