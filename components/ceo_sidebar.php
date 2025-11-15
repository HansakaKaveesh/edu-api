<?php
// components/ceo_sidebar.php
// Reusable CEO sidebar with built-in helpers and a polished UI.
// Now ensures the signed-in user's name is displayed (pulls from session, then DB fallback).
//
// Optionally define before include:
//   - $navItems: array of items: ['label'=>string, 'href'=>string, 'icon'=>ionicon, 'badge'=>string|int, 'badgeClass'=>string]
//   - $keepQuery: array of GET keys to preserve on links (default: all)
//   - $sidebarId: string (default: 'sidebar')
//   - $toggleId: string (default: 'sbToggle')
//   - $sidebarTitle: string (default: 'Menu')
//   - $currentPath: string (default: derived from REQUEST_URI)
//   - $qs: string query suffix (default: derived from $_GET + $keepQuery)

// Fallback helpers
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sidebar_initials')) {
  function sidebar_initials(string $name, string $fallback='U'): string {
    $name = trim($name);
    if ($name === '') return $fallback;
    $parts = preg_split('/\s+/u', $name);
    $a = isset($parts[0]) ? mb_substr($parts[0], 0, 1, 'UTF-8') : '';
    $b = isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : '';
    $ini = strtoupper($a . $b);
    return $ini ?: $fallback;
  }
}

// IDs and title
$sidebarId    = isset($sidebarId)    ? (string)$sidebarId    : 'sidebar';
$toggleId     = isset($toggleId)     ? (string)$toggleId     : 'sbToggle';
$sidebarTitle = isset($sidebarTitle) ? (string)$sidebarTitle : 'Menu';

// Current path
if (!isset($currentPath) || $currentPath === null) {
  $currentPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
}

// Build query string to preserve filters (e.g. range/start/end/top)
if (!isset($qs)) {
  $query = $_GET ?? [];
  if (isset($keepQuery) && is_array($keepQuery) && $keepQuery) {
    $query = array_intersect_key($query, array_flip($keepQuery));
  }
  $qs = $query ? ('?' . http_build_query($query)) : '';
}

// Default nav (includes Payments)
if (!isset($navItems) || !is_array($navItems) || !$navItems) {
  $navItems = [
    ['label' => 'Dashboard',     'href' => 'ceo_dashboard.php' . $qs, 'icon' => 'grid-outline'],
    ['label' => 'Payments',      'href' => 'ceo_payments.php'  . $qs, 'icon' => 'card-outline'],
    ['label' => 'Teachers',      'href' => 'ceo_teachers.php'  . $qs, 'icon' => 'easel-outline'],
    ['label' => 'Students',      'href' => 'ceo_students.php'  . $qs, 'icon' => 'school-outline'],
    ['label' => 'Top Courses',   'href' => 'ceo_courses.php'   . $qs, 'icon' => 'trophy-outline'],
    ['label' => 'Settings',      'href' => 'ceo_settings.php',        'icon' => 'settings-outline'],
    ['label' => 'Logout',        'href' => 'logout.php',              'icon' => 'log-out-outline'],
  ];
}

// Session-derived profile
$displayName = '';
$roleBadge   = '';
$userId      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userRole    = isset($_SESSION['role']) ? (string)$_SESSION['role'] : '';

if (isset($_SESSION)) {
  $roleBadge   = ucfirst($userRole ?: '');
  $displayName = trim((string)($_SESSION['full_name'] ?? '')) ?: (string)($_SESSION['username'] ?? '');
}

// DB fallback to show real name (first_name + last_name) if displayName still empty
if ($displayName === '' && $userId > 0) {
  // Try to include DB if not already present
  @include_once __DIR__ . '/../db_connect.php';
  if (isset($conn) && $conn instanceof mysqli) {
    $table = null;
    if ($userRole === 'student') $table = 'students';
    elseif ($userRole === 'teacher') $table = 'teachers';
    elseif ($userRole === 'admin') $table = 'admins';
    elseif ($userRole === 'ceo') $table = 'ceo';
    elseif ($userRole === 'cto') $table = 'cto';

    if ($table) {
      if ($stmt = $conn->prepare("SELECT first_name, last_name FROM {$table} WHERE user_id = ? LIMIT 1")) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $full = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
          if ($full !== '') $displayName = $full;
        }
        $stmt->close();
      }
    }
  }
}

