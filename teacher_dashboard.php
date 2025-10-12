<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'teacher')) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/* helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function excerpt($text, $limit = 200) {
    $t = trim(strip_tags((string)$text));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($t) <= $limit ? $t : mb_substr($t, 0, $limit) . '…';
    }
    return strlen($t) <= $limit ? $t : substr($t, 0, $limit) . '…';
}

/**
 * Check if a table has a column using INFORMATION_SCHEMA (works with prepared statements).
 */
function table_has_col(mysqli $conn, string $table, string $col): bool {
    $sql = "
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
      LIMIT 1
    ";
    if (!$stmt = $conn->prepare($sql)) return false;
    $stmt->bind_param("ss", $table, $col);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok  = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

/* fetch teacher record with name (prepared) */
$teacher_name = 'Teacher';
$teacher_id   = 0;

if ($stmt = $conn->prepare("SELECT teacher_id, first_name, last_name FROM teachers WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $teacher_id   = (int)$row['teacher_id'];
        $teacher_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Teacher';
    }
    $stmt->close();
}

/* stats (prepared queries) */
$course_count = 0;
$contents_count = 0;
$announcements_count = 0;
/** @var mysqli_result|null $courses */
$courses = null;

if ($teacher_id > 0) {
    // Courses list
    if ($stmt = $conn->prepare("
        SELECT c.course_id, c.name
        FROM teacher_courses tc
        JOIN courses c ON tc.course_id = c.course_id
        WHERE tc.teacher_id = ?
        ORDER BY c.name
    ")) {
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $courses = $stmt->get_result(); // keep result for later iteration
        $course_count = $courses ? $courses->num_rows : 0;
        $stmt->close();
    }

    // Contents count
    if ($stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM contents 
        WHERE course_id IN (SELECT course_id FROM teacher_courses WHERE teacher_id = ?)
    ")) {
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $contents_count = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }
}

// Announcements count (tolerate missing 'audience' column)
if (table_has_col($conn, 'announcements', 'audience')) {
    if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM announcements WHERE audience IN ('teachers','all')")) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $announcements_count = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }
} else {
    if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM announcements")) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $announcements_count = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }
}

/* Fetch latest announcements with robust column aliasing */
$announcements = [];
$pkCol    = table_has_col($conn, 'announcements', 'announcement_id') ? 'announcement_id'
          : (table_has_col($conn, 'announcements', 'id') ? 'id' : null);
$titleCol = table_has_col($conn, 'announcements', 'title') ? 'title' : null;
$msgCol   = table_has_col($conn, 'announcements', 'message') ? 'message'
          : (table_has_col($conn, 'announcements', 'body') ? 'body' : null);
$timeCol  = table_has_col($conn, 'announcements', 'posted_at') ? 'posted_at'
          : (table_has_col($conn, 'announcements', 'created_at') ? 'created_at' : null);
$audCol   = table_has_col($conn, 'announcements', 'audience') ? 'audience' : null;

if ($pkCol && $titleCol && $msgCol) {
    $where = $audCol ? "WHERE `$audCol` IN ('teachers','all')" : "";
    $timeSelect = $timeCol ? "`$timeCol`" : "NOW()";
    $orderBy = $timeCol ? "`$timeCol` DESC" : "`$pkCol` DESC";

    $sql = "
        SELECT
          `$pkCol`   AS announcement_id,
          `$titleCol` AS title,
          `$msgCol`   AS message,
          $timeSelect AS posted_at
          " . ($audCol ? ", `$audCol` AS audience" : "") . "
        FROM announcements
        $where
        ORDER BY $orderBy
        LIMIT 10
    ";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $announcements[] = $row;
        $stmt->close();
    }
}

/* greeting */
$hr = (int)date('G');
$greet = ($hr < 12) ? 'Good morning' : (($hr < 18) ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <title>Teacher Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: { 50:'#eef2ff', 100:'#e0e7ff', 200:'#c7d2fe', 300:'#a5b4fc', 400:'#818cf8', 500:'#6366f1', 600:'#4f46e5', 700:'#4338ca', 800:'#3730a3', 900:'#312e81' }
          },
          backgroundImage: {
            grid: 'linear-gradient(to right, rgba(148,163,184,.08) 1px, transparent 1px), linear-gradient(to bottom, rgba(148,163,184,.08) 1px, transparent 1px)'
          },
          backgroundSize: { grid: '22px 22px' },
          boxShadow: { glow: '0 0 0 3px rgba(99,102,241,.15), 0 20px 40px rgba(2,6,23,.10)' },
          keyframes: { 'fade-in-up': { '0%': { opacity: 0, transform: 'translateY(8px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } } },
          animation: { 'fade-in-up': 'fade-in-up .6s ease-out both' }
        }
      }
    }
  </script>
  <!-- Phosphor icons -->
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI'; }
    .hover-raise { transition: .2s ease; }
    .hover-raise:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(2,6,23,0.10); }
    html { scroll-behavior: smooth; }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50 text-slate-900 min-h-screen">
