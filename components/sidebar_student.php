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

<!-- Collapsed state + beauty styles -->
<style>
  /* Desktop collapse */
  #desktopSidebar { transition: width .2s ease, padding .2s ease, background .2s ease, box-shadow .2s ease; }
  #desktopSidebar.collapsed { width: 72px; padding: .5rem; }
  #desktopSidebar.collapsed .profile-name,
  #desktopSidebar.collapsed .profile-role,
  #desktopSidebar.collapsed .edit-btn,
  #desktopSidebar.collapsed .label-text,
  #desktopSidebar.collapsed .badge { display: none !important; }
  #desktopSidebar.collapsed .nav-link { justify-content: center; }
  #desktopSidebar.collapsed .profile { justify-content: center; }
  #desktopSidebar.collapsed .profile-initials { height: 2.25rem; width: 2.25rem; }
  #desktopSidebar.collapsed .nav-icon { height: 2rem; width: 2rem; }

  /* Glassy panels + soft gradient */
  .sb-glass {
    background: linear-gradient(180deg, rgba(255,255,255,.88), rgba(255,255,255,.78));
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
  }
  .sb-rail { background: linear-gradient(180deg, rgba(255,255,255,.9), rgba(255,255,255,.85)); }

  /* Nav links enhanced */
  .nav-link { position: relative; overflow: hidden; }
  .nav-link::before {
    content: ""; position: absolute; left: 0; top: 8px; bottom: 8px; width: 0;
    border-radius: 4px;
    background: linear-gradient(180deg, #4f46e5, #06b6d4);
    transition: width .18s ease;
  }
  .nav-link:hover::before { width: 3px; }
  .nav-link.is-active::before { width: 4px; }

  /* Icon chip: subtle inset ring + hover scale */
  .nav-icon {
    box-shadow: 0 0 0 1px rgba(255,255,255,.8) inset, 0 1px 0 rgba(2,6,23,.06);
    transition: transform .15s ease;
  }
  .nav-link:hover .nav-icon { transform: scale(1.06); }

  /* Gradient glow on desktop toggle */
  #desktopSidebarToggle {
    background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.84));
    box-shadow: 0 12px 30px rgba(2,6,23,.12);
  }

  /* Tooltip when collapsed */
  #desktopSidebar.collapsed .nav-link:hover::after {
    content: attr(title);
    position: absolute; left: calc(100% + 10px); top: 50%; transform: translateY(-50%);
    color: #0f172a; background: #fff; border: 1px solid rgba(2,6,23,.08);
    border-radius: .5rem; padding: .35rem .5rem; font-size: 12px; white-space: nowrap;
    box-shadow: 0 10px 25px rgba(2,6,23,.15);
    z-index: 50;
  }

  /* Ripple effect */
  .ripple {
    position: absolute; border-radius: 9999px; transform: translate(-50%,-50%);
    pointer-events: none; background: radial-gradient(circle, rgba(79,70,229,.25) 0%, rgba(79,70,229,0) 60%);
    animation: ripple .6s ease forwards;
  }
  @keyframes ripple { from { opacity: .3; transform: translate(-50%,-50%) scale(.5);} to { opacity: 0; transform: translate(-50%,-50%) scale(2.2);} }

  /* Nice scrollbar (mobile panel) */
  #studentSidebar::-webkit-scrollbar { width: 10px; }
  #studentSidebar::-webkit-scrollbar-thumb { background: rgba(15,23,42,.15); border-radius: 9999px; }
</style>

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
  class="fixed top-0 left-0 z-50 w-[80%] max-w-[280px] h-full sb-glass backdrop-blur p-3 rounded-r-xl shadow-lg border-r border-gray-100 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden overflow-y-auto hidden"
  role="dialog"
  aria-modal="true"
  aria-labelledby="sidebarTitle"
  aria-hidden="true"
>
  <!-- Header -->
  <div class="flex items-center justify-between mb-3">
    <div class="flex items-center gap-2">
      <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200 text-sm font-semibold">
        <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
      </span>
      <h3 id="sidebarTitle" class="text-sm font-semibold text-gray-700 truncate max-w-[170px]">
        <span class="text-slate-500">Hi,</span> <strong><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></strong>
      </h3>
    </div>
    <button id="sidebarClose" class="text-xl text-gray-400 hover:text-gray-700" aria-label="Close sidebar">
      <ion-icon name="close-outline"></ion-icon>
    </button>
  </div>

  <!-- Quick link -->
  <a href="student_settings.php" class="mb-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-xs">
    <ion-icon name="settings-outline" class="text-sm"></ion-icon> Settings
  </a>

  <!-- Nav -->
  <nav class="grid gap-2 text-sm">
    <?php foreach ($navItems as $item): ?>
      <?php
        $isActive = (basename($item['href']) === $currentPage);
        $th = $themes[$item['theme']] ?? $themes['slate'];
        $badge = $badgeByLabel[$item['label']] ?? 0;

        $mobileClasses = "nav-link group relative flex items-center gap-2 {$th['bg']} {$th['text']} {$th['hover']} px-2.5 py-2 rounded-lg shadow-sm hover:shadow transition";
        if ($isActive) { $mobileClasses .= " ring-2 ring-offset-1 {$th['ring']} is-active"; }
      ?>
      <a
        href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
        class="<?= $mobileClasses ?>"
        aria-label="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <span class="nav-icon shrink-0 inline-flex h-7 w-7 items-center justify-center rounded-md bg-white text-slate-700 ring-1 ring-white/60 transition" aria-hidden="true">
          <ion-icon name="<?= htmlspecialchars($item['icon']) ?>" class="text-[15px] leading-none"></ion-icon>
        </span>
        <span class="flex items-center gap-1.5 font-medium">
          <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          <?php if ($badge > 0): ?>
            <span class="badge inline-flex items-center justify-center min-w-[1rem] h-4 px-1 rounded-full bg-rose-500 text-white text-[10px]"><?= (int)$badge ?></span>
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<!-- Desktop Sidebar Toggle (open/close) -->
<button
  id="desktopSidebarToggle"
  aria-controls="desktopSidebar"
  aria-expanded="true"
  title="Collapse sidebar"
  class="hidden lg:flex fixed left-3 top-28 z-40 p-2 rounded-full border border-gray-200 text-slate-700 hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400"
