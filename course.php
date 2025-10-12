<?php
session_start();
include 'db_connect.php';

/* =================== Quiz options =================== */
const QUIZ_MAX_ATTEMPTS = 0;         // 0 = unlimited attempts
const QUIZ_SHUFFLE_QUESTIONS = true; // shuffle the questions shown to students
/* ==================================================== */

// Allow both students and teachers
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'teacher'], true)) {
    http_response_code(403);
    die("Access Denied.");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$user_id   = (int)$_SESSION['user_id'];
$role      = $_SESSION['role'];
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Access checks (prepared)
if ($role === 'student') {
    $check = $conn->prepare("SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? AND status = 'active' LIMIT 1");
    $check->bind_param("ii", $user_id, $course_id);
    $check->execute();
    $r = $check->get_result();
    if (!$r || $r->num_rows === 0) die("⛔ You are not enrolled in this course.");
    $check->close();
} else { // teacher
    $trowStmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ? LIMIT 1");
    $trowStmt->bind_param("i", $user_id);
    $trowStmt->execute();
    $trow = $trowStmt->get_result()->fetch_assoc();
    $trowStmt->close();
    $teacher_id = (int)($trow['teacher_id'] ?? 0);

    $check = $conn->prepare("SELECT 1 FROM teacher_courses WHERE teacher_id = ? AND course_id = ? LIMIT 1");
    $check->bind_param("ii", $teacher_id, $course_id);
    $check->execute();
    $r = $check->get_result();
    if (!$r || $r->num_rows === 0) die("⛔ You are not assigned to this course.");
    $check->close();
}

// Get course info
$courseStmt = $conn->prepare("SELECT name, description FROM courses WHERE course_id = ? LIMIT 1");
$courseStmt->bind_param("i", $course_id);
$courseStmt->execute();
$course = $courseStmt->get_result()->fetch_assoc();
$courseStmt->close();
if (!$course) $course = ['name'=>'Course','description'=>''];

// Fetch contents
$contentsStmt = $conn->prepare("SELECT content_id, course_id, type, title, body, file_url, position FROM contents WHERE course_id = ? ORDER BY position ASC");
$contentsStmt->bind_param("i", $course_id);
$contentsStmt->execute();
$res = $contentsStmt->get_result();
$contents = [];
while ($row = $res->fetch_assoc()) $contents[] = $row;
$contentsStmt->close();
$total_contents = count($contents);

// Student id (if student)
$student_id = 0;
if ($role === 'student') {
    $sidStmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
    $sidStmt->bind_param("i", $user_id);
    $sidStmt->execute();
    $row = $sidStmt->get_result()->fetch_assoc();
    $sidStmt->close();
    $student_id = (int)($row['student_id'] ?? 0);
}

// Progress bar (students)
$progress_percent = 0;
if ($role === 'student' && $student_id) {
    $prStmt = $conn->prepare("SELECT chapters_completed FROM student_progress WHERE student_id = ? AND course_id = ? LIMIT 1");
    $prStmt->bind_param("ii", $student_id, $course_id);
    $prStmt->execute();
    $pr = $prStmt->get_result()->fetch_assoc();
    $prStmt->close();
    $completed = (int)($pr['chapters_completed'] ?? 0);
    if ($total_contents > 0) $progress_percent = min(100, (int) floor(($completed / $total_contents) * 100));
}

// Viewed content for completion ticks (students)
$viewedIds = [];
if ($role === 'student') {
    $vStmt = $conn->prepare("
        SELECT DISTINCT al.content_id
        FROM activity_logs al
        JOIN contents c ON c.content_id = al.content_id
        WHERE al.user_id = ? AND al.action = 'view' AND c.course_id = ?
    ");
    $vStmt->bind_param("ii", $user_id, $course_id);
    $vStmt->execute();
    $vres = $vStmt->get_result();
    while ($r = $vres->fetch_assoc()) $viewedIds[(int)$r['content_id']] = true;
    $vStmt->close();
}

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Quiz nonce helpers to prevent double submit ---------- */
if (!isset($_SESSION['quiz_nonce'])) $_SESSION['quiz_nonce'] = [];
function quiz_generate_nonce(int $content_id): string {
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['quiz_nonce'][$content_id] = $nonce;
    return $nonce;
}
function quiz_consume_nonce(int $content_id, string $nonce): bool {
    if (empty($_SESSION['quiz_nonce'][$content_id])) return false;
    $valid = hash_equals($_SESSION['quiz_nonce'][$content_id], $nonce);
    unset($_SESSION['quiz_nonce'][$content_id]); // one-time use
    return $valid;
}

// Log view + progress
function log_view_and_progress(mysqli $conn, int $user_id, int $content_id, int $course_id, string $role): void {
    if ($role !== 'student') return;

    // Already logged?
    $chk = $conn->prepare("SELECT 1 FROM activity_logs WHERE user_id = ? AND content_id = ? AND action = 'view' LIMIT 1");
    $chk->bind_param("ii", $user_id, $content_id);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();
    if ($exists) return;

    // Log view
    $ins = $conn->prepare("INSERT INTO activity_logs (user_id, content_id, action) VALUES (?, ?, 'view')");
    $ins->bind_param("ii", $user_id, $content_id);
    $ins->execute();
    $ins->close();

    // Fetch student_id
    $sidStmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
    $sidStmt->bind_param("i", $user_id);
    $sidStmt->execute();
    $row = $sidStmt->get_result()->fetch_assoc();
    $sidStmt->close();
    $student_id = (int)($row['student_id'] ?? 0);
    if ($student_id > 0) {
        $prog = $conn->prepare("
            INSERT INTO student_progress (student_id, course_id, chapters_completed)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE chapters_completed = chapters_completed + 1
        ");
        $prog->bind_param("ii", $student_id, $course_id);
        $prog->execute();
        $prog->close();
    }
}

// AJAX: log view (with CSRF)
if (isset($_POST['action']) && $_POST['action'] === 'log_view' && $role === 'student') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('csrf');
    }
    $cid = (int)($_POST['content_id'] ?? 0);
    if ($cid > 0) log_view_and_progress($conn, $user_id, $cid, $course_id, $role);
    exit('ok');
}

// Forum Post Handler
if (isset($_POST['post_forum']) && isset($_POST['forum_body'], $_POST['forum_content_id']) && $role === 'student') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo "<script>alert('Invalid session. Please refresh and try again.');</script>";
    } else {
        $msg = trim((string)$_POST['forum_body']);
        $cid = (int)$_POST['forum_content_id'];
        if ($msg !== '' && $cid > 0) {
            $stmt = $conn->prepare("INSERT INTO forum_posts (content_id, user_id, body) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $cid, $user_id, $msg);
            $stmt->execute();
            $stmt->close();
            echo "<script>window.location.href = '" . strtok($_SERVER['REQUEST_URI'], '?') . "?course_id=$course_id#content_$cid';</script>";
            exit;
        }
    }
}

