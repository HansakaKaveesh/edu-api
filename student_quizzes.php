<?php
session_start();
include 'db_connect.php';

// Must be logged in as a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Get student_id safely
$student_id = null;
if ($stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_id = $stmt->get_result()->fetch_assoc()['student_id'] ?? null;
    $stmt->close();
}
if (!$student_id) {
    die("Student profile not found.");
}

// Fetch active courses safely
$courses = [];
if ($stmt = $conn->prepare("
    SELECT c.course_id, c.name
    FROM enrollments e
    JOIN courses c ON c.course_id = e.course_id
    WHERE e.user_id = ? AND e.status = 'active'
    ORDER BY c.name ASC
")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $courses[] = ['course_id' => (int)$row['course_id'], 'name' => $row['name']];
    }
    $stmt->close();
}

// Prepare statements for quizzes and attempts
$courseQuizzes = []; // course_id => array of quizzes
$totalQuizzes = 0;
$completedCount = 0;
$passedCount = 0;
$failedCount = 0;
$notAttemptedCount = 0;

if (!empty($courses)) {
    $stmtQuizzes = $conn->prepare("
        SELECT co.content_id, co.title, a.assignment_id
        FROM contents co
        JOIN assignments a ON a.lesson_id = co.content_id
        WHERE co.course_id = ? AND co.type = 'quiz'
        ORDER BY co.title ASC
    ");

    $stmtLatest = $conn->prepare("
        SELECT score, passed, attempted_at
        FROM student_assignment_attempts
        WHERE assignment_id = ? AND student_id = ?
        ORDER BY attempted_at DESC
        LIMIT 1
    ");

    $stmtCount = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM student_assignment_attempts
        WHERE assignment_id = ? AND student_id = ?
    ");

    foreach ($courses as $c) {
        $cid = (int)$c['course_id'];
        $courseQuizzes[$cid] = [];

        // Get quizzes for course
        $stmtQuizzes->bind_param("i", $cid);
        $stmtQuizzes->execute();
        $qres = $stmtQuizzes->get_result();

        while ($q = $qres->fetch_assoc()) {
            $content_id = (int)$q['content_id'];
            $assignment_id = (int)$q['assignment_id'];
            $title = $q['title'];

            // Latest attempt
            $stmtLatest->bind_param("ii", $assignment_id, $student_id);
            $stmtLatest->execute();
            $latest = $stmtLatest->get_result()->fetch_assoc();

            // Attempts count
            $stmtCount->bind_param("ii", $assignment_id, $student_id);
            $stmtCount->execute();
            $attempt_count = (int)($stmtCount->get_result()->fetch_assoc()['cnt'] ?? 0);

            // Status
            $status = 'not_attempted';
            $score = null;
            $passed = null;
            $last_attempted = null;

            if ($latest) {
                $score = is_null($latest['score']) ? null : (float)$latest['score'];
                $passed = (int)$latest['passed'] === 1;
                $last_attempted = $latest['attempted_at'];
                $status = 'completed';
                $completedCount++;
                if ($passed) $passedCount++; else $failedCount++;
            } else {
                $notAttemptedCount++;
            }
            $totalQuizzes++;

            $courseQuizzes[$cid][] = [
                'content_id'    => $content_id,
                'assignment_id' => $assignment_id,
                'title'         => $title,
                'attempts'      => $attempt_count,
                'score'         => $score,
                'passed'        => $passed,
                'last_attempted'=> $last_attempted,
                'status'        => $status
            ];
        }
    }

    $stmtQuizzes->close();
    $stmtLatest->close();
    $stmtCount->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>My Quizzes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
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
      @keyframes fadeUp {
        from { opacity:0; transform: translateY(10px);}
        to   { opacity:1; transform: translateY(0);}
      }
      .animate-fadeUp { animation: fadeUp .45s ease-out both; }

      .bg-bubbles::before, .bg-bubbles::after {
        content:""; position:absolute; border-radius:9999px; filter: blur(40px); opacity:.28; z-index:0; pointer-events:none;
      }
      .bg-bubbles::before {
        width:420px; height:420px;
        background: radial-gradient(closest-side,#60a5fa,transparent 70%);
        top:-90px; left:-80px;
      }
      .bg-bubbles::after  {
        width:500px; height:500px;
        background: radial-gradient(closest-side,#a855f7,transparent 70%);
        bottom:-140px; right:-140px;
      }

      .grid-pattern {
        position:absolute;
        inset:0;
        background-image: linear-gradient(to right, rgba(148,163,184,0.16) 1px, transparent 1px),
                          linear-gradient(to bottom, rgba(148,163,184,0.16) 1px, transparent 1px);
        background-size: 38px 38px;
        opacity:.45;
        mix-blend-mode:multiply;
        pointer-events:none;
      }

      /* Badges */
      .badge {
        display:inline-flex;
        align-items:center;
        gap:.375rem;
        padding:.25rem .625rem;
        border-radius:9999px;
        font-size:.72rem;
        font-weight:600;
        border:1px solid;
        white-space:nowrap;
      }
      .badge-emerald { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
      .badge-rose    { background:#fff1f2; color:#9f1239; border-color:#fecdd3; }
      .badge-gray    { background:#f8fafc; color:#334155; border-color:#e2e8f0; }
      .badge-amber   { background:#fffbeb; color:#92400e; border-color:#fde68a; }
      .badge-indigo  { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    </style>
</head>
<body class="bg-gradient-to-br from-sky-50 via-white to-indigo-50 text-gray-900 min-h-screen antialiased">

<div class="fixed inset-0 bg-bubbles -z-20"></div>
<div class="fixed inset-0 grid-pattern -z-10"></div>

<?php include 'components/navbar.php'; ?>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-24 sm:py-28 gap-8">

    <!-- Sidebar -->
    <?php include 'components/sidebar_student.php'; ?>

    <!-- Main Content -->
    <main class="w-full space-y-8 animate-fadeUp">

        <!-- Hero -->
        <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-indigo-500 via-indigo-600 to-sky-500 text-white shadow-2xl border border-white/40">
          <div class="absolute inset-0 opacity-45 mix-blend-soft-light"
               style="background-image:radial-gradient(circle at 0 0, rgba(244,244,245,0.9), transparent 55%);">
          </div>

          <div class="relative px-5 sm:px-8 py-6 sm:py-8 lg:py-9 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5 sm:gap-6">
            <div class="space-y-3 sm:space-y-4 max-w-xl">
              <div class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/30 backdrop-blur">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/25">
                  <ion-icon name="help-circle-outline" class="text-sm"></ion-icon>
                </span>
                Quiz overview · Track your progress
              </div>

              <div class="flex items-center gap-3 flex-wrap">
                <div class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-white/20 ring-1 ring-white/40 shadow-lg">
                  <ion-icon name="reader-outline" class="text-2xl"></ion-icon>
                </div>
                <div>
                  <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight">
                    My Quizzes
                  </h1>
                  <p class="mt-1 text-sm sm:text-base text-indigo-100/90 max-w-md">
                    Review your quizzes across all enrolled courses. See what you’ve completed,
                    what you’ve passed, and where you can improve.
                  </p>
                </div>
              </div>

              <div class="flex flex-wrap items-center gap-2.5 mt-1 sm:mt-2 text-xs sm:text-sm">
                <span class="badge badge-indigo bg-white/20 border-white/40 text-indigo-50">
                  <ion-icon name="albums-outline"></ion-icon>
                  <?= (int)$totalQuizzes ?> total quizzes
                </span>
                <span class="badge badge-emerald bg-white/15 border-white/40 text-emerald-50">
                  <ion-icon name="checkmark-done-outline"></ion-icon>
                  <?= (int)$completedCount ?> completed
                </span>
                <span class="badge badge-emerald bg-white/15 border-white/40 text-emerald-50">
                  <ion-icon name="trophy-outline"></ion-icon>
                  <?= (int)$passedCount ?> passed
                </span>
                <span class="badge badge-rose bg-white/15 border-white/40 text-rose-50">
                  <ion-icon name="close-circle-outline"></ion-icon>
                  <?= (int)$failedCount ?> failed
                </span>
                <span class="badge badge-amber bg-white/15 border-white/40 text-amber-50">
                  <ion-icon name="time-outline"></ion-icon>
                  <?= (int)$notAttemptedCount ?> not attempted
                </span>
              </div>
            </div>

            <div class="flex flex-col items-end gap-4">
              <a href="student_dashboard.php"
                 class="inline-flex items-center gap-2 rounded-full border border-white/40 bg-white/15 px-4 py-2 text-sm font-medium text-white hover:bg-white/25 transition shadow-sm">
                <ion-icon name="arrow-back-outline" class="text-lg"></ion-icon>
                Back to Dashboard
              </a>
            </div>
          </div>
        </section>

        <?php if (empty($courses)): ?>

            <!-- No courses -->
            <section class="bg-white/95 backdrop-blur-xl p-8 rounded-3xl shadow-2xl border border-slate-200/70 text-center">
                <div class="inline-flex items-center justify-center h-16 w-16 rounded-2xl bg-indigo-50 text-indigo-600 shadow-md border border-indigo-100 mb-3">
                  <ion-icon name="information-circle-outline" class="text-3xl"></ion-icon>
                </div>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900 mb-1">
                  You are not enrolled in any courses yet
                </h2>
                <p class="text-sm sm:text-base text-slate-600">
                  Enroll in a course to start taking quizzes and tracking your performance.
                </p>
                <div class="mt-4 flex flex-col sm:flex-row justify-center items-center gap-3">
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
            </section>

        <?php else: ?>

            <!-- Summary cards -->
            <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
              <div class="bg-white/95 border border-slate-200/80 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-slate-500 inline-flex items-center gap-1">
                  <ion-icon name="albums-outline" class="text-slate-500"></ion-icon>
                  Total Quizzes
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                  <span class="text-2xl font-extrabold text-slate-900"><?= (int)$totalQuizzes ?></span>
                </div>
              </div>

              <div class="bg-white/95 border border-slate-200/80 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-slate-500 inline-flex items-center gap-1">
                  <ion-icon name="checkmark-done-outline" class="text-emerald-600"></ion-icon>
                  Completed
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                  <span class="text-2xl font-extrabold text-emerald-600"><?= (int)$completedCount ?></span>
                </div>
              </div>

              <div class="bg-white/95 border border-slate-200/80 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-slate-500 inline-flex items-center gap-1">
                  <ion-icon name="trophy-outline" class="text-indigo-600"></ion-icon>
                  Passed
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                  <span class="text-2xl font-extrabold text-indigo-700"><?= (int)$passedCount ?></span>
                </div>
              </div>

              <div class="bg-white/95 border border-slate-200/80 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-slate-500 inline-flex items-center gap-1">
                  <ion-icon name="close-circle-outline" class="text-rose-600"></ion-icon>
                  Failed
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                  <span class="text-2xl font-extrabold text-rose-600"><?= (int)$failedCount ?></span>
                </div>
              </div>

              <div class="bg-white/95 border border-slate-200/80 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-slate-500 inline-flex items-center gap-1">
                  <ion-icon name="time-outline" class="text-amber-500"></ion-icon>
                  Not Attempted
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                  <span class="text-2xl font-extrabold text-amber-600"><?= (int)$notAttemptedCount ?></span>
                </div>
              </div>
            </section>

            <!-- Filters -->
            <section class="bg-white/95 backdrop-blur-xl p-4 sm:p-5 rounded-3xl shadow-xl border border-slate-200/80">
              <div class="flex items-center justify-between gap-3 mb-3">
                <div class="flex items-center gap-2 text-sm text-slate-600">
                  <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 border border-indigo-100">
                    <ion-icon name="funnel-outline" class="text-lg"></ion-icon>
                  </span>
                  <div>
                    <div class="font-semibold text-slate-800">Filter your quizzes</div>
                    <div class="text-xs text-slate-500">Search by quiz or course and filter by status.</div>
                  </div>
                </div>
                <div class="hidden sm:flex items-center gap-2 text-xs text-slate-500">
                  <ion-icon name="stats-chart-outline" class="text-slate-400"></ion-icon>
                  Showing <span id="shownCount"><?= (int)$totalQuizzes ?></span> of <?= (int)$totalQuizzes ?> quizzes
                </div>
              </div>

              <div class="flex flex-col md:flex-row md:items-center gap-3 md:gap-4">
                <div class="relative flex-1 min-w-[240px]">
                  <input id="searchInput" type="text" placeholder="Search by quiz or course..."
                         class="w-full rounded-2xl bg-slate-50 border border-slate-200/80 px-4 py-2.5 pl-11 text-sm shadow-inner focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400 outline-none"
                         aria-label="Search quizzes">
                  <ion-icon name="search-outline"
                            class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xl">
                  </ion-icon>
                </div>
                <div class="flex gap-2">
                  <div class="relative">
                    <ion-icon name="filter-outline"
                              class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                    </ion-icon>
                    <select id="statusFilter"
                            class="pl-9 rounded-2xl border border-slate-200/80 bg-white px-4 py-2.5 text-xs sm:text-sm focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400">
                      <option value="">All Statuses</option>
                      <option value="not_attempted">Not Attempted</option>
                      <option value="completed">Completed</option>
                      <option value="passed">Passed</option>
                      <option value="failed">Failed</option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="mt-2 flex items-center gap-2 text-xs text-slate-500 sm:hidden">
                <ion-icon name="stats-chart-outline" class="text-slate-400"></ion-icon>
                Showing <span id="shownCountSm"><?= (int)$totalQuizzes ?></span> of <?= (int)$totalQuizzes ?> quizzes
              </div>
            </section>

            <!-- No results after filtering -->
            <section id="noResults"
                     class="hidden bg-white/95 backdrop-blur-xl p-6 rounded-3xl shadow-xl border border-slate-200/80 text-center">
              <div class="inline-flex items-center justify-center h-12 w-12 rounded-2xl bg-slate-50 text-slate-600 mb-2 border border-slate-200">
                <ion-icon name="filter-circle-outline" class="text-2xl"></ion-icon>
              </div>
              <h3 class="text-base sm:text-lg font-semibold text-slate-900">No quizzes match your filters</h3>
              <p class="mt-1 text-sm text-slate-600">
                Try adjusting the search text or status filter to see more quizzes.
              </p>
            </section>

            <!-- Course sections -->
            <?php foreach ($courses as $course): ?>
                <?php
                  $cid         = (int)$course['course_id'];
                  $course_name = $course['name'];
                  $quizzes     = $courseQuizzes[$cid] ?? [];
                  $courseQuizCount = count($quizzes);
                ?>
                <section class="mt-4">
                  <div class="flex items-center justify-between gap-3 mb-2 sm:mb-3">
                    <div class="flex items-center gap-2 sm:gap-3">
                      <span class="inline-flex h-9 w-9 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-100">
                        <ion-icon name="book-outline" class="text-xl"></ion-icon>
                      </span>
                      <div>
                        <h3 class="text-lg sm:text-xl font-semibold text-slate-900">
                          <?= htmlspecialchars($course_name) ?>
                        </h3>
                        <p class="text-xs text-slate-500">
                          <?= (int)$courseQuizCount ?> quiz<?= $courseQuizCount === 1 ? '' : 'zes' ?> in this course
                        </p>
                      </div>
                    </div>
                  </div>

                  <?php if (empty($quizzes)): ?>
                      <div class="bg-white/95 border border-slate-200/80 rounded-2xl p-5 text-sm text-slate-600 shadow-sm">
                        <em>No quizzes added yet for this course.</em>
                      </div>
                  <?php else: ?>
                      <div class="overflow-hidden rounded-2xl shadow-xl border border-slate-200/80 bg-white/95">
                          <table class="w-full table-auto">
                              <thead class="bg-gradient-to-r from-slate-50 to-indigo-50/60 text-xs sm:text-sm text-slate-700">
                                  <tr>
                                      <th class="px-4 py-3 border-b text-left font-semibold">Quiz Title</th>
                                      <th class="px-4 py-3 border-b text-center font-semibold">Attempts</th>
                                      <th class="px-4 py-3 border-b text-center font-semibold">Status</th>
                                      <th class="px-4 py-3 border-b text-center font-semibold">Score</th>
                                      <th class="px-4 py-3 border-b text-center font-semibold">Result</th>
                                      <th class="px-4 py-3 border-b text-center font-semibold">Last Attempt</th>
                                      <th class="px-4 py-3 border-b text-center font-semibold">Action</th>
                                  </tr>
                              </thead>
                              <tbody class="text-xs sm:text-sm">
                                  <?php foreach ($quizzes as $q):
                                      $rawTitle = $q['title'];
                                      $title    = htmlspecialchars($rawTitle);
                                      $attempts = (int)$q['attempts'];
                                      $score    = is_null($q['score']) ? null : number_format((float)$q['score'], 2);
                                      $status   = $q['status']; // 'not_attempted' or 'completed'
                                      $passed   = is_null($q['passed']) ? null : (bool)$q['passed'];
                                      $lastRaw  = $q['last_attempted'] ? date('M d, Y, g:i A', strtotime($q['last_attempted'])) : null;
                                      $last     = $lastRaw ? htmlspecialchars($lastRaw) : '-';

                                      // Result badge
                                      if (is_null($passed)) {
                                          $resultBadge = '<span class="badge badge-gray"><ion-icon name="remove-outline"></ion-icon>-</span>';
                                      } elseif ($passed) {
                                          $resultBadge = '<span class="badge badge-emerald"><ion-icon name="trophy-outline"></ion-icon>Pass</span>';
                                      } else {
                                          $resultBadge = '<span class="badge badge-rose"><ion-icon name="close-circle-outline"></ion-icon>Fail</span>';
                                      }

                                      // Status badge
                                      if ($status === 'completed') {
                                          $statusBadge = '<span class="badge badge-emerald"><ion-icon name="checkmark-done-outline"></ion-icon>Completed</span>';
                                      } else {
                                          $statusBadge = '<span class="badge badge-amber"><ion-icon name="time-outline"></ion-icon>Not Attempted</span>';
                                      }

                                      // Derived status for filtering: passed/failed override completed
                                      $filterStatus = $status;
                                      if ($status === 'completed') {
                                          if ($passed === true) $filterStatus = 'passed';
                                          elseif ($passed === false) $filterStatus = 'failed';
                                      }

                                      $content_id = (int)$q['content_id'];

                                      // Score display with icon
                                      $scoreHtml = is_null($score)
                                        ? '<span class="text-slate-500">-</span>'
                                        : '<span class="inline-flex items-center gap-1.5 text-slate-800"><ion-icon name="speedometer-outline" class="text-slate-500"></ion-icon>' . htmlspecialchars($score) . '</span>';
                                  ?>
                                  <tr class="quiz-row odd:bg-white even:bg-slate-50/70 hover:bg-indigo-50/70 transition-colors"
                                      data-title="<?= htmlspecialchars(mb_strtolower($rawTitle), ENT_QUOTES) ?>"
                                      data-course="<?= htmlspecialchars(mb_strtolower($course_name), ENT_QUOTES) ?>"
                                      data-status="<?= htmlspecialchars($filterStatus) ?>">
                                      <td class="px-4 py-3 border-t align-middle">
                                        <span class="font-medium text-slate-900"><?= $title ?></span>
                                      </td>
                                      <td class="px-4 py-3 border-t text-center align-middle">
                                        <span class="inline-flex items-center gap-1.5 text-slate-700">
                                          <ion-icon name="repeat-outline" class="text-slate-500"></ion-icon>
                                          <?= $attempts ?>
                                        </span>
                                      </td>
                                      <td class="px-4 py-3 border-t text-center align-middle">
                                        <?= $statusBadge ?>
                                      </td>
                                      <td class="px-4 py-3 border-t text-center align-middle">
                                        <?= $scoreHtml ?>
                                      </td>
                                      <td class="px-4 py-3 border-t text-center align-middle">
                                        <?= $resultBadge ?>
                                      </td>
                                      <td class="px-4 py-3 border-t text-center align-middle">
                                        <?php if ($lastRaw): ?>
                                          <span class="inline-flex items-center gap-1.5 text-slate-700">
                                            <ion-icon name="time-outline" class="text-slate-500"></ion-icon><?= $last ?>
                                          </span>
                                        <?php else: ?>
                                          <span class="text-slate-500">-</span>
                                        <?php endif; ?>
                                      </td>
                                      <td class="px-4 py-3 border-t text-center align-middle">
                                          <a href="view_content.php?id=<?= $content_id ?>"
                                             class="inline-flex items-center gap-2 bg-indigo-600 text-white px-3 py-1.5 rounded-xl text-xs sm:text-sm font-medium hover:bg-indigo-700 shadow-md shadow-indigo-500/25 transition">
                                              <ion-icon name="<?= $status === 'completed' ? 'refresh-outline' : 'play-outline' ?>"></ion-icon>
                                              <?= $status === 'completed' ? 'Retake Quiz' : 'Start Quiz' ?>
                                          </a>
                                      </td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                  <?php endif; ?>
                </section>
            <?php endforeach; ?>

        <?php endif; ?>
    </main>
</div>

<?php include 'components/footer.php'; ?>

<script>
  // Global search + status filter across all course tables
  const searchInput   = document.getElementById('searchInput');
  const statusFilter  = document.getElementById('statusFilter');
  const shownCount    = document.getElementById('shownCount');
  const shownCountSm  = document.getElementById('shownCountSm');
  const noResultsCard = document.getElementById('noResults');

  function applyFilters() {
    const q      = (searchInput?.value || '').toLowerCase().trim();
    const status = (statusFilter?.value || '').toLowerCase();
    const rows   = document.querySelectorAll('.quiz-row');

    let visible = 0;
    rows.forEach(row => {
      const title  = row.dataset.title || '';
      const course = row.dataset.course || '';
      const st     = row.dataset.status || ''; // not_attempted / completed / passed / failed

      const matchText   = !q || title.includes(q) || course.includes(q);
      const matchStatus = !status || st === status || (status === 'completed' && (st === 'passed' || st === 'failed'));

      const show = matchText && matchStatus;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    if (shownCount)   shownCount.textContent   = String(visible);
    if (shownCountSm) shownCountSm.textContent = String(visible);

    if (noResultsCard) {
      const hasRows = rows.length > 0;
      noResultsCard.classList.toggle('hidden', visible > 0 || !hasRows);
    }
  }

  searchInput?.addEventListener('input', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
  applyFilters();
</script>
</body>
</html>