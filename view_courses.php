<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'admin') die("Access Denied");

// Handle delete action
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    $course_id = intval($_GET['id']);
    // You may want to delete related records in other tables as well (e.g., teacher_courses, contents, etc.)
    $conn->query("DELETE FROM courses WHERE course_id = $course_id");
    header("Location: all_courses.php");
    exit;
}

$query = $conn->query("
    SELECT c.course_id, c.name AS course_name, c.description, ct.board, ct.level,
        CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
    FROM courses c
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    LEFT JOIN teacher_courses tc ON c.course_id = tc.course_id
    LEFT JOIN teachers t ON tc.teacher_id = t.teacher_id
    ORDER BY c.course_id DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>All Courses</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-6 flex justify-center items-start">
<?php include 'components/navbar.php'; ?>
  <div class="w-full max-w-7xl bg-white rounded-lg shadow p-6 mt-12">
    <h2 class="text-3xl font-semibold mb-6 flex items-center gap-2">
      <span>📘</span> All Courses
    </h2>

    <a href="admin_dashboard.php" 
       class="inline-block mb-6 px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
      ⬅ Back to Dashboard
    </a>

    <div class="overflow-x-auto">
      <table class="min-w-full border-collapse text-left">
        <thead>
          <tr class="bg-gray-200 text-gray-700">
            <th class="border border-gray-300 px-4 py-2">ID</th>
            <th class="border border-gray-300 px-4 py-2">Name</th>
            <th class="border border-gray-300 px-4 py-2">Board</th>
            <th class="border border-gray-300 px-4 py-2">Level</th>
            <th class="border border-gray-300 px-4 py-2">Description</th>
            <th class="border border-gray-300 px-4 py-2">Teacher</th>
            <th class="border border-gray-300 px-4 py-2">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $query->fetch_assoc()): ?>
          <tr class="hover:bg-gray-50 transition-colors duration-150">
            <td class="border border-gray-300 px-4 py-2"><?= $row['course_id'] ?></td>
            <td class="border border-gray-300 px-4 py-2 font-medium"><?= htmlspecialchars($row['course_name']) ?></td>
            <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($row['board']) ?></td>
            <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($row['level']) ?></td>
            <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($row['description']) ?></td>
            <td class="border border-gray-300 px-4 py-2">
              <?= htmlspecialchars($row['teacher_name'] ?? '❌ Not Assigned') ?>
            </td>
            <td class="border border-gray-300 px-4 py-2 space-x-2">
              <a href="edit_course.php?id=<?= $row['course_id'] ?>"
                 class="inline-block px-4 py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600 transition">
                ✏️ Edit
              </a>
              <a href="?action=delete&id=<?= $row['course_id'] ?>"
                 onclick="return confirm('Are you sure you want to delete this course?');"
                 class="inline-block px-4 py-1 bg-red-600 text-white rounded hover:bg-red-700 transition">
                🗑️ Delete
              </a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

</body>
</html>