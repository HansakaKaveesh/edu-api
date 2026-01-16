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
    $asStmt = $conn->prepare("SELECT assignment_id FROM assignments WHERE lesson_id = ? LIMIT 1");
    $asStmt->bind_param("i", $content_id);
    $asStmt->execute();
    $rowA = $asStmt->get_result()->fetch_assoc();
    $asStmt->close();

    $assignment_id = (int)($rowA['assignment_id'] ?? 0);

    if ($assignment_id) {
        // Resolve student
        $su = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
        $su->bind_param("i", $user_id);
        $su->execute();
        $rowS = $su->get_result()->fetch_assoc();
        $su->close();

        $student_id = (int)($rowS['student_id'] ?? 0);
        if ($student_id) {
            // Fetch questions
            $qs = $conn->prepare("SELECT question_id, correct_option FROM assignment_questions WHERE assignment_id = ?");
            $qs->bind_param("i", $assignment_id);
            $qs->execute();
            $questionsRes = $qs->get_result();

            // Assignment meta (for pass score)
            $am = $conn->prepare("SELECT passing_score FROM assignments WHERE assignment_id = ? LIMIT 1");
            $am->bind_param("i", $assignment_id);
            $am->execute();
            $assignment = $am->get_result()->fetch_assoc();
            $am->close();

            $answers = $_POST['quiz'];
            $score   = 0;

            // Create attempt
            $insAttempt = $conn->prepare("INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed) VALUES (?, ?, 0, 0)");
            $insAttempt->bind_param("ii", $student_id, $assignment_id);
            $insAttempt->execute();
            $attempt_id = (int)$conn->insert_id;
            $insAttempt->close();

            // Insert answers
            $insQ = $conn->prepare("INSERT INTO assignment_attempt_questions (attempt_id, question_id, selected_option, is_correct) VALUES (?, ?, ?, ?)");

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

            $up = $conn->prepare("UPDATE student_assignment_attempts SET score = ?, passed = ? WHERE attempt_id = ?");
            $up->bind_param("iii", $score, $passed, $attempt_id);
            $up->execute();
            $up->close();

            // Redirect to self to show results
            header("Location: " . $_SERVER['REQUEST_URI']);
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
    // Get Assignment ID
    $asStmt = $conn->prepare("SELECT assignment_id FROM assignments WHERE lesson_id = ? LIMIT 1");
    $asStmt->bind_param("i", $content_id);
    $asStmt->execute();
    $rowA = $asStmt->get_result()->fetch_assoc();
    $asStmt->close();

    $assignment_id = (int)($rowA['assignment_id'] ?? 0);

    if ($assignment_id) {
        $stuStmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
        $stuStmt->bind_param("i", $user_id);
        $stuStmt->execute();
        $rowS = $stuStmt->get_result()->fetch_assoc();
        $stuStmt->close();
        $student_id = (int)($rowS['student_id'] ?? 0);

        if ($student_id) {
            // Count attempts
            $cntAtt = $conn->prepare("SELECT COUNT(*) as cnt FROM student_assignment_attempts WHERE student_id = ? AND assignment_id = ?");
            $cntAtt->bind_param("ii", $student_id, $assignment_id);
            $cntAtt->execute();
            $attempts = (int)($cntAtt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $cntAtt->close();

            // Get Last attempt
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
                // Get user answers
                $ans = $conn->prepare("SELECT question_id, selected_option, is_correct FROM assignment_attempt_questions WHERE attempt_id = ?");
                $ans->bind_param("i", $aid);
                $ans->execute();
                $ansRes = $ans->get_result();
                while ($row = $ansRes->fetch_assoc()) {
                    $answersMap[(int)$row['question_id']] = $row;
                }
                $ans->close();

                // Get questions for review
                $qrev = $conn->prepare("
                    SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_option 
                    FROM assignment_questions 
                    WHERE assignment_id = ?
                ");
                $qrev->bind_param("i", $assignment_id);
                $qrev->execute();
                $questions_review = $qrev->get_result();
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

      <!-- File Attachment / Modal Button -->
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
            <?php else: ?>
              <iframe src="<?= h($content['file_url']) ?>" class="w-full h-[75vh] rounded" frameborder="0"></iframe>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Linked Assignment (for lessons) -->
      <?php if ($content_type === 'lesson'): ?>
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
          <h2 class="text-xl font-semibold mt-8 mb-3 inline-flex items-center gap-2">
             <ion-icon name="create-outline" class="text-amber-600"></ion-icon> Linked Assignment
          </h2>
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
        <?php endif; ?>
      <?php endif; ?>

      <!-- QUIZ ASSESSMENT (MODERN REDESIGN) -->
      <?php if ($content_type === 'quiz'): ?>
        
        <?php
          // 1. Get Assignment ID (Logic reused for safety in display block)
          $aidStmt = $conn->prepare("SELECT assignment_id FROM assignments WHERE lesson_id = ? LIMIT 1");
          $aidStmt->bind_param("i", $content_id);
          $aidStmt->execute();
          $aidRow = $aidStmt->get_result()->fetch_assoc();
          $aidStmt->close();
          $assignment_id = (int)($aidRow['assignment_id'] ?? 0);

          // 2. Fetch Questions (for the form)
          $questionsList = null;
          if ($assignment_id) {
              $qList = $conn->prepare("SELECT question_id, question_text, option_a, option_b, option_c, option_d FROM assignment_questions WHERE assignment_id = ?");
              $qList->bind_param("i", $assignment_id);
              $qList->execute();
              $questionsList = $qList->get_result();
          }
        ?>

        <div class="mt-8 border-t border-gray-100 pt-8">
          <div class="flex items-center gap-3 mb-6">
            <div class="p-2 bg-indigo-100 text-indigo-600 rounded-lg">
              <ion-icon name="trophy" class="text-xl"></ion-icon>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">Quiz Assessment</h2>
          </div>

          <?php if (!empty($assignment_id)): ?>
            
            <!-- Student View -->
            <?php if ($role === 'student'): ?>
              <div x-data="{ showReview: false }">
                
                <!-- 1. Stats / Summary Dashboard -->
                <?php if ($latest_attempt): 
                    $totalQ = $questions_review ? $questions_review->num_rows : 0;
                    $score = (int)$latest_attempt['score'];
                    $passed = (bool)$latest_attempt['passed'];
                    $percentage = $totalQ > 0 ? round(($score / $totalQ) * 100) : 0;
                ?>
                  <div class="bg-gradient-to-r from-slate-800 to-slate-900 rounded-2xl shadow-lg p-6 text-white mb-8 relative overflow-hidden">
                    <!-- Background decoration -->
                    <div class="absolute top-0 right-0 -mr-8 -mt-8 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
                    
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 relative z-10">
                      <div>
                        <p class="text-slate-300 text-sm font-medium uppercase tracking-wider mb-1">Last Result</p>
                        <h3 class="text-3xl font-bold flex items-center gap-3">
                          <?= $passed ? '<span class="text-emerald-400">Passed</span>' : '<span class="text-rose-400">Failed</span>' ?>
                          <span class="text-lg font-normal text-slate-400">
                             (<?= $score ?>/<?= $totalQ ?> Correct)
                          </span>
                        </h3>
                        <p class="text-sm text-slate-400 mt-2 flex items-center gap-2">
                           <ion-icon name="calendar-outline"></ion-icon> 
                           <?= date('M d, Y h:i A', strtotime($latest_attempt['attempted_at'])) ?>
                           <span class="w-1 h-1 bg-slate-500 rounded-full"></span>
                           Attempt #<?= (int)$attempts ?>
                        </p>
                      </div>

                      <div class="flex items-center gap-4">
                        <!-- Circular Progress Visual -->
                        <div class="relative w-16 h-16 flex items-center justify-center rounded-full border-4 <?= $passed ? 'border-emerald-500' : 'border-rose-500' ?> bg-slate-800">
                           <span class="font-bold text-sm"><?= $percentage ?>%</span>
                        </div>
                        
                        <button @click="showReview = !showReview" 
                                class="px-5 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 transition flex items-center gap-2 text-sm font-medium backdrop-blur-sm">
                          <ion-icon :name="showReview ? 'eye-off-outline' : 'eye-outline'"></ion-icon>
                          <span x-text="showReview ? 'Hide Review' : 'Review Answers'"></span>
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- 2. Review Section (Toggleable) -->
                  <div x-show="showReview" x-cloak 
                       x-transition:enter="transition ease-out duration-300"
                       x-transition:enter-start="opacity-0 translate-y-2"
                       x-transition:enter-end="opacity-100 translate-y-0"
                       class="space-y-6 mb-10">
                    
                    <h3 class="font-bold text-gray-700 text-lg border-b pb-2">Answer Breakdown</h3>

                    <?php if ($questions_review && $questions_review->num_rows > 0): ?>
                      <?php foreach ($questions_review as $index => $q): 
                          $qid = (int)$q['question_id'];
                          $selected = $answersMap[$qid]['selected_option'] ?? null;
                          $correct = $q['correct_option'];
                          $is_correct = ($selected === $correct);
                          
                          // Styling Logic
                          $cardBorder = $is_correct ? 'border-emerald-200' : 'border-rose-200';
                          $cardBg = $is_correct ? 'bg-emerald-50/30' : 'bg-rose-50/30';
                          $icon = $is_correct ? 'checkmark-circle' : 'close-circle';
                          $iconColor = $is_correct ? 'text-emerald-500' : 'text-rose-500';
                      ?>
                        <div class="rounded-xl border <?= $cardBorder ?> <?= $cardBg ?> p-5 transition hover:shadow-md bg-white">
                          <div class="flex gap-4">
                            <div class="flex-shrink-0 mt-1">
                               <ion-icon name="<?= $icon ?>" class="text-2xl <?= $iconColor ?>"></ion-icon>
                            </div>
                            <div class="flex-grow">
                              <p class="font-semibold text-gray-800 text-lg mb-3">
                                <span class="text-gray-400 mr-1"><?= sprintf("%02d", $index + 1) ?>.</span> 
                                <?= h($q['question_text']) ?>
                              </p>
                              
                              <div class="grid gap-2 sm:grid-cols-2">
                                <?php foreach (['A','B','C','D'] as $opt): 
                                    $txt = h($q['option_'.strtolower($opt)]);
                                    $isUser = ($selected === $opt);
                                    $isAns = ($correct === $opt);
                                    
                                    // Option Styles
                                    $optClass = "border-gray-200 text-gray-600";
                                    $badge = "";

                                    if ($isAns) {
                                        $optClass = "border-emerald-500 bg-emerald-50 text-emerald-800 ring-1 ring-emerald-500";
                                        $badge = "<span class='ml-auto text-xs font-bold text-emerald-600 uppercase tracking-wider'>Correct</span>";
                                    } elseif ($isUser && !$is_correct) {
                                        $optClass = "border-rose-300 bg-rose-50 text-rose-800";
                                        $badge = "<span class='ml-auto text-xs font-bold text-rose-600 uppercase tracking-wider'>Your Answer</span>";
                                    } elseif ($isUser && $is_correct) {
                                        $badge = "<span class='ml-auto text-xs font-bold text-emerald-600 uppercase tracking-wider'>You & Correct</span>";
                                    }
                                ?>
                                  <div class="relative flex items-center p-3 rounded-lg border <?= $optClass ?> text-sm">
                                    <span class="w-6 font-bold opacity-60"><?= $opt ?>.</span>
                                    <span><?= $txt ?></span>
                                    <?= $badge ?>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="text-center py-8 text-gray-500 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                        No review data available.
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <!-- 3. New Submission Form -->
                <?php if ($questionsList && $questionsList->num_rows > 0): ?>
                  <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                      <h3 class="font-bold text-gray-800 flex items-center gap-2">
                        <ion-icon name="pencil" class="text-blue-600"></ion-icon> Take Quiz
                      </h3>
                      <span class="text-xs font-medium px-2 py-1 bg-white border rounded text-gray-500">
                        Attempting now
                      </span>
                    </div>

                    <form method="post" class="p-6 md:p-8 space-y-10">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                      
                      <?php 
                        // Reset pointer in case it was used before
                        $questionsList->data_seek(0);
                        $qCount = 0;
                      ?>
                      <?php while($q = $questionsList->fetch_assoc()): $qCount++; ?>
                        <!-- Single Question Block -->
                        <div class="group" x-data="{ selected: '' }">
                          <div class="flex items-start gap-4 mb-4">
                            <span class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 font-bold text-sm">
                              <?= $qCount ?>
                            </span>
                            <h4 class="text-lg font-medium text-gray-900 leading-snug pt-0.5">
                              <?= h($q['question_text']) ?>
                            </h4>
                          </div>

                          <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pl-0 md:pl-12">
                            <?php foreach (['A','B','C','D'] as $opt): ?>
                              <label class="relative flex cursor-pointer rounded-xl border p-4 shadow-sm focus:outline-none transition-all duration-200"
                                     :class="selected === '<?= $opt ?>' 
                                        ? 'border-blue-600 ring-1 ring-blue-600 bg-blue-50/50 z-10' 
                                        : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'">
                                <input type="radio" 
                                       name="quiz[<?= (int)$q['question_id'] ?>]" 
                                       value="<?= $opt ?>" 
                                       class="sr-only" 
                                       required
                                       x-model="selected">
                                <span class="flex flex-1">
                                  <span class="flex flex-col">
                                    <span class="block text-sm font-medium text-gray-900 flex gap-2">
                                      <span class="text-gray-400 font-normal"><?= $opt ?>.</span> 
                                      <?= h($q['option_'.strtolower($opt)]) ?>
                                    </span>
                                  </span>
                                </span>
                                <!-- Checkmark Icon (Visible when selected) -->
                                <ion-icon name="checkmark-circle" 
                                          class="text-blue-600 text-xl ml-3"
                                          x-show="selected === '<?= $opt ?>'"
                                          x-transition:enter="transition ease-out duration-200"
                                          x-transition:enter-start="opacity-0 scale-50"
                                          x-transition:enter-end="opacity-100 scale-100"></ion-icon>
                                <!-- Empty Circle (Visible when NOT selected) -->
                                <div class="h-5 w-5 rounded-full border border-gray-300 ml-3"
                                     x-show="selected !== '<?= $opt ?>'"></div>
                              </label>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      <?php endwhile; ?>

                      <div class="pt-6 border-t border-gray-100 flex items-center justify-between">
                         <span class="text-sm text-gray-500">
                           Total Attempts: <span class="font-medium text-gray-800"><?= (int)$attempts ?></span>
                         </span>
                         <button type="submit" name="submit_quiz"
                                 class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-xl font-semibold shadow-lg shadow-blue-200 transition transform hover:-translate-y-0.5">
                           <span>Submit Assessment</span>
                           <ion-icon name="arrow-forward"></ion-icon>
                         </button>
                      </div>
                    </form>
                  </div>
                <?php endif; ?>

              </div>
            <?php else: // Not Student (Teacher/Admin) ?>
               <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center">
                  <ion-icon name="eye-off-outline" class="text-4xl text-amber-400 mb-2"></ion-icon>
                  <p class="text-amber-800 font-medium">Teacher View Mode</p>
                  <p class="text-sm text-amber-700 mt-1">
                    You can manage quiz questions in the course manager. The interactive quiz interface above is visible only to enrolled students.
                  </p>
                  <?php if ($questionsList && $questionsList->num_rows > 0): ?>
                    <div class="mt-6 text-left max-w-2xl mx-auto space-y-4">
                       <h4 class="font-bold text-gray-700">Preview Questions:</h4>
                       <?php $questionsList->data_seek(0); while($q = $questionsList->fetch_assoc()): ?>
                         <div class="p-3 bg-white border rounded">
                           <?= h($q['question_text']) ?>
                         </div>
                       <?php endwhile; ?>
                    </div>
                  <?php endif; ?>
               </div>
            <?php endif; ?>

          <?php else: ?>
            <div class="text-center py-10 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
               <ion-icon name="document-text-outline" class="text-4xl text-gray-300 mb-2"></ion-icon>
               <p class="text-gray-500 font-medium">No quiz questions have been assigned to this content yet.</p>
            </div>
          <?php endif; ?>
        </div>
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