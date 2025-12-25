<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die("Access denied");
}

$user_id = (int)$_SESSION['user_id'];

// Fetch active enrollments with course + type + teacher (+ cover image)
// NOTE: assumes `courses` table has a `cover_image` column storing a URL/path.
$courses = [];
$boards  = [];
$levels  = [];

$sql = "
    SELECT
        c.course_id,
        c.name,
        ct.board,
        ct.level,
        c.cover_image,
        GROUP_CONCAT(CONCAT(t.first_name, ' ', t.last_name) SEPARATOR ', ') AS teachers
    FROM enrollments e
    JOIN courses c      ON e.course_id = c.course_id
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    LEFT JOIN teacher_courses tc ON c.course_id = tc.course_id
    LEFT JOIN teachers t         ON tc.teacher_id = t.teacher_id
    WHERE e.user_id = ? AND e.status = 'active'
    GROUP BY c.course_id, c.name, ct.board, ct.level, c.cover_image
    ORDER BY c.name ASC
";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $courses[] = [
            'course_id'    => (int)$row['course_id'],
            'name'         => $row['name'],
            'board'        => $row['board'],
            'level'        => $row['level'],
            'teacher'      => $row['teachers'] ?? '',
            'cover_image'  => $row['cover_image'] ?? ''
        ];
        if (!empty($row['board'])) $boards[$row['board']] = true;
        if (!empty($row['level'])) $levels[$row['level']] = true;
    }
    $stmt->close();
}

$boardOptions = array_keys($boards);
$levelOptions = array_keys($levels);
sort($boardOptions);
sort($levelOptions);