// Final fallback
if ($displayName === '') $displayName = 'User';

// Initials and avatar accent
$initials = sidebar_initials($displayName ?: $roleBadge ?: 'User');
$avatarAccent = 'from-blue-600 to-indigo-600';
?>
<!-- Mobile toggle -->
<button id="<?= e($toggleId) ?>"
        class="lg:hidden w-full inline-flex items-center justify-between px-4 py-2 rounded-lg bg-white ring-1 ring-gray-200 shadow-sm mb-3">
  <span class="font-semibold text-blue-900"><?= e($sidebarTitle) ?></span>
  <ion-icon name="chevron-down-outline"></ion-icon>
</button>

<!-- Sidebar -->
<nav id="<?= e($sidebarId) ?>"
     class="rounded-2xl bg-white/90 backdrop-blur ring-1 ring-gray-200 shadow-sm p-3 lg:p-4 hidden lg:block"
     role="navigation" aria-label="Sidebar">
  <!-- Mini profile tile -->
  <div class="relative overflow-hidden rounded-xl ring-1 ring-gray-200 bg-gradient-to-br from-slate-50 to-white p-3 mb-3">
    <div class="flex items-center gap-3">
      <span class="inline-flex h-10 w-10 items-center justify-center rounded-full text-white text-sm font-bold ring-2 ring-white shadow-sm bg-gradient-to-br <?= e($avatarAccent) ?>">
        <?= e($initials) ?>
      </span>
      <div class="min-w-0">
        <div class="font-semibold text-blue-900 truncate">
          <?= e($displayName) ?>
        </div>
        <?php if ($roleBadge): ?>
          <span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200 mt-0.5">
            <ion-icon name="shield-outline"></ion-icon> <?= e($roleBadge) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
    <div class="mt-3 flex gap-2">
      <a href="ceo_settings.php"
         class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs rounded-lg bg-blue-600 text-white hover:bg-blue-700">
        <ion-icon name="settings-outline"></ion-icon> Settings
      </a>
      <a href="logout.php"
         class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs rounded-lg bg-rose-50 text-rose-700 ring-1 ring-rose-200 hover:bg-rose-100">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>

  <!-- Nav list -->
  <ul class="space-y-1 text-sm">
    <?php foreach ($navItems as $item):
      $href     = (string)($item['href'] ?? '#');
      $icon     = (string)($item['icon'] ?? 'ellipse-outline');
      $label    = (string)($item['label'] ?? 'Link');
      $badge    = isset($item['badge']) ? (string)$item['badge'] : '';
      $badgeCls = (string)($item['badgeClass'] ?? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200');

      $isActive = ($currentPath === basename(parse_url($href, PHP_URL_PATH) ?: ''));
      $base     = 'group flex items-center justify-between gap-3 px-3 py-2 rounded-lg transition';
      $cls      = stripos($label, 'logout') !== false
                  ? ($isActive ? 'bg-rose-600 text-white' : 'text-rose-600 hover:bg-rose-50')
                  : ($isActive ? 'bg-blue-600 text-white shadow-sm' : 'text-blue-900 hover:bg-blue-50');
    ?>
      <li>
        <a href="<?= e($href) ?>" class="<?= e($base . ' ' . $cls) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
          <span class="inline-flex items-center gap-3 min-w-0">
            <ion-icon name="<?= e($icon) ?>" class="text-lg shrink-0"></ion-icon>
            <span class="font-medium truncate"><?= e($label) ?></span>
          </span>

          <span class="inline-flex items-center gap-2">
            <?php if ($badge !== ''): ?>
              <span class="inline-flex items-center h-5 px-2 rounded-full text-[11px] <?= e($badgeCls) ?>"><?= e($badge) ?></span>
            <?php endif; ?>
            <ion-icon name="chevron-forward-outline"
                      class="text-gray-400 transition-transform duration-200 group-hover:translate-x-0.5 <?= $isActive ? 'opacity-0' : 'opacity-70' ?>"></ion-icon>
          </span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>

<script>
  // Sidebar mobile toggle (scoped)
  (function(){
    const btn = document.getElementById('<?= e($toggleId) ?>');
    const sb  = document.getElementById('<?= e($sidebarId) ?>');
    if (!btn || !sb) return;
    btn.addEventListener('click', () => { sb.classList.toggle('hidden'); });
  })();
</script>