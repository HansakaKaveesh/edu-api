<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    die("Access denied");
}

$course_id = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (!$course_id) {
    http_response_code(400);
    die("Invalid course_id");
}

$message = '';
$messageType = 'success'; // 'success' or 'error'

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function uploadErrorMessage($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE: return 'The uploaded file exceeds upload_max_filesize in php.ini.';
        case UPLOAD_ERR_FORM_SIZE: return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.';
        case UPLOAD_ERR_PARTIAL: return 'The uploaded file was only partially uploaded.';
        case UPLOAD_ERR_NO_FILE: return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Missing a temporary folder on server.';
        case UPLOAD_ERR_CANT_WRITE: return 'Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION: return 'A PHP extension stopped the file upload.';
        case UPLOAD_ERR_OK: return 'No error.';
        default: return 'Unknown file upload error.';
    }
}

// Persist posted values so the form can re-render with them after an error
$postedType  = $_POST['type'] ?? 'lesson';
$postedTitle = $_POST['title'] ?? '';
$postedBody  = $_POST['body'] ?? '';
$postedUrl   = $_POST['file_url'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES)) {
        $message = "❌ The request is larger than allowed. Please upload a smaller file or increase upload_max_filesize/post_max_size.";
        $messageType = 'error';
    } else {
        $title = trim($postedTitle);
        $body  = trim($postedBody);
        $type  = $postedType;
        $url   = trim($postedUrl);

        $errors = [];
        $allowedTypes = ['lesson', 'pdf', 'video', 'quiz', 'forum'];
        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'Invalid content type.';
        }

        // File upload handling (only if a file was actually sent)
        if (isset($_FILES['upload_file']) && is_array($_FILES['upload_file']) && $_FILES['upload_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $fileErr = $_FILES['upload_file']['error'];
            if ($fileErr === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['upload_file']['tmp_name'];
                $origName    = $_FILES['upload_file']['name'];
                $ext         = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowedExtensions = ['pdf', 'mp4', 'html'];

                if (!in_array($ext, $allowedExtensions, true)) {
                    $errors[] = "Invalid file type. Only PDF, MP4, and HTML allowed.";
                } else {
                    $uploadFolder = __DIR__ . '/uploads/';
                    if (!is_dir($uploadFolder)) {
                        mkdir($uploadFolder, 0755, true);
                    }

                    $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
                    $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '_' . substr($base, 0, 50) . '.' . $ext;
                    $destFsPath = $uploadFolder . $uniqueName;
                    $destUrl    = 'uploads/' . $uniqueName;

                    if (move_uploaded_file($fileTmpPath, $destFsPath)) {
                        $url = $destUrl;
                        $postedUrl = $url; // reflect back in form
                    } else {
                        $errors[] = "Error uploading the file.";
                    }
                }
            } else {
                $errors[] = 'File upload error: ' . uploadErrorMessage($fileErr);
            }
        }

        if (!$errors) {
            // Determine next position
            $position = 1;
            if ($posStmt = $conn->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM contents WHERE course_id = ?")) {
                $posStmt->bind_param("i", $course_id);
                if ($posStmt->execute()) {
                    $posStmt->bind_result($position);
                    $posStmt->fetch();
                }
                $posStmt->close();
            }

            if ($stmt = $conn->prepare("INSERT INTO contents (course_id, type, title, body, file_url, position) VALUES (?, ?, ?, ?, ?, ?)")) {
                $stmt->bind_param("issssi", $course_id, $type, $title, $body, $url, $position);
                if ($stmt->execute()) {
                    $message = "✅ Content added successfully.";
                    $messageType = 'success';
                    // Clear form values after success
                    $postedType = 'lesson';
                    $postedTitle = '';
                    $postedBody = '';
                    $postedUrl = '';
                } else {
                    $message = "❌ Database error: " . $stmt->error;
                    $messageType = 'error';
                }
                $stmt->close();
            } else {
                $message = "❌ Failed to prepare database statement.";
                $messageType = 'error';
            }
        } else {
            $message = "❌ " . implode(' ', $errors);
            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Add Course Content</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen flex items-center justify-center px-4 py-8">
  <?php include 'components/navbar.php'; ?>

  <div class="w-full max-w-2xl bg-white p-8 rounded-lg shadow mt-20">
    <h2 class="text-3xl font-bold mb-6 text-center">📘 Add Content to Course ID: <?= e($course_id) ?></h2>

    <?php if ($message): ?>
      <div class="mb-4 border px-4 py-2 rounded <?= $messageType === 'success' ? 'text-green-700 bg-green-100 border-green-200' : 'text-red-700 bg-red-100 border-red-200' ?>">
        <?= e($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="?course_id=<?= e($course_id) ?>" enctype="multipart/form-data" class="space-y-6">
      <div>
        <label class="block font-medium mb-1">Type:</label>
        <select name="type" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" required>
          <option value="lesson" <?= $postedType === 'lesson' ? 'selected' : '' ?>>Lesson</option>
          <option value="pdf" <?= $postedType === 'pdf' ? 'selected' : '' ?>>PDF</option>
          <option value="video" <?= $postedType === 'video' ? 'selected' : '' ?>>Video</option>
          <option value="quiz" <?= $postedType === 'quiz' ? 'selected' : '' ?>>Quiz</option>
          <option value="forum" <?= $postedType === 'forum' ? 'selected' : '' ?>>Forum</option>
        </select>
      </div>

      <div>
        <label class="block font-medium mb-1">Title:</label>
        <input type="text" name="title" value="<?= e($postedTitle) ?>" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
      </div>

      <div>
        <label class="block font-medium mb-1">Body (description or text):</label>
        <textarea name="body" rows="5" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400"><?= e($postedBody) ?></textarea>
      </div>

      <div>
        <label class="block font-medium mb-1">Manual URL (optional):</label>
        <input type="text" name="file_url" value="<?= e($postedUrl) ?>" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
      </div>

      <div>
        <label class="block font-medium mb-1">Upload File (PDF, Video, or HTML):</label>
        <input type="hidden" name="MAX_FILE_SIZE" value="220200960" />
        <input type="file" name="upload_file" accept=".pdf,.mp4,.html,text/html,application/pdf,video/mp4" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
        <p class="text-sm text-gray-500 mt-1">Allowed types: .pdf, .mp4, .html</p>
      </div>

      <div class="text-center">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 shadow">
          📤 Upload Content
        </button>
      </div>
    </form>
  </div>
</body>
</html>