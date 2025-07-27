<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'teacher') die("Access denied");

$course_id = $_GET['course_id'];
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $body = $_POST['body'];
    $type = $_POST['type'];
    $url = $_POST['file_url']; // fallback if no file uploaded

    // File upload handling
    if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['upload_file']['tmp_name'];
        $fileName = basename($_FILES['upload_file']['name']);
        $uploadFolder = './uploads/';
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'mp4', 'html'];

        if (!in_array($fileExtension, $allowedExtensions)) {
            $message = "❌ Invalid file type. Only PDF, MP4, and HTML allowed.";
        } else {
            // Create unique file name
            $destPath = $uploadFolder . time() . '_' . $fileName;

            // Ensure uploads folder exists
            if (!is_dir($uploadFolder)) {
                mkdir($uploadFolder, 0755, true);
            }

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $url = $destPath;
            } else {
                $message = "❌ Error uploading the file.";
            }
        }
    }

    if (!$message) {
        $stmt = $conn->prepare("INSERT INTO contents (course_id, type, title, body, file_url, position) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("issss", $course_id, $type, $title, $body, $url);
        $stmt->execute();
        $message = "✅ Content added successfully.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Course Content</title>
  <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="./images/logo.png" />
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen flex items-center justify-center px-4 py-8">

<?php include 'components/navbar.php'; ?>

  <div class="w-full max-w-2xl bg-white p-8 rounded-lg shadow mt-20">
    <h2 class="text-3xl font-bold mb-6 text-center">📘 Add Content to Course ID: <?= htmlspecialchars($course_id) ?></h2>

    <?php if ($message): ?>
      <div class="mb-4 text-green-700 bg-green-100 border border-green-200 px-4 py-2 rounded">
        <?= $message ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="space-y-6">
      <div>
        <label class="block font-medium mb-1">Type:</label>
        <select name="type" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" required>
          <option value="lesson">Lesson</option>
          <option value="pdf">PDF</option>
          <option value="video">Video</option>
          <option value="quiz">Quiz</option>
          <option value="forum">Forum</option>
        </select>
      </div>

      <div>
        <label class="block font-medium mb-1">Title:</label>
        <input type="text" name="title" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
      </div>

      <div>
        <label class="block font-medium mb-1">Body (description or text):</label>
        <textarea name="body" rows="5" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400"></textarea>
      </div>

      <div>
        <label class="block font-medium mb-1">Manual URL (optional):</label>
        <input type="text" name="file_url" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
      </div>

      <div>
        <label class="block font-medium mb-1">Upload File (PDF, Video, or HTML):</label>
        <input type="file" name="upload_file" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
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
