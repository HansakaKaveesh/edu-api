<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'admin') die("Access Denied.");

$course_id = intval($_GET['id']);

// Fetch course details
$course = $conn->query("
    SELECT c.name, c.description, ct.board, ct.level, ct.course_type_id
    FROM courses c
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    WHERE c.course_id = $course_id
")->fetch_assoc();

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $desc = $_POST['description'];
    $board = $_POST['board'];
    $level = $_POST['level'];

    // Update course_types
    $conn->query("UPDATE course_types SET board = '$board', level = '$level' WHERE course_type_id = {$course['course_type_id']}");

    // Update course
    $stmt = $conn->prepare("UPDATE courses SET name = ?, description = ? WHERE course_id = ?");
    $stmt->bind_param("ssi", $name, $desc, $course_id);
    $stmt->execute();

    $success = true;
    // Refresh course data for form
    $course['name'] = $name;
    $course['description'] = $desc;
    $course['board'] = $board;
    $course['level'] = $level;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Edit Course</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-lg bg-white rounded-xl shadow-lg p-8 mt-10">
    <h2 class="text-2xl font-bold mb-6 flex items-center gap-2 text-blue-700">
      ✏️ Edit Course <span class="text-gray-500 text-base">(ID: <?= $course_id ?>)</span>
    </h2>

    <?php if ($success): ?>
      <div class="mb-4 p-3 rounded bg-green-100 text-green-800 font-semibold flex items-center gap-2">
        ✅ Course updated!
        <a href="view_courses.php" class="ml-auto text-blue-600 hover:underline">⬅ Back to Courses</a>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <div>
        <label class="block mb-1 font-medium text-gray-700">Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($course['name']) ?>" required
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
      </div>
      <div>
        <label class="block mb-1 font-medium text-gray-700">Description</label>
        <textarea name="description" rows="3"
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"><?= htmlspecialchars($course['description']) ?></textarea>
      </div>
      <div>
        <label class="block mb-1 font-medium text-gray-700">Board</label>
        <select name="board" class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
          <option <?= $course['board'] === 'Cambridge' ? 'selected' : '' ?>>Cambridge</option>
          <option <?= $course['board'] === 'Edexcel' ? 'selected' : '' ?>>Edexcel</option>
          <option <?= $course['board'] === 'Local' ? 'selected' : '' ?>>Local</option>
          <option <?= $course['board'] === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
      </div>
      <div>
        <label class="block mb-1 font-medium text-gray-700">Level</label>
        <select name="level" class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
          <option <?= $course['level'] === 'O/L' ? 'selected' : '' ?>>O/L</option>
          <option <?= $course['level'] === 'A/L' ? 'selected' : '' ?>>A/L</option>
          <option <?= $course['level'] === 'IGCSE' ? 'selected' : '' ?>>IGCSE</option>
          <option <?= $course['level'] === 'Others' ? 'selected' : '' ?>>Others</option>
        </select>
      </div>
      <div class="flex justify-between items-center mt-6">
        <button type="submit"
          class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded shadow transition">
          💾 Save Changes
        </button>
        <a href="view_courses.php" class="text-blue-600 hover:underline">Cancel</a>
      </div>
    </form>
  </div>
</body>
</html>