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
    $url = $_POST['file_url'];

    $stmt = $conn->prepare("INSERT INTO contents (course_id, type, title, body, file_url, position) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("issss", $course_id, $type, $title, $body, $url);
    $stmt->execute();

    $message = "✅ Content added successfully.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Course Content</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen flex items-center justify-center px-4 py-8">

  <div class="w-full max-w-2xl bg-white p-8 rounded-lg shadow">
    <h2 class="text-3xl font-bold mb-6 text-center">📘 Add Content to Course ID: <?= htmlspecialchars($course_id) ?></h2>

    <?php if ($message): ?>
      <div class="mb-4 text-green-700 bg-green-100 border border-green-200 px-4 py-2 rounded">
        <?= $message ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
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
        <label class="block font-medium mb-1">File/Video URL (optional):</label>
        <input type="text" name="file_url" class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
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
