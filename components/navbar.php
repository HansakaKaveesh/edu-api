<?php
// components/navbar.php (light-only, accessible, mobile drawer + user dropdown)

if (session_status() === PHP_SESSION_NONE) session_start();

// Optional DB (for display name). If you already include this globally, remove the line below.
@include_once __DIR__ . '/../db_connect.php';

$isLoggedIn = isset($_SESSION['user_id']);
$role       = $_SESSION['role'] ?? null;
$userId     = (int)($_SESSION['user_id'] ?? 0);

// Current path (for active link)
$currentPath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$isHome = ($currentPath === '' || $currentPath === 'index.php');

// Dashboard routes
$dashboardLink = $role === 'student' ? 'student_dashboard.php' :
                 ($role === 'teacher' ? 'teacher_dashboard.php' :
                 ($role === 'admin'   ? 'admin_dashboard.php'   : ''));

// Profile/Settings routes (adjust as needed)
$profileLink  = $role === 'student' ? 'student_settings.php'  : ($role === 'teacher' ? 'teacher_profile.php'  : 'admin_profile.php');
$settingsLink = $role === 'student' ? 'student_settings.php' : ($role === 'teacher' ? 'teacher_settings.php' : 'admin_settings.php');

// Active class helper
function activeClass(bool $isActive, string $extra = '') {
  return $isActive ? "text-yellow-300 $extra" : "hover:text-yellow-300 $extra";
}

