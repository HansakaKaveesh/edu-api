<?php
// upload_image.php
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image or upload error']);
    exit;
}

$file = $_FILES['image'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','gif','webp'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowedMimes = ['image/jpeg','image/png','image/gif','image/webp'];

if (!in_array($ext, $allowed, true) || !in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image type']);
    exit;
}

$dir = __DIR__ . '/uploads/images/';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }

$base = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$name = time() . '_' . bin2hex(random_bytes(4)) . '_' . substr($base, 0, 50) . '.' . $ext;
$dest = $dir . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to move uploaded file']);
    exit;
}

// Return a URL TinyMCE can insert into <img src="">
echo json_encode(['location' => 'uploads/images/' . $name]);