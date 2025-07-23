<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$teacher_id = $conn->query("SELECT teacher_id FROM teachers WHERE user_id = $user_id")->fetch_assoc()['teacher_id'];

$courses = $conn->query("
    SELECT c.course_id, c.name 
    FROM courses c
    JOIN teacher_courses tc ON tc.course_id = c.course_id
    WHERE tc.teacher_id = $teacher_id
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = $_POST['course_id'];
    $quiz_title = $_POST['quiz_title'];
    $quiz_desc = $_POST['quiz_desc'];
    $due_date = $_POST['due_date'] ?? null;

    $stmt = $conn->prepare("INSERT INTO contents (course_id, type, title, body, position) VALUES (?, 'quiz', ?, ?, 1)");
    $stmt->bind_param("iss", $course_id, $quiz_title, $quiz_desc);
    $stmt->execute();
    $content_id = $conn->insert_id;

    $stmt2 = $conn->prepare("INSERT INTO assignments (lesson_id, title, description, due_date) VALUES (?, ?, ?, ?)");
    $stmt2->bind_param("isss", $content_id, $quiz_title, $quiz_desc, $due_date);
    $stmt2->execute();
    $assignment_id = $conn->insert_id;

    $count = count($_POST['question']);
    for ($i = 0; $i < $count; $i++) {
        $q = $_POST['question'][$i];
        $a = $_POST['a'][$i];
        $b = $_POST['b'][$i];
        $c = $_POST['c'][$i];
        $d = $_POST['d'][$i];
        $correct = $_POST['correct'][$i];

        $stmtQ = $conn->prepare("INSERT INTO assignment_questions 
            (assignment_id, question_text, option_a, option_b, option_c, option_d, correct_option)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtQ->bind_param("issssss", $assignment_id, $q, $a, $b, $c, $d, $correct);
        $stmtQ->execute();
    }

    $message = "✅ Quiz created with $count question(s).";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add New Quiz</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    function addQuestion() {
      const block = document.querySelector('.question-block').cloneNode(true);
      block.querySelectorAll('input, textarea, select').forEach(el => el.value = '');
      document.getElementById('questions').appendChild(block);
    }
  </script>
</head>
<body class="bg-gray-100 py-10 px-4">

  <div class="max-w-4xl mx-auto bg-white p-8 rounded shadow-md">
    <h2 class="text-3xl font-bold mb-6 text-blue-800">🧠 Add New Quiz</h2>
    <p class="mb-4"><a href="teacher_dashboard.php" class="text-blue-600 hover:underline">⬅ Back to Dashboard</a></p>

    <?php if (isset($message)): ?>
      <div class="bg-green-100 text-green-700 border border-green-300 px-4 py-2 rounded mb-6">
        <?= $message ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="mb-4">
        <label class="block font-semibold mb-1">Select Course:</label>
        <select name="course_id" required class="w-full border px-3 py-2 rounded">
          <?php while ($cr = $courses->fetch_assoc()): ?>
            <option value="<?= $cr['course_id'] ?>"><?= htmlspecialchars($cr['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="mb-4">
        <label class="block font-semibold mb-1">Quiz Title:</label>
        <input type="text" name="quiz_title" required class="w-full border px-3 py-2 rounded">
      </div>

      <div class="mb-4">
        <label class="block font-semibold mb-1">Short Description / Instructions:</label>
        <textarea name="quiz_desc" class="w-full border px-3 py-2 rounded" rows="3"></textarea>
      </div>

      <div class="mb-6">
        <label class="block font-semibold mb-1">Due Date:</label>
        <input type="date" name="due_date" class="w-full border px-3 py-2 rounded">
      </div>

      <!-- Questions Section -->
      <div id="questions">
        <div class="question-block border p-4 mb-6 rounded bg-gray-50">
          <label class="block font-semibold mb-1">Question:</label>
          <textarea name="question[]" rows="2" required class="w-full border px-3 py-2 rounded mb-3"></textarea>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label>Option A:</label>
              <input type="text" name="a[]" required class="w-full border px-3 py-2 rounded">
            </div>
            <div>
              <label>Option B:</label>
              <input type="text" name="b[]" required class="w-full border px-3 py-2 rounded">
            </div>
            <div>
              <label>Option C:</label>
              <input type="text" name="c[]" required class="w-full border px-3 py-2 rounded">
            </div>
            <div>
              <label>Option D:</label>
              <input type="text" name="d[]" required class="w-full border px-3 py-2 rounded">
            </div>
          </div>

          <div class="mt-4">
            <label class="block font-semibold mb-1">Correct Option:</label>
            <select name="correct[]" required class="w-full border px-3 py-2 rounded">
              <option value="A">A</option>
              <option value="B">B</option>
              <option value="C">C</option>
              <option value="D">D</option>
            </select>
          </div>
        </div>
      </div>

      <div class="mb-6">
        <button type="button" onclick="addQuestion()" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 transition">
          ➕ Add Another Question
        </button>
      </div>

      <button type="submit" class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800 transition">
        💾 Create Quiz
      </button>
    </form>
  </div>

</body>
</html>
