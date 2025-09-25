<?php
// student_sidebar.php (compact variant)

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
$initials = strtoupper(mb_substr($full_name, 0, 1)) ?: 'S';

// Detect current page
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Simple badges (plug real counts if available)
$badgeByLabel = [
  'Messages'    => 0,
  'Quizzes'     => 0,
  'Assignments' => 0,
];

// Navigation items (Ionicons + theme)
$navItems = [
  ['icon' => 'home-outline',           'label' => 'Dashboard',   'href' => 'student_dashboard.php', 'theme' => 'indigo'],
  ['icon' => 'library-outline',        'label' => 'Enroll',      'href' => 'enroll_course.php',     'theme' => 'emerald'],
  ['icon' => 'book-outline',           'label' => 'My Courses',  'href' => 'student_courses.php',   'theme' => 'purple'],
  ['icon' => 'reader-outline',         'label' => 'Quizzes',     'href' => 'student_quizzes.php',   'theme' => 'amber'],
  ['icon' => 'chatbubbles-outline',    'label' => 'Discussions', 'href' => 'forum.php',             'theme' => 'yellow'],
  ['icon' => 'settings-outline',       'label' => 'Settings',    'href' => 'student_settings.php',  'theme' => 'slate'],
  ['icon' => 'mail-unread-outline',    'label' => 'Messages',    'href' => 'messages.php',          'theme' => 'sky'],
  ['icon' => 'log-out-outline',        'label' => 'Logout',      'href' => 'logout.php',            'theme' => 'rose'],
];

// Theme utility map (compact palette)
$themes = [
  'indigo' => ['bg'=>'bg-indigo-50', 'text'=>'text-indigo-700', 'hover'=>'group-hover:text-indigo-900', 'ring'=>'ring-indigo-300'],
  'emerald'=> ['bg'=>'bg-emerald-50','text'=>'text-emerald-700','hover'=>'group-hover:text-emerald-900','ring'=>'ring-emerald-300'],
  'purple' => ['bg'=>'bg-purple-50','text'=>'text-purple-700','hover'=>'group-hover:text-purple-900','ring'=>'ring-purple-300'],
  'amber'  => ['bg'=>'bg-amber-50', 'text'=>'text-amber-700', 'hover'=>'group-hover:text-amber-900', 'ring'=>'ring-amber-300'],
  'yellow' => ['bg'=>'bg-yellow-50','text'=>'text-yellow-700','hover'=>'group-hover:text-yellow-900','ring'=>'ring-yellow-300'],
  'slate'  => ['bg'=>'bg-slate-50', 'text'=>'text-slate-700', 'hover'=>'group-hover:text-slate-900', 'ring'=>'ring-slate-300'],
  'sky'    => ['bg'=>'bg-sky-50',   'text'=>'text-sky-700',   'hover'=>'group-hover:text-sky-900',   'ring'=>'ring-sky-300'],
  'rose'   => ['bg'=>'bg-rose-50',  'text'=>'text-rose-700',  'hover'=>'group-hover:text-rose-900',  'ring'=>'ring-rose-300'],
];
?>
<!-- Ionicons -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

<!-- Compact Mobile FAB -->
<button
  id="sidebarToggle"
  aria-controls="studentSidebar"
  aria-expanded="false"
  aria-label="Open sidebar"
  class="lg:hidden fixed bottom-4 left-3 z-50 p-2.5 rounded-full bg-indigo-600 text-white shadow-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-400"
>
  <ion-icon id="hamburgerIcon" name="menu-outline" class="text-xl"></ion-icon>
  <ion-icon id="closeIcon" name="close-outline" class="text-xl hidden"></ion-icon>
</button>

<!-- Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black/40 z-40 hidden lg:hidden" aria-hidden="true"></div>

<!-- Mobile Sidebar (compact) -->
<aside
  id="studentSidebar"
  class="fixed top-0 left-0 z-50 w-[80%] max-w-[260px] h-full bg-white/95 backdrop-blur p-2 rounded-r-xl shadow-lg border-r border-gray-100 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden overflow-y-auto hidden"
  role="dialog"
  aria-modal="true"
  aria-labelledby="sidebarTitle"
  aria-hidden="true"
