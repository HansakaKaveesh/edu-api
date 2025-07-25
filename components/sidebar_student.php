<?php
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch student's name
$query = $conn->query("SELECT first_name, last_name FROM students WHERE user_id = $user_id");
$student = $query->fetch_assoc();
$full_name = $student ? $student['first_name'] . ' ' . $student['last_name'] : 'Student';
?>

<aside class="lg:w-1/5 w-full bg-white/90 backdrop-blur p-6 rounded-2xl shadow-xl border border-gray-100 h-fit">
  <h3 class="text-xl sm:text-xl font-semibold mb-2 text-gray-700">👋 Welcome, <strong><?php echo htmlspecialchars($full_name); ?></strong></h3>

  <nav class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-1 gap-4">

    <a href="student_dashboard.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">🏠</div>
      <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Dashboard</h4>
    </a>

    <a href="enroll_course.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">📚</div>
      <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Enroll in New Courses</h4>
    </a>

    <a href="student_courses.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">📖</div>
      <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">View My Courses</h4>
    </a>

    <a href="student_quizzes.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">🧠</div>
      <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Attempt Quizzes</h4>
    </a>

    <a href="attempt_assignment.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">📝</div>
      <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Attempt Assignments</h4>
    </a>

    <a href="forum.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">💬</div>
      <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Join Discussions</h4>
    </a>

    <a href="student_settings.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">⚙️</div>
      <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Account Settings</h4>
    </a>

    <a href="logout.php" class="group block bg-red-100 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">🔒</div>
      <h4 class="font-semibold text-red-700 group-hover:text-red-900">Logout</h4>
    </a>

  </nav>
</aside>
