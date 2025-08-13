<?php
session_start();
include 'db_connect.php';

// Allow both students and teachers
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'teacher'])) {
    die("Access Denied.");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;

// Check access
if ($role === 'student') {
    $check = $conn->query("SELECT * FROM enrollments WHERE user_id = $user_id AND course_id = $course_id AND status = 'active'");
    if ($check->num_rows == 0) {
        die("⛔ You are not enrolled in this course.");
    }
} else if ($role === 'teacher') {
    $teacher_id = $conn->query("SELECT teacher_id FROM teachers WHERE user_id = $user_id")->fetch_assoc()['teacher_id'];
    $check = $conn->query("SELECT * FROM teacher_courses WHERE teacher_id = $teacher_id AND course_id = $course_id");
    if ($check->num_rows == 0) {
        die("⛔ You are not assigned to this course.");
    }
}

// Get course info
$course = $conn->query("SELECT name, description FROM courses WHERE course_id = $course_id")->fetch_assoc();
$contents = $conn->query("SELECT * FROM contents WHERE course_id = $course_id ORDER BY position ASC");
$total_contents = $contents->num_rows;

// Optional: light progress bar (for students)
$progress_percent = 0;
if ($role === 'student') {
    $student_id_for_progress = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'] ?? 0;
    if ($student_id_for_progress) {
        $pr = $conn->query("SELECT chapters_completed FROM student_progress WHERE student_id = $student_id_for_progress AND course_id = $course_id")->fetch_assoc();
        $completed = $pr['chapters_completed'] ?? 0;
        if ($total_contents > 0) {
            $progress_percent = min(100, (int) floor(($completed / $total_contents) * 100));
        }
    }
}

function log_view_and_progress($conn, $user_id, $content_id, $course_id, $role) {
    if ($role !== 'student') return; // Only log for students
    // Log activity
    $logged = $conn->prepare("SELECT 1 FROM activity_logs WHERE user_id = ? AND content_id = ? AND action = 'view'");
    $logged->bind_param("ii", $user_id, $content_id);
    $logged->execute();
    $result = $logged->get_result();
    if ($result->num_rows === 0) {
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, content_id, action) VALUES (?, ?, 'view')");
        $logStmt->bind_param("ii", $user_id, $content_id);
        $logStmt->execute();
        $student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'];
        $conn->query("INSERT INTO student_progress (student_id, course_id, chapters_completed) VALUES ($student_id, $course_id, 1) ON DUPLICATE KEY UPDATE chapters_completed = chapters_completed + 1");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title><?= htmlspecialchars($course['name']) ?> - Course</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <!-- Light, clean font -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    [x-cloak] { display: none; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial, 'Noto Sans', 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol'; }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 min-h-screen antialiased">
  <?php include 'components/navbar.php'; ?>

  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-28">
    <!-- Course Header Card -->
    <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-8 mb-8">
      <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
      </div>
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">
        <div>
          <h1 class="text-3xl md:text-4xl font-extrabold text-blue-700 tracking-tight flex items-center gap-3">
            📘 <?= htmlspecialchars($course['name']) ?>
          </h1>
          <p class="mt-3 text-gray-600 leading-relaxed"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
        </div>
        <div class="shrink-0">
          <a href="<?= $role === 'student' ? 'student_courses.php' : 'teacher_dashboard.php' ?>"
             class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
            ← Back to <?= $role === 'student' ? 'Courses' : 'Dashboard' ?>
          </a>
        </div>
      </div>

      <?php if ($role === 'student'): ?>
        <div class="mt-6">
          <div class="flex items-center justify-between text-sm text-gray-600 mb-2">
            <span>Course progress</span>
            <span><?= $progress_percent ?>%</span>
          </div>
          <div class="h-2 w-full bg-gray-200 rounded-full overflow-hidden">
            <div class="h-full bg-blue-600 rounded-full transition-all" style="width: <?= $progress_percent ?>%"></div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Contents Card -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
      <h3 class="text-2xl font-semibold text-gray-900 mb-4">📂 Course Contents</h3>

      <?php if ($contents->num_rows === 0): ?>
        <div class="text-center py-16">
          <div class="mx-auto w-14 h-14 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 mb-4">📄</div>
          <p class="text-gray-700 font-medium">No content added yet for this course.</p>
          <p class="text-gray-500 text-sm mt-1">Please check back later.</p>
        </div>
      <?php else: ?>
        <div class="space-y-5">
          <?php while ($c = $contents->fetch_assoc()):
            log_view_and_progress($conn, $user_id, $c['content_id'], $course_id, $role);
            $content_type = $c['type'];

            // Soft badge colors by type
            $badgeMap = [
              'lesson' => 'bg-sky-100 text-sky-700',
              'video'  => 'bg-rose-100 text-rose-700',
              'pdf'    => 'bg-amber-100 text-amber-800',
              'quiz'   => 'bg-emerald-100 text-emerald-700',
              'forum'  => 'bg-indigo-100 text-indigo-700',
            ];
            $badge = $badgeMap[$content_type] ?? 'bg-gray-100 text-gray-700';
          ?>
            <?php if ($content_type === 'lesson'): ?>
              <!-- LESSON: uses a light modal -->
              <div id="content_<?= $c['content_id'] ?>" x-data="{ showModal: false }" class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
                <div class="flex items-center justify-between gap-4">
                  <div class="min-w-0">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>"><?= ucfirst($content_type) ?></span>
                    <button @click="showModal = true" class="block text-left w-full mt-1 text-blue-700 text-lg font-semibold hover:underline truncate">
                      <?= htmlspecialchars($c['title']) ?>
                    </button>
                  </div>
                  <button @click="showModal = true" class="text-gray-500 hover:text-gray-700 transition" aria-label="Open lesson">
                    <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z"/></svg>
                  </button>
                </div>
                <!-- Modal -->
                <div
                  x-show="showModal"
                  x-cloak
                  class="fixed inset-0 z-50 flex items-center justify-center"
                  aria-modal="true" role="dialog"
                >
                  <div @click="showModal = false" class="absolute inset-0 bg-slate-100/60 backdrop-blur-sm"></div>
                  <div
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                    x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                    class="relative bg-white rounded-2xl shadow-xl ring-1 ring-gray-200 max-w-5xl w-[95%] p-6"
                  >
                    <button @click="showModal = false" class="absolute top-3 right-3 text-gray-400 hover:text-gray-700 text-2xl leading-none" aria-label="Close">&times;</button>
                    <h2 class="text-2xl font-bold text-blue-700 mb-4"><?= htmlspecialchars($c['title']) ?></h2>
                    <?php if (!empty($c['body'])): ?>
                      <div class="bg-slate-50 ring-1 ring-slate-100 p-4 rounded-lg mb-4 text-gray-700 leading-relaxed">
                        <?= nl2br(htmlspecialchars($c['body'])) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($c['file_url'])):
                      $ext = strtolower(pathinfo($c['file_url'], PATHINFO_EXTENSION));
                      $isVideo = $ext === 'mp4';
                    ?>
                      <div class="mb-2">
                        <h4 class="font-semibold mb-2">Attached File</h4>
                        <?php if ($isVideo): ?>
                          <video controls class="w-full max-h-[460px] rounded-lg ring-1 ring-gray-200">
                            <source src="<?= $c['file_url'] ?>" type="video/mp4">
                            Your browser does not support the video tag.
                          </video>
                        <?php else: ?>
                          <iframe src="<?= $c['file_url'] ?>" class="w-full h-[460px] rounded-lg ring-1 ring-gray-200" frameborder="0"></iframe>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <!-- OTHER TYPES: collapsible card -->
              <div id="content_<?= $c['content_id'] ?>" x-data="{ open: false }" class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
                <div class="flex items-center justify-between gap-4">
                  <div class="min-w-0">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>"><?= ucfirst($content_type) ?></span>
                    <button @click="open = !open" class="block text-left w-full mt-1 text-blue-700 text-lg font-semibold hover:underline truncate">
                      <?= htmlspecialchars($c['title']) ?>
                    </button>
                  </div>
                  <button @click="open = !open" class="text-gray-500 hover:text-gray-700 transition" aria-label="Toggle section">
                    <svg :class="{'rotate-180': open}" class="w-5 h-5 transition-transform" viewBox="0 0 20 20" fill="currentColor">
                      <path d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z"/>
                    </svg>
                  </button>
                </div>

                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="mt-4 space-y-4">
                  <?php if (!empty($c['body'])): ?>
                    <div class="bg-slate-50 ring-1 ring-slate-100 p-4 rounded-lg text-gray-700 leading-relaxed">
                      <?= nl2br(htmlspecialchars($c['body'])) ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($c['file_url'])):
                    $ext = strtolower(pathinfo($c['file_url'], PATHINFO_EXTENSION));
                    $isVideo = $content_type === 'video' || $ext === 'mp4';
                    $isPdf = $content_type === 'pdf' || $ext === 'pdf';
                  ?>
                    <div>
                      <h4 class="font-semibold mb-2">Attached File</h4>
                      <?php if ($isVideo): ?>
                        <video controls class="w-full max-h-[460px] rounded-lg ring-1 ring-gray-200">
                          <source src="<?= $c['file_url'] ?>" type="video/mp4">
                          Your browser does not support the video tag.
                        </video>
                      <?php else: ?>
                        <iframe src="<?= $c['file_url'] ?>" class="w-full h-[600px] rounded-lg ring-1 ring-gray-200" frameborder="0"></iframe>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <?php if ($content_type === 'quiz'):
                    $assignment = $conn->query("SELECT * FROM assignments WHERE lesson_id = {$c['content_id']}")->fetch_assoc();
                    $questions = $assignment ? $conn->query("SELECT * FROM assignment_questions WHERE assignment_id = {$assignment['assignment_id']}") : [];
                    $max_attempts = 2;
                    $student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'];

                    // Fetch all attempts
                    $attempts_arr = [];
                    if ($assignment) {
                      $attempts_res = $conn->query("SELECT * FROM student_assignment_attempts WHERE student_id = $student_id AND assignment_id = {$assignment['assignment_id']} ORDER BY attempted_at DESC");
                      while ($row = $attempts_res->fetch_assoc()) {
                          $attempts_arr[] = $row;
                      }
                    }
                    $attempts = count($attempts_arr);
                    $latest_attempt = $attempts > 0 ? $attempts_arr[0] : null;
                  ?>
                    <div class="bg-emerald-50 ring-1 ring-emerald-100 p-4 rounded-lg">
                      <h4 class="font-semibold mb-3 flex items-center gap-2">🧠 Quiz</h4>

                      <?php if ($role === 'student'): ?>
                        <?php if ($attempts > 0): ?>
                          <div class="bg-green-50 ring-1 ring-green-100 p-3 rounded mb-3 text-sm">
                            <h5 class="font-semibold mb-1">Your Latest Attempt</h5>
                            <p><span class="font-medium">Date & Time:</span> <?= date('Y-m-d H:i:s', strtotime($latest_attempt['attempted_at'])) ?></p>
                            <p>Score: <span class="font-bold"><?= $latest_attempt['score'] ?></span> / <?= $questions->num_rows ?></p>
                            <p>Result: <?= $latest_attempt['passed'] ? '<span class="text-green-700 font-semibold">Pass</span>' : '<span class="text-red-600 font-semibold">Fail</span>' ?></p>
                            <p>Attempt <?= $attempts ?> of <?= $max_attempts ?></p>
                          </div>
                        <?php endif; ?>

                        <?php if ($attempts >= 2): ?>
                          <div class="bg-blue-50 ring-1 ring-blue-100 p-3 rounded mb-3">
                            <h5 class="font-semibold mb-2">Quiz Review</h5>
                            <?php
                            $review_questions = $conn->query("SELECT * FROM assignment_questions WHERE assignment_id = {$assignment['assignment_id']}");
                            $attempt_id = $latest_attempt['attempt_id'];
                            $student_answers = [];
                            $ans_res = $conn->query("SELECT question_id, selected_option, is_correct FROM assignment_attempt_questions WHERE attempt_id = $attempt_id");
                            while ($row = $ans_res->fetch_assoc()) {
                                $student_answers[$row['question_id']] = $row;
                            }
                            foreach ($review_questions as $q):
                              $ans = $student_answers[$q['question_id']] ?? null;
                              $is_correct = $ans ? $ans['is_correct'] : 0;
                              $selected = $ans ? $ans['selected_option'] : '';
                            ?>
                              <div class="mb-3">
                                <div class="font-medium"><?= htmlspecialchars($q['question_text']) ?></div>
                                <div class="text-sm">
                                  <span class="font-semibold">Your answer:</span>
                                  <span class="<?= $is_correct ? 'text-green-700' : 'text-red-700' ?>">
                                    <?= $selected ? htmlspecialchars($selected) . ') ' . htmlspecialchars($q['option_' . strtolower($selected)]) : 'No answer' ?>
                                    <?= $is_correct ? '✅' : '❌' ?>
                                  </span>
                                </div>
                                <?php if (!$is_correct): ?>
                                <div class="text-sm">
                                  <span class="font-semibold">Correct answer:</span>
                                  <span class="text-green-700"><?= htmlspecialchars($q['correct_option']) ?>) <?= htmlspecialchars($q['option_' . strtolower($q['correct_option'])]) ?></span>
                                </div>
                                <?php endif; ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>

                        <?php if ($attempts < $max_attempts && $questions && $questions->num_rows > 0): ?>
                          <form method="post" class="space-y-4">
                            <input type="hidden" name="quiz_content_id" value="<?= $c['content_id'] ?>">
                            <?php
                            $questions->data_seek(0); // Reset pointer for the form
                            foreach ($questions as $q): ?>
                              <div class="rounded-lg border border-gray-100 p-3">
                                <p class="font-medium"><?= htmlspecialchars($q['question_text']) ?></p>
                                <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                  <label class="block ml-4 mt-1 text-gray-700">
                                    <input class="mr-2 accent-blue-600" type="radio" name="quiz[<?= $q['question_id'] ?>]" value="<?= $opt ?>" required>
                                    <?= $opt ?>) <?= htmlspecialchars($q['option_' . strtolower($opt)]) ?>
                                  </label>
                                <?php endforeach; ?>
                              </div>
                            <?php endforeach; ?>
                            <div class="flex items-center justify-between">
                              <button type="submit" name="submit_quiz" value="<?= $assignment['assignment_id'] ?>"
                                      class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 shadow-sm">
                                Submit Quiz
                              </button>
                              <p class="text-sm text-gray-500">Attempt <?= $attempts + 1 ?> of <?= $max_attempts ?></p>
                            </div>
                          </form>
                        <?php elseif ($attempts >= $max_attempts): ?>
                          <p class="text-red-600 font-semibold">⛔ You have reached the maximum of 2 attempts for this quiz.</p>
                        <?php endif; ?>
                      <?php elseif ($role === 'teacher'): ?>
                        <p class="italic text-gray-500">Students can attempt this quiz. Preview questions below:</p>
                        <?php if ($questions && $questions->num_rows > 0): ?>
                          <?php foreach ($questions as $q): ?>
                            <div class="mb-3">
                              <p class="font-medium"><?= htmlspecialchars($q['question_text']) ?></p>
                              <ul class="ml-6 list-disc text-gray-700">
                                <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                  <li><?= $opt ?>) <?= htmlspecialchars($q['option_' . strtolower($opt)]) ?></li>
                                <?php endforeach; ?>
                              </ul>
                              <span class="text-green-600 text-xs">Correct: <?= $q['correct_option'] ?></span>
                            </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <p class="text-gray-600">No quiz questions found.</p>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <?php if ($content_type === 'forum'):
                    $posts = $conn->query("SELECT p.post_id, p.body, u.username, p.posted_at FROM forum_posts p JOIN users u ON u.user_id = p.user_id WHERE p.content_id = {$c['content_id']} AND p.parent_post_id IS NULL ORDER BY posted_at");
                  ?>
                    <div class="bg-indigo-50 ring-1 ring-indigo-100 p-4 rounded-lg">
                      <h4 class="font-semibold mb-3">💬 Forum Discussion</h4>
                      <?php while ($post = $posts->fetch_assoc()): ?>
                        <div class="bg-white border border-gray-100 p-3 mb-2 rounded-lg">
                          <div class="flex items-center justify-between">
                            <strong class="text-gray-800"><?= htmlspecialchars($post['username']) ?></strong>
                            <small class="text-gray-500"><?= $post['posted_at'] ?></small>
                          </div>
                          <p class="mt-1 text-gray-700"><?= nl2br(htmlspecialchars($post['body'])) ?></p>
                        </div>
                      <?php endwhile; ?>

                      <?php if ($role === 'student'): ?>
                        <form method="post" class="mt-3">
                          <input type="hidden" name="forum_content_id" value="<?= $c['content_id'] ?>">
                          <textarea name="forum_body" class="w-full border border-gray-200 focus:border-blue-300 focus:ring-2 focus:ring-blue-200 outline-none p-3 rounded-lg text-gray-800"
                                    rows="2" placeholder="Type your comment..." required></textarea>
                          <button type="submit" name="post_forum"
                                  class="mt-2 inline-flex items-center gap-2 bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 shadow-sm">
                            Post
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php
// Forum Post Handler
if (isset($_POST['post_forum']) && isset($_POST['forum_body'], $_POST['forum_content_id']) && $role === 'student') {
    $msg = $_POST['forum_body'];
    $cid = intval($_POST['forum_content_id']);
    $stmt = $conn->prepare("INSERT INTO forum_posts (content_id, user_id, body) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $cid, $user_id, $msg);
    $stmt->execute();
    echo "<script>window.location.href = '" . strtok($_SERVER['REQUEST_URI'], '?') . "?course_id=$course_id#content_$cid';</script>";
    exit;
}

// Quiz Submission Handler
if (isset($_POST['submit_quiz']) && isset($_POST['quiz']) && $role === 'student') {
    $assignment_id = intval($_POST['submit_quiz']);
    $quiz_content_id = intval($_POST['quiz_content_id'] ?? 0);
    $student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'];
    // Check attempts
    $attempts_res = $conn->query("SELECT COUNT(*) as cnt FROM student_assignment_attempts WHERE student_id = $student_id AND assignment_id = $assignment_id");
    $attempts = $attempts_res->fetch_assoc()['cnt'];
    if ($attempts < 2) {
        $questions = $conn->query("SELECT * FROM assignment_questions WHERE assignment_id = $assignment_id");
        $assignment = $conn->query("SELECT * FROM assignments WHERE assignment_id = $assignment_id")->fetch_assoc();
        $answers = $_POST['quiz'];
        $score = 0;

        $conn->query("INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed) VALUES ($student_id, $assignment_id, 0, 0)");
        $attempt_id = $conn->insert_id;

        foreach ($questions as $q) {
            $selected = $answers[$q['question_id']] ?? '';
            $correct = $q['correct_option'];
            $is_correct = ($selected === $correct) ? 1 : 0;
            $score += $is_correct;

            $stmt = $conn->prepare("INSERT INTO assignment_attempt_questions (attempt_id, question_id, selected_option, is_correct) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisi", $attempt_id, $q['question_id'], $selected, $is_correct);
            $stmt->execute();
        }

        $pass = ($score >= $assignment['passing_score']) ? 1 : 0;
        $conn->query("UPDATE student_assignment_attempts SET score = $score, passed = $pass WHERE attempt_id = $attempt_id");

        echo "<script>alert('✅ Quiz submitted! Score: $score / " . $questions->num_rows . "'); window.location.href = '" . strtok($_SERVER['REQUEST_URI'], '?') . "?course_id=$course_id#content_$quiz_content_id';</script>";
        exit;
    } else {
        echo "<script>alert('⛔ You have reached the maximum of 2 attempts for this quiz.'); window.location.href = '" . strtok($_SERVER['REQUEST_URI'], '?') . "?course_id=$course_id#content_$quiz_content_id';</script>";
        exit;
    }
}
?>
</body>
</html>