/* ==================== QUIZ SUBMISSION (HARDENED) ==================== */
if (isset($_POST['submit_quiz'], $_POST['quiz']) && $role === 'student') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo "<script>alert('Invalid session. Please refresh and try again.');</script>";
    } else {
        $assignment_id   = (int)$_POST['submit_quiz'];
        $quiz_content_id = (int)($_POST['quiz_content_id'] ?? 0);
        $quiz_nonce      = (string)($_POST['quiz_nonce'] ?? '');

        // One-time form nonce
        if (!$quiz_content_id || !$quiz_nonce || !quiz_consume_nonce($quiz_content_id, $quiz_nonce)) {
            echo "<script>alert('This form has expired. Please reopen the quiz.');</script>";
        } else {
            // Resolve student_id
            $sidStmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
            $sidStmt->bind_param("i", $user_id);
            $sidStmt->execute();
            $rowS = $sidStmt->get_result()->fetch_assoc();
            $sidStmt->close();
            $student_id = (int)($rowS['student_id'] ?? 0);

            if ($student_id <= 0) {
                echo "<script>alert('Unable to resolve student.');</script>";
            } else {
                // Verify the assignment belongs to this content AND course
                $v = $conn->prepare("
                    SELECT a.assignment_id, a.passing_score
                    FROM assignments a
                    JOIN contents c ON c.content_id = a.lesson_id
                    WHERE a.assignment_id = ? AND a.lesson_id = ? AND c.course_id = ?
                    LIMIT 1
                ");
                $v->bind_param("iii", $assignment_id, $quiz_content_id, $course_id);
                $v->execute();
                $assignment = $v->get_result()->fetch_assoc();
                $v->close();

                if (!$assignment) {
                    echo "<script>alert('Invalid quiz payload.');</script>";
                } else {
                    // Attempt limit check
                    if (QUIZ_MAX_ATTEMPTS > 0) {
                        $cntStmt = $conn->prepare("
                            SELECT COUNT(*) AS cnt FROM student_assignment_attempts WHERE student_id = ? AND assignment_id = ?
                        ");
                        $cntStmt->bind_param("ii", $student_id, $assignment_id);
                        $cntStmt->execute();
                        $cnt = (int)($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
                        $cntStmt->close();
                        if ($cnt >= QUIZ_MAX_ATTEMPTS) {
                            echo "<script>alert('You have reached the maximum number of attempts for this quiz.');</script>";
                            // Redirect back to the quiz section
                            echo "<script>location.href='" . strtok($_SERVER['REQUEST_URI'], '?') . "?course_id=$course_id#content_$quiz_content_id';</script>";
                            exit;
                        }
                    }

                    // Load questions to array
                    $qStmt = $conn->prepare("
                        SELECT question_id, correct_option
                        FROM assignment_questions
                        WHERE assignment_id = ?
                        ORDER BY question_id ASC
                    ");
                    $qStmt->bind_param("i", $assignment_id);
                    $qStmt->execute();
                    $qRes = $qStmt->get_result();
                    $questionRows = $qRes->fetch_all(MYSQLI_ASSOC);
                    $qStmt->close();

                    $answers = $_POST['quiz'] ?? [];
                    $validOpts = ['A','B','C','D'];

                    // Transaction: attempt + answers + final score
                    $conn->begin_transaction();
                    try {
                        // Create attempt placeholder
                        $insAtt = $conn->prepare("
                            INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed)
                            VALUES (?, ?, 0, 0)
                        ");
                        $insAtt->bind_param("ii", $student_id, $assignment_id);
                        $insAtt->execute();
                        $attempt_id = (int)$conn->insert_id;
                        $insAtt->close();

                        $score = 0;
                        $insQ = $conn->prepare("
                            INSERT INTO assignment_attempt_questions (attempt_id, question_id, selected_option, is_correct)
                            VALUES (?, ?, ?, ?)
                        ");

                        foreach ($questionRows as $qr) {
                            $qid = (int)$qr['question_id'];
                            $selected = strtoupper((string)($answers[$qid] ?? ''));
                            if (!in_array($selected, $validOpts, true)) {
                                $selected = '';
                            }
                            $is_correct = (int)($selected !== '' && $selected === strtoupper($qr['correct_option']));
                            $score += $is_correct;

                            $insQ->bind_param("iisi", $attempt_id, $qid, $selected, $is_correct);
                            $insQ->execute();
                        }
                        $insQ->close();

                        $passScore = (int)($assignment['passing_score'] ?? 0);
                        $passed    = ($score >= $passScore) ? 1 : 0;

                        $up = $conn->prepare("UPDATE student_assignment_attempts SET score = ?, passed = ? WHERE attempt_id = ?");
                        $up->bind_param("iii", $score, $passed, $attempt_id);
                        $up->execute();
                        $up->close();

                        $conn->commit();

                        echo "<script>alert('✅ Quiz submitted! Score: ".(int)$score."'); window.location.href = '" . strtok($_SERVER['REQUEST_URI'], '?') . "?course_id=$course_id#content_$quiz_content_id';</script>";
                        exit;
                    } catch (Throwable $e) {
                        $conn->rollback();
                        error_log('Quiz submit failed: '.$e->getMessage());
                        echo "<script>alert('Could not submit quiz. Please try again.');</script>";
                    }
                }
            }
        }
    }
}
/* ================== END QUIZ SUBMISSION ================== */

$badgeMap = [
  'lesson' => 'bg-sky-100 text-sky-700',
  'video'  => 'bg-rose-100 text-rose-700',
  'pdf'    => 'bg-amber-100 text-amber-800',
  'quiz'   => 'bg-emerald-100 text-emerald-700',
  'forum'  => 'bg-indigo-100 text-indigo-700',
];
$typeIconName = [
  'lesson' => 'book-outline',
  'video'  => 'videocam-outline',
  'pdf'    => 'document-text-outline',
  'quiz'   => 'trophy-outline',
  'forum'  => 'chatbubbles-outline',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title><?= h($course['name']) ?> - Course</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <!-- Alpine.js -->
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <!-- Ionicons -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    [x-cloak] { display: none; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
    .toc-active { background-color: rgb(239 246 255); border-color: rgb(191 219 254); }
    .prose h1{font-size:1.6rem;margin:0.8rem 0;color:#1e40af}
    .prose h2{font-size:1.3rem;margin:0.75rem 0}
    .prose h3{font-size:1.15rem;margin:0.6rem 0}
    .prose p{margin:0.5rem 0}
    .prose ul{list-style:disc;margin-left:1.25rem;padding-left:1rem}
    .prose ol{list-style:decimal;margin-left:1.25rem;padding-left:1rem}
    .prose img{max-width:100%;height:auto}
    .prose table{border-collapse:collapse;margin:.5rem 0}
    .prose th,.prose td{border:1px solid #e5e7eb;padding:.4rem}
    .prose th{background:#f8fafc}
    .pill[data-off="true"] { opacity: .55; }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 min-h-screen antialiased text-gray-800" id="top">
  <?php include 'components/navbar.php'; ?>

  <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-28">
    <!-- Course Header Card -->
    <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-8 mb-8">
      <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
      </div>
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">
        <div>
          <h1 class="text-3xl md:text-4xl font-extrabold text-blue-700 tracking-tight flex items-center gap-3">
            <ion-icon name="book-outline" class="text-blue-700 text-2xl"></ion-icon> <?= h($course['name']) ?>
            <?php if ($role === 'student'): ?>
              <span class="ml-2 inline-flex items-center gap-1 text-xs rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5">
                <ion-icon name="checkmark-done-outline"></ion-icon>
                <?= (int)$progress_percent ?>% complete
              </span>
            <?php endif; ?>
          </h1>
          <p class="mt-3 text-gray-600 leading-relaxed"><?= nl2br(h($course['description'])) ?></p>
        </div>
        <div class="shrink-0 flex gap-2">
          <?php if ($role === 'teacher'): ?>
            <a href="teacher_course_editor.php?course_id=<?= (int)$course_id ?>"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-gray-700 hover:bg-gray-50 transition">
              <ion-icon name="create-outline"></ion-icon> Edit Course
            </a>
          <?php endif; ?>
          <a href="<?= $role === 'student' ? 'student_courses.php' : 'teacher_dashboard.php' ?>"
             class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
            <ion-icon name="arrow-back-outline"></ion-icon>
            Back to <?= $role === 'student' ? 'Courses' : 'Dashboard' ?>
          </a>
        </div>
      </div>

      <?php if ($role === 'student'): ?>
        <div class="mt-6">
          <div class="flex items-center justify-between text-sm text-gray-600 mb-2">
            <span class="inline-flex items-center gap-1">
              <ion-icon name="stats-chart-outline" class="text-blue-700"></ion-icon> Course progress
            </span>
            <span><?= (int)$progress_percent ?>%</span>
          </div>
          <div class="h-2 w-full bg-gray-200 rounded-full overflow-hidden">
            <div class="h-full bg-blue-600 rounded-full transition-all" style="width: <?= (int)$progress_percent ?>%"></div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- TOC Sidebar -->
      <aside class="lg:col-span-4 xl:col-span-3">
        <div class="sticky top-24 space-y-4">
          <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
            <h3 class="text-lg font-semibold text-gray-900 mb-3 inline-flex items-center gap-2">
              <ion-icon name="list-outline" class="text-blue-700"></ion-icon> Contents
            </h3>
            <div class="relative mb-3">
              <input id="tocSearch" type="text" placeholder="Search content..."
                     class="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <ion-icon name="search-outline" class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></ion-icon>
            </div>
            <div class="flex flex-wrap gap-2 mb-3">
              <?php foreach (['lesson','video','pdf','quiz','forum'] as $t): ?>
                <label class="cursor-pointer">
                  <input type="checkbox" class="sr-only toc-filter" value="<?= $t ?>" checked>
                  <span class="pill inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs ring-1 ring-gray-200 bg-gray-100 text-gray-700"
                        data-type-pill="<?= $t ?>">
                    <ion-icon name="<?= h($typeIconName[$t]) ?>"></ion-icon> <?= ucfirst($t) ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <ul id="tocList" class="space-y-1 max-h-[60vh] overflow-y-auto pr-1">
              <?php if (count($contents) === 0): ?>
                <li class="text-gray-500 text-sm">No content.</li>
              <?php else: ?>
                <?php foreach ($contents as $c):
                  $t = $c['type'];
                  $title = $c['title'] ?? '';
                  $searchKey = strtolower(($title ?? '') . ' ' . ($t ?? ''));
                  $isViewed = $role === 'student' && isset($viewedIds[(int)$c['content_id']]);
                ?>
                  <li data-type="<?= h($t) ?>" data-key="<?= h($searchKey) ?>">
                    <a href="#content_<?= (int)$c['content_id'] ?>"
                       class="flex items-center gap-2 px-3 py-2 rounded-lg border border-transparent hover:bg-blue-50 hover:border-blue-100 transition">
                      <ion-icon name="<?= h($typeIconName[$t] ?? 'document-text-outline') ?>" class="text-blue-700"></ion-icon>
                      <span class="truncate flex-1"><?= h($title) ?></span>
                      <?php if ($isViewed): ?>
                        <span class="inline-flex items-center gap-1 text-emerald-700 text-xs">
                          <ion-icon name="checkmark-circle-outline"></ion-icon> Done
                        </span>
                      <?php endif; ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
          </div>
          <a href="#top" class="block text-center text-blue-700 hover:underline inline-flex items-center gap-1">
            <ion-icon name="arrow-up-outline"></ion-icon> Back to top
          </a>
        </div>
      </aside>

      <!-- Contents -->
      <div class="lg:col-span-8 xl:col-span-9">
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-2xl font-semibold text-gray-900 inline-flex items-center gap-2">
              <ion-icon name="folder-open-outline" class="text-blue-700"></ion-icon>
              Course Contents
            </h3>
            <div class="hidden sm:flex items-center gap-2">
              <button id="expandAll" class="text-sm rounded-md border px-2 py-1 hover:bg-gray-50">
                <ion-icon name="chevron-down-outline"></ion-icon> Expand all
              </button>
              <button id="collapseAll" class="text-sm rounded-md border px-2 py-1 hover:bg-gray-50">
                <ion-icon name="chevron-up-outline"></ion-icon> Collapse all
              </button>
            </div>
          </div>

          <?php if (count($contents) === 0): ?>
            <div class="text-center py-16">
              <div class="mx-auto w-14 h-14 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 mb-4">
                <ion-icon name="document-outline"></ion-icon>
              </div>
              <p class="text-gray-700 font-medium">No content added yet for this course.</p>
              <p class="text-gray-500 text-sm mt-1">Please check back later.</p>
            </div>
          <?php else: ?>
            <div id="contentList" class="space-y-5">
              <?php foreach ($contents as $c):
                $content_type = $c['type'];
                $badge = $badgeMap[$content_type] ?? 'bg-gray-100 text-gray-700';
                $title = $c['title'] ?? '';
                $isViewed = $role === 'student' && isset($viewedIds[(int)$c['content_id']]);
                $searchKey = strtolower(($title ?? '') . ' ' . ($content_type ?? ''));
              ?>
                <?php if ($content_type === 'lesson'): ?>
                  <!-- LESSON: Modal -->
                  <div id="content_<?= (int)$c['content_id'] ?>" data-type="<?= h($content_type) ?>" data-key="<?= h($searchKey) ?>"
                       x-data="{ showModal: false }"
                       class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
                    <div class="flex items-center justify-between gap-4">
                      <div class="min-w-0">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                          <ion-icon name="<?= h($typeIconName[$content_type]) ?>"></ion-icon> <?= ucfirst($content_type) ?>
                        </span>
                        <button @click="showModal = true; $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>));"
                                class="block text-left w-full mt-1 text-blue-700 text-lg font-semibold hover:underline truncate">
                          <?= h($title) ?>
                        </button>
                        <?php if ($isViewed): ?>
                          <span class="inline-flex items-center gap-1 text-emerald-700 text-xs mt-1">
                            <ion-icon name="checkmark-circle-outline"></ion-icon> Completed
                          </span>
                        <?php endif; ?>
                      </div>
                      <button @click="showModal = true; $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>));"
                              class="text-gray-500 hover:text-gray-700 transition" aria-label="Open lesson">
                        <ion-icon name="open-outline" class="w-5 h-5"></ion-icon>
                      </button>
                    </div>
                    <!-- Modal -->
                    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" aria-modal="true" role="dialog">
                      <div @click="showModal = false" class="absolute inset-0 bg-slate-100/60 backdrop-blur-sm"></div>
                      <div x-transition:enter="transition ease-out duration-200"
                           x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                           x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                           x-transition:leave="transition ease-in duration-150"
                           x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                           x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                           class="relative bg-white rounded-2xl shadow-xl ring-1 ring-gray-200 max-w-6xl w-[95%] p-6">
                        <button @click="showModal = false" class="absolute top-3 right-3 text-gray-400 hover:text-gray-700 text-2xl leading-none" aria-label="Close">&times;</button>
                        <h2 class="text-2xl font-bold text-blue-700 mb-4 inline-flex items-center gap-2">
                          <ion-icon name="book-outline"></ion-icon> <?= h($title) ?>
                        </h2>

                        <?php if (!empty($c['body'])): ?>
                          <div class="prose max-w-none">
                            <?= $c['body'] /* sanitized on save */ ?>
                          </div>
                        <?php endif; ?>

                        <?php if (!empty($c['file_url'])):
                          $pathPart = parse_url($c['file_url'], PHP_URL_PATH);
                          $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
                          $isVideo = $ext === 'mp4' || $ext === 'webm' || $ext === 'ogg';
                          $isPdf   = $ext === 'pdf';
                        ?>
                          <div class="mt-6">
                            <?php if ($isVideo): ?>
                              <video controls playsinline preload="metadata" controlsList="nodownload"
                                     class="w-full max-h-[520px] rounded-lg ring-1 ring-gray-200"
                                     oncontextmenu="return false;">
                                <source src="<?= h($c['file_url']) ?>">
                                Your browser does not support the video tag.
                              </video>
                            <?php elseif ($isPdf): ?>
                              <iframe
                                src="pdf_image_viewer.php?course_id=<?= (int)$course_id ?>&content_id=<?= (int)$c['content_id'] ?>"
                                sandbox="allow-scripts allow-same-origin"
                                referrerpolicy="no-referrer"
                                loading="lazy"
                                class="w-full h-[550px] rounded-lg ring-1 ring-gray-200"
                                frameborder="0"
                                oncontextmenu="return false"></iframe>
                            <?php else: ?>
                              <iframe src="<?= h($c['file_url']) ?>"
                                      loading="lazy"
                                      class="w-full h-[550px] rounded-lg ring-1 ring-gray-200"
                                      frameborder="0"></iframe>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php else: ?>
                  <!-- OTHER TYPES: Collapsible (quiz/video/pdf/forum) -->
                  <div id="content_<?= (int)$c['content_id'] ?>" data-type="<?= h($content_type) ?>" data-key="<?= h($searchKey) ?>"
                       x-data="{ open: false }"
                       class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
                    <div class="flex items-center justify-between gap-4">
                      <div class="min-w-0">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                          <ion-icon name="<?= h($typeIconName[$content_type] ?? 'document-text-outline') ?>"></ion-icon>
                          <?= ucfirst($content_type) ?>
                        </span>
                        <button @click="open = !open; if (open) { $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>)); }"
                                class="block text-left w-full mt-1 text-blue-700 text-lg font-semibold hover:underline truncate">
                          <?= h($title) ?>
                        </button>
                        <?php if ($isViewed): ?>
                          <span class="inline-flex items-center gap-1 text-emerald-700 text-xs mt-1">
                            <ion-icon name="checkmark-circle-outline"></ion-icon> Completed
                          </span>
                        <?php endif; ?>
                      </div>
                      <button @click="open = !open; if (open) { $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>)); }"
                              class="text-gray-500 hover:text-gray-700 transition" aria-label="Toggle section">
                        <ion-icon :name="open ? 'chevron-up-outline' : 'chevron-down-outline'" class="transition-transform"></ion-icon>
                      </button>
                    </div>

                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="mt-4 space-y-4">
                      <?php if (!empty($c['body'])): ?>
                        <div class="bg-slate-50 ring-1 ring-slate-100 p-4 rounded-lg text-gray-700 leading-relaxed">
                          <?= nl2br(h($c['body'])) ?>
                        </div>
                      <?php endif; ?>

                      <?php if (!empty($c['file_url'])):
                        $pathPart = parse_url($c['file_url'], PHP_URL_PATH);
                        $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
                        $isVideo = $content_type === 'video' || $ext === 'mp4' || $ext === 'webm' || $ext === 'ogg';
                        $isPdf   = $ext === 'pdf';
                      ?>
                        <div>
                          <div class="flex items-center justify-between mb-2">
                            <h4 class="font-semibold inline-flex items-center gap-2">
                              <ion-icon name="attach-outline"></ion-icon> Attached File
                            </h4>
                            <?php if (!$isPdf): ?>
                              <a href="<?= h($c['file_url']) ?>" target="_blank" rel="noopener"
                                 class="text-sm text-blue-700 hover:underline inline-flex items-center gap-1">
                                <ion-icon name="open-outline"></ion-icon> Open in new tab
                              </a>
                            <?php endif; ?>
                          </div>
                          <?php if ($isVideo): ?>
                            <video controls playsinline preload="metadata" controlsList="nodownload"
                                   class="w-full max-h-[520px] rounded-lg ring-1 ring-gray-200"
                                   oncontextmenu="return false;">
                              <source src="<?= h($c['file_url']) ?>">
                              Your browser does not support the video tag.
                            </video>
                          <?php elseif ($isPdf): ?>
                            <iframe
                              src="pdf_image_viewer.php?course_id=<?= (int)$course_id ?>&content_id=<?= (int)$c['content_id'] ?>"
                              sandbox="allow-scripts allow-same-origin"
                              referrerpolicy="no-referrer"
                              loading="lazy"
                              class="w-full h-[600px] rounded-lg ring-1 ring-gray-200"
                              frameborder="0"
                              oncontextmenu="return false"></iframe>
                          <?php else: ?>
                            <iframe src="<?= h($c['file_url']) ?>"
                                    loading="lazy"
                                    class="w-full h-[600px] rounded-lg ring-1 ring-gray-200"
                                    frameborder="0"></iframe>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <?php if ($content_type === 'quiz'):
                        /* --------- Load assignment + questions as array --------- */
                        $assignStmt = $conn->prepare("SELECT assignment_id, passing_score FROM assignments WHERE lesson_id = ? LIMIT 1");
                        $assignStmt->bind_param("i", $c['content_id']);
                        $assignStmt->execute();
                        $assignmentRow = $assignStmt->get_result()->fetch_assoc();
                        $assignStmt->close();

                        $questionsArr = [];
                        if ($assignmentRow) {
                          $qStmt = $conn->prepare("
                            SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_option
                            FROM assignment_questions
                            WHERE assignment_id = ?
                            ORDER BY question_id ASC
                          ");
                          $qStmt->bind_param("i", $assignmentRow['assignment_id']);
                          $qStmt->execute();
                          $qRes = $qStmt->get_result();
                          $questionsArr = $qRes->fetch_all(MYSQLI_ASSOC);
                          $qStmt->close();
                          if (QUIZ_SHUFFLE_QUESTIONS) shuffle($questionsArr);
                        }

                        // Attempts and review
                        $attempts_arr = []; $latest_attempt = null; $answersMap = []; $questions_review = $questionsArr;
                        $attemptsCount = 0; $attemptsLeft = '∞';

                        if ($assignmentRow && $role === 'student' && $student_id) {
                          $resA = $conn->prepare("
                            SELECT attempt_id, attempted_at, score, passed
                            FROM student_assignment_attempts
                            WHERE student_id = ? AND assignment_id = ?
                            ORDER BY attempted_at DESC
                          ");
                          $resA->bind_param("ii", $student_id, $assignmentRow['assignment_id']);
                          $resA->execute();
                          $attRes = $resA->get_result();
                          $attempts_arr = $attRes->fetch_all(MYSQLI_ASSOC);
                          $resA->close();

                          $attemptsCount = count($attempts_arr);
                          if (QUIZ_MAX_ATTEMPTS > 0) $attemptsLeft = max(0, QUIZ_MAX_ATTEMPTS - $attemptsCount);

                          $latest_attempt = $attempts_arr[0] ?? null;

                          if ($latest_attempt) {
                            $aid = (int)$latest_attempt['attempt_id'];
                            $ansStmt = $conn->prepare("
                              SELECT question_id, selected_option, is_correct
                              FROM assignment_attempt_questions
                              WHERE attempt_id = ?
                            ");
                            $ansStmt->bind_param("i", $aid);
                            $ansStmt->execute();
                            $ansR = $ansStmt->get_result();
                            while ($rowAns = $ansR->fetch_assoc()) {
                              $answersMap[(int)$rowAns['question_id']] = $rowAns;
                            }
                            $ansStmt->close();
                          }
                        }

                        $quizNonce = quiz_generate_nonce((int)$c['content_id']);
                      ?>
                        <div class="bg-emerald-50 ring-1 ring-emerald-100 p-4 rounded-lg" x-data="{ showSummary:false, showReview:false, showHistory:false }">
                          <h4 class="font-semibold mb-3 inline-flex items-center gap-2">
                            <ion-icon name="trophy-outline" class="text-emerald-700"></ion-icon> Quiz
                          </h4>

                          <?php if ($role === 'student'): ?>
                            <div class="flex flex-wrap items-center gap-2 mb-3">
                              <?php if ($latest_attempt): ?>
                                <button @click="showSummary = !showSummary" 
                                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-emerald-200 text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition">
                                  <ion-icon name="clipboard-outline"></ion-icon> Last Attempt Summary
                                </button>
                                <button @click="showReview = !showReview" 
                                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-indigo-200 text-indigo-700 bg-indigo-50 hover:bg-indigo-100 transition">
                                  <ion-icon name="eye-outline"></ion-icon> Review Answers
                                </button>
                              <?php endif; ?>
                              <button @click="showHistory = !showHistory" 
                                      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-gray-700 bg-white hover:bg-gray-50 transition">
                                <ion-icon name="time-outline"></ion-icon> Attempts (<?= (int)$attemptsCount ?><?= (QUIZ_MAX_ATTEMPTS>0 ? ' / '.(int)QUIZ_MAX_ATTEMPTS : '') ?>)
                              </button>
                            </div>

                            <?php if ($latest_attempt): ?>
                              <div x-show="showSummary" x-cloak
                                   x-transition:enter="transition ease-out duration-200"
                                   x-transition:enter-start="opacity-0 -translate-y-1"
                                   x-transition:enter-end="opacity-100 translate-y-0"
                                   class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 mb-3">
                                <?php
                                  $totalQ = count($questions_review);
                                  $attemptDt = $latest_attempt['attempted_at'] ? date('Y-m-d H:i:s', strtotime($latest_attempt['attempted_at'])) : '';
                                  $passedTxt = $latest_attempt['passed'] ? '<span class="text-emerald-700 font-bold">Pass</span>' : '<span class="text-rose-600 font-bold">Fail</span>';
                                ?>
                                <p class="text-sm">
                                  <span class="inline-flex items-center gap-1 mr-2"><ion-icon name="time-outline"></ion-icon> <?= h($attemptDt) ?></span> ·
                                  Score: <span class="font-semibold text-blue-700"><?= (int)$latest_attempt['score'] ?></span> /
                                  <span class="font-semibold text-blue-700"><?= (int)$totalQ ?></span> ·
                                  Result: <?= $passedTxt ?>
                                </p>
                              </div>

                              <div x-show="showReview" x-cloak
                                   x-transition:enter="transition ease-out duration-200"
                                   x-transition:enter-start="opacity-0 -translate-y-1"
                                   x-transition:enter-end="opacity-100 translate-y-0"
                                   class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-3">
                                <?php if (!empty($questions_review)): ?>
                                  <div class="space-y-3">
                                    <?php foreach ($questions_review as $q):
                                      $qid       = (int)$q['question_id'];
                                      $selected  = $answersMap[$qid]['selected_option'] ?? null;
                                      $is_correct= (int)($answersMap[$qid]['is_correct'] ?? 0);
                                      $correct   = $q['correct_option'];
                                    ?>
                                      <div class="p-3 rounded border <?= $is_correct ? 'border-emerald-300 bg-emerald-50' : 'border-rose-300 bg-rose-50' ?>">
                                        <div class="font-medium mb-1"><?= h($q['question_text']) ?></div>
                                        <?php foreach (['A','B','C','D'] as $opt):
                                          $txt = h($q['option_'.strtolower($opt)]);
                                          $isUser = ($selected === $opt);
                                          $isAnswer = ($correct === $opt);
                                        ?>
                                          <div class="ml-4 flex items-center text-sm">
                                            <?php if ($isUser && $isAnswer): ?>
                                              <ion-icon name="checkmark-circle-outline" class="text-emerald-600 mr-2"></ion-icon>
                                            <?php elseif ($isUser && !$isAnswer): ?>
                                              <ion-icon name="close-circle-outline" class="text-rose-600 mr-2"></ion-icon>
                                            <?php else: ?>
                                              <span class="w-5 inline-block"></span>
                                            <?php endif; ?>
                                            <span class="<?= $isAnswer ? 'font-semibold underline text-emerald-700' : ($isUser ? 'text-rose-700' : '') ?>">
                                              <?= $opt ?>) <?= $txt ?>
                                            </span>
                                            <?php if ($isUser): ?><span class="ml-2 text-xs text-gray-500">(Your answer)</span><?php endif; ?>
                                            <?php if ($isAnswer): ?><span class="ml-2 text-xs text-emerald-700">(Correct answer)</span><?php endif; ?>
                                          </div>
                                        <?php endforeach; ?>
                                      </div>
                                    <?php endforeach; ?>
                                  </div>
                                <?php else: ?>
                                  <p class="text-gray-600">No questions to review.</p>
                                <?php endif; ?>
                              </div>
                            <?php endif; ?>

                            <div x-show="showHistory" x-cloak
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 -translate-y-1"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 class="bg-white border border-gray-200 rounded-xl p-3 mb-3">
                              <?php if (!empty($attempts_arr)): ?>
                                <div class="text-sm text-gray-700 mb-2">
                                  Attempts used: <strong><?= (int)$attemptsCount ?></strong>
                                  <?php if (QUIZ_MAX_ATTEMPTS > 0): ?> · Attempts left: <strong><?= (int)$attemptsLeft ?></strong><?php endif; ?>
                                </div>
                                <div class="overflow-x-auto">
                                  <table class="min-w-full text-sm">
                                    <thead>
                                      <tr class="text-left text-gray-500">
                                        <th class="py-1 pr-4">Date</th>
                                        <th class="py-1 pr-4">Score</th>
                                        <th class="py-1 pr-4">Result</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php foreach (array_slice($attempts_arr, 0, 5) as $att): ?>
                                        <tr class="border-t">
                                          <td class="py-1 pr-4"><?= h(date('Y-m-d H:i:s', strtotime($att['attempted_at']))) ?></td>
                                          <td class="py-1 pr-4"><?= (int)$att['score'] ?></td>
                                          <td class="py-1 pr-4"><?= $att['passed'] ? 'Pass' : 'Fail' ?></td>
                                        </tr>
                                      <?php endforeach; ?>
                                    </tbody>
                                  </table>
                                </div>
                              <?php else: ?>
                                <p class="text-gray-600">No attempts yet.</p>
                              <?php endif; ?>
                            </div>

                            <?php
                              $canAttempt = true;
                              if (QUIZ_MAX_ATTEMPTS > 0 && $attemptsCount >= QUIZ_MAX_ATTEMPTS) $canAttempt = false;
                            ?>
                            <?php if (!empty($questionsArr) && $assignmentRow): ?>
                              <?php if ($canAttempt): ?>
                                <form method="post" class="space-y-4">
                                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                  <input type="hidden" name="quiz_content_id" value="<?= (int)$c['content_id'] ?>">
                                  <input type="hidden" name="quiz_nonce" value="<?= h($quizNonce) ?>">
                                  <?php foreach ($questionsArr as $q): ?>
                                    <div class="rounded-lg border border-gray-100 p-3">
                                      <p class="font-medium"><?= h($q['question_text']) ?></p>
                                      <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                        <label class="block ml-4 mt-1 text-gray-700">
                                          <input class="mr-2 accent-blue-600" type="radio" name="quiz[<?= (int)$q['question_id'] ?>]" value="<?= $opt ?>" required>
                                          <?= $opt ?>) <?= h($q['option_' . strtolower($opt)]) ?>
                                        </label>
                                      <?php endforeach; ?>
                                    </div>
                                  <?php endforeach; ?>
                                  <div class="flex items-center justify-between">
                                    <button type="submit" name="submit_quiz" value="<?= (int)$assignmentRow['assignment_id'] ?>"
                                            class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 shadow-sm">
                                      <ion-icon name="send-outline"></ion-icon> Submit Quiz
                                    </button>
                                    <p class="text-sm text-gray-500">
                                      Attempt <?= (int)($attemptsCount + 1) ?><?= (QUIZ_MAX_ATTEMPTS>0 ? ' of '.(int)QUIZ_MAX_ATTEMPTS : '') ?>
                                    </p>
                                  </div>
                                </form>
                              <?php else: ?>
                                <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-800 p-3">
                                  You have reached the maximum number of attempts for this quiz.
                                </div>
                              <?php endif; ?>
                            <?php else: ?>
                              <p class="text-gray-600">No quiz questions available.</p>
                            <?php endif; ?>
                          <?php elseif ($role === 'teacher'): ?>
                            <p class="italic text-gray-500">Students can attempt this quiz. Preview questions below:</p>
                            <?php if (!empty($questionsArr)): ?>
                              <?php foreach ($questionsArr as $q): ?>
                                <div class="mb-3">
                                  <p class="font-medium"><?= h($q['question_text']) ?></p>
                                  <ul class="ml-6 list-disc text-gray-700">
                                    <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                      <li><?= $opt ?>) <?= h($q['option_' . strtolower($opt)]) ?></li>
                                    <?php endforeach; ?>
                                  </ul>
                                  <span class="text-green-600 text-xs">Correct: <?= h($q['correct_option']) ?></span>
                                </div>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <p class="text-gray-600">No quiz questions found.</p>
                            <?php endif; ?>
                            <div class="mt-2">
                              <a href="teacher_attempts.php?course_id=<?= (int)$course_id ?>&content_id=<?= (int)$c['content_id'] ?>"
                                 class="inline-flex items-center gap-2 text-indigo-700 hover:underline">
                                <ion-icon name="podium-outline"></ion-icon> Review Attempts
                              </a>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      
                      <?php if ($content_type === 'forum'):
                        $postsStmt = $conn->prepare("
                          SELECT p.post_id, p.body, u.username, p.posted_at 
                          FROM forum_posts p 
                          JOIN users u ON u.user_id = p.user_id 
                          WHERE p.content_id = ? AND p.parent_post_id IS NULL 
                          ORDER BY posted_at
                        ");
                        $postsStmt->bind_param("i", $c['content_id']);
                        $postsStmt->execute();
                        $posts = $postsStmt->get_result();
                      ?>
                        <div class="bg-indigo-50 ring-1 ring-indigo-100 p-4 rounded-lg">
                          <h4 class="font-semibold mb-3 inline-flex items-center gap-2">
                            <ion-icon name="chatbubbles-outline" class="text-indigo-700"></ion-icon>
                            Forum Discussion
                          </h4>
                          <?php while ($post = $posts->fetch_assoc()): ?>
                            <div class="bg-white border border-gray-100 p-3 mb-2 rounded-lg">
                              <div class="flex items-center justify-between">
                                <strong class="text-gray-800 inline-flex items-center gap-1">
                                  <ion-icon name="person-circle-outline" class="text-gray-600"></ion-icon>
                                  <?= h($post['username']) ?>
                                </strong>
                                <small class="text-gray-500 inline-flex items-center gap-1">
                                  <ion-icon name="time-outline"></ion-icon> <?= h($post['posted_at']) ?>
                                </small>
                              </div>
                              <p class="mt-1 text-gray-700"><?= nl2br(h($post['body'])) ?></p>
                            </div>
                          <?php endwhile; $postsStmt->close(); ?>

                          <?php if ($role === 'student'): ?>
                            <form method="post" class="mt-3">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="forum_content_id" value="<?= (int)$c['content_id'] ?>">
                              <textarea name="forum_body" class="w-full border border-gray-200 focus:border-blue-300 focus:ring-2 focus:ring-blue-200 outline-none p-3 rounded-lg text-gray-800"
                                        rows="2" placeholder="Type your comment..." required></textarea>
                              <button type="submit" name="post_forum"
                                      class="mt-2 inline-flex items-center gap-2 bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 shadow-sm">
                                <ion-icon name="send-outline"></ion-icon> Post
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<script>
// CSRF
const CSRF = <?= json_encode($csrf) ?>;

// Client-side: log view only once per content (student)
window.loggedViews = new Set();
window.logView = function(contentId) {
  if (!contentId || window.loggedViews.has(contentId)) return;
  const params = new URLSearchParams();
  params.append('action','log_view');
  params.append('content_id', String(contentId));
  params.append('csrf', CSRF);
  fetch(location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString()
  }).catch(()=>{});
  window.loggedViews.add(contentId);
};

// TOC search + filters
const tocSearch = document.getElementById('tocSearch');
const pills = document.querySelectorAll('.toc-filter');
const pillBadges = {};
document.querySelectorAll('[data-type-pill]').forEach(el => pillBadges[el.getAttribute('data-type-pill')] = el);

function applyTocFilters() {
  const q = (tocSearch?.value || '').toLowerCase().trim();
  const enabled = new Set([...pills].filter(cb => cb.checked).map(cb => cb.value));
  document.querySelectorAll('#tocList li').forEach(li => {
    const key = (li.getAttribute('data-key') || '').toLowerCase();
    const type = li.getAttribute('data-type') || '';
    const show = (enabled.has(type)) && (!q || key.includes(q));
    li.style.display = show ? '' : 'none';
  });
  pills.forEach(cb => {
    const pill = pillBadges[cb.value];
    if (pill) pill.dataset.off = cb.checked ? 'false' : 'true';
  });
}
function filterContentCards() {
  const q = (tocSearch?.value || '').toLowerCase().trim();
  const enabled = new Set([...pills].filter(cb => cb.checked).map(cb => cb.value));
  document.querySelectorAll('#contentList > div[id^="content_"]').forEach(card => {
    const key = (card.getAttribute('data-key') || '').toLowerCase();
    const type = (card.getAttribute('data-type') || '');
    const show = enabled.has(type) && (!q || key.includes(q));
    card.style.display = show ? '' : 'none';
  });
}
if (tocSearch) { tocSearch.addEventListener('input', () => { applyTocFilters(); filterContentCards(); }); }
pills.forEach(cb => cb.addEventListener('change', () => { applyTocFilters(); filterContentCards(); }));
applyTocFilters(); filterContentCards();

// Active ToC highlight
const sectionEls = [...document.querySelectorAll('#contentList > div[id^="content_"]')];
const tocLinks = new Map();
document.querySelectorAll('#tocList a[href^="#content_"]').forEach(a => { tocLinks.set(a.getAttribute('href').slice(1), a.parentElement); });
const io = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    const id = e.target.id;
    const el = tocLinks.get(id);
    if (!el) return;
    if (e.isIntersecting && e.intersectionRatio > 0.5) el.classList.add('toc-active','border','border-blue-100');
    else el.classList.remove('toc-active','border','border-blue-100');
  });
}, { rootMargin: '-20% 0px -70% 0px', threshold: [0.5] });
sectionEls.forEach(sec => io.observe(sec));

// Expand/Collapse all
document.getElementById('expandAll')?.addEventListener('click', () => {
  document.querySelectorAll('#contentList > div[x-data]').forEach(el => {
    const comp = Alpine.$data(el);
    if (comp && 'open' in comp) comp.open = true;
  });
});
document.getElementById('collapseAll')?.addEventListener('click', () => {
  document.querySelectorAll('#contentList > div[x-data]').forEach(el => {
    const comp = Alpine.$data(el);
    if (comp && 'open' in comp) comp.open = false;
  });
});
</script>
</body>
</html>