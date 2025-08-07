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
  <style>[x-cloak] { display: none; }</style>
    <link rel="icon" type="image/png" href="./images/logo.png" />
</head>
<body class="bg-gray-100 min-h-screen font-sans">
  <?php include 'components/navbar.php'; ?>
<div class="max-w-full mx-auto px-10 py-28">
  <div class="bg-white shadow rounded-lg p-6 mb-6">
    <h2 class="text-3xl font-bold text-blue-600 mb-2">📘 <?= htmlspecialchars($course['name']) ?></h2>
    <p class="text-gray-700 mb-4"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
    <a href="<?= $role === 'student' ? 'student_courses.php' : 'teacher_dashboard.php' ?>" class="text-blue-500 hover:underline">⬅ Back to <?= $role === 'student' ? 'Courses' : 'Dashboard' ?></a>
  </div>
  <div class="bg-white shadow rounded-lg p-6">
    <h3 class="text-2xl font-semibold text-gray-800 mb-4">📂 Course Contents</h3>

<?php if ($contents->num_rows === 0): ?>
  <p class="text-gray-600 italic">No content added yet for this course.</p>
<?php else: ?>
  <div class="space-y-6">
    <?php while ($c = $contents->fetch_assoc()):
      log_view_and_progress($conn, $user_id, $c['content_id'], $course_id, $role);
      $content_type = $c['type'];
    ?>
      <?php if ($content_type === 'lesson'): ?>
        <div id="content_<?= $c['content_id'] ?>" x-data="{ showModal: false }" class="border border-gray-200 p-4 rounded-md shadow-sm bg-gray-50">
          <div class="flex justify-between items-center">
            <div>
              <p class="text-sm font-medium text-gray-600"><?= ucfirst($content_type) ?></p>
              <button @click="showModal = true" class="text-blue-600 text-lg font-semibold hover:underline">
                <?= htmlspecialchars($c['title']) ?>
              </button>
            </div>
            <button @click="showModal = true" class="text-sm text-gray-500 hover:text-gray-700">🔽</button>
          </div>
          <!-- Modal -->
          <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div @click.away="showModal = false" class="bg-white rounded-xl shadow-lg max-w-7xl w-full p-6 relative">
              <button @click="showModal = false" class="absolute top-2 right-2 text-gray-400 hover:text-gray-700 text-2xl">&times;</button>
              <h2 class="text-2xl font-bold text-blue-700 mb-4"><?= htmlspecialchars($c['title']) ?></h2>
              <?php if (!empty($c['body'])): ?>
                <div class="bg-gray-100 p-3 rounded mb-4"><?= nl2br(htmlspecialchars($c['body'])) ?></div>
              <?php endif; ?>
              <?php if (!empty($c['file_url'])): 
                $ext = strtolower(pathinfo($c['file_url'], PATHINFO_EXTENSION));
                $isVideo = $ext === 'mp4';
              ?>
                <div class="mb-4">
                  <h4 class="font-semibold mb-1">Attached File:</h4>
                  <?php if ($isVideo): ?>
                    <video controls class="w-full max-h-[400px] rounded">
                      <source src="<?= $c['file_url'] ?>" type="video/mp4">
                      Your browser does not support the video tag.
                    </video>
                  <?php else: ?>
                    <iframe src="<?= $c['file_url'] ?>" class="w-full h-[400px] rounded" frameborder="0"></iframe>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div id="content_<?= $c['content_id'] ?>" x-data="{ open: false }" class="border border-gray-200 p-4 rounded-md shadow-sm bg-gray-50">
          <div class="flex justify-between items-center">
            <div>
              <p class="text-sm font-medium text-gray-600"><?= ucfirst($content_type) ?></p>
              <button @click="open = !open" class="text-blue-600 text-lg font-semibold hover:underline">
                <?= htmlspecialchars($c['title']) ?>
              </button>
            </div>
            <button @click="open = !open" class="text-sm text-gray-500 hover:text-gray-700">🔽</button>
          </div>
          <div x-show="open" x-cloak class="mt-4 space-y-4">
            <?php if (!empty($c['body'])): ?>
              <div class="bg-gray-100 p-3 rounded"><?= nl2br(htmlspecialchars($c['body'])) ?></div>
            <?php endif; ?>

            <?php if (!empty($c['file_url'])): 
              $ext = strtolower(pathinfo($c['file_url'], PATHINFO_EXTENSION));
              $isVideo = $content_type === 'video' || $ext === 'mp4';
              $isPdf = $content_type === 'pdf' || $ext === 'pdf';
            ?>
              <div>
                <h4 class="font-semibold mb-1">Attached File:</h4>
                <?php if ($isVideo): ?>
                  <video controls class="w-full max-h-[400px] rounded">
                    <source src="<?= $c['file_url'] ?>" type="video/mp4">
                    Your browser does not support the video tag.
                  </video>
                <?php else: ?>
                  <iframe src="<?= $c['file_url'] ?>" class="w-full h-[600px] rounded" frameborder="0"></iframe>
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
              $attempts_res = $conn->query("SELECT * FROM student_assignment_attempts WHERE student_id = $student_id AND assignment_id = {$assignment['assignment_id']} ORDER BY attempted_at DESC");
              while ($row = $attempts_res->fetch_assoc()) {
                  $attempts_arr[] = $row;
              }
              $attempts = count($attempts_arr);
              $latest_attempt = $attempts > 0 ? $attempts_arr[0] : null;
            ?>
              <div class="bg-blue-50 p-3 rounded">
                <h4 class="font-semibold mb-2">🧠 Quiz</h4>
                <?php if ($role === 'student'): ?>
                  <?php if ($attempts > 0): ?>
                    <div class="bg-green-50 p-3 rounded mb-2">
                      <h4 class="font-semibold mb-1">Your Latest Attempt</h4>
                      <p>
                        <span class="font-semibold">Date & Time:</span>
                        <?= date('Y-m-d H:i:s', strtotime($latest_attempt['attempted_at'])) ?>
                      </p>
                      <p>Score: <span class="font-bold"><?= $latest_attempt['score'] ?></span> / <?= $questions->num_rows ?></p>
                      <p>Result: <?= $latest_attempt['passed'] ? '<span class="text-green-600 font-bold">Pass</span>' : '<span class="text-red-600 font-bold">Fail</span>' ?></p>
                      <p>Attempt <?= $attempts ?> of <?= $max_attempts ?></p>
                    </div>
                  <?php endif; ?>

                  <?php if ($attempts >= 2): ?>
                    <div class="bg-blue-100 p-3 rounded mb-2">
                      <h4 class="font-semibold mb-1">Quiz Review</h4>
                      <?php
                      // Fetch all questions and student's answers for the latest attempt
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
                          <div>
                            <span class="font-semibold">Your answer:</span>
                            <span class="<?= $is_correct ? 'text-green-700' : 'text-red-700' ?>">
                              <?= $selected ? htmlspecialchars($selected) . ') ' . htmlspecialchars($q['option_' . strtolower($selected)]) : 'No answer' ?>
                              <?= $is_correct ? '✅' : '❌' ?>
                            </span>
                          </div>
                          <?php if (!$is_correct): ?>
                          <div>
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
                        <div>
                          <p class="font-medium"><?= htmlspecialchars($q['question_text']) ?></p>
                          <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                            <label class="block ml-4">
                              <input type="radio" name="quiz[<?= $q['question_id'] ?>]" value="<?= $opt ?>" required>
                              <?= $opt ?>) <?= htmlspecialchars($q['option_' . strtolower($opt)]) ?>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      <?php endforeach; ?>
                      <button type="submit" name="submit_quiz" value="<?= $assignment['assignment_id'] ?>" class="bg-blue-500 text-white px-4 py-2 rounded">Submit Quiz</button>
                      <p class="text-sm text-gray-500 mt-2">Attempt <?= $attempts + 1 ?> of <?= $max_attempts ?></p>
                    </form>
                  <?php elseif ($attempts >= $max_attempts): ?>
                    <p class="text-red-600 font-semibold">⛔ You have reached the maximum of 2 attempts for this quiz.</p>
                  <?php endif; ?>
                <?php elseif ($role === 'teacher'): ?>
                  <p class="italic text-gray-500">Students can attempt this quiz. You can preview questions below:</p>
                  <?php if ($questions && $questions->num_rows > 0): ?>
                    <?php foreach ($questions as $q): ?>
                      <div class="mb-2">
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
                    <p>No quiz questions found.</p>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($content_type === 'forum'): 
              $posts = $conn->query("SELECT p.post_id, p.body, u.username, p.posted_at FROM forum_posts p JOIN users u ON u.user_id = p.user_id WHERE p.content_id = {$c['content_id']} AND p.parent_post_id IS NULL ORDER BY posted_at");
            ?>
              <div class="bg-gray-50 p-3 rounded">
                <h4 class="font-semibold mb-2">💬 Forum Discussion</h4>
                <?php while ($post = $posts->fetch_assoc()): ?>
                  <div class="border p-2 mb-2 rounded">
                    <strong><?= htmlspecialchars($post['username']) ?></strong>:
                    <p><?= nl2br(htmlspecialchars($post['body'])) ?></p>
                    <small class="text-gray-500"><?= $post['posted_at'] ?></small>
                  </div>
                <?php endwhile; ?>

                <?php if ($role === 'student'): ?>
                <form method="post" class="mt-2">
                  <input type="hidden" name="forum_content_id" value="<?= $c['content_id'] ?>">
                  <textarea name="forum_body" class="w-full border p-2 rounded" rows="2" placeholder="Type your comment..." required></textarea>
                  <button type="submit" name="post_forum" class="mt-2 bg-green-500 text-white px-3 py-1 rounded">Post</button>
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
</div> </div>
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