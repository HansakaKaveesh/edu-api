<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id LIMIT 1");
$student = $result->fetch_assoc();
$student_id = $student['student_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Student Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-white min-h-screen font-sans text-gray-800">

<?php include 'components/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4 py-10">

<!-- Hero Section -->
<section class="relative bg-gradient-to-r from-indigo-600 via-blue-600 to-cyan-600 text-white rounded-3xl shadow-2xl p-12 mb-16 overflow-hidden mt-16">
  <!-- Overlay Blur -->
  <div class="absolute inset-0 backdrop-blur-sm bg-black/10 rounded-3xl"></div>

  <div class="relative z-10 max-w-5xl mx-auto text-center space-y-8">
    <!-- Live Date & Time -->
    <div id="datetime" class="text-sm text-right italic opacity-90 drop-shadow-sm"></div>

    <!-- Welcome Heading -->
    <h1 class="text-5xl sm:text-6xl font-extrabold leading-tight drop-shadow-xl">
      🎓 Welcome Back, <span class="underline decoration-white/30">Student</span>! <span class="inline-block animate-wave">👋</span>
    </h1>

    <!-- Description -->
    <p class="text-xl sm:text-2xl font-light text-white/90 max-w-3xl mx-auto">
      Continue your learning journey, explore new subjects, and grow your skills.<br>
      <span class="italic">Access your courses, take quizzes, and join discussions.</span>
    </p>

    <!-- CTA Button -->
    <a href="enroll_course.php" class="inline-block bg-white text-indigo-700 font-semibold px-8 py-4 rounded-full shadow-xl hover:bg-gray-100 transition-transform duration-300 hover:scale-105">
      🚀 Enroll in a New Course
    </a>
  </div>
</section>

<!-- Wave Animation Style -->
<style>
  @keyframes wave {
    0%, 60%, 100% {
      transform: rotate(0deg);
    }
    30% {
      transform: rotate(15deg);
    }
    50% {
      transform: rotate(-10deg);
    }
  }
  .animate-wave {
    display: inline-block;
    animation: wave 2s infinite;
    transform-origin: 70% 70%;
  }
</style>


  <!-- Header -->
  <div class="flex justify-between items-center mb-8">
    <h2 class="text-3xl font-bold text-gray-700">📊 Student Dashboard</h2>
    <a href="logout.php" class="bg-red-500 text-white px-5 py-2 rounded-full hover:bg-red-600 shadow-md transition">
      🔒 Logout
    </a>
  </div>

  <!-- Dashboard Cards -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

    <!-- Left Card as Action Grid -->
    <div class="bg-white/80 backdrop-blur-sm p-8 rounded-2xl shadow-xl transition hover:shadow-2xl border border-gray-100">
      <h3 class="text-2xl font-semibold mb-6 text-gray-700">✅ What would you like to do?</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        
        <a href="enroll_course.php" class="group block bg-blue-50 p-6 rounded-xl shadow hover:shadow-lg transition">
          <div class="text-3xl mb-2 group-hover:scale-110 transition">📚</div>
          <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Enroll in New Courses</h4>
        </a>

        <a href="student_courses.php" class="group block bg-blue-50 p-6 rounded-xl shadow hover:shadow-lg transition">
          <div class="text-3xl mb-2 group-hover:scale-110 transition">📖</div>
          <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">View My Courses & Content</h4>
        </a>

        <a href="student_quizzes.php" class="group block bg-blue-50 p-6 rounded-xl shadow hover:shadow-lg transition">
          <div class="text-3xl mb-2 group-hover:scale-110 transition">🧠</div>
          <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Attempt Quizzes</h4>
        </a>

        <a href="attempt_assignment.php" class="group block bg-blue-50 p-6 rounded-xl shadow hover:shadow-lg transition">
          <div class="text-3xl mb-2 group-hover:scale-110 transition">📝</div>
          <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Attempt Assignments</h4>
        </a>

        <a href="forum.php" class="group block bg-blue-50 p-6 rounded-xl shadow hover:shadow-lg transition">
          <div class="text-3xl mb-2 group-hover:scale-110 transition">💬</div>
          <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Join Course Discussions</h4>
        </a>

      </div>
    </div>

    <!-- Right Card -->
    <div class="bg-white/80 backdrop-blur-sm p-8 rounded-2xl shadow-xl transition hover:shadow-2xl border border-gray-100">
      <h3 class="text-2xl font-semibold mb-6 text-gray-700">📚 Your Enrolled Courses</h3>
      <ul class="list-disc list-inside space-y-3 text-lg text-gray-800">
        <?php
        $query = $conn->query("
            SELECT c.course_id, c.name 
            FROM enrollments e
            JOIN courses c ON e.course_id = c.course_id
            WHERE e.user_id = $user_id AND e.status = 'active'
        ");
        if ($query->num_rows > 0) {
            while($course = $query->fetch_assoc()) {
                echo "<li><strong>" . htmlspecialchars($course['name']) . "</strong></li>";
            }
        } else {
            echo "<li>No courses enrolled yet. <a href='enroll_course.php' class='text-blue-600 hover:underline'>Enroll Now</a></li>";
        }
        ?>
      </ul>
    </div>

  </div>
</div>

<!-- Live Date & Time Script -->
<script>
  function updateDateTime() {
    const now = new Date();
    const options = {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: true
    };
    document.getElementById('datetime').textContent = `📅 ${now.toLocaleString('en-US', options)}`;
  }
  setInterval(updateDateTime, 1000);
  updateDateTime();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>
