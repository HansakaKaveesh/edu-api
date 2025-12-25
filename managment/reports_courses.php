<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? null;
$allowedRoles = ['admin', 'ceo', 'accountant', 'coordinator'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    die("Access denied.");
}

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// Check if enrollments table exists (for metrics)
$hasEnrollmentsRes = $conn->query("SHOW TABLES LIKE 'enrollments'");
$hasEnrollments    = $hasEnrollmentsRes && $hasEnrollmentsRes->num_rows > 0;
if ($hasEnrollmentsRes) $hasEnrollmentsRes->free();

/* --------------------------------
   Fetch courses with metrics
-----------------------------------*/
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
    " . ($hasEnrollments ? ",
    COALESCE((
      SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.course_id
    ), 0) AS enrollment_count" : ", 0 AS enrollment_count") . "
  FROM courses c
  LEFT JOIN course_types ct ON c.course_type_id = ct.course_type_id
  ORDER BY ct.board, ct.level, c.name
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

/* --------------------------------
   Aggregate metrics and breakdowns
-----------------------------------*/
$totalCourses       = count($courses);
$totalEnrollments   = 0;
$coursesWithTeacher = 0;
$coursesWithContent = 0;
$boardStats         = [];
$levelStats         = [];

$topCourseByEnrollments = null;

foreach ($courses as $c) {
    $totalEnrollments += $c['enrollment_count'];
    if ($c['teacher_count'] > 0) $coursesWithTeacher++;
    if ($c['content_count'] > 0) $coursesWithContent++;

    $board = $c['board'] ?: 'Unassigned';
    $level = $c['level'] ?: 'Unassigned';

    if (!isset($boardStats[$board])) {
        $boardStats[$board] = [
            'courses'      => 0,
            'enrollments'  => 0,
            'withTeacher'  => 0,
            'withContent'  => 0,
        ];
    }
    if (!isset($levelStats[$level])) {
        $levelStats[$level] = [
            'courses'      => 0,
            'enrollments'  => 0,
            'withTeacher'  => 0,
            'withContent'  => 0,
        ];
    }

    $boardStats[$board]['courses']++;
    $boardStats[$board]['enrollments']  += $c['enrollment_count'];
    if ($c['teacher_count'] > 0) $boardStats[$board]['withTeacher']++;
    if ($c['content_count'] > 0) $boardStats[$board]['withContent']++;

    $levelStats[$level]['courses']++;
    $levelStats[$level]['enrollments']  += $c['enrollment_count'];
    if ($c['teacher_count'] > 0) $levelStats[$level]['withTeacher']++;
    if ($c['content_count'] > 0) $levelStats[$level]['withContent']++;

    if (
        $topCourseByEnrollments === null ||
        $c['enrollment_count'] > $topCourseByEnrollments['enrollment_count']
    ) {
        $topCourseByEnrollments = $c;
    }
}

$percentWithTeachers = $totalCourses ? round(($coursesWithTeacher / $totalCourses) * 100) : 0;
$percentWithContent  = $totalCourses ? round(($coursesWithContent / $totalCourses) * 100) : 0;
$avgEnrollments      = $totalCourses ? round($totalEnrollments / $totalCourses, 1) : 0.0;

// For filters: distinct boards and levels
$boardsList = [];
$levelsList = [];
foreach ($courses as $c) {
    $b = trim($c['board']);
    $l = trim($c['level']);
    if ($b !== '' && !in_array($b, $boardsList, true)) $boardsList[] = $b;
    if ($l !== '' && !in_array($l, $levelsList, true)) $levelsList[] = $l;
}
sort($boardsList);
sort($levelsList);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Course Reports & Analytics</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body {
      font-family: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI",
                   Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      min-height: 100vh;
    }
    body::before {
      content:"";
      position:fixed;
      inset:0;
      background:
        radial-gradient(circle at 0% 0%, rgba(56,189,248,0.16) 0, transparent 55%),
        radial-gradient(circle at 100% 100%, rgba(129,140,248,0.20) 0, transparent 55%);
      pointer-events:none;
      z-index:-1;
    }
    .glass-card {
      background: linear-gradient(to bottom right, rgba(255,255,255,0.96), rgba(248,250,252,0.95));
      border: 1px solid rgba(226,232,240,0.9);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow: 0 18px 40px rgba(15,23,42,0.06);
    }
    .soft-card {
      background: linear-gradient(to bottom right, rgba(248,250,252,0.96), rgba(239,246,255,0.96));
      border: 1px solid rgba(222,231,255,0.9);
      box-shadow: 0 14px 30px rgba(15,23,42,0.05);
    }
    .line-clamp-2 {
      display:-webkit-box;
      -webkit-box-orient:vertical;
      -webkit-line-clamp:2;
      overflow:hidden;
    }
    th.sticky { position:sticky; top:0; z-index:10; backdrop-filter:blur(12px); }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>


