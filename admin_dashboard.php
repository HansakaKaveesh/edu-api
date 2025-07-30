<?php
session_start();
include 'db_connect.php';

// Allow only admin users
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Handle announcement creation
$announce_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'], $_POST['message'], $_POST['audience'])) {
    $title = $conn->real_escape_string(trim($_POST['title']));
    $message = $conn->real_escape_string(trim($_POST['message']));
    $audience = $conn->real_escape_string($_POST['audience']);
    if ($title && $message && in_array($audience, ['students', 'teachers', 'all'])) {
        $conn->query("INSERT INTO announcements (title, message, audience) VALUES ('$title', '$message', '$audience')");
        $announce_message = '<div class="mb-4 text-green-700 bg-green-100 border-l-4 border-green-500 p-3 rounded">Announcement posted!</div>';
    } else {
        $announce_message = '<div class="mb-4 text-red-700 bg-red-100 border-l-4 border-red-500 p-3 rounded">Please fill all fields.</div>';
    }
}

// Fetch statistics
$total_students = $conn->query("SELECT COUNT(*) AS count FROM students")->fetch_assoc()['count'];
$total_teachers = $conn->query("SELECT COUNT(*) AS count FROM teachers")->fetch_assoc()['count'];
$total_courses = $conn->query("SELECT COUNT(*) AS count FROM courses")->fetch_assoc()['count'];
$total_enrollments = $conn->query("SELECT COUNT(*) AS count FROM enrollments")->fetch_assoc()['count'];
$total_revenue = $conn->query("SELECT IFNULL(SUM(amount), 0) AS total FROM student_payments WHERE payment_status = 'completed'")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans min-h-screen flex flex-col">

<?php include 'components/navbar.php'; ?>

<!-- Hero Section -->
<section class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 text-white py-12 sm:py-16 px-4 sm:px-6 md:px-12 rounded-b-3xl shadow-lg">
  <div class="max-w-7xl mx-auto text-center md:text-left mt-10">
    <h1 class="text-3xl sm:text-4xl md:text-5xl font-extrabold leading-tight drop-shadow-lg">
      Welcome Back, Admin! <span class="inline-block animate-wave">👋</span>
    </h1>
    <p class="mt-4 text-base sm:text-lg md:text-xl max-w-3xl mx-auto md:mx-0 drop-shadow-md">
      Monitor students, teachers, courses, and revenue all in one place. Manage your platform effortlessly with our comprehensive admin dashboard.
    </p>
  </div>
</section>

