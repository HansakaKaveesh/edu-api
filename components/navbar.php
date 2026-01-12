<?php
// components/navbar.php

if (session_status() === PHP_SESSION_NONE) session_start();

@include_once __DIR__ . '/../db_connect.php';

$isLoggedIn = isset($_SESSION['user_id']);
$role       = $_SESSION['role'] ?? null;
$userId     = (int)($_SESSION['user_id'] ?? 0);

$currentPath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$isHome = ($currentPath === '' || $currentPath === 'index.php');

function activeClass(bool $isActive, string $extra = '') {
  return $isActive ? "text-white bg-white/20 $extra" : "text-white/80 hover:text-white hover:bg-white/10 $extra";
}

$dashMap = [
  'student'     => 'student_dashboard.php',
  'teacher'     => 'teacher_dashboard.php',
  'admin'       => 'admin_dashboard.php',
  'accountant'  => 'accountant_dashboard.php',
  'ceo'         => '/edu-api/managment/ceo_dashboard.php',
  'cto'         => 'cto_dashboard.php',
  'coordinator' => 'coordinator_dashboard.php',
];
$dashboardLink = $dashMap[$role] ?? '';

$profileMap = [
  'student'     => 'student_settings.php',
  'teacher'     => 'teacher_profile.php',
  'admin'       => 'admin_profile.php',
  'accountant'  => 'accountant_profile.php',
  'ceo'         => '/edu-api/managment/ceo_settings.php',
  'cto'         => 'cto_profile.php',
  'coordinator' => 'coordinator_profile.php',
];

$settingsMap = [
  'student'     => 'student_settings.php',
  'teacher'     => 'teacher_settings.php',
  'admin'       => 'admin_settings.php',
  'accountant'  => 'accountant_settings.php',
  'ceo'         => '/edu-api/managment/ceo_settings.php',
  'cto'         => 'cto_settings.php',
  'coordinator' => 'coordinator_settings.php',
];

$profileLink  = $profileMap[$role]  ?? 'admin_profile.php';
$settingsLink = $settingsMap[$role] ?? 'admin_settings.php';

$displayName = 'Account';
if ($isLoggedIn) {
  $displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? ucfirst($role ?? 'User');

  if (isset($conn) && $conn instanceof mysqli) {
    $tbl = match($role) {
      'student' => 'students',
      'teacher' => 'teachers',
      'admin' => 'admins',
      'ceo' => 'ceo',
      'cto' => 'cto',
      'accountant' => 'accountants',
      'coordinator' => 'course_coordinators',
      default => null
    };

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

$initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $displayName), 0, 2, 'UTF-8') ?: 'U');

$profilePic = 'uploads/default.png';
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

// Role badge colors
$roleBadgeColors = [
  'student' => 'from-emerald-400 to-teal-500',
  'teacher' => 'from-blue-400 to-indigo-500',
  'admin' => 'from-purple-400 to-pink-500',
  'accountant' => 'from-amber-400 to-orange-500',
  'ceo' => 'from-rose-400 to-red-500',
  'cto' => 'from-cyan-400 to-blue-500',
  'coordinator' => 'from-violet-400 to-purple-500',
];
$roleBadge = $roleBadgeColors[$role] ?? 'from-gray-400 to-gray-500';
?>

<!-- Fonts & Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

