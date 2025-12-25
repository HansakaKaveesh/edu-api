<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch coordinator info (from separate table)
$stmt = $conn->prepare("
  SELECT cc.coordinator_id, cc.first_name, cc.last_name, cc.email, u.username
  FROM course_coordinators cc
  JOIN users u ON cc.user_id = u.user_id
  WHERE cc.user_id = ?
  LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$coordinator = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$coordinator) {
    // User exists but is not a coordinator
    http_response_code(403);
    echo "Access denied: coordinator account not found.";
    exit;
}

$coordId   = (int)$coordinator['coordinator_id'];
$coordName = trim(($coordinator['first_name'] ?? '') . ' ' . ($coordinator['last_name'] ?? ''));
if ($coordName === '') {
    $coordName = $coordinator['username'] ?? 'Coordinator';
}
$roleLabel = 'Course Coordinator';

// --- Metrics ---

// Total courses
$totalCourses = 0;
if ($res = $conn->query("SELECT COUNT(*) AS c FROM courses")) {
    $row = $res->fetch_assoc();
    $totalCourses = (int)($row['c'] ?? 0);
    $res->free();
}

// Total teachers
$totalTeachers = 0;
if ($res = $conn->query("SELECT COUNT(*) AS c FROM teachers")) {
    $row = $res->fetch_assoc();
    $totalTeachers = (int)($row['c'] ?? 0);
    $res->free();
}

// Total contents
$totalContents = 0;
if ($res = $conn->query("SELECT COUNT(*) AS c FROM contents")) {
    $row = $res->fetch_assoc();
    $totalContents = (int)($row['c'] ?? 0);
    $res->free();
}

// Total enrollments (if enrollments table exists)
$totalEnrollments = 0;
$hasEnrollments = $conn->query("SHOW TABLES LIKE 'enrollments'");
if ($hasEnrollments && $hasEnrollments->num_rows) {
    if ($res = $conn->query("SELECT COUNT(*) AS c FROM enrollments")) {
        $row = $res->fetch_assoc();
        $totalEnrollments = (int)($row['c'] ?? 0);
        $res->free();
    }
}

// Courses per board & level
$boardStats = [];
$levelStats = [];

$statsSql = "
  SELECT ct.board, ct.level, COUNT(*) AS course_count
  FROM courses c
  JOIN course_types ct ON c.course_type_id = ct.course_type_id
  GROUP BY ct.board, ct.level
  ORDER BY ct.board, ct.level
";
if ($res = $conn->query($statsSql)) {
    while ($row = $res->fetch_assoc()) {
        $board = $row['board'];
        $level = $row['level'];
        $count = (int)$row['course_count'];

        if (!isset($boardStats[$board])) $boardStats[$board] = 0;
        if (!isset($levelStats[$level])) $levelStats[$level] = 0;

        $boardStats[$board] += $count;
        $levelStats[$level] += $count;
    }
    $res->free();
}

// Detailed course list (with cover image, board/level, counts)
$courses = [];
$sql = "
  SELECT
    c.course_id,
    c.name,
    c.description,
    c.cover_image,
    ct.board,
    ct.level,
    COALESCE((
      SELECT COUNT(*) FROM teacher_courses tc WHERE tc.course_id = c.course_id
    ), 0) AS teacher_count,
    COALESCE((
      SELECT COUNT(*) FROM contents cnt WHERE cnt.course_id = c.course_id
    ), 0) AS content_count
    " . ($hasEnrollments && $hasEnrollments->num_rows ? ",
    COALESCE((
      SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.course_id
    ), 0) AS enrollment_count" : ", 0 AS enrollment_count") . "
  FROM courses c
  JOIN course_types ct ON c.course_type_id = ct.course_type_id
  ORDER BY c.name ASC
";