>
  <ion-icon id="desktopToggleIcon" name="chevron-back-outline" class="text-lg"></ion-icon>
  <span class="sr-only">Toggle sidebar</span>
</button>

<!-- Desktop Sidebar (compact, collapsible) -->
<aside id="desktopSidebar" class="sticky top-28 hidden lg:block lg:w-1/5 w-full sb-rail backdrop-blur p-4 rounded-xl shadow-lg border border-gray-100 h-fit">
  <!-- Profile small -->
  <div class="profile flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
      <span class="profile-initials inline-flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200 text-sm font-semibold">
        <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
      </span>
      <div class="text-xs">
        <div class="profile-name text-gray-700 font-semibold leading-tight truncate max-w-[140px]"><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="profile-role text-[11px] text-slate-500">Student</div>
      </div>
    </div>
    <a href="student_settings.php" class="edit-btn inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded border border-slate-200 hover:bg-slate-50">
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

        $desktopClasses = "nav-link group relative flex items-center gap-2 {$th['bg']} p-3 rounded-lg shadow-sm hover:shadow transition";
        if ($isActive) { $desktopClasses .= " ring-2 ring-offset-1 {$th['ring']} is-active"; }
      ?>
      <a
        href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
        class="<?= $desktopClasses ?>"
        aria-label="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
        title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <span class="nav-icon shrink-0 inline-flex h-8 w-8 items-center justify-center rounded-md bg-white text-slate-700 ring-1 ring-white/70 transition">
          <ion-icon name="<?= htmlspecialchars($item['icon']) ?>" class="text-[15px] leading-none"></ion-icon>
        </span>
        <span class="label-text flex items-center gap-1.5 text-[13px] font-medium <?= htmlspecialchars($th['text']) ?> <?= htmlspecialchars($th['hover']) ?>">
          <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          <?php if ($badge > 0): ?>
            <span class="badge inline-flex items-center justify-center min-w-[1rem] h-4 px-1 rounded-full bg-rose-500 text-white text-[10px]"><?= (int)$badge ?></span>
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<!-- Sidebar Script -->
<script>
  // Mobile sidebar logic
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

  // Desktop collapse/expand logic (with persistence)
  const desktopSidebar = document.getElementById('desktopSidebar');
  const desktopToggle  = document.getElementById('desktopSidebarToggle');
  const desktopIcon    = document.getElementById('desktopToggleIcon');
  const LS_KEY = 'studentDesktopSidebarCollapsed';

  function setDesktopCollapsed(collapsed) {
    desktopSidebar.classList.toggle('collapsed', collapsed);
    desktopToggle.setAttribute('aria-expanded', String(!collapsed));
    desktopToggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    desktopIcon?.setAttribute('name', collapsed ? 'chevron-forward-outline' : 'chevron-back-outline');
    try { localStorage.setItem(LS_KEY, collapsed ? '1' : '0'); } catch(e){}
  }

  (function initDesktopSidebar() {
    if (!desktopSidebar || !desktopToggle) return;
    let collapsed = false;
    try { collapsed = localStorage.getItem(LS_KEY) === '1'; } catch(e){}
    setDesktopCollapsed(collapsed);

    desktopToggle.addEventListener('click', () => {
      const isCollapsed = desktopSidebar.classList.contains('collapsed');
      setDesktopCollapsed(!isCollapsed);
    });
  })();

  // Tiny ripple on click
  (function addRipple() {
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;
    const links = document.querySelectorAll('#desktopSidebar .nav-link, #studentSidebar .nav-link');
    links.forEach(link => {
      link.addEventListener('click', (e) => {
        const rect = link.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const span = document.createElement('span');
        span.className = 'ripple';
        span.style.left = x + 'px';
        span.style.top = y + 'px';
        span.style.width = span.style.height = Math.max(rect.width, rect.height) + 'px';
        link.appendChild(span);
        setTimeout(() => span.remove(), 600);
      }, { passive: true });
    });
  })();
</script>