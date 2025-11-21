<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied.");
}

$course_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($course_id <= 0) {
    http_response_code(400);
    die("Invalid course ID.");
}

$success = false;
$error = '';

// Fetch course details (with price)
$stmt = $conn->prepare("
    SELECT c.name, c.description, c.price, ct.board, ct.level, ct.course_type_id
    FROM courses c
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    WHERE c.course_id = ?
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$res = $stmt->get_result();
$course = $res->fetch_assoc();
$stmt->close();

if (!$course) {
    http_response_code(404);
    die("Course not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $board = trim($_POST['board'] ?? '');
    $level = trim($_POST['level'] ?? '');
    $priceRaw = trim($_POST['price'] ?? '');

    // Normalize price (support comma or space separators)
    $normalized = str_replace([' ', ','], ['', ''], $priceRaw);
    if ($priceRaw !== '' && !is_numeric($normalized)) {
        $error = 'Invalid price format.';
    } else {
        $price = ($priceRaw === '') ? 0.00 : (float)$normalized;
        if ($price < 0) {
            $error = 'Price cannot be negative.';
        }
    }

    if (!$error) {
        // Update course_types (board, level)
        $stmtCt = $conn->prepare("UPDATE course_types SET board = ?, level = ? WHERE course_type_id = ?");
        $stmtCt->bind_param("ssi", $board, $level, $course['course_type_id']);
        $stmtCt->execute();
        $stmtCt->close();

        // Update courses (name, description, price)
        $stmtC = $conn->prepare("UPDATE courses SET name = ?, description = ?, price = ? WHERE course_id = ?");
        $stmtC->bind_param("ssdi", $name, $desc, $price, $course_id);
        $stmtC->execute();
        $stmtC->close();

        $success = true;

        // Refresh course data for form
        $course['name'] = $name;
        $course['description'] = $desc;
        $course['board'] = $board;
        $course['level'] = $level;
        $course['price'] = number_format((float)$price, 2, '.', '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Edit Course</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-lg bg-white rounded-xl shadow-lg p-8 mt-10">
    <h2 class="text-2xl font-bold mb-6 flex items-center gap-2 text-blue-700">
      <i data-lucide="pencil" class="w-6 h-6"></i>
      Edit Course <span class="text-gray-500 text-base">(ID: <?= (int)$course_id ?>)</span>
    </h2>

    <?php if ($success): ?>
      <div class="mb-4 p-3 rounded bg-green-100 text-green-800 font-semibold flex items-center gap-2">
        <i data-lucide="check-circle-2" class="w-5 h-5"></i> Course updated!
        <a href="view_courses.php" class="ml-auto inline-flex items-center gap-1 text-blue-600 hover:underline">
          <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Courses
        </a>
      </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="mb-4 p-3 rounded bg-red-100 text-red-800 font-semibold flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-5 h-5"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5" autocomplete="off">
      <div>
        <label class="block mb-1 font-medium text-gray-700">Name</label>
        <div class="relative">
          <input type="text" name="name" value="<?= htmlspecialchars($course['name']) ?>" required
            class="w-full border border-gray-300 rounded px-3 py-2 pr-10 focus:outline-none focus:ring-2 focus:ring-blue-400" />
          <i data-lucide="book-open" class="w-4 h-4 text-slate-400 absolute right-3 top-2.5 pointer-events-none"></i>
        </div>
      </div>

      <div>
        <label class="block mb-1 font-medium text-gray-700">Description</label>
        <textarea name="description" rows="3"
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"><?= htmlspecialchars($course['description']) ?></textarea>
      </div>

      <div>
        <label class="block mb-1 font-medium text-gray-700">Board</label>
        <div class="relative">
          <select name="board" class="w-full border border-gray-300 rounded px-3 py-2 pr-10 focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option <?= ($course['board'] === 'Cambridge') ? 'selected' : '' ?>>Cambridge</option>
            <option <?= ($course['board'] === 'Edexcel') ? 'selected' : '' ?>>Edexcel</option>
            <option <?= ($course['board'] === 'Local') ? 'selected' : '' ?>>Local</option>
            <option <?= ($course['board'] === 'Other') ? 'selected' : '' ?>>Other</option>
          </select>
          <i data-lucide="library" class="w-4 h-4 text-slate-400 absolute right-3 top-2.5 pointer-events-none"></i>
        </div>
      </div>

      <div>
        <label class="block mb-1 font-medium text-gray-700">Level</label>
        <div class="relative">
          <select name="level" class="w-full border border-gray-300 rounded px-3 py-2 pr-10 focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option <?= ($course['level'] === 'O/L') ? 'selected' : '' ?>>O/L</option>
            <option <?= ($course['level'] === 'A/L') ? 'selected' : '' ?>>A/L</option>
            <option <?= ($course['level'] === 'IGCSE') ? 'selected' : '' ?>>IGCSE</option>
            <option <?= ($course['level'] === 'Others') ? 'selected' : '' ?>>Others</option>
          </select>
          <i data-lucide="layers" class="w-4 h-4 text-slate-400 absolute right-3 top-2.5 pointer-events-none"></i>
        </div>
      </div>

      <div>
        <label class="block mb-1 font-medium text-gray-700">Price</label>
        <div class="relative">
          <input
            type="number"
            name="price"
            step="0.01"
            min="0"
            value="<?= htmlspecialchars(number_format((float)($course['price'] ?? 0), 2, '.', '')) ?>"
            required
            class="w-full border border-gray-300 rounded pl-10 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
          <i data-lucide="currency" class="w-4 h-4 text-slate-400 absolute left-3 top-2.5 pointer-events-none"></i>
        </div>
        <small class="text-gray-500">Enter price in your base currency (e.g., 0.00 if free).</small>
      </div>

      <div class="flex justify-between items-center mt-6">
        <button type="submit"
          class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded shadow transition">
          <i data-lucide="save" class="w-4 h-4"></i> Save Changes
        </button>
        <a href="view_courses.php" class="inline-flex items-center gap-1 text-blue-600 hover:underline">
          <i data-lucide="x-circle" class="w-4 h-4"></i> Cancel
        </a>
      </div>
    </form>
  </div>

  <script>
    if (window.lucide) { lucide.createIcons(); }
  </script>
</body>
</html>