<?php
include 'db_connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$content_id = $_GET['content_id'] ?? null;

if (!$content_id) {
    die("Invalid content ID");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $position = $_POST['position'];
    $body = $_POST['body'];
    $file_url = $_POST['file_url'];

    $stmt = $conn->prepare("UPDATE contents SET title = ?, position = ?, body = ?, file_url = ? WHERE content_id = ?");
    $stmt->bind_param("sissi", $title, $position, $body, $file_url, $content_id);
    $stmt->execute();
    $stmt->close();

    header("Location: teacher_dashboard.php");
    exit;
}

// Fetch current data
$stmt = $conn->prepare("SELECT * FROM contents WHERE content_id = ?");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$result = $stmt->get_result();
$content = $result->fetch_assoc();
$stmt->close();

if (!$content) {
    die("Content not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Content</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-white min-h-screen flex items-center justify-center">
  <div class="w-full max-w-xl mx-auto bg-white/90 dark:bg-gray-900/90 p-8 rounded-2xl shadow-2xl border border-gray-100 dark:border-gray-800">
    <h2 class="text-3xl font-bold mb-6 text-blue-700 dark:text-blue-300 flex items-center gap-2">
      ✏️ Edit Content
    </h2>
    <form method="POST" autocomplete="off">
      <label class="block mb-2 font-semibold text-gray-700 dark:text-gray-200" for="title">Title</label>
      <input
        type="text"
        id="title"
        name="title"
        value="<?= htmlspecialchars($content['title'] ?? '') ?>"
        required
        class="w-full px-4 py-2 border rounded mb-4 focus:outline-none focus:ring-2 focus:ring-blue-400"
      >

      <label class="block mb-2 font-semibold text-gray-700 dark:text-gray-200" for="position">Position</label>
      <input
        type="number"
        id="position"
        name="position"
        value="<?= $content['position'] ?? '' ?>"
        required
        class="w-full px-4 py-2 border rounded mb-4 focus:outline-none focus:ring-2 focus:ring-blue-400"
      >

      <label class="block mb-2 font-semibold text-gray-700 dark:text-gray-200" for="body">Body</label>
      <textarea
        id="body"
        name="body"
        rows="5"
        class="w-full px-4 py-2 border rounded mb-4 focus:outline-none focus:ring-2 focus:ring-blue-400"
      ><?= htmlspecialchars($content['body'] ?? '') ?></textarea>

      <label class="block mb-2 font-semibold text-gray-700 dark:text-gray-200" for="file_url">File URL (if any)</label>
      <input
        type="text"
        id="file_url"
        name="file_url"
        value="<?= htmlspecialchars($content['file_url'] ?? '') ?>"
        class="w-full px-4 py-2 border rounded mb-6 focus:outline-none focus:ring-2 focus:ring-blue-400"
      >

      <div class="flex items-center gap-4">
        <button
          type="submit"
          class="bg-blue-600 text-white px-6 py-2 rounded-full font-semibold shadow hover:bg-blue-700 transition"
        >
          💾 Save Changes
        </button>
        <a
          href="teacher_dashboard.php"
          class="text-blue-600 hover:underline font-medium"
        >
          Cancel
        </a>
      </div>
    </form>
  </div>
</body>
</html>