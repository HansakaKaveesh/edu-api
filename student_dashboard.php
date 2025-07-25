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

    <!-- Enrolled Courses -->
    <section class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100">
      <h3 class="text-xl sm:text-2xl font-semibold mb-6 text-gray-700">📚 Your Enrolled Courses</h3>
      <ul class="list-disc list-inside space-y-3 text-lg text-gray-800 overflow-x-auto">
        <?php
        $query = $conn->query("
            SELECT c.course_id, c.name 
            FROM enrollments e
            JOIN courses c ON e.course_id = c.course_id
            WHERE e.user_id = $user_id AND e.status = 'active'
        ");
        if ($query->num_rows > 0) {
            while ($course = $query->fetch_assoc()) {
                echo "<li><strong>" . htmlspecialchars($course['name']) . "</strong></li>";
            }
        } else {
            echo "<li>No courses enrolled yet. <a href='enroll_course.php' class='text-blue-600 hover:underline'>Enroll Now</a></li>";
        }
        ?>
      </ul>
    </section>

  </main>
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
