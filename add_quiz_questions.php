<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'teacher') {
    die("Access denied.");
}

$quiz_id = isset($_GET['quiz_id']) ? intval($_GET['quiz_id']) : 0;

// Get the assignment_id linked to this quiz content
$quiz_stmt = $conn->prepare("SELECT a.assignment_id, c.title, c.course_id
                             FROM assignments a
                             JOIN contents c ON a.lesson_id = c.content_id
                             WHERE a.lesson_id = ?");
$quiz_stmt->bind_param("i", $quiz_id);
$quiz_stmt->execute();
$quiz_data = $quiz_stmt->get_result()->fetch_assoc();

if (!$quiz_data) {
    die("Invalid quiz ID. Make sure you linked it to an assignment.");
}

$assignment_id = $quiz_data['assignment_id'];
$course_id = $quiz_data['course_id'];
$quiz_title = $quiz_data['title'];
?>

<!DOCTYPE html>
<html>
<head><title>Add Quiz Questions</title></head>
<body>

<h2>🧠 Add Questions to Quiz: <?= htmlspecialchars($quiz_title) ?></h2>
<p><a href="teacher_dashboard.php">⬅ Back to Dashboard</a></p>

<!-- Question Form -->
<h3>➕ Add New Question</h3>
<form method="POST" action="">
    <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
    Question:<br>
    <textarea name="question_text" required rows="3" cols="60"></textarea><br><br>

    Option A: <input type="text" name="option_a" required><br>
    Option B: <input type="text" name="option_b" required><br>
    Option C: <input type="text" name="option_c" required><br>
    Option D: <input type="text" name="option_d" required><br>

    Correct Option:
    <select name="correct_option" required>
        <option value="A">A</option>
        <option value="B">B</option>
        <option value="C">C</option>
        <option value="D">D</option>
    </select><br><br>

    <button type="submit" name="add_question">➕ Add Question</button>
</form>

<?php
if (isset($_POST['add_question'])) {
    $stmt = $conn->prepare("
        INSERT INTO assignment_questions 
        (assignment_id, question_text, option_a, option_b, option_c, option_d, correct_option)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issssss",
        $_POST['assignment_id'],
        $_POST['question_text'],
        $_POST['option_a'],
        $_POST['option_b'],
        $_POST['option_c'],
        $_POST['option_d'],
        $_POST['correct_option']
    );
    if ($stmt->execute()) {
        echo "<p style='color:green;'>✅ Question added successfully.</p>";
    } else {
        echo "<p style='color:red;'>❌ Error: " . $stmt->error . "</p>";
    }
}
?>

<hr>

<!-- View existing questions -->
<h3>📋 Existing Questions</h3>
<table border="1" cellpadding="6">
    <tr>
        <th>#</th>
        <th>Question</th>
        <th>Options</th>
        <th>Correct</th>
    </tr>

<?php
$q_result = $conn->query("SELECT * FROM assignment_questions WHERE assignment_id = $assignment_id");
$counter = 1;
while ($q = $q_result->fetch_assoc()):
?>
    <tr>
        <td><?= $counter++ ?></td>
        <td><?= htmlspecialchars($q['question_text']) ?></td>
        <td>
            A) <?= htmlspecialchars($q['option_a']) ?><br>
            B) <?= htmlspecialchars($q['option_b']) ?><br>
            C) <?= htmlspecialchars($q['option_c']) ?><br>
            D) <?= htmlspecialchars($q['option_d']) ?>
        </td>
        <td><strong><?= $q['correct_option'] ?></strong></td>
    </tr>
<?php endwhile; ?>
</table>

</body>
</html>