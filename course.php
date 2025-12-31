<?php
session_start();
include 'db_connect.php';

/* =================== Quiz options =================== */
const QUIZ_MAX_ATTEMPTS = 0;         // 0 = unlimited attempts
const QUIZ_SHUFFLE_QUESTIONS = true; // shuffle the questions shown to students
/* ==================================================== */

// Allow students, teachers, and coordinators
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'teacher', 'coordinator'], true)) {
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
    // Students must be actively enrolled
    $check = $conn->prepare("SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? AND status = 'active' LIMIT 1");
    $check->bind_param("ii", $user_id, $course_id);
    $check->execute();
    $r = $check->get_result();
    if (!$r || $r->num_rows === 0) die("⛔ You are not enrolled in this course.");
    $check->close();

} elseif ($role === 'teacher') {
    // Teachers must be assigned to this course
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

} elseif ($role === 'coordinator') {
    // Coordinators: currently allowed to access any course.
    // If you want to restrict coordinators, add coordinator-specific checks here.
} else {
    http_response_code(403);
    die("Access Denied.");
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
      <?php include 'components/student/course_toc.php'; ?>

      <!-- Contents -->
      <?php include 'components/student/course_contents.php'; ?>
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