<main class="container mx-auto px-4 sm:px-6 md:px-12 py-12 flex-grow">

  <!-- Announcements List -->
  <section class="bg-white/90 rounded-2xl shadow-lg p-6 mb-10">
    <h3 class="text-xl font-bold text-indigo-700 mb-4">Recent Announcements</h3>
    <?php
    $announcements = $conn->query("SELECT id, title, message, audience, created_at FROM announcements ORDER BY created_at DESC LIMIT 10");
    if ($announcements && $announcements->num_rows > 0): ?>
      <ul class="space-y-6">
        <?php while ($a = $announcements->fetch_assoc()): ?>
          <li class="bg-blue-50 border-l-4 border-blue-400 rounded-lg p-4 shadow-sm">
            <div class="flex items-center justify-between mb-1">
              <span class="font-semibold text-blue-700"><?= htmlspecialchars($a['title']) ?></span>
              <span class="text-xs text-gray-500"><?= date('M d, Y', strtotime($a['created_at'])) ?> | <?= ucfirst($a['audience']) ?></span>
            </div>
            <div class="text-gray-700 text-sm mb-2"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
          </li>
        <?php endwhile; ?>
      </ul>
    <?php else: ?>
      <div class="text-gray-600 text-lg">No announcements yet.</div>
    <?php endif; ?>
  </section>

  <!-- Summary Cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
    <div class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 hover:shadow-2xl transition-shadow duration-300">
      <h4 class="text-xl font-semibold text-indigo-700">👨‍🎓 Total Students</h4>
      <p class="text-4xl font-extrabold mt-3 text-gray-900"><?= $total_students ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 hover:shadow-2xl transition-shadow duration-300">
      <h4 class="text-xl font-semibold text-purple-700">👩‍🏫 Total Teachers</h4>
      <p class="text-4xl font-extrabold mt-3 text-gray-900"><?= $total_teachers ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 hover:shadow-2xl transition-shadow duration-300">
      <h4 class="text-xl font-semibold text-pink-600">📘 Total Courses</h4>
      <p class="text-4xl font-extrabold mt-3 text-gray-900"><?= $total_courses ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 hover:shadow-2xl transition-shadow duration-300">
      <h4 class="text-xl font-semibold text-indigo-700">🧑‍🤝‍🧑 Total Enrollments</h4>
      <p class="text-4xl font-extrabold mt-3 text-gray-900"><?= $total_enrollments ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 col-span-1 sm:col-span-2 lg:col-span-3 hover:shadow-2xl transition-shadow duration-300">
      <h4 class="text-xl font-semibold text-green-600">💵 Total Revenue</h4>
      <p class="text-4xl font-extrabold mt-3 text-green-700">$<?= number_format($total_revenue, 2) ?></p>
    </div>
  </div>

  <!-- Admin Tools -->
  <section class="mt-12">
    <h3 class="text-3xl font-bold mb-6 text-gray-900">⚙️ Admin Tools</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 sm:gap-6">
      <a href="admin_register.php" class="block bg-white rounded-2xl shadow-lg p-6 hover:shadow-2xl transition-shadow duration-300 text-indigo-700 hover:text-indigo-900">
        <h4 class="text-xl font-semibold mb-2">➕ Register Admin</h4>
        <p class="text-sm text-gray-600">Create new admin accounts with secure access.</p>
      </a>
      <a href="view_users.php" class="block bg-white rounded-2xl shadow-lg p-6 hover:shadow-2xl transition-shadow duration-300 text-indigo-700 hover:text-indigo-900">
        <h4 class="text-xl font-semibold mb-2">👁️ View All Users</h4>
        <p class="text-sm text-gray-600">Browse the full list of students, teachers, and admins.</p>
      </a>
      <a href="view_courses.php" class="block bg-white rounded-2xl shadow-lg p-6 hover:shadow-2xl transition-shadow duration-300 text-indigo-700 hover:text-indigo-900">
        <h4 class="text-xl font-semibold mb-2">📚 View All Courses</h4>
        <p class="text-sm text-gray-600">Manage and review all available courses.</p>
      </a>
      <a href="admin_reports.php" class="block bg-white rounded-2xl shadow-lg p-6 hover:shadow-2xl transition-shadow duration-300 text-indigo-700 hover:text-indigo-900">
        <h4 class="text-xl font-semibold mb-2">📑 Payment & Enrollment Reports</h4>
        <p class="text-sm text-gray-600">Analyze payment statuses and enrollment trends.</p>
      </a>
      <a href="admin_progress_reports.php" class="block bg-white rounded-2xl shadow-lg p-6 hover:shadow-2xl transition-shadow duration-300 text-indigo-700 hover:text-indigo-900">
        <h4 class="text-xl font-semibold mb-2">📊 View Progress (Students & Teachers)</h4>
        <p class="text-sm text-gray-600">Track learning and teaching progress over time.</p>
      </a>
    </div>
  </section>

  <!-- Create Announcement Form -->
  <section class="bg-white/90 rounded-2xl shadow-lg p-6 mb-10 mt-12">
    <h3 class="text-xl font-bold text-indigo-700 mb-4">📢 Create Announcement</h3>
    <?= $announce_message ?>
    <form method="post" class="space-y-4">
      <div>
        <label class="block font-semibold mb-1" for="title">Title</label>
        <input type="text" id="title" name="title" required class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring focus:ring-indigo-200">
      </div>
      <div>
        <label class="block font-semibold mb-1" for="message">Message</label>
        <textarea id="message" name="message" rows="3" required class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring focus:ring-indigo-200"></textarea>
      </div>
      <div>
        <label class="block font-semibold mb-1" for="audience">Audience</label>
        <select id="audience" name="audience" required class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring focus:ring-indigo-200">
          <option value="all">All</option>
          <option value="students">Students</option>
          <option value="teachers">Teachers</option>
        </select>
      </div>
      <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded font-semibold hover:bg-indigo-700 transition">Post Announcement</button>
    </form>
  </section>
</main>

<!-- Wave Animation Style -->
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

<?php include 'components/footer.php'; ?>
</body>
</html>