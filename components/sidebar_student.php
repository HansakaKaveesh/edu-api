<?php
// student_sidebar.php (drop-in partial)
// Assumes Tailwind CSS is already included in your layout.

include 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
  header("Location: login.php");
  exit;
}

$user_id = (int) $_SESSION['user_id'];

// Securely fetch student's name
$full_name = 'Student';
if ($stmt = $conn->prepare("SELECT first_name, last_name FROM students WHERE user_id = ? LIMIT 1")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $student = $res->fetch_assoc()) {
    $full_name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?: 'Student';
  }
  $stmt->close();
}

// Detect current page for "active" state
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Navigation items config (icon HTML, label, link, bg color, text color, hover text color)
$navItems = [
  ['<i class="fa-solid fa-house text-lg text-blue-600"></i>', 'Dashboard', 'student_dashboard.php', 'bg-blue-50', 'text-blue-700', 'group-hover:text-blue-900'],
  ['<i class="fa-solid fa-book-open text-lg text-green-600"></i>', 'Enroll', 'enroll_course.php', 'bg-green-50', 'text-green-700', 'group-hover:text-green-900'],
  ['<i class="fa-solid fa-book text-lg text-purple-600"></i>', 'My Courses', 'student_courses.php', 'bg-purple-50', 'text-purple-700', 'group-hover:text-purple-900'],
  ['<i class="fa-solid fa-brain text-lg text-orange-600"></i>', 'Quizzes', 'student_quizzes.php', 'bg-orange-50', 'text-orange-700', 'group-hover:text-orange-900'],
  ['<i class="fa-solid fa-pen text-lg text-pink-600"></i>', 'Assignments', 'attempt_assignment.php', 'bg-pink-50', 'text-pink-700', 'group-hover:text-pink-900'],
  ['<i class="fa-solid fa-comments text-lg text-yellow-600"></i>', 'Discussions', 'forum.php', 'bg-yellow-50', 'text-yellow-700', 'group-hover:text-yellow-900'],
  ['<i class="fa-solid fa-gear text-lg text-gray-700"></i>', 'Settings', 'student_settings.php', 'bg-gray-50', 'text-gray-700', 'group-hover:text-gray-900'],
  ['<i class="fa-solid fa-envelope text-lg text-indigo-600"></i>', 'Messages', 'messages.php', 'bg-indigo-50', 'text-indigo-700', 'group-hover:text-indigo-900'],
  ['<i class="fa-solid fa-right-from-bracket text-lg text-red-600"></i>', 'Logout', 'logout.php', 'bg-red-100', 'text-red-700', 'group-hover:text-red-900']
];

// Optional: badges (replace with real counts if needed)
$badgeByLabel = [
  'Messages'    => 0, // e.g., unread messages
  'Assignments' => 0, // e.g., due soon
  'Quizzes'     => 0, // e.g., upcoming
];

// Example to fetch counts safely (uncomment and adapt to your schema)
// function getCount(mysqli $conn, string $sql, int $uid): int {
//     $stmt = $conn->prepare($sql);
//     $stmt->bind_param('i', $uid);
//     $stmt->execute();
//     $stmt->bind_result($count);
//     $stmt->fetch();
//     $stmt->close();
//     return (int)$count;
// }
// $badgeByLabel['Messages'] = getCount($conn, 'SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0', $user_id);
?>

<!-- Font Awesome (icons) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<!-- Mobile Menu Button -->
<div class="lg:hidden flex justify-between items-center mb-4">
  <h3 class="text-sm font-semibold text-gray-700">Menu</h3>
  <button
    id="sidebarToggle"
    aria-controls="studentSidebar"
    aria-expanded="false"
    aria-label="Open sidebar"
    class="p-1 rounded-md text-blue-700 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-400"
  >
    <svg id="hamburgerIcon" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
    </svg>
    <svg id="closeIcon" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
    </svg>
  </button>
</div>

<!-- Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-40 z-40 hidden lg:hidden" aria-hidden="true"></div>

<!-- Mobile Sidebar -->
<aside
  id="studentSidebar"
  class="fixed top-0 left-0 z-50 w-4/5 max-w-[280px] h-full bg-white/95 backdrop-blur p-2 rounded-r-2xl shadow-xl border-r border-gray-100 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden overflow-y-auto hidden"
  role="dialog"
  aria-modal="true"
  aria-labelledby="sidebarTitle"
  aria-hidden="true"