<?php include 'components/navbar.php'; ?>

<!-- Content Grid -->
<div class="max-w-8xl mx-auto px-6 mt-24 relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-y-8 lg:gap-x-10 mb-24">
  
  <!-- Sidebar (desktop only) -->
  <aside class="hidden lg:block lg:col-span-3">
    <?php
      $active = 'dashboard';
      include __DIR__ . '/components/teacher_sidebar.php';
    ?>
  </aside>

  <!-- Main column -->
  <main class="lg:col-span-9 space-y-14 mt-2">
<!-- Compact Hero -->
<section id="dashboard" class="relative overflow-hidden rounded-xl ring-1 ring-indigo-100 shadow-sm">
  <!-- Background image + tint -->
  <div aria-hidden="true" class="absolute inset-0 -z-10">
    <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1513258496099-48168024aec0?q=80&w=1600&auto=format&fit=crop')] bg-cover bg-center"></div>
    <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/90 via-blue-900/80 to-sky-900/80"></div>
    <div class="absolute inset-0 opacity-30 [mask-image:radial-gradient(ellipse_80%_60%_at_50%_0%,black,transparent)] bg-grid bg-[length:22px_22px]"></div>
  </div>

  <div class="max-w-7xl mx-auto px-5 pt-12 pb-8">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-white flex items-center gap-2">
          <i class="ph ph-chalkboard-teacher ph-bold"></i>
          <?= h($greet) ?>, <span class="underline decoration-white/30 underline-offset-4"><?= h($teacher_name) ?></span>
        </h1>
        <p class="mt-1 text-white/90 max-w-2xl text-sm">
          Manage your courses, publish content, and keep students inspired.
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
          <a href="#courses" class="inline-flex items-center gap-2 bg-white/20 hover:bg-white/30 text-white px-3 py-2 rounded-lg font-medium text-sm transition">
            <i class="ph ph-arrow-down"></i> Go to Courses
          </a>
          <a href="create_course.php" class="inline-flex items-center gap-2 bg-white text-indigo-700 px-3 py-2 rounded-lg font-semibold text-sm shadow hover:shadow-md transition">
            <i class="ph ph-plus-circle"></i> Create Course
          </a>
          <a href="teacher_sidebar.php?active=dashboard"
             class="lg:hidden inline-flex items-center gap-2 bg-black/30 text-white px-3 py-2 rounded-lg border border-white/20 hover:bg-black/40 text-sm transition">
            <i class="ph ph-list"></i> Menu
          </a>
        </div>
      </div>
      <div class="hidden md:block text-white/90 text-xs">
        <div class="rounded-lg border border-white/20 bg-white/10 backdrop-blur px-3 py-2">
          <?= date('l, d M Y') ?><br>
          <span class="text-white/70" id="clockTime"><?= date('h:i A') ?></span>
        </div>
      </div>
    </div>

    <!-- Compact Stats -->
    <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div class="rounded-xl border border-white/20 bg-white/10 backdrop-blur p-4 text-white">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-lg bg-white/20 text-white flex items-center justify-center text-xl">
            <i class="ph ph-books"></i>
          </div>
          <div>
            <p class="text-xs text-white/80">Courses</p>
            <p class="text-2xl font-extrabold leading-snug"><?= (int)$course_count ?></p>
          </div>
        </div>
      </div>
      <div class="rounded-xl border border-white/20 bg-white/10 backdrop-blur p-4 text-white">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-lg bg-white/20 text-white flex items-center justify-center text-xl">
            <i class="ph ph-folders"></i>
          </div>
          <div>
            <p class="text-xs text-white/80">Total Contents</p>
            <p class="text-2xl font-extrabold leading-snug"><?= (int)$contents_count ?></p>
          </div>
        </div>
      </div>
      <div class="rounded-xl border border-white/20 bg-white/10 backdrop-blur p-4 text-white">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-lg bg-white/20 text-white flex items-center justify-center text-xl">
            <i class="ph ph-megaphone"></i>
          </div>
          <div>
            <p class="text-xs text-white/80">Announcements</p>
            <p class="text-2xl font-extrabold leading-snug"><?= (int)$announcements_count ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

    <!-- Announcements -->
    <section id="announcements" class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-sm p-6">
      <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
          <h2 class="text-2xl font-extrabold flex items-center gap-2">
            <i class="ph ph-megaphone-simple text-indigo-600"></i> Announcements
          </h2>
        </div>
        <a href="teacher_announcements.php" class="inline-flex items-center gap-1.5 text-sm text-indigo-700 hover:underline">
          View all <i class="ph ph-caret-right"></i>
        </a>
      </div>
      <div class="h-0.5 w-full bg-gradient-to-r from-indigo-100 via-slate-100 to-transparent mb-4"></div>

      <?php if (!empty($announcements)): ?>
        <ul class="divide-y divide-slate-200">
          <?php foreach ($announcements as $an): 
            $when = !empty($an['posted_at']) ? date('M d, Y • h:i A', strtotime($an['posted_at'])) : '';
          ?>
            <li class="py-4">
              <div class="flex items-start gap-3">
                <div class="mt-0.5 h-9 w-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                  <i class="ph ph-megaphone"></i>
                </div>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center justify-between gap-3">
                    <h3 class="font-semibold text-slate-900 truncate">
                      <?= h($an['title'] ?? 'Untitled') ?>
                    </h3>
                    <?php if ($when): ?>
                      <span class="shrink-0 text-xs text-slate-500"><?= h($when) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($an['message'])): ?>
                    <p class="mt-1 text-sm text-slate-700">
                      <?= h(excerpt($an['message'], 220)) ?>
                    </p>
                  <?php endif; ?>
                  <div class="mt-2">
                    <a href="teacher_announcements.php?id=<?= (int)($an['announcement_id'] ?? 0) ?>"
                       class="inline-flex items-center gap-1 text-indigo-700 hover:underline text-sm">
                      Read more <i class="ph ph-caret-right"></i>
                    </a>
                  </div>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="text-center py-10">
          <div class="mx-auto w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-slate-500 mb-3">
            <i class="ph ph-bell-simple"></i>
          </div>
          <p class="text-slate-600">No announcements yet.</p>
        </div>
      <?php endif; ?>
    </section>

    <!-- Courses header -->
    <section id="courses" class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-sm p-6">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
        <div class="flex items-center gap-2">
          <h2 class="text-2xl md:text-3xl font-extrabold flex items-center gap-2">
            <i class="ph ph-bookmarks-simple text-indigo-600"></i> Your Courses
          </h2>
        </div>

        <div class="flex items-center gap-3">
          <div class="relative">
            <input id="courseSearch" type="text" placeholder="Search courses..."
                   class="w-64 pl-9 pr-3 py-2 rounded-lg border-slate-300 bg-white focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 text-sm">
            <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
          </div>

          <!-- Sort and density controls -->
          <div class="hidden md:flex items-center gap-2">
            <button id="sortAZ" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm">
              <i class="ph ph-sort-ascending"></i> A–Z
            </button>
            <button id="sortZA" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm">
              <i class="ph ph-sort-descending"></i> Z–A
            </button>
            <button id="densityToggle" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-sm">
              <i class="ph ph-arrows-in-line-horizontal"></i> Compact
            </button>
          </div>

          <a href="create_course.php"
             class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg shadow">
            <i class="ph ph-plus-circle"></i> Create New
          </a>
          <a href="logout.php"
             class="inline-flex items-center gap-2 bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-lg shadow">
             <i class="ph ph-sign-out"></i> Logout
          </a>
        </div>
      </div>
      <div class="h-0.5 w-full bg-gradient-to-r from-indigo-100 via-slate-100 to-transparent mb-6"></div>

      <?php if ($course_count > 0 && $courses): ?>
        <div id="coursesGrid" class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <?php while ($course = $courses->fetch_assoc()): ?>
            <div class="group rounded-2xl ring-1 ring-slate-200 bg-white shadow-sm hover:shadow-md hover:ring-indigo-100 transition overflow-hidden"
                 data-course-id="<?= (int)$course['course_id'] ?>">
              <!-- Accent stripe -->
              <div class="h-1.5 w-full bg-gradient-to-r from-indigo-500 via-sky-500 to-emerald-500"></div>
              <div class="p-6">
                <h3 class="text-xl font-bold text-slate-900 mb-1 truncate"
                    data-name="<?= h(strtolower($course['name'])) ?>"
                    data-id="<?= (int)$course['course_id'] ?>">
                  <?= h($course['name']) ?>
                </h3>
                <p class="text-xs text-slate-500 mb-4">Course ID: <?= (int)$course['course_id'] ?></p>
                <div class="flex flex-wrap gap-3">
                  <a href="teacher_course_manage.php?course_id=<?= (int)$course['course_id'] ?>"
                     class="inline-flex items-center gap-2 bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition text-sm font-semibold">
                    <i class="ph ph-gear"></i> Manage
                  </a>
                  <a href="teacher_view_content.php?course_id=<?= (int)$course['course_id'] ?>"
                     class="inline-flex items-center gap-2 bg-slate-100 text-slate-700 px-4 py-2 rounded-lg hover:bg-slate-200 transition text-sm">
                    <i class="ph ph-eye"></i> View Content
                  </a>
                  <button type="button"
                          class="copyLink inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 px-3 py-2 rounded-lg hover:bg-slate-50 transition text-sm"
                          data-course-id="<?= (int)$course['course_id'] ?>">
                    <i class="ph ph-link-simple"></i> Copy link
                  </button>
                </div>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="text-center py-14">
          <div class="mx-auto w-16 h-16 rounded-2xl bg-indigo-50 flex items-center justify-center text-indigo-600 mb-4 text-2xl">
            <i class="ph ph-cube"></i>
          </div>
          <h3 class="text-xl font-semibold"><?= $teacher_id ? 'No Courses Yet' : 'No Teacher Profile Found' ?></h3>
          <p class="text-slate-600 mt-1">
            <?= $teacher_id ? 'Create your first course to get started.' : 'Please contact an administrator to set up your teacher profile.' ?>
          </p>
          <?php if ($teacher_id): ?>
          <a href="create_course.php" class="inline-flex items-center gap-2 mt-5 bg-indigo-600 text-white px-6 py-2.5 rounded-lg shadow hover:bg-indigo-700 transition">
            <i class="ph ph-plus-circle"></i> Create Course
          </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>

