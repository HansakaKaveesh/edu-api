<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT student_id, first_name, last_name FROM students WHERE user_id = $user_id LIMIT 1");
$student = $result->fetch_assoc();
$student_id = $student['student_id'];
$full_name = $student['first_name'] . ' ' . $student['last_name'];
$role = ucfirst($_SESSION['role']); // Capitalize first letter
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Student Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @keyframes wave {
      0%, 60%, 100% { transform: rotate(0deg); }
      30% { transform: rotate(15deg); }
      50% { transform: rotate(-10deg); }
    }
    .animate-wave {
      display: inline-block;
      animation: wave 2s infinite;
      transform-origin: 70% 70%;
    }
    .line-clamp-3 {
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-white min-h-screen font-sans text-gray-800">
<?php include 'components/navbar.php'; ?>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-8 py-28 gap-8">
  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full max-w-3x2 space-y-10">

    <!-- Hero Section -->
    <section class="relative bg-[url('https://www.vedamo.com/wp-content/uploads/cache/2017/06/what-is-virtual-learning-1/4148946552.png')] bg-cover bg-center text-white rounded-3xl shadow-2xl p-6 sm:p-12 overflow-hidden">
      <div class="absolute inset-0 backdrop-blur-sm bg-black/40 rounded-3xl"></div>
      <div class="relative z-10 text-center space-y-6 sm:space-y-8">
        <div id="datetime" class="text-sm text-right italic opacity-90 drop-shadow-sm"></div>
        <h1 class="text-2xl sm:text-3xl font-extrabold leading-tight drop-shadow-xl">
          🎓 Welcome Back, <span class="underline decoration-white/30"><?php echo htmlspecialchars($full_name); ?></span>
          <span class="text-sm sm:text-xl font-light italic">(<?php echo $role; ?>)</span>!
          <span class="inline-block animate-wave">👋</span>
        </h1>
        <p class="text-lg sm:text-xl font-light text-white/90 max-w-3xl mx-auto">
          Continue your learning journey, explore new subjects, and grow your skills.<br />
          <span class="italic">Access your courses, take quizzes, and join discussions.</span>
        </p>
      </div>
    </section>

    <!-- Enrolled Courses as Cards -->
    <section class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100">
      <h3 class="text-xl sm:text-2xl font-semibold mb-6 text-gray-700">📚 Your Enrolled Courses</h3>
      <?php
      $query = $conn->query("
          SELECT c.course_id, c.name, c.description
          FROM enrollments e
          JOIN courses c ON e.course_id = c.course_id
          WHERE e.user_id = $user_id AND e.status = 'active'
      ");
      if ($query->num_rows > 0): ?>
        <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
          <?php while ($course = $query->fetch_assoc()): ?>
            <a href="course.php?course_id=<?= $course['course_id'] ?>"
               class="block bg-gradient-to-br from-blue-50 via-white to-blue-100 border border-blue-200 rounded-2xl shadow-lg p-6 hover:scale-105 hover:shadow-2xl transition-all duration-200 group">
              <div class="flex items-center gap-3 mb-2">
                <span class="text-2xl">📖</span>
                <span class="text-lg font-bold text-blue-700 group-hover:underline"><?= htmlspecialchars($course['name']) ?></span>
              </div>
              <p class="text-gray-600 text-sm line-clamp-3"><?= htmlspecialchars($course['description'] ?? 'No description.') ?></p>
            </a>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="text-gray-600 text-lg">
          No courses enrolled yet. <a href="enroll_course.php" class="text-blue-600 hover:underline">Enroll Now</a>
        </div>
      <?php endif; ?>
    </section>

    <!-- Activity Logs Section -->
    <section class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100">
      <h3 class="text-xl sm:text-2xl font-semibold mb-6 text-gray-700">📝 Your Recent Activity</h3>
      <?php
      $logs = $conn->query("
          SELECT a.action, a.timestamp, c.title 
          FROM activity_logs a
          JOIN contents c ON a.content_id = c.content_id
          WHERE a.user_id = $user_id
          ORDER BY a.timestamp DESC
          LIMIT 10
      ");
      if ($logs->num_rows > 0): ?>
        <ul class="space-y-4">
          <?php while ($log = $logs->fetch_assoc()): ?>
            <li class="border-l-4 border-blue-400 pl-4 text-gray-700">
              <div class="text-sm">
                <strong><?= htmlspecialchars($log['action']) ?></strong> 
                on <span class="text-blue-700 font-medium"><?= htmlspecialchars($log['title']) ?></span>
              </div>
              <div class="text-xs text-gray-500 italic"><?= date('F j, Y, g:i A', strtotime($log['timestamp'])) ?></div>
            </li>
          <?php endwhile; ?>
        </ul>
      <?php else: ?>
        <div class="text-gray-600 text-lg">
          No activity logged yet.
        </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<!-- Live Date & Time Script -->
<script>
function updateDateTime() {
  const now = new Date();
  const options = {
    weekday: 'long', year: 'numeric', month: 'long',
    day: 'numeric', hour: '2-digit', minute: '2-digit',
    second: '2-digit', hour12: true
  };
  document.getElementById('datetime').textContent = `📅 ${now.toLocaleString('en-US', options)}`;
}
setInterval(updateDateTime, 1000);
updateDateTime();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>