>
  <!-- Header -->
  <div class="flex items-center justify-between mb-2">
    <div class="flex items-center gap-2">
      <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200 text-xs font-semibold">
        <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
      </span>
      <h3 id="sidebarTitle" class="text-xs font-semibold text-gray-700 truncate max-w-[150px]">
        <span class="text-slate-500">Hi,</span> <strong><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></strong>
      </h3>
    </div>
    <button id="sidebarClose" class="text-lg text-gray-400 hover:text-gray-700" aria-label="Close sidebar">
      <ion-icon name="close-outline"></ion-icon>
    </button>
  </div>

  <!-- Quick link -->
  <a href="student_settings.php" class="mb-2 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md border border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-[11px]">
    <ion-icon name="settings-outline" class="text-sm"></ion-icon> Settings
  </a>

  <!-- Nav -->
  <nav class="grid gap-1.5 text-xs">
    <?php foreach ($navItems as $item): ?>
      <?php
        $isActive = (basename($item['href']) === $currentPage);
        $th = $themes[$item['theme']] ?? $themes['slate'];
        $badge = $badgeByLabel[$item['label']] ?? 0;

        $mobileClasses = "group flex items-center gap-2 {$th['bg']} {$th['text']} {$th['hover']} p-1.5 rounded-lg shadow-sm hover:shadow transition";
        if ($isActive) $mobileClasses .= " ring-2 ring-offset-1 {$th['ring']}";
      ?>
      <a
        href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
        class="<?= $mobileClasses ?>"
        aria-label="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <span class="shrink-0 inline-flex h-6 w-6 items-center justify-center rounded-md bg-white text-slate-700 ring-1 ring-white/60 group-hover:scale-110 transition" aria-hidden="true">
          <ion-icon name="<?= htmlspecialchars($item['icon']) ?>" class="text-[14px] leading-none"></ion-icon>
        </span>
        <span class="flex items-center gap-1.5 font-medium">
          <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          <?php if ($badge > 0): ?>
            <span class="inline-flex items-center justify-center min-w-[1rem] h-4 px-1 rounded-full bg-rose-500 text-white text-[10px]"><?= (int)$badge ?></span>
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<!-- Desktop Sidebar (compact) -->
<aside class="sticky top-28 hidden lg:block lg:w-1/5 w-full bg-white/90 backdrop-blur p-4 rounded-xl shadow-lg border border-gray-100 h-fit">
  <!-- Profile small -->
  <div class="flex items-center justify-between mb-3">
    <div class="flex items-center gap-2">
      <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200 text-sm font-semibold">
        <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
      </span>
      <div class="text-xs">
        <div class="text-gray-700 font-semibold leading-tight truncate max-w-[140px]"><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="text-[11px] text-slate-500">Student</div>
      </div>
    </div>
    <a href="student_settings.php" class="inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded border border-slate-200 hover:bg-slate-50">
      <ion-icon name="settings-outline" class="text-slate-600 text-sm"></ion-icon> Edit
    </a>
  </div>

  <!-- Nav -->
  <nav class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-1 gap-3">
    <?php foreach ($navItems as $item): ?>
      <?php
        $isActive = (basename($item['href']) === $currentPage);
        $th = $themes[$item['theme']] ?? $themes['slate'];
        $badge = $badgeByLabel[$item['label']] ?? 0;

        $desktopClasses = "group flex items-center gap-2 {$th['bg']} p-3 rounded-lg shadow-sm hover:shadow transition";
        if ($isActive) $desktopClasses .= " ring-2 ring-offset-1 {$th['ring']}";
      ?>
      <a
        href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
        class="<?= $desktopClasses ?>"
        aria-label="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <span class="shrink-0 inline-flex h-8 w-8 items-center justify-center rounded-md bg-white text-slate-700 ring-1 ring-white/70 group-hover:scale-110 transition">
          <ion-icon name="<?= htmlspecialchars($item['icon']) ?>" class="text-[15px] leading-none"></ion-icon>
        </span>
        <span class="flex items-center gap-1.5 text-[13px] font-medium <?= htmlspecialchars($th['text']) ?> <?= htmlspecialchars($th['hover']) ?>">
          <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          <?php if ($badge > 0): ?>
            <span class="inline-flex items-center justify-center min-w-[1rem] h-4 px-1 rounded-full bg-rose-500 text-white text-[10px]"><?= (int)$badge ?></span>
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<!-- Sidebar Script -->
<script>
  const sidebarToggle   = document.getElementById('sidebarToggle');
  const sidebar         = document.getElementById('studentSidebar');
  const sidebarOverlay  = document.getElementById('sidebarOverlay');
  const sidebarClose    = document.getElementById('sidebarClose');
  const hamburgerIcon   = document.getElementById('hamburgerIcon');
  const closeIcon       = document.getElementById('closeIcon');

  let previouslyFocused = null;
  const focusableSelectors = 'a[href], button, textarea, input, select, [tabindex]:not([tabindex="-1"])';
  let firstFocusable = null, lastFocusable = null;

  function setFocusTrap() {
    const nodes = sidebar.querySelectorAll(focusableSelectors);
    firstFocusable = nodes[0] || sidebarClose;
    lastFocusable  = nodes[nodes.length - 1] || sidebarClose;
  }

  function handleKeydown(e) {
    if (e.key === 'Escape') { e.preventDefault(); closeSidebar(); }
    else if (e.key === 'Tab' && sidebar.getAttribute('aria-hidden') === 'false') {
      if (e.shiftKey && document.activeElement === firstFocusable) { e.preventDefault(); lastFocusable.focus(); }
      else if (!e.shiftKey && document.activeElement === lastFocusable) { e.preventDefault(); firstFocusable.focus(); }
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
    if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus();
  }

  sidebarToggle?.addEventListener('click', () => {
    if (sidebar.getAttribute('aria-hidden') === 'true') openSidebar();
    else closeSidebar();
  });
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