>
  <div class="flex justify-between items-center mb-2">
    <h3 id="sidebarTitle" class="text-sm font-semibold text-gray-700">👋 <strong><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></strong></h3>
    <button id="sidebarClose" class="text-xl text-gray-400 hover:text-gray-700" aria-label="Close sidebar">&times;</button>
  </div>
  <nav class="grid gap-2 text-xs">
    <?php foreach ($navItems as $item): ?>
      <?php
        $isActive = (basename($item[2]) === $currentPage);
        $badge = $badgeByLabel[$item[1]] ?? 0;
        // removed "text-center" to align icon and text inline
        $mobileClasses = "group block {$item[3]} {$item[4]} {$item[5]} p-2 rounded-xl shadow hover:shadow-lg transition";
        if ($isActive) $mobileClasses .= ' ring-2 ring-offset-2 ring-blue-300 scale-[1.01]';
      ?>
      <a
        href="<?= htmlspecialchars($item[2], ENT_QUOTES, 'UTF-8') ?>"
        class="<?= $mobileClasses ?>"
        aria-label="<?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <div class="flex items-center gap-3">
          <span class="shrink-0 leading-none group-hover:scale-110 transition" aria-hidden="true"><?= $item[0] ?></span>
          <span class="flex items-center gap-2 font-semibold <?= htmlspecialchars($item[4], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($item[5], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($badge > 0): ?>
              <span class="inline-flex items-center justify-center min-w-[1.1rem] h-4 px-1 rounded-full bg-red-500 text-white text-[10px]"><?= (int)$badge ?></span>
            <?php endif; ?>
          </span>
        </div>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<!-- Desktop Sidebar -->
<aside class="hidden lg:block lg:w-1/5 w-full bg-white/90 backdrop-blur p-6 rounded-2xl shadow-xl border border-gray-100 h-fit">
  <h3 class="text-sm sm:text-sm font-semibold mb-2 text-gray-700">👋 Welcome, <strong><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></strong></h3>
  <nav class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-1 gap-4">
    <?php foreach ($navItems as $item): ?>
      <?php
        $isActive = (basename($item[2]) === $currentPage);
        $badge = $badgeByLabel[$item[1]] ?? 0;
        // removed "text-center" to align icon and text inline
        $desktopClasses = "group block {$item[3]} p-4 rounded-xl shadow hover:shadow-lg transition";
        if ($isActive) $desktopClasses .= ' ring-2 ring-offset-2 ring-blue-300 scale-[1.01]';
      ?>
      <a
        href="<?= htmlspecialchars($item[2], ENT_QUOTES, 'UTF-8') ?>"
        class="<?= $desktopClasses ?>"
        aria-label="<?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <div class="flex items-center gap-3">
          <span class="shrink-0 leading-none group-hover:scale-110 transition" aria-hidden="true"><?= $item[0] ?></span>
          <span class="flex items-center gap-2 font-semibold <?= htmlspecialchars($item[4], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($item[5], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($badge > 0): ?>
              <span class="inline-flex items-center justify-center min-w-[1.1rem] h-4 px-1 rounded-full bg-red-500 text-white text-[10px]"><?= (int)$badge ?></span>
            <?php endif; ?>
          </span>
        </div>
      </a>
    <?php endforeach; ?>
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

  let previouslyFocused = null;
  const focusableSelectors = 'a[href], button, textarea, input, select, [tabindex]:not([tabindex="-1"])';
  let firstFocusable = null, lastFocusable = null;

  function setFocusTrap() {
    const nodes = sidebar.querySelectorAll(focusableSelectors);
    firstFocusable = nodes[0] || sidebarClose;
    lastFocusable  = nodes[nodes.length - 1] || sidebarClose;
  }

  function handleKeydown(e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      closeSidebar();
    } else if (e.key === 'Tab' && sidebar.getAttribute('aria-hidden') === 'false') {
      // cycle focus within the sidebar
      if (e.shiftKey && document.activeElement === firstFocusable) {
        e.preventDefault();
        lastFocusable.focus();
      } else if (!e.shiftKey && document.activeElement === lastFocusable) {
        e.preventDefault();
        firstFocusable.focus();
      }
    }
  }

  function openSidebar() {
    previouslyFocused = document.activeElement;
    sidebar.classList.remove('hidden', '-translate-x-full');
    sidebarOverlay.classList.remove('hidden');
    hamburgerIcon.classList.add('hidden');
    closeIcon.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    sidebar.setAttribute('aria-hidden', 'false');
    sidebarToggle.setAttribute('aria-expanded', 'true');

    setFocusTrap();
    (firstFocusable || sidebarClose).focus();

    document.addEventListener('keydown', handleKeydown);
  }

  function closeSidebar() {
    sidebar.classList.add('-translate-x-full');
    sidebarOverlay.classList.add('hidden');
    hamburgerIcon.classList.remove('hidden');
    closeIcon.classList.add('hidden');
    setTimeout(() => sidebar.classList.add('hidden'), 200);
    document.body.style.overflow = '';
    sidebar.setAttribute('aria-hidden', 'true');
    sidebarToggle.setAttribute('aria-expanded', 'false');

    document.removeEventListener('keydown', handleKeydown);
    if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
      previouslyFocused.focus();
    }
  }

  sidebarToggle?.addEventListener('click', openSidebar);
  sidebarClose?.addEventListener('click', closeSidebar);
  sidebarOverlay?.addEventListener('click', closeSidebar);

  window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
      sidebar.classList.add('hidden');
      sidebarOverlay.classList.add('hidden');
      hamburgerIcon.classList.remove('hidden');
      closeIcon.classList.add('hidden');
      document.body.style.overflow = '';
      sidebar.setAttribute('aria-hidden', 'true');
      sidebarToggle.setAttribute('aria-expanded', 'false');
      document.removeEventListener('keydown', handleKeydown);
    }
  });
</script>