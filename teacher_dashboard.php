<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT teacher_id FROM teachers WHERE user_id = $user_id LIMIT 1");
$teacher_row = $result->fetch_assoc();
$teacher_id = $teacher_row['teacher_id'];

// Handle delete course action
if (isset($_GET['action'], $_GET['course_id']) && $_GET['action'] === 'delete') {
    $course_id = intval($_GET['course_id']);
    // Remove the course from this teacher's list
    $conn->query("DELETE FROM teacher_courses WHERE teacher_id = $teacher_id AND course_id = $course_id");
    // Optionally, delete the course entirely if no other teachers are assigned
    $check = $conn->query("SELECT * FROM teacher_courses WHERE course_id = $course_id");
    if ($check->num_rows == 0) {
        $conn->query("DELETE FROM courses WHERE course_id = $course_id");
        // Optionally, delete related contents, assignments, etc.
    }
    header("Location: teacher_dashboard.php");
    exit;
}

$courses = $conn->query("
    SELECT c.course_id, c.name 
    FROM teacher_courses tc 
    JOIN courses c ON tc.course_id = c.course_id 
    WHERE tc.teacher_id = $teacher_id
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Teacher Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    // Toggle collapse sections
    function toggleContent(id) {
      const el = document.getElementById(id);
      el.classList.toggle('hidden');
    }
  </script>
  <!-- Icons -->
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="bg-gray-100 text-gray-800">
<?php include 'components/navbar.php'; ?>

<!-- Hero Section -->
<section class="relative h-72 md:h-96 w-full mb-10">
  <div class="absolute inset-0 bg-[url('/your-hero-image.jpg')] bg-cover bg-center brightness-75"></div>
  <div class="absolute inset-0 bg-gradient-to-r from-blue-900/70 to-cyan-700/60"></div>
  <div class="relative z-10 flex flex-col justify-center items-start h-full px-6 md:px-20 text-white">
    <h1 class="text-3xl md:text-5xl font-bold mb-2">Welcome Back, Teacher 👩‍🏫</h1>
    <p class="text-lg md:text-xl max-w-2xl">Empower students with engaging content, quizzes, and assignments. Shape the future today.</p>
    <a href="#courses" class="mt-6 inline-block bg-white text-blue-700 px-6 py-2 rounded font-semibold hover:bg-blue-50 transition">
      Go to Courses ⬇️
    </a>
  </div>
</section>

<!-- Announcements Section -->
<section class="max-w-6xl mx-auto px-6 mb-10">
  <div class="bg-white/90 rounded-2xl shadow-lg p-6">
    <h3 class="text-xl font-bold text-blue-700 mb-4">📢 Announcements</h3>
    <?php
    $announcements = $conn->query("
        SELECT title, message, created_at
        FROM announcements
        WHERE audience = 'teachers' OR audience = 'all'
        ORDER BY created_at DESC
        LIMIT 5
    ");
    if ($announcements && $announcements->num_rows > 0): ?>
      <ul class="space-y-6">
        <?php while ($a = $announcements->fetch_assoc()): ?>
          <li class="bg-blue-50 border-l-4 border-blue-400 rounded-lg p-4 shadow-sm">
            <div class="flex items-center justify-between mb-1">
              <span class="font-semibold text-blue-700"><?= htmlspecialchars($a['title']) ?></span>
              <span class="text-xs text-gray-500"><?= date('M d, Y', strtotime($a['created_at'])) ?></span>
            </div>
            <div class="text-gray-700 text-sm"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
          </li>
        <?php endwhile; ?>
      </ul>
    <?php else: ?>
      <div class="text-gray-600 text-lg">No announcements at this time.</div>
    <?php endif; ?>
  </div>
</section>

<!-- Main Content -->
<div class="max-w-6xl mx-auto px-6 py-10">
  <div class="flex justify-between items-center mb-8">
    <h2 class="text-4xl font-bold">📚 Teacher Dashboard</h2>
    <a href="logout.php" class="bg-red-500 text-white px-5 py-2 rounded hover:bg-red-600 shadow">
      🔒 Logout
    </a>
  </div>

  <!-- Courses -->
  <div id="courses" class="mb-6">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-2xl font-semibold">📘 Your Courses</h3>
      <a href="create_course.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 shadow">
        ➕ Create New Course
      </a>
    </div>

    <?php if ($courses->num_rows > 0): ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php while ($course = $courses->fetch_assoc()): ?>
          <?php
            $course_id = $course['course_id'];
            $contents_query = $conn->query("
                SELECT content_id, title, type, position 
                FROM contents 
                WHERE course_id = $course_id 
                ORDER BY type, position
            ");
            $content_map = [];
            while ($row = $contents_query->fetch_assoc()) {
              $content_map[$row['type']][] = $row;
            }

            // Icon map
            $type_icons = [
              'lesson' => '📖',
              'video' => '🎥',
              'pdf' => '📄',
              'quiz' => '🧠',
              'forum' => '💬'
            ];
          ?>

          <div class="bg-white p-6 rounded-lg shadow hover:shadow-md transition">
            <div class="flex justify-between items-center">
              <h4 class="text-xl font-semibold mb-2"><?= htmlspecialchars($course['name']) ?></h4>
              <div class="flex gap-2">
                <button onclick="toggleContent('content-<?= $course_id ?>')" class="text-sm text-blue-600 hover:underline">
                  📂 Toggle Content
                </button>
                <a href="?action=delete&course_id=<?= $course_id ?>"
                   onclick="return confirm('Are you sure you want to delete this course? This cannot be undone.');"
                   class="text-sm text-red-600 hover:underline ml-2">
                  🗑️ Delete Course
                </a>
              </div>
            </div>
            <p class="text-gray-600 mb-4">Course ID: <strong><?= $course_id ?></strong></p>

            <!-- Actions -->
            <div class="space-y-2 mb-4">
              <a href="add_content.php?course_id=<?= $course_id ?>" class="block text-blue-600 hover:underline">➕ Add Content</a>
              <a href="upload_assignment.php?course_id=<?= $course_id ?>" class="block text-blue-600 hover:underline">📝 Add Assignment</a>
              <a href="add_quiz.php?course_id=<?= $course_id ?>" class="block text-blue-600 hover:underline">🧠 Add Quiz</a>
              <a href="add_forum.php?course_id=<?= $course_id ?>" class="block text-blue-600 hover:underline">💬 Add Forum</a>
              <a href="course.php?course_id=<?= $course_id ?>" class="block text-blue-600 hover:underline">👁️ View Content</a>
            </div>

            <!-- Content List (Collapsible) -->
            <div id="content-<?= $course_id ?>" class="hidden">
              <?php foreach ($content_map as $type => $items): ?>
                <div class="mt-4">
                  <h5 class="font-semibold text-gray-700 mb-1"><?= $type_icons[$type] ?? '' ?> <?= ucfirst($type) ?>s</h5>
                  <ul class="space-y-1 list-inside text-sm text-gray-700">
                    <?php foreach ($items as $item): ?>
                      <li class="flex justify-between items-center bg-gray-100 px-3 py-2 rounded">
                        <span>
                          <?= htmlspecialchars($item['title']) ?>
                          <span class="text-xs text-gray-400">(Pos: <?= $item['position'] ?>)</span>
                        </span>
                        <span class="flex space-x-2">
                          <a href="edit_content.php?content_id=<?= $item['content_id'] ?>" class="text-green-600 hover:underline text-sm">✏️ Edit</a>
                          <a href="delete_content.php?content_id=<?= $item['content_id'] ?>" onclick="return confirm('Delete this content?')" class="text-red-600 hover:underline text-sm">🗑️ Delete</a>
                        </span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <p class="text-gray-600">You haven't created any courses yet. <a href="create_course.php" class="text-blue-600 hover:underline">Create your first course</a>.</p>
    <?php endif; ?>
  </div>
</div>

</body>
</html>