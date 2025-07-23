<?php include 'db_connect.php'; session_start(); ?>

<h2>Add MCQ Questions to Assignment</h2>

<form method="POST">
    Assignment ID: <input type="number" name="assignment_id" required><br>
    Question: <textarea name="question_text" required></textarea><br>
    Option A: <input type="text" name="a"><br>
    Option B: <input type="text" name="b"><br>
    Option C: <input type="text" name="c"><br>
    Option D: <input type="text" name="d"><br>
    Correct Option: <select name="correct_option">
        <option>A</option><option>B</option><option>C</option><option>D</option>
    </select><br>
    <button type="submit">Add Question</button>
</form>

<?php
if ($_POST) {
    $stmt = $conn->prepare("INSERT INTO assignment_questions 
        (assignment_id, question_text, option_a, option_b, option_c, option_d, correct_option)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $_POST['assignment_id'], $_POST['question_text'], $_POST['a'], $_POST['b'], $_POST['c'], $_POST['d'], $_POST['correct_option']);
    $stmt->execute();

    echo "<p style='color:green;'>✅ Question added.</p>";
}
?>