<style>
  :root {
    --nav-height: 72px;
  }
  
  .nav-glass {
    background: linear-gradient(135deg, rgba(0, 49, 163, 0.94) 0%, rgba(0, 83, 216, 0.9) 100%);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
  }
  
  .nav-glass-scrolled {
    background: linear-gradient(135deg, rgba(0, 49, 163, 0.98) 0%, rgba(0, 83, 216, 0.95) 100%);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.05);
  }
  
  .nav-link {
    position: relative;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .nav-link::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 50%;
    width: 0;
    height: 2px;
    background: linear-gradient(90deg, #fbbf24, #f59e0b);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    transform: translateX(-50%);
    border-radius: 2px;
  }
  
  .nav-link:hover::after,
  .nav-link.active::after {
    width: 100%;
  }
  
  .nav-link.active {
    color: #fbbf24;
  }
  
  .glow-button {
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
  }
  
  .glow-button::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s ease;
  }
  
  .glow-button:hover::before {
    left: 100%;
  }
  
  .glow-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 40px rgba(251, 191, 36, 0.3);
  }
  
  .dropdown-menu {
    transform-origin: top right;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .dropdown-menu.hidden {
    opacity: 0;
    transform: scale(0.95) translateY(-10px);
    pointer-events: none;
  }
  
  .dropdown-menu.visible {
    opacity: 1;
    transform: scale(1) translateY(0);
    pointer-events: auto;
  }
  
  .menu-item {
    transition: all 0.2s ease;
  }
  
  .menu-item:hover {
    transform: translateX(4px);
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.1), transparent);
  }
  
  .avatar-ring {
    background: linear-gradient(135deg, #fbbf24, #f59e0b, #ea580c);
    padding: 2px;
  }
  
  .mobile-menu {
    transform-origin: top;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .mobile-menu.hidden {
    opacity: 0;
    transform: scaleY(0);
    max-height: 0;
  }
  
  .mobile-menu.visible {
    opacity: 1;
    transform: scaleY(1);
    max-height: 600px;
  }
  
  .hamburger-line {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .hamburger-active .line-1 {
    transform: translateY(8px) rotate(45deg);
  }
  
  .hamburger-active .line-2 {
    opacity: 0;
    transform: scaleX(0);
  }
  
  .hamburger-active .line-3 {
    transform: translateY(-8px) rotate(-45deg);
  }
  
  .floating-shapes {
    position: absolute;
    width: 100%;
    height: 100%;
    overflow: hidden;
    pointer-events: none;
  }
  
  .floating-shapes::before,
  .floating-shapes::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    opacity: 0.03;
  }
  
  .floating-shapes::before {
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, #fbbf24, transparent);
    top: -150px;
    right: -100px;
  }
  
  .floating-shapes::after {
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, #3b82f6, transparent);
    bottom: -100px;
    left: -50px;
  }
</style>

<nav id="siteNav"
     class="nav-glass fixed top-4 left-4 right-4 rounded-xl z-50 transition-all duration-500"
     role="navigation" 
     aria-label="Primary Navigation">
  
  <div class="floating-shapes"></div>
  
  <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between h-[72px]">
      
      <!-- Logo Section -->
      <a href="index.php" 
         class="group flex items-center gap-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-400 rounded-xl p-1 -m-1">
        <div class="flex flex-col">
          <span class="text-xl font-extrabold tracking-tight text-white group-hover:text-yellow-400 transition-colors">
            Synap<span class="text-yellow-400 group-hover:text-white transition-colors">Z</span>
          </span>
          <span class="text-[10px] font-medium text-white/40 tracking-widest uppercase">Learning Platform</span>
        </div>
      </a>

      <!-- Desktop Navigation -->
      <div class="hidden lg:flex items-center gap-1">
        <?php
        $navItems = [
          ['href' => 'index.php', 'icon' => 'home', 'label' => 'Home', 'active' => $isHome],
          ['href' => 'past_papers.php', 'icon' => 'document-text', 'label' => 'Past Papers', 'active' => $currentPath === 'past_papers.php'],
          ['href' => 'courseus.php', 'icon' => 'library', 'label' => 'Courses', 'active' => $currentPath === 'courseus.php'],
          ['href' => 'tutors.php', 'icon' => 'people', 'label' => 'Tutors', 'active' => $currentPath === 'tutors.php'],
          ['href' => '#contact', 'icon' => 'mail', 'label' => 'Contact', 'active' => false],
        ];
        
        foreach ($navItems as $item): ?>
          <a href="<?= $item['href'] ?>" 
             class="nav-link <?= $item['active'] ? 'active' : '' ?> flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-white/80 hover:text-white transition-all"
             <?= $item['active'] ? 'aria-current="page"' : '' ?>>
            <ion-icon name="<?= $item['icon'] ?>-outline" class="text-lg"></ion-icon>
            <span><?= $item['label'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Right Section -->
      <div class="flex items-center gap-3">
        
        <?php if ($isLoggedIn && $dashboardLink): ?>
          
          <!-- User Menu -->
          <div class="relative" id="userMenuWrapper">
            <button id="userMenuButton"
                    class="flex items-center gap-3 p-1.5 pr-3 rounded-2xl hover:bg-white/10 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-400"
                    aria-haspopup="menu" 
                    aria-expanded="false" 
                    aria-controls="userMenu">
              
              <!-- Avatar -->
              <div class="avatar-ring rounded-full">
                <?php if (!empty($profilePic) && $profilePic !== 'uploads/default.png'): ?>
                  <img src="<?= htmlspecialchars($profilePic, ENT_QUOTES, 'UTF-8') ?>" 
                       alt="Profile" 
                       class="w-9 h-9 rounded-full object-cover">
                <?php else: ?>
                  <div class="w-9 h-9 rounded-full bg-gradient-to-br from-slate-600 to-slate-700 flex items-center justify-center">
                    <span class="text-sm font-bold text-white"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                <?php endif; ?>
              </div>
              
              <!-- User Info (Desktop) -->
              <div class="hidden lg:flex flex-col items-start">
                <span class="text-sm font-semibold text-white truncate max-w-[120px]">
                  <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="text-xs text-white/50 capitalize"><?= htmlspecialchars($role ?? '', ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              
              <ion-icon name="chevron-down-outline" class="hidden lg:block text-white/50 text-sm transition-transform duration-300" id="userMenuChevron"></ion-icon>
            </button>

            <!-- Dropdown Menu (Light Theme) -->
            <div id="userMenu"
                 class="dropdown-menu hidden absolute right-0 mt-3 w-72 rounded-2xl shadow-2xl bg-white border border-gray-200 overflow-hidden"
                 role="menu" 
                 aria-labelledby="userMenuButton">
              
              <!-- User Header -->
              <div class="p-4 bg-gradient-to-br from-gray-50 to-gray-100 border-b border-gray-200">
                <div class="flex items-center gap-3">
                  <div class="avatar-ring rounded-xl p-0.5">
                    <?php if (!empty($profilePic) && $profilePic !== 'uploads/default.png'): ?>
                      <img src="<?= htmlspecialchars($profilePic, ENT_QUOTES, 'UTF-8') ?>" 
                           alt="Profile" 
                           class="w-12 h-12 rounded-xl object-cover">
                    <?php else: ?>
                      <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-600 to-slate-700 flex items-center justify-center">
                        <span class="text-lg font-bold text-white"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="font-semibold text-gray-900 truncate"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="flex items-center gap-2 mt-1">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gradient-to-r <?= $roleBadge ?> text-white capitalize">
                        <?= htmlspecialchars($role ?? '', ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Menu Items -->
              <div class="p-2 bg-white">
                <a href="<?= htmlspecialchars($dashboardLink) ?>" 
                   class="menu-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-gray-700 hover:text-gray-900 hover:bg-gray-50" 
                   role="menuitem">
                  <div class="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center">
                    <ion-icon name="speedometer-outline" class="text-blue-600 text-lg"></ion-icon>
                  </div>
                  <div>
                    <div class="text-sm font-medium">Dashboard</div>
                    <div class="text-xs text-gray-500">View your overview</div>
                  </div>
                </a>
                
                <a href="<?= htmlspecialchars($profileLink) ?>" 
                   class="menu-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-gray-700 hover:text-gray-900 hover:bg-gray-50" 
                   role="menuitem">
                  <div class="w-9 h-9 rounded-lg bg-purple-100 flex items-center justify-center">
                    <ion-icon name="person-outline" class="text-purple-600 text-lg"></ion-icon>
                  </div>
                  <div>
                    <div class="text-sm font-medium">Profile</div>
                    <div class="text-xs text-gray-500">Manage your info</div>
                  </div>
                </a>
                
                <a href="<?= htmlspecialchars($settingsLink) ?>" 
                   class="menu-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-gray-700 hover:text-gray-900 hover:bg-gray-50" 
                   role="menuitem">
                  <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center">
                    <ion-icon name="settings-outline" class="text-gray-600 text-lg"></ion-icon>
                  </div>
                  <div>
                    <div class="text-sm font-medium">Settings</div>
                    <div class="text-xs text-gray-500">Preferences & security</div>
                  </div>
                </a>
              </div>
              
              <!-- Logout -->
              <div class="p-2 border-t border-gray-200 bg-white">
                <a href="logout.php" 
                   class="menu-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-600 hover:bg-rose-50" 
                   role="menuitem">
                  <div class="w-9 h-9 rounded-lg bg-rose-100 flex items-center justify-center">
                    <ion-icon name="log-out-outline" class="text-rose-600 text-lg"></ion-icon>
                  </div>
                  <div class="text-sm font-medium">Sign Out</div>
                </a>
              </div>
            </div>
          </div>
          
        <?php else: ?>
          
          <!-- Auth Buttons -->
          <div class="hidden sm:flex items-center gap-2">
            <a href="login.php" 
               class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-white/90 hover:text-white rounded-xl hover:bg-white/10 transition-all">
              <ion-icon name="log-in-outline" class="text-lg"></ion-icon>
              <span>Sign In</span>
            </a>
            <a href="register.php" 
               class="glow-button flex items-center gap-2 px-5 py-2.5 text-sm font-semibold bg-gradient-to-r from-yellow-400 to-amber-500 text-slate-900 rounded-xl shadow-lg">
              <ion-icon name="person-add-outline" class="text-lg"></ion-icon>
              <span>Get Started</span>
            </a>
          </div>
          
        <?php endif; ?>

        <!-- Mobile Menu Toggle -->
        <button id="mobileToggle"
                class="lg:hidden flex flex-col items-center justify-center w-10 h-10 rounded-xl hover:bg-white/10 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-400"
                aria-label="Toggle menu" 
                aria-expanded="false" 
                aria-controls="mobileMenu">
          <span class="hamburger-line line-1 w-5 h-0.5 bg-white rounded-full"></span>
          <span class="hamburger-line line-2 w-5 h-0.5 bg-white rounded-full mt-1.5"></span>
          <span class="hamburger-line line-3 w-5 h-0.5 bg-white rounded-full mt-1.5"></span>
        </button>
      </div>
    </div>
  </div>

  <!-- Mobile Menu (Fixed Light Theme) -->
  <div id="mobileMenu"
       class="mobile-menu hidden lg:hidden bg-gradient-to-b from-blue-900 to-blue-800 border-t border-white/10 overflow-hidden"
       role="menu">
    <div class="max-w-7xl mx-auto px-4 py-4 space-y-2">
      
      <?php if ($isLoggedIn && $dashboardLink): ?>
        <!-- Mobile User Card -->
        <div class="flex items-center gap-3 p-4 rounded-2xl bg-white/10 mb-4">
          <div class="avatar-ring rounded-xl p-0.5">
            <?php if (!empty($profilePic) && $profilePic !== 'uploads/default.png'): ?>
              <img src="<?= htmlspecialchars($profilePic, ENT_QUOTES, 'UTF-8') ?>" 
                   alt="Profile" 
                   class="w-12 h-12 rounded-xl object-cover">
            <?php else: ?>
              <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-600 to-slate-700 flex items-center justify-center">
                <span class="text-lg font-bold text-white"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            <?php endif; ?>
          </div>
          <div>
            <div class="font-semibold text-white"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gradient-to-r <?= $roleBadge ?> text-white capitalize mt-1">
              <?= htmlspecialchars($role ?? '', ENT_QUOTES, 'UTF-8') ?>
            </span>
          </div>
        </div>
      <?php endif; ?>

      <!-- Navigation Links -->
      <?php foreach ($navItems as $index => $item): ?>
        <a href="<?= $item['href'] ?>" 
           class="flex items-center gap-3 px-4 py-3 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-all"
           role="menuitem"
           style="animation-delay: <?= $index * 50 ?>ms">
          <ion-icon name="<?= $item['icon'] ?>-outline" class="text-xl text-yellow-400/80"></ion-icon>
          <span class="font-medium"><?= $item['label'] ?></span>
          <?php if ($item['active']): ?>
            <span class="ml-auto w-2 h-2 rounded-full bg-yellow-400"></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>

      <div class="border-t border-white/10 my-3"></div>

      <?php if ($isLoggedIn && $dashboardLink): ?>
        <a href="<?= htmlspecialchars($dashboardLink) ?>" 
           class="flex items-center gap-3 px-4 py-3 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-all" 
           role="menuitem">
          <ion-icon name="speedometer-outline" class="text-xl text-blue-400"></ion-icon>
          <span class="font-medium">Dashboard</span>
        </a>
        <a href="<?= htmlspecialchars($profileLink) ?>" 
           class="flex items-center gap-3 px-4 py-3 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-all" 
           role="menuitem">
          <ion-icon name="person-outline" class="text-xl text-purple-400"></ion-icon>
          <span class="font-medium">Profile</span>
        </a>
        <a href="<?= htmlspecialchars($settingsLink) ?>" 
           class="flex items-center gap-3 px-4 py-3 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-all" 
           role="menuitem">
          <ion-icon name="settings-outline" class="text-xl text-gray-300"></ion-icon>
          <span class="font-medium">Settings</span>
        </a>
        <a href="logout.php" 
           class="flex items-center gap-3 px-4 py-3 rounded-xl text-rose-400 hover:text-rose-300 hover:bg-rose-500/10 transition-all" 
           role="menuitem">
          <ion-icon name="log-out-outline" class="text-xl"></ion-icon>
          <span class="font-medium">Sign Out</span>
        </a>
      <?php else: ?>
        <a href="login.php" 
           class="flex items-center gap-3 px-4 py-3 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-all" 
           role="menuitem">
          <ion-icon name="log-in-outline" class="text-xl text-yellow-400"></ion-icon>
          <span class="font-medium">Sign In</span>
        </a>
        <a href="register.php" 
           class="flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-gradient-to-r from-yellow-400 to-amber-500 text-slate-900 font-semibold mt-2" 
           role="menuitem">
          <ion-icon name="person-add-outline" class="text-xl"></ion-icon>
          <span>Get Started Free</span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Mobile Overlay -->
  <div id="navOverlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[-1] hidden lg:hidden opacity-0 transition-opacity duration-300"></div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const nav = document.getElementById('siteNav');
  const mobileToggle = document.getElementById('mobileToggle');
  const mobileMenu = document.getElementById('mobileMenu');
  const navOverlay = document.getElementById('navOverlay');
  const userMenuButton = document.getElementById('userMenuButton');
  const userMenu = document.getElementById('userMenu');
  const userMenuChevron = document.getElementById('userMenuChevron');

  let ticking = false;

  window.addEventListener('scroll', () => {
    if (!ticking) {
      requestAnimationFrame(() => {
        if (window.scrollY > 20) {
          nav.classList.add('nav-glass-scrolled');
        } else {
          nav.classList.remove('nav-glass-scrolled');
        }
        ticking = false;
      });
      ticking = true;
    }
  }, { passive: true });

  function openMobileMenu() {
    mobileMenu.classList.remove('hidden');
    navOverlay.classList.remove('hidden');
    mobileToggle.classList.add('hamburger-active');
    mobileToggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    
    requestAnimationFrame(() => {
      mobileMenu.classList.add('visible');
      navOverlay.classList.add('opacity-100');
    });
  }

  function closeMobileMenu() {
    mobileMenu.classList.remove('visible');
    navOverlay.classList.remove('opacity-100');
    mobileToggle.classList.remove('hamburger-active');
    mobileToggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    
    setTimeout(() => {
      mobileMenu.classList.add('hidden');
      navOverlay.classList.add('hidden');
    }, 300);
  }

  mobileToggle?.addEventListener('click', () => {
    if (mobileMenu.classList.contains('visible')) {
      closeMobileMenu();
    } else {
      openMobileMenu();
    }
  });

  navOverlay?.addEventListener('click', closeMobileMenu);

  mobileMenu?.addEventListener('click', (e) => {
    if (e.target.closest('a')) {
      closeMobileMenu();
    }
  });

  let userMenuOpen = false;

  function openUserMenu() {
    userMenu?.classList.remove('hidden');
    requestAnimationFrame(() => {
      userMenu?.classList.add('visible');
      if (userMenuChevron) userMenuChevron.style.transform = 'rotate(180deg)';
    });
    userMenuButton?.setAttribute('aria-expanded', 'true');
    userMenuOpen = true;
    document.addEventListener('click', handleOutsideClick);
    document.addEventListener('keydown', handleEscKey);
  }

  function closeUserMenu() {
    userMenu?.classList.remove('visible');
    if (userMenuChevron) userMenuChevron.style.transform = 'rotate(0)';
    userMenuButton?.setAttribute('aria-expanded', 'false');
    userMenuOpen = false;
    
    setTimeout(() => {
      userMenu?.classList.add('hidden');
    }, 200);
    
    document.removeEventListener('click', handleOutsideClick);
    document.removeEventListener('keydown', handleEscKey);
  }

  function handleOutsideClick(e) {
    if (userMenu && !userMenu.contains(e.target) && !userMenuButton?.contains(e.target)) {
      closeUserMenu();
    }
  }

  function handleEscKey(e) {
    if (e.key === 'Escape') {
      closeUserMenu();
      userMenuButton?.focus();
    }
  }

  userMenuButton?.addEventListener('click', (e) => {
    e.stopPropagation();
    if (userMenuOpen) {
      closeUserMenu();
    } else {
      openUserMenu();
    }
  });

  userMenu?.addEventListener('click', (e) => {
    if (e.target.closest('a')) {
      closeUserMenu();
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
      closeMobileMenu();
    }
  });

  const isHome = <?= json_encode($isHome) ?>;
  if (isHome) {
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('.nav-link');
    
    const observerOptions = {
      rootMargin: '-20% 0px -60% 0px',
      threshold: 0
    };

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === `#${entry.target.id}`) {
              link.classList.add('active');
            }
          });
        }
      });
    }, observerOptions);

    sections.forEach(section => observer.observe(section));
  }
});
</script>