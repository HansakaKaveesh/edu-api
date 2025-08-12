<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Fetch student_id safely
$student_id = null;
if ($stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_id = (int)($stmt->get_result()->fetch_assoc()['student_id'] ?? 0);
    $stmt->close();
}
if (!$student_id) {
    die("Student profile not found.");
}

$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

// List available assignments
if ($assignment_id <= 0) {
    $assignments = [];
    if ($stmt = $conn->prepare("
        SELECT DISTINCT a.assignment_id, COALESCE(a.title, CONCAT('Assignment #', a.assignment_id)) AS title
        FROM enrollments e
        JOIN contents l ON e.course_id = l.course_id
        JOIN assignments a ON a.lesson_id = l.content_id
        WHERE e.user_id = ? AND e.status = 'active'
        ORDER BY title ASC
    ")) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $assignments[] = $row;
        $stmt->close();
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title>Available Assignments</title>
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
      <div class="bg-white/90 rounded-xl shadow-lg p-8 w-full max-w-xl">
        <h2 class="text-2xl font-bold mb-6 text-blue-700 flex items-center gap-2">📝 Available Assignments</h2>
        <?php if (empty($assignments)): ?>
          <p class="text-gray-700">No assignments available right now.</p>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($assignments as $a): ?>
              <li>
                <a href="attempt_assignment.php?assignment_id=<?= (int)$a['assignment_id'] ?>"
                   class="block px-4 py-2 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 font-semibold transition">
                  <?= htmlspecialchars($a['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Validate assignment belongs to an active enrolled course
$assignment = null;
if ($stmt = $conn->prepare("
    SELECT a.assignment_id, COALESCE(a.title, CONCAT('Assignment #', a.assignment_id)) AS title
    FROM assignments a
    JOIN contents l ON a.lesson_id = l.content_id
    JOIN enrollments e ON e.course_id = l.course_id
    WHERE e.user_id = ? AND e.status = 'active' AND a.assignment_id = ?
    LIMIT 1
")) {
    $stmt->bind_param("ii", $user_id, $assignment_id);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$assignment) {
    http_response_code(403);
    die("Assignment not found or not accessible.");
}

// Fetch questions
$questions = [];
if ($stmt = $conn->prepare("
    SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_option
    FROM assignment_questions
    WHERE assignment_id = ?
    ORDER BY question_id ASC
")) {
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $questions[] = $row;
    $stmt->close();
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die("Invalid request.");
    }

    $answers = $_POST['answers'] ?? [];
    $allowed = ['A','B','C','D'];

    $score = 0;
    $total = count($questions);
    $details = [];

    foreach ($questions as $q) {
        $qid = (int)$q['question_id'];
        $sel = $answers[$qid] ?? null;
        $sel = $sel !== null ? strtoupper($sel) : null;
        if (!in_array($sel, $allowed, true)) $sel = null;

        $correct = strtoupper(trim($q['correct_option']));
        $is_correct = ($sel !== null && $sel === $correct) ? 1 : 0;
        if ($is_correct) $score++;

        $details[] = [
          'question_id' => $qid,
          'selected'    => $sel,
          'is_correct'  => $is_correct
        ];
    }

    // Pass threshold: 70% (adjust if needed)
    $requiredCorrect = $total > 0 ? (int)ceil(0.70 * $total) : 0;
    $passed = ($total > 0 && $score >= $requiredCorrect) ? 1 : 0;

    // Store attempt + details atomically
    $conn->begin_transaction();

    // Insert attempt
    $stmt = $conn->prepare("
        INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("iiii", $student_id, $assignment_id, $score, $passed);
    $stmt->execute();
    $attempt_id = $stmt->insert_id;
    $stmt->close();

    // Insert per-question details
    $stmt = $conn->prepare("
        INSERT INTO assignment_attempt_questions (attempt_id, question_id, selected_option, is_correct)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($details as $d) {
        $sel = $d['selected']; // can be null
        $qid = $d['question_id'];
        $isc = $d['is_correct'];
        $stmt->bind_param("iisi", $attempt_id, $qid, $sel, $isc);
        $stmt->execute();
    }
    $stmt->close();

    $conn->commit();

    // Result page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title>Assignment Result</title>
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
      <div class="bg-white/90 rounded-xl shadow-lg p-8 max-w-md w-full text-center">
        <h2 class="text-2xl font-bold mb-4 <?= $passed ? 'text-green-700' : 'text-rose-700' ?>">
          <?= $passed ? '✅ Assignment Finished — Passed' : '❌ Assignment Finished — Failed' ?>
        </h2>
        <p class="text-lg mb-2">
          Score: <span class="font-semibold text-blue-700"><?= $score ?></span>
          / <span class="font-semibold text-blue-700"><?= $total ?></span>
        </p>
        <p class="text-sm text-gray-600 mb-6">Required: <?= $requiredCorrect ?> correct</p>
        <div class="flex flex-col sm:flex-row gap-2 justify-center">
          <a href="attempt_assignment.php" class="px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">Back to Assignments</a>
          <a href="attempt_assignment.php?assignment_id=<?= $assignment_id ?>" class="px-5 py-2 border border-gray-200 bg-white rounded hover:bg-gray-50 transition">Retake</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Answer Assignment</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white/90 rounded-xl shadow-lg p-8 w-full max-w-2xl">
    <h2 class="text-2xl font-bold mb-6 text-blue-700 flex items-center gap-2">
      📝 <?= htmlspecialchars($assignment['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </h2>

    <?php if (empty($questions)): ?>
      <div class="text-gray-700">
        No questions available for this assignment.
      </div>
      <div class="mt-6">
        <a href="attempt_assignment.php" class="inline-block px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">Back to Assignments</a>
      </div>
    <?php else: ?>
      <form method="POST" action="attempt_assignment.php?assignment_id=<?= $assignment_id ?>" class="space-y-8">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <?php foreach ($questions as $i => $q): ?>
          <?php
            $qid = (int)$q['question_id'];
            $qtext = htmlspecialchars($q['question_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $opts = [
              'A' => htmlspecialchars($q['option_a'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
              'B' => htmlspecialchars($q['option_b'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
              'C' => htmlspecialchars($q['option_c'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
              'D' => htmlspecialchars($q['option_d'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ];
          ?>
          <div class="mb-4">
            <p class="font-semibold mb-2 text-gray-800"><?= ($i+1) . ". " . $qtext ?></p>
            <div class="space-y-2">
              <?php foreach ($opts as $key => $val): ?>
                <label class="flex items-start gap-2 cursor-pointer">
                  <input type="radio"
                         name="answers[<?= $qid ?>]"
                         value="<?= $key ?>"
                         <?= $key === 'A' ? 'required' : '' ?>
                         class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500" />
                  <span><?= $key ?>) <?= $val ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <button type="submit"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded shadow transition">
          Submit Assignment
        </button>
        <div class="mt-4 text-center">
          <a href="attempt_assignment.php" class="text-blue-700 hover:underline">← Back to Assignments</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>