$total      = count($courses);
$boardCount = count($boardOptions);
$levelCount = count($levelOptions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <!-- Improved viewport for modern mobile devices with notches -->
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <title>My Enrolled Courses</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="./images/logo.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
      rel="stylesheet"
    >

    <!-- Ionicons -->
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

    <style>
      html, body {
        font-family: "Inter", ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial,
                     "Apple Color Emoji", "Segoe UI Emoji";
      }
      html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
      * { -webkit-tap-highlight-color: transparent; }
      img, svg, video, canvas { max-width: 100%; height: auto; display: block; }

      @keyframes fadeUp {
        from { opacity: 0; transform: translateY(12px); }
        to   { opacity: 1; transform: translateY(0);   }
      }
      .animate-fadeUp { animation: fadeUp .45s ease-out both; }

      /* Light abstract background */
      .bg-bubbles::before,
      .bg-bubbles::after {
        content:"";
        position:absolute;
        border-radius:9999px;
        filter: blur(40px);
        opacity:.35;
        z-index:0;
        pointer-events:none;
      }
      .bg-bubbles::before {
        width:420px; height:420px;
        background: radial-gradient(closest-side,#60a5fa,transparent 70%);
        top:-110px; left:-80px;
      }
      .bg-bubbles::after  {
        width:520px; height:520px;
        background: radial-gradient(closest-side,#a855f7,transparent 70%);
        bottom:-140px; right:-140px;
      }

      .grid-pattern {
        position:absolute;
        inset:0;
        background-image: linear-gradient(to right, rgba(148,163,184,0.14) 1px, transparent 1px),
                          linear-gradient(to bottom, rgba(148,163,184,0.14) 1px, transparent 1px);
        background-size: 40px 40px;
        opacity:.4;
        mix-blend-mode:multiply;
        pointer-events:none;
      }

      /* Chips */
      .chip {
        display:inline-flex;
        align-items:center;
        gap:.4rem;
        padding:.28rem .7rem;
        border-radius:9999px;
        font-size:.72rem;
        font-weight:600;
        border-width:1px;
        white-space:nowrap;
      }
      .chip-gray    { background:#f9fafb;   color:#374151; border-color:#e5e7eb; }
      .chip-blue    { background:#eff6ff;   color:#1d4ed8; border-color:#bfdbfe; }
      .chip-purple  { background:#f5f3ff;   color:#6d28d9; border-color:#ddd6fe; }
      .chip-rose    { background:#fff1f2;   color:#be123c; border-color:#fecdd3; }
      .chip-emerald { background:#ecfdf5;   color:#047857; border-color:#a7f3d0; }

      /* Cards */
      .card {
        transition: box-shadow .22s ease, transform .22s ease, border-color .22s ease, background-color .22s ease;
      }
      .card:hover {
        box-shadow: 0 22px 45px rgba(15,23,42,.10);
        transform: translateY(-2px);
        border-color: rgba(79,70,229,.35);
        background-color: rgba(255,255,255,.98);
      }

      /* iOS safe area bottom padding helper */
      .safe-area-b { padding-bottom: max(env(safe-area-inset-bottom), 0px); }
    </style>
</head>
<body class="relative min-h-screen bg-gradient-to-br from-sky-50 via-white to-indigo-50 text-gray-900 antialiased">

<div class="fixed inset-0 bg-bubbles -z-20"></div>
<div class="fixed inset-0 grid-pattern -z-10"></div>

<?php include 'components/navbar.php'; ?>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 sm:pt-28 pb-16 gap-6 sm:gap-8">

  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full space-y-6 sm:space-y-8 animate-fadeUp">

    <!-- Hero / Heading -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-indigo-500 via-indigo-600 to-sky-500 text-white shadow-2xl border border-white/40">
      <div class="absolute inset-0 opacity-45 mix-blend-soft-light"
           style="background-image:radial-gradient(circle at 0 0, rgba(244,244,245,0.9), transparent 55%);">
      </div>
      <div class="relative px-5 sm:px-8 py-6 sm:py-8 lg:py-9 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5 sm:gap-6">
        <div class="space-y-3 sm:space-y-4 max-w-xl">
          <div class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/30 backdrop-blur">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/25">
              <ion-icon name="sparkles-outline" class="text-sm"></ion-icon>
            </span>
            Learning journey in progress
          </div>

          <div class="flex items-center gap-3 flex-wrap">
            <div class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-white/20 ring-1 ring-white/40 shadow-lg">
              <ion-icon name="library-outline" class="text-2xl"></ion-icon>
            </div>
            <div>
              <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight">
                My Enrolled Courses
              </h1>
              <p class="mt-1 text-sm sm:text-base text-indigo-100/90 max-w-md">
                Quickly jump into your active courses, filter by board or level,
                and keep track of everything you’re studying.
              </p>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2.5 mt-1 sm:mt-2">
            <span class="chip chip-gray bg-white/20 border-white/40 text-indigo-50">
              <ion-icon name="albums-outline"></ion-icon>
              <?= (int)$total ?> active course<?= $total === 1 ? '' : 's' ?>
            </span>
            <span class="chip chip-gray bg-white/20 border-white/40 text-indigo-50">
              <ion-icon name="school-outline"></ion-icon>
              <?= (int)$boardCount ?> board<?= $boardCount === 1 ? '' : 's' ?>
            </span>
            <span class="chip chip-gray bg-white/20 border-white/40 text-indigo-50">
              <ion-icon name="layers-outline"></ion-icon>
              <?= (int)$levelCount ?> level<?= $levelCount === 1 ? '' : 's' ?>
            </span>
          </div>
        </div>

        <div class="flex flex-col items-end gap-4">
          <a href="student_dashboard.php"
             class="inline-flex items-center gap-2 rounded-full border border-white/40 bg-white/15 px-4 py-2 text-sm font-medium text-white hover:bg-white/25 transition shadow-sm">
            <ion-icon name="arrow-back-outline" class="text-lg"></ion-icon>
            Back to Dashboard
          </a>

          <?php if ($total > 0): ?>
            <div class="flex items-center gap-3 text-xs sm:text-sm text-indigo-100/90">
              <div class="flex items-center gap-1.5">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-400/25 text-emerald-50 ring-1 ring-emerald-200/80">
                  <ion-icon name="checkmark-circle-outline" class="text-base"></ion-icon>
                </span>
                <div class="leading-tight">
                  <div class="font-semibold"><?= (int)$total ?> enrolled</div>
                  <div class="text-indigo-100/80">Keep your streak going</div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($total === 0): ?>

      <!-- Empty state -->
      <section class="bg-white/95 backdrop-blur-xl px-6 sm:px-8 py-10 rounded-3xl shadow-2xl border border-slate-200/70">
        <div class="max-w-lg mx-auto text-center space-y-4">
          <div class="inline-flex items-center justify-center h-16 w-16 rounded-2xl bg-indigo-50 text-indigo-600 shadow-md border border-indigo-100">
            <ion-icon name="information-circle-outline" class="text-3xl"></ion-icon>
          </div>
          <div>
            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">You haven’t enrolled in any courses yet</h2>
            <p class="mt-1 text-sm sm:text-base text-slate-600">
              Explore our catalog and start your first course to unlock lessons, resources,
              and progress tracking tailored just for you.
            </p>
          </div>
          <div class="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
            <a href="enroll_course.php"
               class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-xl text-sm font-semibold hover:bg-indigo-700 shadow-md shadow-indigo-500/30 transition">
              <ion-icon name="add-circle-outline" class="text-xl"></ion-icon>
              Enroll in a Course
            </a>
            <a href="student_dashboard.php"
               class="inline-flex items-center gap-1.5 text-indigo-700 hover:text-indigo-900 text-sm font-medium">
              <ion-icon name="arrow-back-outline"></ion-icon>
              Back to Dashboard
            </a>
          </div>
        </div>
      </section>

    <?php else: ?>

      <!-- Mobile Filters button -->
      <div class="md:hidden">
        <button id="openMobileFilters"
                class="w-full inline-flex items-center justify-between gap-2 px-4 py-3 rounded-2xl bg-white/95 border border-slate-200/80 shadow-sm text-slate-800">
          <div class="inline-flex items-center gap-2">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 border border-indigo-100">
              <ion-icon name="options-outline" class="text-lg"></ion-icon>
            </span>
            <span class="text-sm font-medium">Filters &amp; Sort</span>
          </div>
          <span class="text-xs text-slate-500">
            Showing <span id="shownCountMobile"><?= $total ?></span> / <?= $total ?>
          </span>
        </button>
      </div>

      <!-- Desktop Filters & summary -->
      <section class="hidden md:block bg-white/95 backdrop-blur-xl p-4 sm:p-5 rounded-3xl shadow-xl border border-slate-200/80">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div class="flex items-center gap-2 text-sm text-slate-600">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 border border-indigo-100">
              <ion-icon name="filter-outline" class="text-lg"></ion-icon>
            </span>
            <div>
              <div class="font-semibold text-slate-800">Filter &amp; sort your courses</div>
              <div class="text-xs text-slate-500">Search by name, refine by board or level, and change the order.</div>
            </div>
          </div>
          <div class="hidden sm:flex items-center gap-2 text-xs text-slate-500">
            <ion-icon name="stats-chart-outline" class="text-slate-400"></ion-icon>
            Showing <span id="shownCount"><?= $total ?></span> of <?= $total ?> course<?= $total === 1 ? '' : 's' ?>
          </div>
        </div>

        <div class="flex flex-col md:flex-row md:items-center gap-3 md:gap-4">
          <!-- Search -->
          <div class="relative flex-1 min-w-[260px]">
            <input id="searchInput"
                   type="text"
                   placeholder="Search by course, board, or level..."
                   class="w-full rounded-2xl bg-slate-50 border border-slate-200/80 px-4 py-2.5 pl-11 text-sm shadow-inner focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400 outline-none"
                   aria-label="Search courses">
            <ion-icon name="search-outline"
                      class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xl">
            </ion-icon>
          </div>

          <!-- Dropdowns -->
          <div class="flex flex-wrap gap-2">
            <div class="relative">
              <ion-icon name="school-outline"
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              </ion-icon>
              <select id="boardFilter"
                      class="pl-9 rounded-2xl border border-slate-200/80 bg-white px-4 py-2.5 text-xs sm:text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400">
                <option value="">All Boards</option>
                <?php foreach ($boardOptions as $b): ?>
                  <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="relative">
              <ion-icon name="layers-outline"
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              </ion-icon>
              <select id="levelFilter"
                      class="pl-9 rounded-2xl border border-slate-200/80 bg-white px-4 py-2.5 text-xs sm:text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400">
                <option value="">All Levels</option>
                <?php foreach ($levelOptions as $l): ?>
                  <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="relative">
              <ion-icon name="swap-vertical-outline"
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              </ion-icon>
              <select id="sortSelect"
                      class="pl-9 rounded-2xl border border-slate-200/80 bg-white px-4 py-2.5 text-xs sm:text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400">
                <option value="name-asc">Sort: Name A–Z</option>
                <option value="name-desc">Sort: Name Z–A</option>
              </select>
            </div>
          </div>
        </div>

        <div class="mt-2 flex items-center gap-2 text-xs text-slate-500 sm:hidden">
          <ion-icon name="stats-chart-outline" class="text-slate-400"></ion-icon>
          Showing <span id="shownCountSm"><?= $total ?></span> of <?= $total ?> course<?= $total === 1 ? '' : 's' ?>
        </div>
      </section>

      <!-- Courses Grid -->
      <section>
        <div id="coursesGrid"
             class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6">
          <?php foreach ($courses as $c): ?>
            <?php
              $courseId = (int)$c['course_id'];
              $cover    = $c['cover_image'] ?? '';
            ?>
            <article
              class="course-card card group relative overflow-hidden rounded-2xl bg-white/95 backdrop-blur-xl border border-slate-200/80 p-5 sm:p-6 shadow-xl"
              data-name="<?= htmlspecialchars(mb_strtolower($c['name'])) ?>"
              data-board="<?= htmlspecialchars(mb_strtolower($c['board'])) ?>"
              data-level="<?= htmlspecialchars(mb_strtolower($c['level'])) ?>"
            >
              <!-- Top accent bar -->
              <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-indigo-500 via-sky-400 to-emerald-400 opacity-80 group-hover:opacity-100"></div>

              <!-- Hover glow -->
              <div class="pointer-events-none absolute inset-0 opacity-0 group-hover:opacity-100 transition
                          bg-[radial-gradient(circle_at_0_0,rgba(79,70,229,0.05),transparent_55%),
                              radial-gradient(circle_at_100%_100%,rgba(56,189,248,0.06),transparent_55%)]">
              </div>

              <div class="relative z-10 h-full flex flex-col">
                <!-- Cover image / fallback banner -->
                <?php if (!empty($cover)): ?>
                  <div class="mb-4 -mx-5 -mt-5 sm:-mx-6 sm:-mt-6">
                    <div class="h-32 sm:h-36 w-full overflow-hidden rounded-t-2xl bg-slate-100">
                      <img
                        src="<?= htmlspecialchars($cover, ENT_QUOTES) ?>"
                        alt="Cover image for <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>"
                        class="w-full h-full object-cover"
                      >
                    </div>
                  </div>
                <?php else: ?>
                  <div class="mb-4 -mx-5 -mt-5 sm:-mx-6 sm:-mt-6 h-16 bg-gradient-to-r from-indigo-500/10 via-sky-400/15 to-emerald-400/10"></div>
                <?php endif; ?>

                <div class="flex items-start justify-between gap-3">
                  <div class="space-y-1">
                    <h3 class="text-base sm:text-lg font-semibold text-slate-900 line-clamp-2">
                      <?= htmlspecialchars($c['name']) ?>
                    </h3>
                    <p class="text-xs text-slate-500">
                      <?= htmlspecialchars($c['board'] ?: 'Board not specified') ?>
                      <?= $c['level'] ? ' • ' . htmlspecialchars($c['level']) : '' ?>
                    </p>
                  </div>
                  <span class="inline-flex h-9 w-9 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-100 shadow-sm">
                    <ion-icon name="book-outline" class="text-xl"></ion-icon>
                  </span>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                  <?php if (!empty($c['board'])): ?>
                    <span class="chip chip-blue">
                      <ion-icon name="school-outline"></ion-icon>
                      <?= htmlspecialchars($c['board']) ?>
                    </span>
                  <?php endif; ?>

                  <?php if (!empty($c['level'])): ?>
                    <span class="chip chip-purple">
                      <ion-icon name="layers-outline"></ion-icon>
                      <?= htmlspecialchars($c['level']) ?>
                    </span>
                  <?php endif; ?>

                  <?php if (!empty($c['teacher'])): ?>
                    <span class="chip chip-rose max-w-full truncate">
                      <ion-icon name="people-outline"></ion-icon>
                      <span class="truncate"><?= htmlspecialchars($c['teacher']) ?></span>
                    </span>
                  <?php endif; ?>

                  <span class="chip chip-emerald ml-auto">
                    <ion-icon name="checkmark-circle-outline"></ion-icon>
                    Active
                  </span>
                </div>

                <div class="mt-5 pt-4 border-t border-slate-100 flex items-center justify-between gap-3">
                  <a href="course.php?course_id=<?= $courseId ?>"
                     class="inline-flex items-center gap-2 bg-indigo-600 text-white px-4 py-2 rounded-xl text-sm font-semibold hover:bg-indigo-700 shadow-md shadow-indigo-500/25 transition">
                    <ion-icon name="arrow-forward-circle-outline" class="text-xl"></ion-icon>
                    Go to Course
                  </a>

                  <a href="course.php?course_id=<?= $courseId ?>"
                     class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 text-xs sm:text-sm font-medium group-hover:translate-x-0.5 transition">
                    Details
                    <ion-icon name="chevron-forward-outline"></ion-icon>
                  </a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <!-- No results (filtered) -->
        <div id="noResults"
             class="hidden mt-4 bg-white/95 backdrop-blur-xl p-8 rounded-3xl shadow-xl border border-slate-200/80 text-center">
          <div class="inline-flex items-center justify-center h-14 w-14 rounded-2xl bg-slate-50 text-slate-600 mb-2 border border-slate-200">
            <ion-icon name="filter-circle-outline" class="text-2xl"></ion-icon>
          </div>
          <h3 class="text-base sm:text-lg font-semibold text-slate-900">No courses match your filters</h3>
          <p class="mt-1 text-sm text-slate-600">
            Try adjusting your search terms or clearing the filters to see all your enrolled courses again.
          </p>
          <button id="clearFilters"
                  class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-200 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50">
            <ion-icon name="refresh-outline"></ion-icon>
            Clear filters
          </button>
        </div>
      </section>

      <!-- Mobile Filters Sheet -->
      <div id="mobileFiltersSheet" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div id="mfOverlay" class="absolute inset-0 bg-black/40"></div>
        <div role="dialog" aria-modal="true"
             class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl shadow-2xl p-4 sm:p-5 safe-area-b">
          <div class="w-12 h-1 rounded-full bg-slate-200/90 mx-auto mb-3"></div>
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-semibold text-slate-900">Filters &amp; Sort</h3>
              <p class="text-xs text-slate-500 mt-0.5">Refine your enrolled courses list on mobile.</p>
            </div>
            <button id="closeMobileFilters" class="text-slate-400 hover:text-slate-600">
              <ion-icon name="close-outline" class="text-2xl"></ion-icon>
              <span class="sr-only">Close</span>
            </button>
          </div>

          <div class="mt-4 space-y-3">
            <div class="relative">
              <ion-icon name="search-outline"
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              </ion-icon>
              <input id="searchInputMobile"
                     type="text"
                     placeholder="Search by course, board, or level..."
                     class="w-full rounded-xl bg-slate-50 border border-slate-200 px-3 py-2.5 pl-10 text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400 outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="relative">
                <ion-icon name="school-outline"
                          class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                </ion-icon>
                <select id="boardFilterMobile"
                        class="pl-9 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400 w-full">
                  <option value="">All Boards</option>
                  <?php foreach ($boardOptions as $b): ?>
                    <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="relative">
                <ion-icon name="layers-outline"
                          class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                </ion-icon>
                <select id="levelFilterMobile"
                        class="pl-9 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400 w-full">
                  <option value="">All Levels</option>
                  <?php foreach ($levelOptions as $l): ?>
                    <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="relative">
              <ion-icon name="swap-vertical-outline"
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              </ion-icon>
              <select id="sortSelectMobile"
                      class="pl-9 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400 w-full">
                <option value="name-asc">Sort: Name A–Z</option>
                <option value="name-desc">Sort: Name Z–A</option>
              </select>
            </div>
          </div>

          <div class="mt-5 flex items-center justify-between gap-3">
            <button id="resetMobileFilters"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-200 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50">
              <ion-icon name="refresh-outline"></ion-icon>
              Reset
            </button>
            <button id="applyMobileFilters"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 shadow-md shadow-indigo-500/30">
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              Apply
            </button>
          </div>
        </div>
      </div>

    <?php endif; ?>
  </main>
</div>

<?php include 'components/footer.php'; ?>

<script>
  // Filtering + sorting
  const grid          = document.getElementById('coursesGrid');
  const cards         = grid ? Array.from(grid.getElementsByClassName('course-card')) : [];
  const shownCount    = document.getElementById('shownCount');
  const shownCountSm  = document.getElementById('shownCountSm');
  const shownCountMob = document.getElementById('shownCountMobile');

  const searchInput = document.getElementById('searchInput');
  const boardFilter = document.getElementById('boardFilter');
  const levelFilter = document.getElementById('levelFilter');
  const sortSelect  = document.getElementById('sortSelect');
  const noResults   = document.getElementById('noResults');
  const clearBtn    = document.getElementById('clearFilters');

  function apply() {
    const q = (searchInput?.value || '').toLowerCase().trim();
    const b = (boardFilter?.value || '').toLowerCase();
    const l = (levelFilter?.value || '').toLowerCase();

    let visible = [];

    cards.forEach(card => {
      const name  = card.dataset.name  || '';
      const board = card.dataset.board || '';
      const level = card.dataset.level || '';

      const matchesText  = !q || name.includes(q) || board.includes(q) || level.includes(q);
      const matchesBoard = !b || board === b;
      const matchesLevel = !l || level === l;

      const show = matchesText && matchesBoard && matchesLevel;
      card.style.display = show ? '' : 'none';
      if (show) visible.push(card);
    });

    // Sort visible cards
    if (sortSelect) {
      const mode = sortSelect.value;
      visible.sort((a, b) => {
        const an = a.dataset.name || '';
        const bn = b.dataset.name || '';
        if (mode === 'name-desc') return bn.localeCompare(an);
        return an.localeCompare(bn);
      });
      visible.forEach(el => grid.appendChild(el));
    }

    const count = String(visible.length);
    if (shownCount)    shownCount.textContent    = count;
    if (shownCountSm)  shownCountSm.textContent  = count;
    if (shownCountMob) shownCountMob.textContent = count;
    if (noResults)     noResults.classList.toggle('hidden', visible.length > 0);
  }

  searchInput?.addEventListener('input', apply, { passive: true });
  boardFilter?.addEventListener('change', apply);
  levelFilter?.addEventListener('change', apply);
  sortSelect?.addEventListener('change', apply);
  clearBtn?.addEventListener('click', () => {
    if (searchInput)  searchInput.value = '';
    if (boardFilter)  boardFilter.value = '';
    if (levelFilter)  levelFilter.value = '';
    if (sortSelect)   sortSelect.value  = 'name-asc';
    apply();
  });

  apply();

  // Mobile Filters Sheet logic
  const openMobileFilters  = document.getElementById('openMobileFilters');
  const mobileSheet        = document.getElementById('mobileFiltersSheet');
  const mfOverlay          = document.getElementById('mfOverlay');
  const closeMobileFilters = document.getElementById('closeMobileFilters');

  const searchInputMobile = document.getElementById('searchInputMobile');
  const boardFilterMobile = document.getElementById('boardFilterMobile');
  const levelFilterMobile = document.getElementById('levelFilterMobile');
  const sortSelectMobile  = document.getElementById('sortSelectMobile');
  const applyMobileBtn    = document.getElementById('applyMobileFilters');
  const resetMobileBtn    = document.getElementById('resetMobileFilters');

  function openSheet() {
    if (!mobileSheet) return;
    mobileSheet.classList.remove('hidden');
    mobileSheet.setAttribute('aria-hidden', 'false');

    // Seed values from desktop
    if (searchInputMobile && searchInput)   searchInputMobile.value   = searchInput.value || '';
    if (boardFilterMobile && boardFilter)   boardFilterMobile.value   = boardFilter.value || '';
    if (levelFilterMobile && levelFilter)   levelFilterMobile.value   = levelFilter.value || '';
    if (sortSelectMobile && sortSelect)     sortSelectMobile.value    = sortSelect.value || 'name-asc';

    document.body.style.overflow = 'hidden';
  }
  function closeSheet() {
    if (!mobileSheet) return;
    mobileSheet.classList.add('hidden');
    mobileSheet.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  openMobileFilters?.addEventListener('click', openSheet);
  mfOverlay?.addEventListener('click', closeSheet);
  closeMobileFilters?.addEventListener('click', closeSheet);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSheet(); });

  applyMobileBtn?.addEventListener('click', () => {
    if (searchInput && searchInputMobile) searchInput.value   = searchInputMobile.value;
    if (boardFilter && boardFilterMobile) boardFilter.value   = boardFilterMobile.value;
    if (levelFilter && levelFilterMobile) levelFilter.value   = levelFilterMobile.value;
    if (sortSelect && sortSelectMobile)   sortSelect.value    = sortSelectMobile.value;
    apply();
    closeSheet();
  });

  resetMobileBtn?.addEventListener('click', () => {
    if (searchInputMobile)  searchInputMobile.value  = '';
    if (boardFilterMobile)  boardFilterMobile.value  = '';
    if (levelFilterMobile)  levelFilterMobile.value  = '';
    if (sortSelectMobile)   sortSelectMobile.value   = 'name-asc';
  });
</script>
</body>
</html>