if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $courses[] = [
            'course_id'        => (int)$row['course_id'],
            'name'             => $row['name'] ?? '',
            'description'      => $row['description'] ?? '',
            'cover_image'      => $row['cover_image'] ?? '',
            'board'            => $row['board'] ?? '',
            'level'            => $row['level'] ?? '',
            'teacher_count'    => (int)$row['teacher_count'],
            'content_count'    => (int)$row['content_count'],
            'enrollment_count' => (int)$row['enrollment_count'],
        ];
    }
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Course Coordinator Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root { color-scheme: light; }
    html, body {
      font-family: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI",
                   Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
    }

    .bg-bubbles::before,
    .bg-bubbles::after {
      content: "";
      position: absolute;
      border-radius: 9999px;
      filter: blur(48px);
      opacity: .45;
      z-index: 0;
      pointer-events: none;
      transform: translate3d(0,0,0);
    }
    .bg-bubbles::before {
      width: 460px;
      height: 460px;
      background: radial-gradient(circle at 30% 20%, rgba(56,189,248,0.9), transparent 65%);
      top: -80px;
      left: -80px;
    }
    .bg-bubbles::after {
      width: 520px;
      height: 520px;
      background: radial-gradient(circle at 70% 80%, rgba(129,140,248,0.95), transparent 65%);
      bottom: -140px;
      right: -120px;
    }

    @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fadeUp { animation: fadeUp .6s ease-out both; }

    .glass-card {
      background: linear-gradient(to bottom right, rgba(255,255,255,0.92), rgba(249,250,251,0.9));
      border: 1px solid rgba(226,232,240,0.8);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow: 0 18px 40px rgba(15,23,42,0.08);
    }

    .soft-card {
      background: linear-gradient(to bottom right, rgba(248,250,252,0.9), rgba(239,246,255,0.9));
      border: 1px solid rgba(222,231,255,0.9);
      box-shadow: 0 14px 30px rgba(15,23,42,0.07);
    }

    .hover-raise {
      transition: transform .2s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease;
    }
    .hover-raise:hover {
      transform: translateY(-3px);
      box-shadow: 0 20px 40px rgba(15,23,42,0.16);
    }

    .line-clamp-2,
    .line-clamp-3 {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .line-clamp-2 { -webkit-line-clamp: 2; }
    .line-clamp-3 { -webkit-line-clamp: 3; }

    .chip {
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      padding:.25rem .7rem;
      border-radius:9999px;
      font-size:.72rem;
      font-weight:600;
      border-width:1px;
      white-space:nowrap;
    }
    .chip-indigo  { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .chip-sky     { background:#e0f2fe; color:#0369a1; border-color:#bae6fd; }
    .chip-emerald { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    .chip-amber   { background:#fffbeb; color:#92400e; border-color:#fde68a; }
    .chip-rose    { background:#fff1f2; color:#be123c; border-color:#fecdd3; }

    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.45); border-radius: 9999px; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(107,114,128,0.7); }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<div class="fixed inset-0 bg-bubbles -z-10"></div>

<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 flex flex-col lg:flex-row gap-8">
  <!-- Coordinator sidebar (create this file separately) -->
  <?php include 'components/sidebar_coordinator.php'; ?>

  <main class="w-full space-y-10 animate-fadeUp">

    <!-- HERO -->
    <section class="relative overflow-hidden rounded-[2rem] glass-card">
      <!-- Background image + gradient overlays -->
      <div class="absolute inset-0">
        <!-- Hero background image -->
        <div class="w-full h-full bg-[url('https://media.istockphoto.com/id/1475739731/photo/management-diagram-with-planning-software-on-laptop-screen.jpg?s=612x612&w=0&k=20&c=qTlo9yUCDQw-OLQl98puvT4oEM23KVvkgw4Ya_KzBGI=')] bg-cover bg-center"></div>

        <!-- Dark/colored gradient overlay -->
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/80 via-sky-700/75 to-cyan-700/70"></div>

        <!-- Soft light radial overlay -->
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_0_0,rgba(255,255,255,0.25),transparent_55%),radial-gradient(circle_at_100%_100%,rgba(15,23,42,0.5),transparent_45%)] mix-blend-soft-light"></div>
      </div>

      <div class="relative z-10 px-4 sm:px-6 lg:px-10 py-6 sm:py-7 lg:py-8 text-white space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="text-xs sm:text-sm italic opacity-90" id="datetime" aria-live="polite"></div>

          <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/10 ring-1 ring-white/25 px-3 py-1.5 text-xs uppercase tracking-wide">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
              <span>Course Coordinator</span>
            </span>
          </div>
        </div>

        <div class="space-y-3 sm:space-y-4">
          <h1 class="text-xl sm:text-2xl md:text-3xl lg:text-4xl font-extrabold leading-tight drop-shadow">
            <span class="block">Welcome back,</span>
            <span class="inline-flex flex-wrap items-center gap-2">
              <span class="underline decoration-white/40 underline-offset-4">
                <?= htmlspecialchars($coordName) ?>
              </span>
              <span class="text-xs sm:text-sm font-light italic text-white/90">
                (<?= htmlspecialchars($roleLabel) ?>)
              </span>
            </span>
          </h1>
          <p class="text-sm sm:text-base md:text-lg font-light text-white/95 max-w-3xl">
            Monitor your curriculum, teachers, and learning content at a glance. Quickly identify gaps,
            unassigned courses, and areas that need more materials.
          </p>
        </div>

        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-5 mt-2">
          <div class="flex flex-wrap items-center gap-3">
            <a href="manage_courses.php"
               class="inline-flex items-center gap-2 rounded-full bg-white text-indigo-700 font-semibold px-4 sm:px-5 py-2.5 shadow-lg shadow-slate-900/20 hover-raise">
              <i data-lucide="layers" class="w-5 h-5"></i>
              <span>Manage Courses</span>
            </a>
            <a href="manage_contents.php"
               class="inline-flex items-center gap-2 rounded-full bg-indigo-900/40 text-white font-medium px-4 sm:px-5 py-2.5 ring-1 ring-white/30 hover:bg-indigo-900/55 hover-raise">
              <i data-lucide="file-text" class="w-5 h-5"></i>
              <span>Manage Content</span>
            </a>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3 max-w-xl mx-auto lg:mx-0">
            <div class="rounded-2xl bg-white/10 ring-1 ring-white/20 p-3 sm:p-4 backdrop-blur">
              <div class="text-[11px] text-white/85 inline-flex items-center gap-1">
                <i data-lucide="book-open" class="w-4 h-4"></i>
                <span>Courses</span>
              </div>
              <div class="mt-1 text-xl sm:text-2xl font-semibold tracking-tight"><?= $totalCourses ?></div>
              <p class="mt-0.5 text-[11px] text-white/80">Total</p>
            </div>
            <div class="rounded-2xl bg-white/10 ring-1 ring-white/20 p-3 sm:p-4 backdrop-blur">
              <div class="text-[11px] text-white/85 inline-flex items-center gap-1">
                <i data-lucide="users" class="w-4 h-4"></i>
                <span>Teachers</span>
              </div>
              <div class="mt-1 text-xl sm:text-2xl font-semibold tracking-tight"><?= $totalTeachers ?></div>
              <p class="mt-0.5 text-[11px] text-white/80">Assigned</p>
            </div>
            <div class="rounded-2xl bg-white/10 ring-1 ring-white/20 p-3 sm:p-4 backdrop-blur">
              <div class="text-[11px] text-white/85 inline-flex items-center gap-1">
                <i data-lucide="file-text" class="w-4 h-4"></i>
                <span>Contents</span>
              </div>
              <div class="mt-1 text-xl sm:text-2xl font-semibold tracking-tight"><?= $totalContents ?></div>
              <p class="mt-0.5 text-[11px] text-white/80">Learning items</p>
            </div>
            <div class="rounded-2xl bg-white/10 ring-1 ring-white/20 p-3 sm:p-4 backdrop-blur">
              <div class="text-[11px] text-white/85 inline-flex items-center gap-1">
                <i data-lucide="users-2" class="w-4 h-4"></i>
                <span>Enrollments</span>
              </div>
              <div class="mt-1 text-xl sm:text-2xl font-semibold tracking-tight"><?= $totalEnrollments ?></div>
              <p class="mt-0.5 text-[11px] text-white/80">Across courses</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- BOARD / LEVEL OVERVIEW -->
    <section class="soft-card rounded-2xl p-6 sm:p-8">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-2">
          <span class="inline-flex items-center justify-center rounded-2xl bg-indigo-100 text-indigo-700 p-2">
            <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
          </span>
          <div>
            <h3 class="text-xl sm:text-2xl font-semibold text-slate-800">
              Curriculum Overview
            </h3>
            <p class="text-xs sm:text-sm text-slate-500">
              Distribution of courses by board and level.
            </p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="bg-white/95 border border-slate-200 rounded-2xl p-4 sm:p-5 shadow-sm">
          <h4 class="text-sm font-semibold text-slate-800 mb-3 flex items-center gap-2">
            <i data-lucide="school" class="w-4 h-4 text-indigo-600"></i>
            By Board
          </h4>
          <?php if (!empty($boardStats)): ?>
            <ul class="space-y-2 text-sm">
              <?php foreach ($boardStats as $board => $count): ?>
                <li class="flex items-center justify-between gap-3">
                  <span class="inline-flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-indigo-500"></span>
                    <span class="font-medium"><?= htmlspecialchars($board) ?></span>
                  </span>
                  <span class="text-slate-500"><?= $count ?> course<?= $count === 1 ? '' : 's' ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-sm text-slate-500">No courses defined yet.</p>
          <?php endif; ?>
        </div>

        <div class="bg-white/95 border border-slate-200 rounded-2xl p-4 sm:p-5 shadow-sm">
          <h4 class="text-sm font-semibold text-slate-800 mb-3 flex items-center gap-2">
            <i data-lucide="layers" class="w-4 h-4 text-sky-600"></i>
            By Level
          </h4>
          <?php if (!empty($levelStats)): ?>
            <ul class="space-y-2 text-sm">
              <?php foreach ($levelStats as $level => $count): ?>
                <li class="flex items-center justify-between gap-3">
                  <span class="inline-flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                    <span class="font-medium"><?= htmlspecialchars($level) ?></span>
                  </span>
                  <span class="text-slate-500"><?= $count ?> course<?= $count === 1 ? '' : 's' ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-sm text-slate-500">No courses defined yet.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- COURSE LIST -->
    <section class="soft-card rounded-2xl p-6 sm:p-8">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-2">
          <span class="inline-flex items-center justify-center rounded-2xl bg-sky-100 text-sky-700 p-2">
            <i data-lucide="books" class="w-5 h-5"></i>
          </span>
          <div>
            <h3 class="text-xl sm:text-2xl font-semibold text-slate-800">
              All Courses
            </h3>
            <p class="text-xs sm:text-sm text-slate-500">
              View course coverage, assigned teachers, and content volume.
            </p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <div class="relative w-full sm:w-72">
            <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
            <input
              id="searchInput"
              type="text"
              placeholder="Search by name, board, level..."
              class="w-full rounded-full bg-white border border-gray-200/80 px-4 py-2.5 pl-10 text-sm shadow-sm focus:ring-2 focus:ring-indigo-500/40 focus:outline-none">
          </div>
        </div>
      </div>

      <?php if (!empty($courses)): ?>
        <div id="noResults" class="hidden mb-4 text-sm text-slate-600 bg-white border border-slate-200 rounded-2xl px-4 py-3">
          No courses match your search.
        </div>

        <div id="coursesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6">
          <?php foreach ($courses as $c): ?>
            <?php
              $cid      = $c['course_id'];
              $name     = $c['name'];
              $desc     = $c['description'];
              $cover    = $c['cover_image'];
              $board    = $c['board'];
              $level    = $c['level'];
              $tCount   = $c['teacher_count'];
              $cntCount = $c['content_count'];
              $enrCount = $c['enrollment_count'];
              $searchText = strtolower(($name ?? '') . ' ' . ($board ?? '') . ' ' . ($level ?? ''));
            ?>
            <article
              class="course-card hover-raise relative overflow-hidden rounded-2xl bg-white/95 border border-slate-200/80 shadow-sm flex flex-col"
              data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
            >
              <!-- Cover -->
              <?php if (!empty($cover)): ?>
                <div class="h-28 sm:h-32 w-full overflow-hidden rounded-t-2xl">
                  <img
                    src="../<?= htmlspecialchars($cover, ENT_QUOTES) ?>"
                    alt="Cover image for <?= htmlspecialchars($name, ENT_QUOTES) ?>"
                    class="w-full h-full object-cover"
                  >
                </div>
              <?php else: ?>
                <div class="h-20 bg-gradient-to-r from-indigo-500/15 via-sky-400/15 to-emerald-400/15 rounded-t-2xl"></div>
              <?php endif; ?>

              <div class="p-4 sm:p-5 flex-1 flex flex-col gap-3">
                <div class="flex items-start justify-between gap-3">
                  <div class="space-y-0.5">
                    <h4 class="text-base sm:text-lg font-semibold text-slate-900 line-clamp-2">
                      <?= htmlspecialchars($name) ?>
                    </h4>
                    <p class="text-xs text-slate-500">
                      <?= htmlspecialchars($board ?: 'Board not set') ?>
                      <?= $level ? ' • ' . htmlspecialchars($level) : '' ?>
                    </p>
                  </div>
                  <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-100">
                    <i data-lucide="book-open" class="w-4 h-4"></i>
                  </span>
                </div>

                <p class="text-sm text-slate-600 line-clamp-3">
                  <?= htmlspecialchars($desc ?: 'No description provided for this course.') ?>
                </p>

                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                  <span class="chip chip-indigo">
                    <i data-lucide="users" class="w-3.5 h-3.5"></i>
                    <?= $tCount ?> teacher<?= $tCount === 1 ? '' : 's' ?>
                  </span>
                  <span class="chip chip-sky">
                    <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                    <?= $cntCount ?> item<?= $cntCount === 1 ? '' : 's' ?>
                  </span>
                  <span class="chip chip-emerald">
                    <i data-lucide="users-2" class="w-3.5 h-3.5"></i>
                    <?= $enrCount ?> enroll<?= $enrCount === 1 ? 'ment' : 'ments' ?>
                  </span>

                  <?php if ($tCount === 0): ?>
                    <span class="chip chip-rose">
                      <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                      No teacher
                    </span>
                  <?php elseif ($cntCount === 0): ?>
                    <span class="chip chip-amber">
                      <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                      No content
                    </span>
                  <?php endif; ?>
                </div>

                <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between gap-3">
                  <a href="course.php?course_id=<?= $cid ?>"
                     class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-800">
                    <span>Open course</span>
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                  </a>
                  <div class="flex items-center gap-2 text-xs text-slate-500">
                    <a href="manage_course_teachers.php?course_id=<?= $cid ?>" class="hover:text-indigo-600">
                      Assign teachers
                    </a>
                    <span>•</span>
                    <a href="manage_course_contents.php?course_id=<?= $cid ?>" class="hover:text-indigo-600">
                      Manage content
                    </a>
                  </div>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="flex items-center gap-3 text-slate-600 text-sm sm:text-base bg-white/95 border border-slate-200 rounded-2xl px-4 py-4">
          <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
            <i data-lucide="info" class="w-5 h-5"></i>
          </span>
          <div>
            <p class="font-medium">No courses have been created yet.</p>
            <p class="text-xs text-slate-500 mt-0.5">
              Get started by adding a new course and assigning it to a teacher.
            </p>
          </div>
        </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<script>
  if (window.lucide) {
    window.lucide.createIcons();
  }

  function updateDateTime() {
    const now = new Date();
    const opt = {
      weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
    };
    const el = document.getElementById('datetime');
    if (el) el.textContent = now.toLocaleString('en-US', opt);
  }
  setInterval(updateDateTime, 1000);
  updateDateTime();

  const searchInput = document.getElementById('searchInput');
  const coursesGrid = document.getElementById('coursesGrid');
  const noResults   = document.getElementById('noResults');

  function applyFilter() {
    if (!coursesGrid) return;
    const q = (searchInput?.value || '').toLowerCase().trim();
    let visible = 0;

    coursesGrid.querySelectorAll('.course-card').forEach(card => {
      const hay = (card.dataset.search || '').toLowerCase();
      const show = !q || hay.includes(q);
      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    if (noResults) noResults.classList.toggle('hidden', visible > 0);
  }

  searchInput?.addEventListener('input', applyFilter);
  applyFilter();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>