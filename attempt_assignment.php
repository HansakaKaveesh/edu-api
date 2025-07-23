<?php include 'db_connect.php'; session_start();
if ($_SESSION['role'] !== 'student') die("Only students.");

$student_id = $conn->query("SELECT student_id FROM students WHERE user_id = " . $_SESSION['user_id'])->fetch_assoc()['student_id'];

// List of available assignments
if (!isset($_GET['assignment_id'])) {
    $res = $conn->query("SELECT a.assignment_id, a.title FROM enrollments e
        JOIN contents l ON e.course_id = l.course_id
        JOIN assignments a ON a.lesson_id = l.content_id
        WHERE e.user_id = {$_SESSION['user_id']}");

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title>Available Assignments</title>
      <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
      <div class="bg-white/90 rounded-xl shadow-lg p-8 max-w-lg w-full">
        <h2 class="text-2xl font-bold mb-6 text-blue-700 flex items-center gap-2">📝 Available Assignments</h2>
        <ul class="space-y-3">';
    while ($row = $res->fetch_assoc()) {
        echo "<li>
                <a href='attempt_assignment.php?assignment_id={$row['assignment_id']}'
                   class='block px-4 py-2 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 font-semibold transition'>
                  {$row['title']}
                </a>
              </li>";
    }
    echo '</ul>
      </div>
    </body>
    </html>';
    exit;
}

// Attempt assignment
$assignment_id = $_GET['assignment_id'];

// Questions
$questions = $conn->query("SELECT * FROM assignment_questions WHERE assignment_id = $assignment_id");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $score = 0;
    $total = 0;

    $stmt = $conn->prepare("INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed) VALUES (?, ?, ?, ?)");
    $selected_options = $_POST['answers'];

    // Grade attempt
    foreach ($questions as $q) {
        $total++;
        $correct = ($selected_options[$q['question_id']] ?? '') === $q['correct_option'];
        if ($correct) $score++;

        $conn->query("INSERT INTO assignment_attempt_questions (attempt_id, question_id, selected_option, is_correct)
        VALUES (LAST_INSERT_ID(), {$q['question_id']}, '{$selected_options[$q['question_id']]}', $correct)");
    }
    $pass = $score >= 7;
    $stmt->bind_param("iiii", $student_id, $assignment_id, $score, $pass);
    $stmt->execute();

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title>Assignment Result</title>
      <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
      <div class="bg-white/90 rounded-xl shadow-lg p-8 max-w-md w-full text-center">
        <h2 class="text-2xl font-bold mb-4 text-green-700">✅ Assignment Finished</h2>
        <p class="text-lg mb-2">Score: <span class="font-semibold text-blue-700">' . $score . '</span> / <span class="font-semibold text-blue-700">' . $total . '</span></p>
        <a href="attempt_assignment.php" class="inline-block mt-4 px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">Back to Assignments</a>
      </div>
    </body>
    </html>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Answer Assignment</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white/90 rounded-xl shadow-lg p-8 max-w-lg w-full">
    <h2 class="text-2xl font-bold mb-6 text-blue-700 flex items-center gap-2">📝 Answer Assignment</h2>
    <form method="POST" class="space-y-8">
      <?php foreach ($questions as $i => $q): ?>
        <div class="mb-4">
          <p class="font-semibold mb-2 text-gray-800"><?= ($i+1) . ". " . htmlspecialchars($q['question_text']) ?></p>
          <div class="space-y-2">
            <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
              <label class="flex items-center space-x-2 cursor-pointer">
                <input type="radio" name="answers[<?= $q['question_id'] ?>]" value="<?= $opt ?>" required
                  class="form-radio text-blue-600 focus:ring-blue-500" />
                <span><?= $opt ?>) <?= htmlspecialchars($q["option_" . strtolower($opt)]) ?></span>
              </label>
            <?php endforeach ?>
          </div>
        </div>
      <?php endforeach ?>
      <button type="submit"
        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded shadow transition">
        Submit Assignment
      </button>
    </form>
  </div>
</body>
</html>