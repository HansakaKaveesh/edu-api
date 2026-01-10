<?php
// components/navbar.php

if (session_status() === PHP_SESSION_NONE) session_start();

// Optional DB (for display name). If you already include this globally, remove the line below.
@include_once __DIR__ . '/../db_connect.php';

$isLoggedIn = isset($_SESSION['user_id']);
$role       = $_SESSION['role'] ?? null;
$userId     = (int)($_SESSION['user_id'] ?? 0);

// Current path (for active link)
$currentPath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$isHome = ($currentPath === '' || $currentPath === 'index.php');

// Active class helper
function activeClass(bool $isActive, string $extra = '') {
  return $isActive ? "text-yellow-300 $extra" : "hover:text-yellow-300 $extra";
}

/* Dashboard routes (includes CEO, CTO, Coordinator) */
$dashMap = [
  'student'     => 'student_dashboard.php',
  'teacher'     => 'teacher_dashboard.php',
  'admin'       => 'admin_dashboard.php',
  'accountant'  => 'accountant_dashboard.php',
  'ceo'         => '/edu-api/managment/ceo_dashboard.php',
  'cto'         => 'cto_dashboard.php',
  'coordinator' => 'coordinator_dashboard.php', // NEW
];
$dashboardLink = $dashMap[$role] ?? '';

/* Profile/Settings routes per role (includes CEO/CTO/Coordinator) */
$profileMap = [
  'student'     => 'student_settings.php',
  'teacher'     => 'teacher_profile.php',
  'admin'       => 'admin_profile.php',
  'accountant'  => 'accountant_profile.php',
  'ceo'         => '/edu-api/managment/ceo_settings.php',
  'cto'         => 'cto_profile.php',
  'coordinator' => 'coordinator_profile.php', // NEW
];

$settingsMap = [
  'student'     => 'student_settings.php',
  'teacher'     => 'teacher_settings.php',
  'admin'       => 'admin_settings.php',
  'accountant'  => 'accountant_settings.php',
  'ceo'         => '/edu-api/managment/ceo_settings.php',
  'cto'         => 'cto_settings.php',
  'coordinator' => 'coordinator_settings.php', // NEW
];

$profileLink  = $profileMap[$role]  ?? 'admin_profile.php';
$settingsLink = $settingsMap[$role] ?? 'admin_settings.php';

/* Display name + initials */
$displayName = 'Account';
if ($isLoggedIn) {
  $displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? ucfirst($role ?? 'User');

  if (isset($conn) && $conn instanceof mysqli) {
    // Map role to the correct person-detail table (includes coordinator -> course_coordinators)
    $tbl = $role === 'student' ? 'students'
         : ($role === 'teacher' ? 'teachers'
         : ($role === 'admin'   ? 'admins'
         : ($role === 'ceo'     ? 'ceo'
         : ($role === 'cto'     ? 'cto'
         : ($role === 'accountant' ? 'accountants'
         : ($role === 'coordinator' ? 'course_coordinators' : null))))));

    if ($tbl && $stmt = $conn->prepare("SELECT first_name, last_name FROM {$tbl} WHERE user_id = ? LIMIT 1")) {
      $stmt->bind_param('i', $userId);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res && ($row = $res->fetch_assoc())) {
        $fn = trim((string)($row['first_name'] ?? ''));
        $ln = trim((string)($row['last_name']  ?? ''));
        $name = trim("$fn $ln");
        if ($name !== '') $displayName = $name;
      }
      $stmt->close();
    }
  }
}

$initials = strtoupper(preg_replace('/[^A-Za-z]/', '', mb_substr($displayName, 0, 1, 'UTF-8') ?: 'U'));

/* Profile Picture */
$profilePic = 'uploads/default.png'; // default
if ($isLoggedIn && isset($conn) && $conn instanceof mysqli) {
  $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE user_id = ? LIMIT 1");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && ($row = $res->fetch_assoc())) {
    if (!empty($row['profile_pic']) && file_exists(__DIR__ . '/../' . $row['profile_pic'])) {
      $profilePic = $row['profile_pic'];
    }
  }
  $stmt->close();
}
?>