<!-- Main Container -->
<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 flex flex-col lg:flex-row gap-8">
 
<!-- Sidebar --> 
<?php include 'components/sidebar_coordinator.php'; ?>

  <main class="w-full space-y-10 animate-fadeUp">

  <!-- Header -->
  <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-indigo-950 via-slate-900 to-sky-900 text-white shadow-2xl mb-6">
    <div class="absolute -left-24 -top-24 w-64 h-64 bg-indigo-500/40 rounded-full blur-3xl"></div>
    <div class="absolute -right-24 top-10 w-60 h-60 bg-sky-400/40 rounded-full blur-3xl"></div>

    <div class="relative z-10 px-5 py-6 sm:px-7 sm:py-7 flex flex-col gap-4">
      <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/25">
            <i data-lucide="bar-chart-3" class="w-3.5 h-3.5"></i>
          </span>
          <span><?= ($role === 'coordinator' ? 'Coordinator' : 'Admin') ?> · Course Reports</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
          <span><?= htmlspecialchars(ucfirst($role)) ?> access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Course Analytics & Coverage
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            Understand course coverage, teacher assignment, content volume, and enrollment distribution.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <?php if ($role === 'coordinator'): ?>
            <a href="managment/coordinator_dashboard.php"
               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white/95 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 shadow-sm">
              <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to dashboard
            </a>
          <?php else: ?>
            <a href="admin_dashboard.php"
               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white/95 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 shadow-sm">
              <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to dashboard
            </a>
          <?php endif; ?>
          <button type="button" onclick="downloadCoursesCSV()"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700 shadow-sm active:scale-[0.99]">
            <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Export CSV
          </button>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="mt-1">
          <div class="relative inline-flex items-start gap-2 rounded-xl px-3 py-2 bg-emerald-50/95 text-emerald-800 ring-1 ring-emerald-200 text-xs">
            <button type="button" onclick="this.parentElement.remove()"
                    class="absolute right-2 top-1.5 text-emerald-400 hover:text-emerald-700"
                    aria-label="Dismiss">
              <i data-lucide="x" class="w-3 h-3"></i>
            </button>
            <div class="flex items-center gap-1.5">
              <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
              <span><?= htmlspecialchars($msg) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="mt-1">
          <div class="relative inline-flex items-start gap-2 rounded-xl px-3 py-2 bg-rose-50/95 text-rose-800 ring-1 ring-rose-200 text-xs">
            <button type="button" onclick="this.parentElement.remove()"
                    class="absolute right-2 top-1.5 text-rose-400 hover:text-rose-700"
                    aria-label="Dismiss">
              <i data-lucide="x" class="w-3 h-3"></i>
            </button>
            <div class="flex items-center gap-1.5">
              <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
              <span><?= htmlspecialchars($err) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Grid: Sidebar + Main -->
  <div class="grid grid-cols-1 lg:grid-cols-14 gap-4">


    <!-- Main Column -->
    <section class="lg:col-span-9 space-y-5">
      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-[11px] text-slate-500 uppercase tracking-wide">Courses</p>
              <p class="mt-1 text-xl sm:text-2xl font-semibold text-slate-900"><?= $totalCourses ?></p>
            </div>
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
              <i data-lucide="layers" class="w-4 h-4"></i>
            </span>
          </div>
          <p class="mt-1 text-[11px] text-slate-500">
            <?= $coursesWithTeacher ?> with teachers • <?= $coursesWithContent ?> with content
          </p>
        </div>

        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-[11px] text-slate-500 uppercase tracking-wide">Enrollments</p>
              <p class="mt-1 text-xl sm:text-2xl font-semibold text-slate-900"><?= $totalEnrollments ?></p>
            </div>
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
              <i data-lucide="users-2" class="w-4 h-4"></i>
            </span>
          </div>
          <p class="mt-1 text-[11px] text-slate-500">
            Avg <?= $avgEnrollments ?> / course
          </p>
        </div>

        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <p class="text-[11px] text-slate-500 uppercase tracking-wide">Coverage</p>
          <div class="mt-1 space-y-1">
            <div class="flex items-center justify-between text-[11px] text-slate-600">
              <span>With teachers</span>
              <span><?= $percentWithTeachers ?>%</span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full bg-indigo-500 rounded-full" style="width: <?= $percentWithTeachers ?>%"></div>
            </div>
            <div class="flex items-center justify-between text-[11px] text-slate-600 mt-1">
              <span>With content</span>
              <span><?= $percentWithContent ?>%</span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full bg-sky-500 rounded-full" style="width: <?= $percentWithContent ?>%"></div>
            </div>
          </div>
        </div>

        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <p class="text-[11px] text-slate-500 uppercase tracking-wide">Top course</p>
          <?php if ($topCourseByEnrollments && $topCourseByEnrollments['enrollment_count'] > 0): ?>
            <p class="mt-1 text-sm font-semibold text-slate-900 line-clamp-2">
              <?= htmlspecialchars($topCourseByEnrollments['name']) ?>
            </p>
            <p class="mt-0.5 text-[11px] text-slate-500">
              <?= $topCourseByEnrollments['enrollment_count'] ?> enrollments
            </p>
          <?php else: ?>
            <p class="mt-1 text-xs text-slate-500">
              No enrollments data available yet.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Board / Level breakdown -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="soft-card rounded-2xl p-4 sm:p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700">
                <i data-lucide="school" class="w-4 h-4"></i>
              </span>
              <div>
                <h2 class="text-sm sm:text-base font-semibold text-slate-800">
                  By board
                </h2>
                <p class="text-[11px] text-slate-500">Courses and enrollments per board.</p>
              </div>
            </div>
          </div>
          <?php if (!empty($boardStats)): ?>
            <ul class="space-y-2 text-xs sm:text-sm">
              <?php foreach ($boardStats as $board => $data): ?>
                <li class="flex items-center justify-between gap-3">
                  <div>
                    <p class="font-medium text-slate-800"><?= htmlspecialchars($board) ?></p>
                    <p class="text-[11px] text-slate-500">
                      <?= $data['courses'] ?> course<?= $data['courses'] === 1 ? '' : 's' ?>,
                      <?= $data['withTeacher'] ?> with teachers,
                      <?= $data['withContent'] ?> with content
                    </p>
                  </div>
                  <div class="text-right text-[11px] text-slate-600">
                    <p><?= $data['enrollments'] ?> enrollments</p>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-xs text-slate-500">No courses found.</p>
          <?php endif; ?>
        </div>

        <div class="soft-card rounded-2xl p-4 sm:p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
                <i data-lucide="layers" class="w-4 h-4"></i>
              </span>
              <div>
                <h2 class="text-sm sm:text-base font-semibold text-slate-800">
                  By level
                </h2>
                <p class="text-[11px] text-slate-500">Courses and enrollments per level.</p>
              </div>
            </div>
          </div>
          <?php if (!empty($levelStats)): ?>
            <ul class="space-y-2 text-xs sm:text-sm">
              <?php foreach ($levelStats as $level => $data): ?>
                <li class="flex items-center justify-between gap-3">
                  <div>
                    <p class="font-medium text-slate-800"><?= htmlspecialchars($level) ?></p>
                    <p class="text-[11px] text-slate-500">
                      <?= $data['courses'] ?> course<?= $data['courses'] === 1 ? '' : 's' ?>,
                      <?= $data['withTeacher'] ?> with teachers,
                      <?= $data['withContent'] ?> with content
                    </p>
                  </div>
                  <div class="text-right text-[11px] text-slate-600">
                    <p><?= $data['enrollments'] ?> enrollments</p>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-xs text-slate-500">No courses found.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Filters -->
      <div class="rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200 p-3">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-2.5 items-center">
          <div class="relative md:col-span-2">
            <input id="searchInput" type="text"
                   placeholder="Search course name, board or level..."
                   class="w-full pl-8 pr-2.5 py-2 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   aria-label="Search courses">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
          </div>
          <div class="flex gap-2">
            <select id="boardFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All boards</option>
              <?php foreach ($boardsList as $b): ?>
                <option value="<?= htmlspecialchars(strtolower($b), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($b) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select id="levelFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All levels</option>
              <?php foreach ($levelsList as $l): ?>
                <option value="<?= htmlspecialchars(strtolower($l), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($l) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="flex gap-2">
            <select id="teacherFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">Teacher coverage</option>
              <option value="has">With teachers</option>
              <option value="none">No teacher</option>
            </select>
            <select id="contentFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">Content coverage</option>
              <option value="has">With content</option>
              <option value="none">No content</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Courses table -->
      <div class="overflow-hidden rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200">
        <?php if ($totalCourses > 0): ?>
          <div class="overflow-x-auto" id="coursesTableWrapper">
            <table id="coursesTable" class="min-w-full text-left border-collapse">
              <thead>
                <tr class="text-slate-700 text-[11px] sm:text-xs bg-slate-50/90 uppercase tracking-wide">
                  <th class="sticky px-3 py-2 border-b border-slate-200">ID</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Course</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Board / Level</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Teachers</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Content</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Enrollments</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Links</th>
                </tr>
              </thead>
              <tbody class="text-[11px] sm:text-xs">
                <?php foreach ($courses as $c):
                    $cid   = $c['course_id'];
                    $name  = $c['name'];
                    $board = $c['board'];
                    $level = $c['level'];
                    $searchKey = strtolower(trim(($name ?? '') . ' ' . ($board ?? '') . ' ' . ($level ?? '')));
                    $hasT = $c['teacher_count'] > 0;
                    $hasC = $c['content_count'] > 0;
                ?>
                  <tr class="hover:bg-slate-50 even:bg-slate-50/40"
                      data-board="<?= htmlspecialchars(strtolower($board), ENT_QUOTES) ?>"
                      data-level="<?= htmlspecialchars(strtolower($level), ENT_QUOTES) ?>"
                      data-hasteacher="<?= $hasT ? '1' : '0' ?>"
                      data-hascontent="<?= $hasC ? '1' : '0' ?>"
                      data-key="<?= htmlspecialchars($searchKey, ENT_QUOTES) ?>">
                    <td class="px-3 py-2 border-b border-slate-100 text-slate-600">
                      <?= $cid ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <div class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($name) ?></div>
                      <div class="text-[10px] text-slate-500 line-clamp-2">
                        <?= htmlspecialchars($c['description'] ?: 'No description.') ?>
                      </div>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <span class="inline-flex flex-col">
                        <span class="text-[11px] font-medium text-slate-800">
                          <?= htmlspecialchars($board ?: '—') ?>
                        </span>
                        <span class="text-[10px] text-slate-500">
                          <?= htmlspecialchars($level ?: '') ?>
                        </span>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="users" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $c['teacher_count'] ?>
                      </span>
                      <?php if (!$hasT): ?>
                        <span class="ml-1 inline-flex items-center gap-1 text-[10px] text-rose-600">
                          <i data-lucide="alert-circle" class="w-3 h-3"></i>No teacher
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $c['content_count'] ?>
                      </span>
                      <?php if (!$hasC): ?>
                        <span class="ml-1 inline-flex items-center gap-1 text-[10px] text-amber-600">
                          <i data-lucide="alert-circle" class="w-3 h-3"></i>No content
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="users-2" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $c['enrollment_count'] ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <div class="flex flex-wrap gap-1.5">
                        <a href="course.php?course_id=<?= $cid ?>"
                           class="inline-flex items-center gap-1 text-sky-700 hover:text-sky-900 px-2 py-0.5 rounded-md ring-1 ring-sky-200 bg-sky-50 text-[10px] font-medium">
                          <i data-lucide="external-link" class="w-3.5 h-3.5"></i> View
                        </a>
                        <a href="manage_course_teachers.php?course_id=<?= $cid ?>"
                           class="inline-flex items-center gap-1 text-emerald-700 hover:text-emerald-900 px-2 py-0.5 rounded-md ring-1 ring-emerald-200 bg-emerald-50 text-[10px] font-medium">
                          <i data-lucide="users" class="w-3.5 h-3.5"></i> Teachers
                        </a>
                        <a href="manage_course_contents.php?course_id=<?= $cid ?>"
                           class="inline-flex items-center gap-1 text-amber-700 hover:text-amber-900 px-2 py-0.5 rounded-md ring-1 ring-amber-200 bg-amber-50 text-[10px] font-medium">
                          <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Contents
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="p-6 text-sm text-slate-600">
            <div class="flex items-center gap-3">
              <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
                <i data-lucide="info" class="w-5 h-5"></i>
              </span>
              <div>
                <p class="font-medium">No courses found.</p>
                <p class="text-xs text-slate-500 mt-0.5">
                  Start by creating courses and assigning types, teachers and content.
                </p>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