<!-- Floating quick-create (mobile) -->
<?php if ($teacher_id): ?>
<a href="create_course.php"
   class="fixed bottom-6 right-6 lg:hidden inline-flex items-center justify-center h-12 w-12 rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700" aria-label="Create course">
  <i class="ph ph-plus"></i>
</a>
<?php endif; ?>

<!-- Toast -->
<div id="toast" class="fixed bottom-6 right-6 hidden items-center gap-2 px-4 py-2 rounded-lg shadow-lg
  bg-slate-900 text-white z-50 transition-opacity duration-300" role="status" aria-live="polite">
  <i class="ph ph-check-circle"></i>
  <span id="toastMsg">Copied!</span>
</div>

<script>
  // Live clock (updates every 15s)
  (function(){
    const el = document.getElementById('clockTime');
    if (!el) return;
    function tick() {
      const now = new Date();
      el.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    tick();
    setInterval(tick, 15000);
  })();

  // Course utilities: search (name/id), sort, compact density, copy link
  (function(){
    const input = document.getElementById('courseSearch');
    const grid = document.getElementById('coursesGrid');
    const sortAZBtn = document.getElementById('sortAZ');
    const sortZABtn = document.getElementById('sortZA');
    const densityToggle = document.getElementById('densityToggle');
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toastMsg');

    if (!grid) return;
    const getCards = () => Array.from(grid.children);

    function normalize(s) {
      return (s || '').toString().trim().toLowerCase();
    }

    // Filter by name or id
    function filter() {
      const q = normalize(input?.value || '');
      getCards().forEach(card => {
        const title = card.querySelector('[data-name]');
        const name = title?.getAttribute('data-name') || '';
        const id = title?.getAttribute('data-id') || '';
        const match = !q || name.includes(q) || id.includes(q);
        card.style.display = match ? '' : 'none';
      });
    }
    input?.addEventListener('input', filter);

    // Sort by name
    function sortByName(direction = 1) {
      const cards = getCards().filter(c => c.style.display !== 'none');
      cards.sort((a, b) => {
        const aEl = a.querySelector('[data-name]');
        const bEl = b.querySelector('[data-name]');
        const an = aEl?.getAttribute('data-name') || '';
        const bn = bEl?.getAttribute('data-name') || '';
        if (an < bn) return -1 * direction;
        if (an > bn) return  1 * direction;
        const ai = parseInt(aEl?.getAttribute('data-id') || '0', 10);
        const bi = parseInt(bEl?.getAttribute('data-id') || '0', 10);
        return (ai - bi) * direction;
      });
      cards.forEach(c => grid.appendChild(c));
    }
    sortAZBtn?.addEventListener('click', () => sortByName(1));
    sortZABtn?.addEventListener('click', () => sortByName(-1));

    // Compact density toggle
    let compact = false;
    function toggleDensity() {
      compact = !compact;
      getCards().forEach(card => {
        const pad = card.querySelector('.p-6, .p-5, .p-4');
        if (pad) {
          pad.classList.toggle('p-6', !compact);
          pad.classList.toggle('p-4', compact);
        }
        const title = card.querySelector('h3');
        if (title) {
          title.classList.toggle('text-xl', !compact);
          title.classList.toggle('text-lg', compact);
        }
        const buttons = card.querySelectorAll('a, button');
        buttons.forEach(btn => {
          btn.classList.toggle('px-4', !compact);
          btn.classList.toggle('py-2', !compact);
          btn.classList.toggle('px-3', compact);
          btn.classList.toggle('py-1.5', compact);
          btn.classList.toggle('text-sm', !compact);
          btn.classList.toggle('text-[13px]', compact);
        });
      });
    }
    densityToggle?.addEventListener('click', toggleDensity);

    // Toast helpers
    function showToast(message = 'Copied!', icon = 'check-circle') {
      if (!toast) return;
      toastMsg.textContent = message;
      const iconEl = toast.querySelector('i');
      if (iconEl) iconEl.className = `ph ph-${icon}`;
      toast.classList.remove('hidden', 'opacity-0');
      toast.classList.add('flex');
      setTimeout(() => {
        toast.classList.add('opacity-0');
        setTimeout(() => toast.classList.add('hidden'), 250);
      }, 1600);
    }

    // Copy link
    grid.addEventListener('click', (e) => {
      const btn = e.target.closest('.copyLink');
      if (!btn) return;
      const card = btn.closest('[data-course-id]') || btn.closest('.group');
      const idAttrFromCard = card?.getAttribute('data-course-id');
      const idAttrFromH3 = card?.querySelector('[data-id]')?.getAttribute('data-id');
      const id = idAttrFromCard || idAttrFromH3;
      if (!id) return;

      const url = new URL('teacher_view_content.php', location.origin);
      url.searchParams.set('course_id', id);
      if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(url.href)
          .then(() => showToast('Course link copied', 'link-simple'))
          .catch(() => showToast('Failed to copy', 'warning-circle'));
      } else {
        // Fallback for older browsers
        const ta = document.createElement('textarea');
        ta.value = url.href; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        showToast('Course link copied', 'link-simple');
      }
    });

    // Init once
    filter();
  })();
</script>
</body>
</html>