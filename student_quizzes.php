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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Ionicons -->
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
      html, body { font-family: "Inter", ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; }
      @keyframes fadeUp { from { opacity:0; transform: translateY(10px);} to { opacity:1; transform: translateY(0);} }
      .animate-fadeUp { animation: fadeUp .45s ease-out both; }
      .bg-bubbles::before, .bg-bubbles::after {
        content:""; position:absolute; border-radius:9999px; filter: blur(40px); opacity:.25; z-index:0; pointer-events:none;
      }
      .bg-bubbles::before { width:420px; height:420px; background: radial-gradient(closest-side,#60a5fa,transparent 70%); top:-80px; left:-80px; }
      .bg-bubbles::after  { width:500px; height:500px; background: radial-gradient(closest-side,#a78bfa,transparent 70%); bottom:-120px; right:-120px; }

      /* Badges */
      .badge { display:inline-flex; align-items:center; gap:.375rem; padding:.25rem .625rem; border-radius:9999px; font-size:.75rem; font-weight:600; border:1px solid; white-space:nowrap; }
      .badge-emerald { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
      .badge-rose    { background:#fff1f2; color:#9f1239; border-color:#fecdd3; }
      .badge-gray    { background:#f8fafc; color:#334155; border-color:#e2e8f0; }
      .badge-amber   { background:#fffbeb; color:#92400e; border-color:#fde68a; }
      .badge-indigo  { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    </style>
</head>
<body class="bg-gradient-to-br from-sky-50 via-white to-indigo-50 text-gray-900 min-h-screen">

<?php include 'components/navbar.php'; ?>

<div class="fixed inset-0 bg-bubbles -z-10"></div>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-6 lg:px-10 py-28 gap-8">

    <!-- Sidebar -->
    <?php include 'components/sidebar_student.php'; ?>

    <!-- Main Content -->
    <main class="w-full space-y-8 animate-fadeUp">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600/90 text-white shadow-sm">
                <ion-icon name="reader-outline" class="text-xl"></ion-icon>
              </span>
              <h2 class="text-3xl font-extrabold text-gray-800">Quizzes for Your Courses</h2>
            </div>
            <a href="student_dashboard.php" class="inline-flex items-center gap-1.5 text-indigo-600 hover:text-indigo-800 font-medium">
              <ion-icon name="arrow-back-outline"></ion-icon>
              Back to Dashboard
            </a>
        </div>

        <?php if (empty($courses)): ?>
            <div class="bg-white/80 backdrop-blur-sm p-8 rounded-2xl shadow-xl border border-gray-100 text-center">
                <div class="inline-flex items-center justify-center h-12 w-12 rounded-full bg-indigo-50 text-indigo-600 mb-2">
                  <ion-icon name="information-circle-outline" class="text-2xl"></ion-icon>
                </div>
                <p class="text-gray-700 text-lg">You are not enrolled in any courses.</p>
                <a href="enroll_course.php" class="mt-4 inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition">
                    <ion-icon name="add-circle-outline" class="text-xl"></ion-icon>
                    Enroll Here
                </a>
            </div>
        <?php else: ?>

            <!-- Summary -->
            <section class="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <div class="bg-white/80 border border-gray-100 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-gray-500 inline-flex items-center gap-1">
                  <ion-icon name="albums-outline" class="text-slate-600"></ion-icon> Total Quizzes
                </div>
                <div class="text-2xl font-bold text-gray-800 mt-1"><?= (int)$totalQuizzes ?></div>
              </div>
              <div class="bg-white/80 border border-gray-100 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-gray-500 inline-flex items-center gap-1">
                  <ion-icon name="checkmark-done-outline" class="text-emerald-600"></ion-icon> Completed
                </div>
                <div class="text-2xl font-bold text-emerald-600 mt-1"><?= (int)$completedCount ?></div>
              </div>
              <div class="bg-white/80 border border-gray-100 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-gray-500 inline-flex items-center gap-1">
                  <ion-icon name="trophy-outline" class="text-indigo-600"></ion-icon> Passed
                </div>
                <div class="text-2xl font-bold text-indigo-700 mt-1"><?= (int)$passedCount ?></div>
              </div>
              <div class="bg-white/80 border border-gray-100 rounded-2xl p-4 shadow-sm">
                <div class="text-xs text-gray-500 inline-flex items-center gap-1">
                  <ion-icon name="close-circle-outline" class="text-rose-600"></ion-icon> Failed
                </div>
                <div class="text-2xl font-bold text-rose-600 mt-1"><?= (int)$failedCount ?></div>
              </div>
            </section>

            <!-- Filters -->
            <section class="bg-white/80 backdrop-blur-sm p-4 sm:p-5 rounded-2xl shadow border border-gray-100">
              <div class="flex items-center gap-2 text-sm text-gray-600 mb-3">
                <ion-icon name="funnel-outline" class="text-indigo-600"></ion-icon>
                Refine your list
              </div>
              <div class="flex flex-col md:flex-row md:items-center gap-3 md:gap-4">
                <div class="relative flex-1 min-w-[240px]">
                  <input id="searchInput" type="text" placeholder="Search by quiz or course..."
                        class="w-full rounded-full bg-white/80 border border-gray-200 px-4 py-2.5 pl-11 shadow-sm focus:ring-2 focus:ring-indigo-500/40 focus:outline-none" aria-label="Search quizzes">
                  <ion-icon name="search-outline" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xl"></ion-icon>
                </div>
                <div class="flex gap-2">
                  <div class="relative">
                    <ion-icon name="filter-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
                    <select id="statusFilter" class="pl-9 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500/40">
                      <option value="">All Statuses</option>
                      <option value="not_attempted">Not Attempted</option>
                      <option value="completed">Completed</option>
                      <option value="passed">Passed</option>
                      <option value="failed">Failed</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="mt-2 text-sm text-gray-500 inline-flex items-center gap-2">
                <ion-icon name="stats-chart-outline" class="text-slate-500"></ion-icon>
                Showing <span id="shownCount"><?= (int)$totalQuizzes ?></span> of <?= (int)$totalQuizzes ?> quizzes
              </div>
            </section>

            <?php foreach ($courses as $course): ?>
                <?php
                  $cid = (int)$course['course_id'];
                  $course_name = $course['name'];
                  $quizzes = $courseQuizzes[$cid] ?? [];
                ?>
                <section>
                    <h3 class="text-xl font-semibold text-gray-800 mt-6 mb-3 inline-flex items-center gap-2">
                      <ion-icon name="book-outline" class="text-indigo-600"></ion-icon>
                      <?= htmlspecialchars($course_name) ?>
                    </h3>

                    <?php if (empty($quizzes)): ?>
                        <p class="text-gray-500"><em>No quizzes added yet.</em></p>
                    <?php else: ?>
                        <div class="overflow-x-auto rounded-2xl shadow-xl border border-gray-100 bg-white/80 backdrop-blur-sm">
                            <table class="w-full table-auto">
                                <thead class="bg-gradient-to-r from-blue-50 to-indigo-50 text-sm text-gray-700">
                                    <tr>
                                        <th class="p-3 border text-left font-semibold">Quiz Title</th>
                                        <th class="p-3 border text-center font-semibold">Attempts</th>
                                        <th class="p-3 border text-center font-semibold">Status</th>
                                        <th class="p-3 border text-center font-semibold">Score</th>
                                        <th class="p-3 border text-center font-semibold">Result</th>
                                        <th class="p-3 border text-center font-semibold">Last Attempt</th>
                                        <th class="p-3 border text-center font-semibold">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm">
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
                                    <tr class="hover:bg-gray-50 quiz-row"
                                        data-title="<?= htmlspecialchars(mb_strtolower($rawTitle), ENT_QUOTES) ?>"
                                        data-course="<?= htmlspecialchars(mb_strtolower($course_name), ENT_QUOTES) ?>"
                                        data-status="<?= htmlspecialchars($filterStatus) ?>">
                                        <td class="p-3 border"><?= $title ?></td>
                                        <td class="p-3 border text-center">
                                          <span class="inline-flex items-center gap-1.5">
                                            <ion-icon name="repeat-outline" class="text-slate-500"></ion-icon>
                                            <?= $attempts ?>
                                          </span>
                                        </td>
                                        <td class="p-3 border text-center"><?= $statusBadge ?></td>
                                        <td class="p-3 border text-center"><?= $scoreHtml ?></td>
                                        <td class="p-3 border text-center"><?= $resultBadge ?></td>
                                        <td class="p-3 border text-center">
                                          <?php if ($lastRaw): ?>
                                            <span class="inline-flex items-center gap-1.5"><ion-icon name="time-outline" class="text-slate-500"></ion-icon><?= $last ?></span>
                                          <?php else: ?>
                                            <span class="text-slate-500">-</span>
                                          <?php endif; ?>
                                        </td>
                                        <td class="p-3 border text-center">
                                            <a href="view_content.php?id=<?= $content_id ?>"
                                               class="inline-flex items-center gap-2 bg-indigo-600 text-white px-3 py-2 rounded hover:bg-indigo-700 transition">
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
  const searchInput = document.getElementById('searchInput');
  const statusFilter = document.getElementById('statusFilter');
  const shownCount = document.getElementById('shownCount');

  function applyFilters() {
    const q = (searchInput?.value || '').toLowerCase().trim();
    const status = (statusFilter?.value || '').toLowerCase();
    const rows = document.querySelectorAll('.quiz-row');

    let visible = 0;
    rows.forEach(row => {
      const title  = row.dataset.title || '';
      const course = row.dataset.course || '';
      const st     = row.dataset.status || ''; // not_attempted / completed / passed / failed

      const matchText = !q || title.includes(q) || course.includes(q);
      const matchStatus = !status || st === status || (status === 'completed' && (st === 'passed' || st === 'failed'));

      const show = matchText && matchStatus;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    if (shownCount) shownCount.textContent = String(visible);
  }

  searchInput?.addEventListener('input', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
  applyFilters();
</script>
</body>
</html>