</main>
<script>
  if (window.lucide) {
    window.lucide.createIcons();
  }

  const searchInput   = document.getElementById('searchInput');
  const boardFilter   = document.getElementById('boardFilter');
  const levelFilter   = document.getElementById('levelFilter');
  const teacherFilter = document.getElementById('teacherFilter');
  const contentFilter = document.getElementById('contentFilter');
  const rows          = [...document.querySelectorAll('#coursesTable tbody tr')];

  function applyFilters() {
    const q       = (searchInput?.value || '').toLowerCase().trim();
    const board   = (boardFilter?.value || '').toLowerCase();
    const level   = (levelFilter?.value || '').toLowerCase();
    const tFilter = (teacherFilter?.value || '').toLowerCase();
    const cFilter = (contentFilter?.value || '').toLowerCase();

    rows.forEach(tr => {
      const key   = (tr.getAttribute('data-key') || '').toLowerCase();
      const b     = (tr.getAttribute('data-board') || '').toLowerCase();
      const l     = (tr.getAttribute('data-level') || '').toLowerCase();
      const hasT  = tr.getAttribute('data-hasteacher') === '1';
      const hasC  = tr.getAttribute('data-hascontent') === '1';

      const matchQ  = !q || key.includes(q);
      const matchB  = !board || b === board;
      const matchL  = !level || l === level;
      const matchT  = !tFilter ||
                      (tFilter === 'has'  && hasT) ||
                      (tFilter === 'none' && !hasT);
      const matchC  = !cFilter ||
                      (cFilter === 'has'  && hasC) ||
                      (cFilter === 'none' && !hasC);

      const show = matchQ && matchB && matchL && matchT && matchC;
      tr.style.display = show ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  boardFilter?.addEventListener('change', applyFilters);
  levelFilter?.addEventListener('change', applyFilters);
  teacherFilter?.addEventListener('change', applyFilters);
  contentFilter?.addEventListener('change', applyFilters);
  applyFilters();

  // CSV export based on current visible table rows
  function downloadCoursesCSV() {
    const table = document.getElementById('coursesTable');
    if (!table) return;

    const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
    const visibleRows = [...table.querySelectorAll('tbody tr')].filter(r => r.style.display !== 'none');

    const data = [headers];
    visibleRows.forEach(row => {
      const cols = [...row.children].map(td =>
        td.innerText.replace(/\s+/g, ' ').trim()
      );
      data.push(cols);
    });

    const csv = data
      .map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))
      .join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'courses_report.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>