// Display name + initials
$displayName = 'Account';
if ($isLoggedIn) {
  // Prefer cached session values if you set them elsewhere:
  $displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? ucfirst($role ?? 'User');

  // If DB available, try to fetch name from respective table (based on provided schema)
  if (isset($conn) && $conn instanceof mysqli) {
    $tbl = $role === 'student' ? 'students' : ($role === 'teacher' ? 'teachers' : ($role === 'admin' ? 'admins' : null));
    if ($tbl) {
      if ($stmt = $conn->prepare("SELECT first_name, last_name FROM {$tbl} WHERE user_id = ? LIMIT 1")) {
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
}
$initials = strtoupper(preg_replace('/[^A-Za-z]/', '', mb_substr($displayName, 0, 1, 'UTF-8') ?: 'U'));
?>
<nav id="siteNav"
     class="bg-blue-600/80 backdrop-blur-md fixed top-4 left-4 right-4 mx-auto rounded-xl z-50 text-white px-6 py-4 shadow-lg transition-colors duration-300 dark:bg-gray-900/80"
     role="navigation" aria-label="Primary Navigation">
  <div class="relative flex items-center justify-between max-w-7xl mx-auto">
    <!-- Logo -->
    <a href="index.php" class="text-2xl md:text-3xl font-extrabold tracking-tight hover:scale-105 transition-transform duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 rounded">
      Synap<span class="text-yellow-400">Z</span>
    </a>

    <!-- Hamburger (mobile) -->
    <div class="md:hidden flex items-center">
      <button id="mobileToggle"
              class="p-2 rounded-lg text-blue-700 hover:bg-blue-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
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
      <li>
        <a href="index.php"
           class="<?= activeClass($isHome) ?> flex items-center gap-1 transition"
           <?= $isHome ? 'aria-current="page"' : '' ?>>Home</a>
      </li>
      <li><a href="#about"   class="flex items-center gap-1 <?= activeClass(false) ?> transition" data-section-link="about">About Us</a></li>
      <li><a href="#courses" class="flex items-center gap-1 <?= activeClass(false) ?> transition" data-section-link="courses">Courses</a></li>
      <li><a href="#tutors"  class="flex items-center gap-1 <?= activeClass(false) ?> transition" data-section-link="tutors">Tutors</a></li>
      <li><a href="#contact" class="flex items-center gap-1 <?= activeClass(false) ?> transition" data-section-link="contact">Contact</a></li>
    </ul>

    <!-- Right Section (Desktop) -->
    <div class="hidden md:flex items-center space-x-3">
      <?php if ($isLoggedIn && $dashboardLink): ?>
        <!-- User dropdown -->
        <div class="relative" id="userMenuWrapper">
          <button id="userMenuButton"
                  class="inline-flex items-center gap-3 px-2 py-1 rounded-full hover:bg-yellow-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
                  aria-haspopup="menu" aria-expanded="false" aria-controls="userMenu">
            <div class="h-9 w-9 rounded-full bg-blue-600 text-white grid place-items-center font-bold ring-2 ring-blue-100">
              <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <span class="hidden lg:block text-sm font-semibold truncate max-w-[140px]"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
            <svg class="w-4 h-4 opacity-80 hidden lg:block" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M7 10l5 5 5-5z"></path>
            </svg>
          </button>

          <div id="userMenu"
               class="absolute right-0 mt-2 w-64 rounded-xl shadow-xl bg-white border border-gray-200 text-gray-800 hidden origin-top-right scale-95 opacity-0 transition-all duration-150"
               role="menu" aria-labelledby="userMenuButton">
            <div class="p-4 border-b border-gray-100 flex items-center gap-3">
              <div class="h-10 w-10 rounded-full bg-blue-600 text-white grid place-items-center font-bold">
                <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="min-w-0">
                <div class="font-semibold truncate"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-xs text-gray-500 capitalize"><?= htmlspecialchars($role ?? '', ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            </div>
            <div class="py-1">
              <a href="<?= htmlspecialchars($profileLink) ?>"  class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50" role="menuitem">Profile</a>
              <a href="<?= htmlspecialchars($settingsLink) ?>" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50" role="menuitem">Settings</a>
              <a href="<?= htmlspecialchars($dashboardLink) ?>" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50" role="menuitem">Dashboard</a>
            </div>
            <div class="py-1 border-t border-gray-100">
              <a href="logout.php" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50 text-red-600" role="menuitem">Logout</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <a href="login.php"
           class="px-5 py-2 bg-yellow-400 text-black font-semibold rounded-full shadow-md hover:bg-yellow-300 transition">
          Login
        </a>
        <a href="register.php"
           class="px-5 py-2 bg-white text-blue-700 font-semibold rounded-full shadow-md ring-1 ring-blue-200 hover:bg-blue-50 transition">
          Register
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Mobile Overlay -->
  <div id="navOverlay" class="inset-0 bg-black/35 z-40 hidden md:hidden"></div>

  <!-- Mobile Menu -->
  <div id="mobileMenu"
       class="hidden md:hidden mt-4 bg-white text-blue-900 rounded-xl shadow-xl p-4 space-y-2 origin-top transition-all duration-200 scale-95 opacity-0"
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

    <a href="index.php" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">Home</a>
    <a href="#about" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">About Us</a>
    <a href="#courses" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">Courses</a>
    <a href="#tutors" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">Tutors</a>
    <a href="#contact" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">Contact</a>

    <hr class="my-2 border-blue-100" />

    <?php if ($isLoggedIn && $dashboardLink): ?>
      <a href="<?= htmlspecialchars($dashboardLink) ?>" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">🚀 Dashboard</a>
      <a href="<?= htmlspecialchars($profileLink) ?>" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">👤 Profile</a>
      <a href="<?= htmlspecialchars($settingsLink) ?>" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">⚙️ Settings</a>
      <a href="logout.php" class="block px-3 py-2 rounded hover:bg-blue-50 text-red-600" role="menuitem">🚪 Logout</a>
    <?php else: ?>
      <a href="login.php" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">🔐 Sign In</a>
      <a href="register.php" class="block px-3 py-2 rounded hover:bg-blue-50" role="menuitem">📝 Register</a>
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

    // Scroll style: subtle ring when scrolled
    (function() {
      const nav = document.getElementById('siteNav');
      function onScroll() {
        if (window.scrollY > 8) {
          nav.classList.add('shadow-xl','ring-blue-100','bg-white/90');
        } else {
          nav.classList.remove('shadow-xl','ring-blue-100','bg-white/90');
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