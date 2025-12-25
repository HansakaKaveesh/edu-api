<?php
session_start();
include 'db_connect.php';

// --- ROLE & BASIC ACCESS CHECK -----------------------------------
$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!$user_id || !$role) {
    http_response_code(403);
    die('Unauthorized access');
}

// CSRF token (used for forms – safe for both roles)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Accept both ?id= and ?content_id=
$content_id = 0;
if (isset($_GET['id'])) {
    $content_id = (int)$_GET['id'];
} elseif (isset($_GET['content_id'])) {
    $content_id = (int)$_GET['content_id'];
}

if (!$content_id) {
    http_response_code(400);
    die('Invalid request.');
}

// Helper esc
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- FETCH CONTENT (for both roles) -------------------------------
$stmt = $conn->prepare("
    SELECT c.*, cs.name AS course_name 
    FROM contents c 
    JOIN courses cs ON c.course_id = cs.course_id 
    WHERE c.content_id = ?
");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    http_response_code(404);
    die('Content not found.');
}
$content      = $res->fetch_assoc();
$course_id    = (int)$content['course_id'];
$content_type = $content['type'] ?? 'lesson';
$stmt->close();

// --- ACCESS CONTROL: STUDENT vs TEACHER --------------------------

// Students: must be actively enrolled
if ($role === 'student') {
    $checkEnroll = $conn->prepare("
        SELECT 1 
        FROM enrollments 
        WHERE user_id = ? AND course_id = ? AND status = 'active' 
        LIMIT 1
    ");
    $checkEnroll->bind_param("ii", $user_id, $course_id);
    $checkEnroll->execute();
    $enrollRes = $checkEnroll->get_result();
    $checkEnroll->close();

    if ($enrollRes->num_rows === 0) {
        http_response_code(403);
        die('You are not enrolled in this course.');
    }
}
// Teachers: must own this course
elseif ($role === 'teacher') {
    $checkTeach = $conn->prepare("
        SELECT 1
        FROM teacher_courses tc
        JOIN teachers t ON tc.teacher_id = t.teacher_id
        WHERE tc.course_id = ? AND t.user_id = ?
        LIMIT 1
    ");
    $checkTeach->bind_param("ii", $course_id, $user_id);
    $checkTeach->execute();
    $teachRes = $checkTeach->get_result();
    $checkTeach->close();

    if ($teachRes->num_rows === 0) {
        http_response_code(403);
        die('Unauthorized access.');
    }
}
// Any other role is blocked
else {
    http_response_code(403);
    die('Unauthorized access.');
}

// --- STUDENT-ONLY: log view & progress ---------------------------
if ($role === 'student') {
    // Log view if not already logged (secure)
    $logged = $conn->prepare("
        SELECT 1 
        FROM activity_logs 
        WHERE user_id = ? AND content_id = ? AND action = 'view' 
        LIMIT 1
    ");
    $logged->bind_param("ii", $user_id, $content_id);
    $logged->execute();
    $loggedRes = $logged->get_result();
    $alreadyLogged = $loggedRes->num_rows > 0;
    $logged->close();

    if (!$alreadyLogged) {
        // Insert activity log
        $logStmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, content_id, action) 
            VALUES (?, ?, 'view')
        ");
        $logStmt->bind_param("ii", $user_id, $content_id);
        $logStmt->execute();
        $logStmt->close();

        // Update student progress
        $stuStmt = $conn->prepare("
            SELECT student_id FROM students WHERE user_id = ? LIMIT 1
        ");
        $stuStmt->bind_param("i", $user_id);
        $stuStmt->execute();
        $rowS = $stuStmt->get_result()->fetch_assoc();
        $stuStmt->close();

        $student_id = (int)($rowS['student_id'] ?? 0);
        if ($student_id) {
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
}

// --- STUDENT-ONLY: Quiz submission handler -----------------------
if (
    $role === 'student' &&
    $content_type === 'quiz' &&
    isset($_POST['submit_quiz']) &&
    isset($_POST['quiz'])
) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    // Assignment by lesson/content link
    $asStmt = $conn->prepare("
        SELECT assignment_id 
        FROM assignments 
        WHERE lesson_id = ? 
        LIMIT 1
    ");
    $asStmt->bind_param("i", $content_id);
    $asStmt->execute();
    $rowA = $asStmt->get_result()->fetch_assoc();
    $asStmt->close();

    $assignment_id = (int)($rowA['assignment_id'] ?? 0);

    if ($assignment_id) {
        // Resolve student
        $su = $conn->prepare("
            SELECT student_id 
            FROM students 
            WHERE user_id = ? 
            LIMIT 1
        ");
        $su->bind_param("i", $user_id);
        $su->execute();
        $rowS = $su->get_result()->fetch_assoc();
        $su->close();

        $student_id = (int)($rowS['student_id'] ?? 0);
        if ($student_id) {
            // Fetch questions
            $qs = $conn->prepare("
                SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_option 
                FROM assignment_questions 
                WHERE assignment_id = ?
            ");
            $qs->bind_param("i", $assignment_id);
            $qs->execute();
            $questionsRes = $qs->get_result();

            // Assignment meta (for pass score)
            $am = $conn->prepare("
                SELECT passing_score 
                FROM assignments 
                WHERE assignment_id = ? 
                LIMIT 1
            ");
            $am->bind_param("i", $assignment_id);
            $am->execute();
            $assignment = $am->get_result()->fetch_assoc();
            $am->close();

            $answers = $_POST['quiz'];
            $score   = 0;

            // Create attempt
            $insAttempt = $conn->prepare("
                INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed) 
                VALUES (?, ?, 0, 0)
            ");
            $insAttempt->bind_param("ii", $student_id, $assignment_id);
            $insAttempt->execute();
            $attempt_id = (int)$conn->insert_id;
            $insAttempt->close();

            // Insert answers
            $insQ = $conn->prepare("
                INSERT INTO assignment_attempt_questions 
                    (attempt_id, question_id, selected_option, is_correct) 
                VALUES (?, ?, ?, ?)
            ");

            while ($q = $questionsRes->fetch_assoc()) {
                $qid      = (int)$q['question_id'];
                $selected = $answers[$qid] ?? '';
                $correct  = $q['correct_option'];
                $is_correct = ($selected === $correct) ? 1 : 0;
                $score += $is_correct;

                $insQ->bind_param("iisi", $attempt_id, $qid, $selected, $is_correct);
                $insQ->execute();
            }
            $insQ->close();
            $qs->close();

            $passScore = (int)($assignment['passing_score'] ?? 0);
            $passed    = ($score >= $passScore) ? 1 : 0;

            $up = $conn->prepare("
                UPDATE student_assignment_attempts 
                SET score = ?, passed = ? 
                WHERE attempt_id = ?
            ");
            $up->bind_param("iii", $score, $passed, $attempt_id);
            $up->execute();
            $up->close();

            echo "<script>
                alert('✅ Quiz submitted! Score: " . (int)$score . "');
                location.href = location.href;
            </script>";
            exit;
        }
    }
}

// --- Icons + badges ----------------------------------------------
$iconByType = [
  'lesson' => 'book-outline',
  'video'  => 'videocam-outline',
  'pdf'    => 'document-text-outline',
  'quiz'   => 'trophy-outline',
  'forum'  => 'chatbubbles-outline',
];
$badgeByType = [
  'lesson' => 'bg-sky-100 text-sky-700',
  'video'  => 'bg-rose-100 text-rose-700',
  'pdf'    => 'bg-amber-100 text-amber-800',
  'quiz'   => 'bg-emerald-100 text-emerald-700',
  'forum'  => 'bg-indigo-100 text-indigo-700',
];
$typeIcon  = $iconByType[$content_type] ?? 'document-text-outline';
$typeBadge = $badgeByType[$content_type] ?? 'bg-gray-100 text-gray-700';

// --- Preload for quiz review (students only) ---------------------
$latest_attempt   = null;
$answersMap       = [];
$questions_review = null;
$attempts         = 0;

if ($role === 'student' && $content_type === 'quiz') {
    // Assignment id again (prepared)
    $asStmt = $conn->prepare("
        SELECT assignment_id 
        FROM assignments 
        WHERE lesson_id = ? 
        LIMIT 1
    ");
    $asStmt->bind_param("i", $content_id);
    $asStmt->execute();
    $rowA = $asStmt->get_result()->fetch_assoc();
    $asStmt->close();

    $assignment_id = (int)($rowA['assignment_id'] ?? 0);

    if ($assignment_id) {
        $stuStmt = $conn->prepare("
            SELECT student_id 
            FROM students 
            WHERE user_id = ? 
            LIMIT 1
        ");
        $stuStmt->bind_param("i", $user_id);
        $stuStmt->execute();
        $rowS = $stuStmt->get_result()->fetch_assoc();
        $stuStmt->close();
        $student_id = (int)($rowS['student_id'] ?? 0);

        if ($student_id) {
            $cntAtt = $conn->prepare("
                SELECT COUNT(*) as cnt 
                FROM student_assignment_attempts 
                WHERE student_id = ? AND assignment_id = ?
            ");
            $cntAtt->bind_param("ii", $student_id, $assignment_id);
            $cntAtt->execute();
            $attempts = (int)($cntAtt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $cntAtt->close();

            $lastAtt = $conn->prepare("
                SELECT attempt_id, score, passed, attempted_at 
                FROM student_assignment_attempts 
                WHERE assignment_id = ? AND student_id = ? 
                ORDER BY attempted_at DESC 
                LIMIT 1
            ");
            $lastAtt->bind_param("ii", $assignment_id, $student_id);
            $lastAtt->execute();
            $latest_attempt = $lastAtt->get_result()->fetch_assoc();
            $lastAtt->close();

            if ($latest_attempt) {
                $aid = (int)$latest_attempt['attempt_id'];
                $ans = $conn->prepare("
                    SELECT question_id, selected_option, is_correct 
                    FROM assignment_attempt_questions 
                    WHERE attempt_id = ?
                ");
                $ans->bind_param("i", $aid);
                $ans->execute();
                $ansRes = $ans->get_result();
                while ($row = $ansRes->fetch_assoc()) {
                    $answersMap[(int)$row['question_id']] = $row;
                }
                $ans->close();

                $qrev = $conn->prepare("
                    SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_option 
                    FROM assignment_questions 
                    WHERE assignment_id = ?
                ");
                $qrev->bind_param("i", $assignment_id);
                $qrev->execute();
                $questions_review = $qrev->get_result();
                // not closing here; rely on end-of-request cleanup
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= h($content['title']) ?> - View Content</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- Ionicons -->
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>[x-cloak]{display:none}</style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen">
<?php include 'components/navbar.php'; ?>

<!-- Layout with sidebar -->
<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 sm:pt-28 pb-12 gap-8"
     x-data="{ showModal: false }">

  <!-- Sidebar (role-based) -->
  <?php if ($role === 'student'): ?>
    <?php include 'components/sidebar_student.php'; ?>
  <?php elseif ($role === 'teacher'): ?>
    <?php
      // Change the path/file name if your teacher sidebar is named differently
      if (file_exists(__DIR__ . '/components/sidebar_teacher.php')) {
          include 'components/sidebar_teacher.php';
      }
    ?>
  <?php endif; ?>

  <!-- Main Content -->
  <main class="w-full space-y-6">

    <!-- Header -->
    <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
      <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
      </div>
      <div class="flex flex-col md:flex-row items-start justify-between gap-4">
        <div class="min-w-0">
          <h1 class="text-3xl font-extrabold text-blue-700 tracking-tight flex items-center gap-2">
            <ion-icon name="<?= h($typeIcon) ?>" class="text-blue-700"></ion-icon>
            <?= h($content['title']) ?>
          </h1>
          <p class="text-sm text-gray-600 mt-1">
            <span class="inline-flex items-center gap-1 mr-2">
              <ion-icon name="school-outline"></ion-icon> <?= h($content['course_name']) ?>
            </span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $typeBadge ?>">
              <ion-icon name="<?= h($typeIcon) ?>"></ion-icon> <?= ucfirst($content_type) ?>
            </span>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <?php if ($role === 'student'): ?>
            <a href="student_courses.php"
               class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
              <ion-icon name="arrow-back-outline"></ion-icon> Back to My Courses
            </a>
          <?php elseif ($role === 'teacher'): ?>
            <a href="manage_course.php?course_id=<?= (int)$course_id ?>"
               class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
              <ion-icon name="arrow-back-outline"></ion-icon> Back to Manage Course
            </a>
          <?php endif; ?>

          <?php if (!empty($content['file_url'])): ?>
            <a href="<?= h($content['file_url']) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-gray-700 hover:bg-gray-50 transition">
              <ion-icon name="open-outline"></ion-icon> Open in new tab
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Body card -->
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-200 p-6">

      <?php if (!empty($content['body'])): ?>
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 leading-relaxed text-gray-700">
          <?= nl2br(h($content['body'])) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($content['file_url'])): ?>
        <?php
          $extPath = parse_url($content['file_url'], PHP_URL_PATH) ?? '';
          $ext     = strtolower(pathinfo($extPath, PATHINFO_EXTENSION));
          $isVideo = ($content_type === 'video' || $ext === 'mp4' || $ext === 'webm' || $ext === 'ogg');
          $isPdf   = ($content_type === 'pdf'   || $ext === 'pdf');
        ?>
        <div class="mt-5 flex flex-wrap items-center gap-2">
          <button @click="showModal = true"
                  class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow">
            <ion-icon name="eye-outline"></ion-icon> View Attached Content
          </button>
          <a class="inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg"
             href="<?= h($content['file_url']) ?>" download>
            <ion-icon name="download-outline"></ion-icon> Download
          </a>
        </div>

        <!-- Modal viewer -->
        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
          <div class="absolute inset-0 bg-black/70" @click="showModal = false" aria-hidden="true"></div>
          <div class="relative bg-white w-[95%] max-w-6xl rounded-2xl shadow-xl ring-1 ring-gray-200 p-4">
            <button @click="showModal = false" class="absolute top-2 right-2 text-gray-600 hover:text-red-600 text-2xl" aria-label="Close">
              &times;
            </button>
            <h2 class="text-lg font-semibold mb-3 flex items-center gap-2">
              <ion-icon name="<?= h($typeIcon) ?>" class="text-blue-700"></ion-icon> <?= h($content['title']) ?>
            </h2>

            <?php if ($isVideo): ?>
              <video controls class="w-full max-h-[70vh] rounded bg-black">
                <source src="<?= h($content['file_url']) ?>">
                Your browser does not support the video tag.
              </video>
            <?php elseif ($isPdf): ?>
              <iframe src="<?= h($content['file_url']) ?>" class="w-full h-[75vh] rounded" frameborder="0"></iframe>
            <?php else: ?>
              <iframe src="<?= h($content['file_url']) ?>" class="w-full h-[75vh] rounded" frameborder="0"></iframe>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Linked Assignment (for lessons) -->
      <?php if ($content_type === 'lesson'): ?>
        <h2 class="text-xl font-semibold mt-8 mb-3 inline-flex items-center gap-2">
          <ion-icon name="create-outline" class="text-amber-600"></ion-icon> Linked Assignment
        </h2>
        <?php
          $assStmt = $conn->prepare("
              SELECT assignment_id, title, description, due_date, total_marks, passing_score 
              FROM assignments 
              WHERE lesson_id = ? 
              LIMIT 1
          ");
          $assStmt->bind_param("i", $content_id);
          $assStmt->execute();
          $assignment = $assStmt->get_result()->fetch_assoc();
          $assStmt->close();
        ?>
        <?php if ($assignment): ?>
          <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <p class="mb-2 font-semibold"><?= h($assignment['title']) ?></p>
            <p class="text-gray-700"><?= nl2br(h($assignment['description'])) ?></p>
            <div class="mt-2 text-sm text-gray-600 flex flex-wrap gap-3">
              <span class="inline-flex items-center gap-1">
                <ion-icon name="calendar-outline"></ion-icon> Due: <?= h($assignment['due_date']) ?>
              </span>
              <span class="inline-flex items-center gap-1">
                <ion-icon name="trophy-outline"></ion-icon> Total: <?= (int)$assignment['total_marks'] ?>
              </span>
              <span class="inline-flex items-center gap-1">
                <ion-icon name="shield-checkmark-outline"></ion-icon> Pass: <?= (int)$assignment['passing_score'] ?>
              </span>
            </div>
            <?php if ($role === 'student'): ?>
              <a href="attempt_assignment.php?assignment_id=<?= (int)$assignment['assignment_id'] ?>"
                 class="inline-flex items-center gap-2 text-blue-700 mt-3 hover:underline">
                <ion-icon name="play-outline"></ion-icon> Attempt Assignment
              </a>
            <?php else: ?>
              <p class="text-xs text-gray-500 mt-3">
                (Teacher preview – students will see an “Attempt Assignment” button here.)
              </p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="text-gray-600"><em>No assignment linked.</em></p>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Quiz (Unlimited attempts) with Summary/Review -->
      <?php if ($content_type === 'quiz'): ?>
        <h2 class="text-xl font-semibold mt-8 mb-3 inline-flex items-center gap-2">
          <ion-icon name="trophy-outline" class="text-emerald-600"></ion-icon> Quiz
        </h2>
        <?php
          // For rendering the quiz questions (fresh)
          $aidStmt = $conn->prepare("
              SELECT assignment_id 
              FROM assignments 
              WHERE lesson_id = ? 
              LIMIT 1
          ");
          $aidStmt->bind_param("i", $content_id);
          $aidStmt->execute();
          $aidRow = $aidStmt->get_result()->fetch_assoc();
          $aidStmt->close();
          $assignment_id = (int)($aidRow['assignment_id'] ?? 0);

          $questionsList = null;
          if ($assignment_id) {
              $qList = $conn->prepare("
                  SELECT question_id, question_text, option_a, option_b, option_c, option_d 
                  FROM assignment_questions 
                  WHERE assignment_id = ?
              ");
              $qList->bind_param("i", $assignment_id);
              $qList->execute();
              $questionsList = $qList->get_result();
          }
        ?>

        <?php if (!empty($assignment_id)): ?>
          <div x-data="{ showSummary:false, showReview:false }" class="space-y-4">
            <?php if ($role === 'student' && $latest_attempt): ?>
              <div class="flex flex-wrap items-center gap-2">
                <button @click="showSummary = !showSummary"
                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-emerald-200 text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition">
                  <ion-icon name="clipboard-outline"></ion-icon> Last Attempt Summary
                </button>
                <button @click="showReview = !showReview"
                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-indigo-200 text-indigo-700 bg-indigo-50 hover:bg-indigo-100 transition">
                  <ion-icon name="eye-outline"></ion-icon> Review Answers
                </button>
              </div>

              <!-- Summary -->
              <div x-show="showSummary" x-cloak
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-1"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   class="bg-emerald-50 border border-emerald-200 rounded-xl p-4">
                <h3 class="text-lg font-bold mb-2 text-emerald-700 inline-flex items-center gap-1">
                  <ion-icon name="checkmark-done-outline"></ion-icon> Your Last Attempt
                </h3>
                <?php
                  $totalQ   = $questions_review ? $questions_review->num_rows : 0;
                  $attemptDt= $latest_attempt['attempted_at'] ? date('Y-m-d H:i:s', strtotime($latest_attempt['attempted_at'])) : '';
                  $passedTxt= $latest_attempt['passed'] ? '<span class="text-emerald-700 font-bold">Pass</span>' : '<span class="text-rose-600 font-bold">Fail</span>';
                ?>
                <p class="text-sm mb-2">
                  Score: <span class="font-semibold text-blue-700"><?= (int)$latest_attempt['score'] ?></span> /
                  <span class="font-semibold text-blue-700"><?= (int)$totalQ ?></span> |
                  Result: <?= $passedTxt ?>
                </p>
                <p class="text-xs text-gray-600 inline-flex items-center gap-1">
                  <ion-icon name="time-outline"></ion-icon> <?= h($attemptDt) ?> · Attempts so far: <?= (int)$attempts ?>
                </p>
              </div>

              <!-- Review -->
              <div x-show="showReview" x-cloak
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-1"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   class="bg-indigo-50 border border-indigo-200 rounded-xl p-4">
                <h3 class="text-lg font-bold mb-3 text-indigo-700 inline-flex items-center gap-1">
                  <ion-icon name="document-text-outline"></ion-icon> Answer Review
                </h3>
                <?php if ($questions_review && $questions_review->num_rows > 0): ?>
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
                          $isUser   = ($selected === $opt);
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
            <?php elseif ($role === 'teacher'): ?>
              <p class="text-xs text-gray-500">
                (Teacher preview – students will see attempt summary and review here.)
              </p>
            <?php endif; ?>
          </div>

          <!-- Submission form / preview of questions -->
          <?php if ($questionsList && $questionsList->num_rows > 0): ?>
            <?php if ($role === 'student'): ?>
              <form method="post" class="space-y-4 mt-6">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <?php foreach ($questionsList as $q): ?>
                  <div class="rounded-lg border border-gray-100 p-3">
                    <p class="font-medium"><?= h($q['question_text']) ?></p>
                    <?php foreach (['A','B','C','D'] as $opt): ?>
                      <label class="block ml-4 mt-1 text-gray-700">
                        <input type="radio" name="quiz[<?= (int)$q['question_id'] ?>]" value="<?= $opt ?>" required class="mr-2 accent-blue-600">
                        <?= $opt ?>) <?= h($q['option_'.strtolower($opt)]) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
                <button type="submit" name="submit_quiz"
                        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                  <ion-icon name="send-outline"></ion-icon> Submit Quiz
                </button>
                <p class="text-sm text-gray-500 mt-1">Attempts so far: <?= (int)$attempts ?></p>
              </form>
            <?php else: ?>
              <div class="space-y-4 mt-6">
                <?php foreach ($questionsList as $q): ?>
                  <div class="rounded-lg border border-gray-100 p-3">
                    <p class="font-medium"><?= h($q['question_text']) ?></p>
                    <?php foreach (['A','B','C','D'] as $opt): ?>
                      <p class="ml-4 mt-1 text-gray-700">
                        <?= $opt ?>) <?= h($q['option_'.strtolower($opt)]) ?>
                      </p>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="text-xs text-gray-500 mt-2">
                (Teacher preview – quiz submission is available only for students.)
              </p>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-gray-600">No quiz questions found.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-gray-600">No quiz attached to this content.</p>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Forum -->
      <?php if ($content_type === 'forum'): ?>
        <h2 class="text-xl font-semibold mt-8 mb-3 inline-flex items-center gap-2">
          <ion-icon name="chatbubbles-outline" class="text-indigo-600"></ion-icon> Forum Discussion
        </h2>
        <?php
        // Fetch posts (secure)
        $postsStmt = $conn->prepare("
          SELECT p.post_id, p.body, u.username, p.posted_at 
          FROM forum_posts p 
          JOIN users u ON u.user_id = p.user_id 
          WHERE p.content_id = ? AND p.parent_post_id IS NULL 
          ORDER BY p.posted_at
        ");
        $postsStmt->bind_param("i", $content_id);
        $postsStmt->execute();
        $postsRes = $postsStmt->get_result();

        while ($post = $postsRes->fetch_assoc()):
        ?>
          <div class="border border-gray-200 rounded-xl p-4 mb-4 bg-white">
            <div class="flex items-center justify-between mb-1">
              <strong class="inline-flex items-center gap-1 text-gray-800">
                <ion-icon name="person-circle-outline" class="text-slate-600"></ion-icon>
                <?= h($post['username']) ?>
              </strong>
              <small class="inline-flex items-center gap-1 text-gray-500">
                <ion-icon name="time-outline"></ion-icon> <?= h($post['posted_at']) ?>
              </small>
            </div>
            <p class="text-gray-700"><?= nl2br(h($post['body'])) ?></p>

            <?php
            // Replies secure
            $repStmt = $conn->prepare("
              SELECT r.body, u.username, r.posted_at 
              FROM forum_posts r 
              JOIN users u ON u.user_id = r.user_id 
              WHERE r.parent_post_id = ? 
              ORDER BY r.posted_at
            ");
            $repStmt->bind_param("i", $post['post_id']);
            $repStmt->execute();
            $repliesRes = $repStmt->get_result();
            while ($reply = $repliesRes->fetch_assoc()):
            ?>
              <div class="ml-6 mt-3 p-2 border-l-2 border-gray-200">
                <div class="flex items-center justify-between">
                  <strong class="inline-flex items-center gap-1 text-gray-800">
                    <ion-icon name="person-circle-outline" class="text-slate-600"></ion-icon>
                    <?= h($reply['username']) ?>
                  </strong>
                  <small class="text-gray-500"><?= h($reply['posted_at']) ?></small>
                </div>
                <p class="text-gray-700"><?= nl2br(h($reply['body'])) ?></p>
              </div>
            <?php endwhile; $repStmt->close(); ?>
          </div>
        <?php endwhile; $postsStmt->close(); ?>

        <!-- New post (allowed for both students & teachers) -->
        <form method="POST" class="mt-6">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <label class="block mb-1 font-medium">Add a reply</label>
          <div class="flex gap-2">
            <textarea name="forum_body" class="w-full border border-gray-200 p-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-200" rows="2" placeholder="Type your reply..." required></textarea>
            <button type="submit" name="post_forum" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded">
              <ion-icon name="send-outline"></ion-icon> Post
            </button>
          </div>
        </form>

        <?php
        if (isset($_POST['post_forum'])) {
          if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo "<script>alert('Invalid session. Please refresh and try again.');</script>";
          } else {
            $msg = trim((string)($_POST['forum_body'] ?? ''));
            if ($msg !== '') {
              $stmt = $conn->prepare("
                  INSERT INTO forum_posts (content_id, user_id, body) 
                  VALUES (?, ?, ?)
              ");
              $stmt->bind_param("iis", $content_id, $user_id, $msg);
              $stmt->execute();
              $stmt->close();
              echo "<script>location.reload();</script>";
            }
          }
        }
        ?>
      <?php endif; ?>

    </div><!-- /body card -->

  </main>
</div>
</body>
</html>