<!-- Ionicons (icons) -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

<nav id="siteNav"
     class="bg-blue-900/80 backdrop-blur-md fixed top-4 left-4 right-4 mx-auto rounded-xl z-50 text-white px-6 py-3.5 shadow-lg transition-colors duration-300"
     role="navigation" aria-label="Primary Navigation">
  <div class="relative flex items-center justify-between max-w-7xl mx-auto">
    <!-- Logo -->
    <a href="index.php" class="inline-flex items-center gap-2 text-2xl md:text-3xl font-extrabold tracking-tight hover:scale-105 transition-transform duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 rounded">
      Synap<span class="text-yellow-400">Z</span>
    </a>

    <!-- Hamburger (mobile) -->
    <div class="md:hidden flex items-center">
      <button id="mobileToggle"
              class="p-2 rounded-lg text-white/90 hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
              aria-label="Toggle menu" aria-expanded="false" aria-controls="mobileMenu">
        <svg id="hamburgerIcon" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        <svg id="closeIcon" class="w-6 h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Desktop Nav -->
    <ul class="hidden md:flex items-center space-x-6 font-medium text-[15px]">
      <li><a href="index.php" class="<?= activeClass($isHome) ?> flex items-center gap-2 transition" <?= $isHome ? 'aria-current="page"' : '' ?>> <ion-icon name="home-outline" class="text-yellow-300/80"></ion-icon> Home</a></li>
      <li><a href="past_papers.php" class="flex items-center gap-2 <?= activeClass(false) ?>"><ion-icon name="information-circle-outline" class="text-yellow-300/80"></ion-icon> Past Papers</a></li>
      <li><a href="courseus.php" class="flex items-center gap-2 <?= activeClass(false) ?>"><ion-icon name="library-outline" class="text-yellow-300/80"></ion-icon> Courses</a></li>
      <li><a href="tutors.php" class="flex items-center gap-2 <?= activeClass(false) ?>"><ion-icon name="people-outline" class="text-yellow-300/80"></ion-icon> Tutors</a></li>
      <li><a href="#contact" class="flex items-center gap-2 <?= activeClass(false) ?>"><ion-icon name="mail-outline" class="text-yellow-300/80"></ion-icon> Contact</a></li>
    </ul>

    <!-- Right Section (Desktop) -->
    <div class="hidden md:flex items-center space-x-3">
      <?php if ($isLoggedIn && $dashboardLink): ?>
        <!-- User dropdown -->
        <div class="relative" id="userMenuWrapper">
          <button id="userMenuButton"
                  class="inline-flex items-center gap-3 px-2 py-1 rounded-full hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
                  aria-haspopup="menu" aria-expanded="false" aria-controls="userMenu">
            <?php if (!empty($profilePic) && $profilePic !== 'uploads/default.png'): ?>
              <img src="<?= htmlspecialchars($profilePic, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" class="h-9 w-9 rounded-full object-cover ring-2 ring-blue-100">
            <?php else: ?>
              <div class="h-9 w-9 rounded-full bg-blue-600 text-white grid place-items-center font-bold ring-2 ring-blue-100"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <span class="hidden lg:block text-sm font-semibold truncate max-w-[140px]"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
            <ion-icon name="chevron-down-outline" class="hidden lg:block"></ion-icon>
          </button>

          <div id="userMenu"
               class="absolute right-0 mt-2 w-64 rounded-xl shadow-xl bg-white border border-gray-200 text-gray-800 hidden origin-top-right scale-95 opacity-0 transition-all duration-150"
               role="menu" aria-labelledby="userMenuButton">
            <div class="p-4 border-b border-gray-100 flex items-center gap-3">
              <?php if (!empty($profilePic) && $profilePic !== 'uploads/default.png'): ?>
                <img src="<?= htmlspecialchars($profilePic, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" class="h-10 w-10 rounded-full object-cover">
              <?php else: ?>
                <div class="h-10 w-10 rounded-full bg-blue-600 text-white grid place-items-center font-bold"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
              <div class="min-w-0">
                <div class="font-semibold truncate"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-xs text-gray-500 capitalize"><?= htmlspecialchars($role ?? '', ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            </div>
            <div class="py-1">
              <a href="<?= htmlspecialchars($profileLink) ?>"  class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50" role="menuitem"><ion-icon name="person-circle-outline" class="text-slate-600"></ion-icon> Profile</a>
              <a href="<?= htmlspecialchars($settingsLink) ?>" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50" role="menuitem"><ion-icon name="settings-outline" class="text-slate-600"></ion-icon> Settings</a>
              <a href="<?= htmlspecialchars($dashboardLink) ?>" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50" role="menuitem"><ion-icon name="speedometer-outline" class="text-slate-600"></ion-icon> Dashboard</a>
            </div>
            <div class="py-1 border-t border-gray-100">
              <a href="logout.php" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50 text-red-600" role="menuitem"><ion-icon name="log-out-outline"></ion-icon> Logout</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <a href="login.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-yellow-400 text-black font-semibold rounded-lg shadow-md hover:bg-yellow-300 transition"><ion-icon name="log-in-outline" class="text-base"></ion-icon> Login</a>
        <a href="register.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white text-blue-700 font-semibold rounded-lg shadow-md ring-1 ring-blue-200 hover:bg-blue-50 transition"><ion-icon name="person-add-outline" class="text-base"></ion-icon> Register</a>
      <?php endif; ?>
    </div>
  </div>
  <!-- Mobile Overlay -->
  <div id="navOverlay" class="inset-0 bg-black/35 z-40 hidden md:hidden"></div>

  <!-- Mobile Menu -->
  <div id="mobileMenu"
       class="hidden md:hidden mt-3 bg-white text-blue-900 rounded-xl shadow-xl p-3 space-y-1 origin-top transition-all duration-200 scale-95 opacity-0"
       role="menu" aria-labelledby="mobileToggle">
    <?php if ($isLoggedIn && $dashboardLink): ?>
      <div class="flex items-center gap-3 p-3 rounded-lg bg-blue-50 mb-2">
        <div class="h-10 w-10 rounded-full bg-blue-600 text-white grid place-items-center font-bold">
          <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div>
          <div class="font-semibold"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-xs text-blue-700 capitalize"><?= htmlspecialchars($role ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
    <?php endif; ?>

    <a href="index.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
      <ion-icon name="home-outline"></ion-icon> Home
    </a>
    <a href="past_papers.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
      <ion-icon name="information-circle-outline"></ion-icon> Past Papers
    </a>
    <a href="courses.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
      <ion-icon name="library-outline"></ion-icon> Courses
    </a>
    <a href="tutors.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
      <ion-icon name="people-outline"></ion-icon> Tutors
    </a>
    <a href="#contact" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
      <ion-icon name="mail-outline"></ion-icon> Contact
    </a>

    <hr class="my-2 border-blue-100" />

    <?php if ($isLoggedIn && $dashboardLink): ?>
      <a href="<?= htmlspecialchars($dashboardLink) ?>" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
        <ion-icon name="rocket-outline"></ion-icon> Dashboard
      </a>
      <a href="<?= htmlspecialchars($profileLink) ?>" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
        <ion-icon name="person-circle-outline"></ion-icon> Profile
      </a>
      <a href="<?= htmlspecialchars($settingsLink) ?>" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
        <ion-icon name="settings-outline"></ion-icon> Settings
      </a>
      <a href="logout.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50 text-red-600" role="menuitem">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    <?php else: ?>
      <a href="login.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
        <ion-icon name="log-in-outline"></ion-icon> Sign In
      </a>
      <a href="register.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-blue-50" role="menuitem">
        <ion-icon name="person-add-outline"></ion-icon> Register
      </a>
    <?php endif; ?>
  </div>

  <!-- Scripts -->
  <script>
    // Mobile menu
    (function() {
      const toggle = document.getElementById('mobileToggle');
      const menu = document.getElementById('mobileMenu');
      const overlay = document.getElementById('navOverlay');
      const burger = document.getElementById('hamburgerIcon');
      const closeI = document.getElementById('closeIcon');
      let prevFocus = null;

      function openMenu() {
        prevFocus = document.activeElement;
        menu.classList.remove('hidden');
        overlay.classList.remove('hidden');
        requestAnimationFrame(() => {
          menu.classList.remove('scale-95','opacity-0');
          menu.classList.add('scale-100','opacity-100');
        });
        burger.classList.add('hidden');
        closeI.classList.remove('hidden');
        toggle.setAttribute('aria-expanded','true');
        document.body.style.overflow = 'hidden';
        const first = menu.querySelector('a,button');
        first && first.focus();
        document.addEventListener('keydown', onKeydown);
        overlay.addEventListener('click', closeMenu, { once: true });
      }
      function closeMenu() {
        menu.classList.add('scale-95','opacity-0');
        menu.classList.remove('scale-100','opacity-100');
        setTimeout(() => menu.classList.add('hidden'), 150);
        overlay.classList.add('hidden');
        burger.classList.remove('hidden');
        closeI.classList.add('hidden');
        toggle.setAttribute('aria-expanded','false');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKeydown);
        prevFocus && prevFocus.focus && prevFocus.focus();
      }
      function onKeydown(e) { if (e.key === 'Escape') { e.preventDefault(); closeMenu(); } }

      toggle?.addEventListener('click', () => menu.classList.contains('hidden') ? openMenu() : closeMenu());
      menu.addEventListener('click', (e) => { if (e.target.closest('a,button')) closeMenu(); });
      window.addEventListener('resize', () => { if (window.innerWidth >= 768) closeMenu(); });
    })();

    // User dropdown
    (function() {
      const btn = document.getElementById('userMenuButton');
      const menu = document.getElementById('userMenu');
      if (!btn || !menu) return;
      let open = false;
      function show() {
        menu.classList.remove('hidden');
        requestAnimationFrame(() => {
          menu.classList.remove('scale-95','opacity-0');
          menu.classList.add('scale-100','opacity-100');
        });
        btn.setAttribute('aria-expanded','true');
        open = true;
        document.addEventListener('click', onDocClick);
        document.addEventListener('keydown', onKey);
      }
      function hide() {
        menu.classList.add('scale-95','opacity-0');
        menu.classList.remove('scale-100','opacity-100');
        setTimeout(() => menu.classList.add('hidden'), 120);
        btn.setAttribute('aria-expanded','false');
        open = false;
        document.removeEventListener('click', onDocClick);
        document.removeEventListener('keydown', onKey);
      }
      function onDocClick(e) { if (!menu.contains(e.target) && !btn.contains(e.target)) hide(); }
      function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); hide(); btn.focus(); } }
      btn.addEventListener('click', () => open ? hide() : show());
      menu.addEventListener('click', (e) => { if (e.target.closest('a')) hide(); });
    })();

    // Scroll style: add subtle ring & stronger shadow on scroll
    (function() {
      const nav = document.getElementById('siteNav');
      function onScroll() {
        if (window.scrollY > 8) {
          nav.classList.add('shadow-xl','ring-1','ring-blue-300/40');
        } else {
          nav.classList.remove('shadow-xl','ring-1','ring-blue-300/40');
        }
      }
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
    })();

    // Highlight in-page sections (homepage only)
    (function() {
      const isHome = <?= json_encode($isHome) ?>;
      if (!isHome) return;
      const links = document.querySelectorAll('[data-section-link]');
      if (!links.length) return;

      const sectionMap = {};
      links.forEach(link => {
        const id = link.getAttribute('data-section-link');
        const el = document.getElementById(id);
        if (el) sectionMap[id] = { link, el };
      });

      function setActive(id) {
        links.forEach(l => l.classList.remove('text-yellow-300'));
        if (sectionMap[id]) sectionMap[id].link.classList.add('text-yellow-300');
      }

      const obs = new IntersectionObserver((entries) => {
        entries.forEach(entry => { if (entry.isIntersecting) setActive(entry.target.id); });
      }, { rootMargin: '-40% 0px -55% 0px', threshold: 0.01 });

      Object.values(sectionMap).forEach(({ el }) => obs.observe(el));
    })();
  </script>
</nav>