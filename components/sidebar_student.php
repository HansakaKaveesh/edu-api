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

<!-- Mobile Menu Button -->
<div class="lg:hidden flex justify-between items-center mb-4">
  <h3 class="text-sm font-semibold text-gray-700">Menu</h3>
  <button id="sidebarToggle" class="p-1 rounded-md text-blue-700 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-400">
    <svg id="hamburgerIcon" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
    </svg>
    <svg id="closeIcon" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
    </svg>
  </button>
</div>

<!-- Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-40 z-40 hidden lg:hidden"></div>

<!-- Mobile Sidebar -->
<aside id="studentSidebar" class="fixed top-0 left-0 z-50 w-4/5 max-w-[280px] h-full bg-white/95 backdrop-blur p-2 rounded-r-2xl shadow-xl border-r border-gray-100 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden overflow-y-auto">
  <div class="flex justify-between items-center mb-2">
    <h3 class="text-sm font-semibold text-gray-700">👋 <strong><?php echo htmlspecialchars($full_name); ?></strong></h3>
    <button id="sidebarClose" class="text-xl text-gray-400 hover:text-gray-700">&times;</button>
  </div>
  <nav class="grid gap-2 text-xs">
    <?php
    $navItems = [
      ['🏠', 'Dashboard', 'student_dashboard.php'],
      ['📚', 'Enroll', 'enroll_course.php'],
      ['📖', 'My Courses', 'student_courses.php'],
      ['🧠', 'Quizzes', 'student_quizzes.php'],
      ['📝', 'Assignments', 'attempt_assignment.php'],
      ['💬', 'Discussions', 'forum.php'],
      ['⚙️', 'Settings', 'student_settings.php'],
      ['✉️', 'Messages', 'messages.php'],
      ['🔒', 'Logout', 'logout.php', 'bg-red-100 text-red-700 group-hover:text-red-900']
    ];

    foreach ($navItems as $item) {
      $icon = $item[0];
      $label = $item[1];
      $link = $item[2];
      $extraClasses = $item[3] ?? 'bg-blue-50 text-blue-700 group-hover:text-blue-900';
      echo <<<HTML
    <a href="{$link}" class="group block {$extraClasses} p-2 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-lg mb-1 group-hover:scale-110 transition">{$icon}</div>
      <h4 class="font-semibold">{$label}</h4>
    </a>
HTML;
    }
    ?>
  </nav>
</aside>

<!-- Desktop Sidebar -->
<aside class="hidden lg:block lg:w-1/5 w-full bg-white/90 backdrop-blur p-6 rounded-2xl shadow-xl border border-gray-100 h-fit">
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
    <a href="messages.php" class="group block bg-blue-50 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
  <div class="text-3xl mb-2 group-hover:scale-110 transition">✉️</div>
  <h4 class="font-semibold text-blue-700 group-hover:text-blue-900">Messages</h4>
</a>
    <a href="logout.php" class="group block bg-red-100 p-4 rounded-xl shadow hover:shadow-lg text-center transition">
      <div class="text-3xl mb-2 group-hover:scale-110 transition">🔒</div>
      <h4 class="font-semibold text-red-700 group-hover:text-red-900">Logout</h4>
    </a>
  </nav>
</aside>

<!-- Sidebar Script -->
<script>
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('studentSidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  const sidebarClose = document.getElementById('sidebarClose');
  const hamburgerIcon = document.getElementById('hamburgerIcon');
  const closeIcon = document.getElementById('closeIcon');

  function openSidebar() {
    sidebar.classList.remove('hidden');
    sidebar.classList.remove('-translate-x-full');
    sidebarOverlay.classList.remove('hidden');
    hamburgerIcon.classList.add('hidden');
    closeIcon.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    sidebar.classList.add('-translate-x-full');
    sidebarOverlay.classList.add('hidden');
    hamburgerIcon.classList.remove('hidden');
    closeIcon.classList.add('hidden');
    setTimeout(() => sidebar.classList.add('hidden'), 200);
    document.body.style.overflow = '';
  }

  if (sidebarToggle) sidebarToggle.addEventListener('click', openSidebar);
  if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
  if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

  window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
      sidebar.classList.add('hidden');
      sidebarOverlay.classList.add('hidden');
      hamburgerIcon.classList.remove('hidden');
      closeIcon.classList.add('hidden');
      document.body.style.overflow = '';
    }
  });
</script>
