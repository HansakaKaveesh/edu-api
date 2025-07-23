<?php session_start(); include 'db_connect.php';
if ($_SESSION['role'] !== 'teacher') die("Access denied.");

$teacher_id = $conn->query("SELECT teacher_id FROM teachers WHERE user_id = " . $_SESSION['user_id'])->fetch_assoc()['teacher_id'];

$courses = $conn->query("SELECT c.course_id, c.name FROM teacher_courses tc JOIN courses c ON tc.course_id = c.course_id WHERE tc.teacher_id = $teacher_id");
?>

<h2>Upload Assignment</h2>
<form method="POST">
    Course: 
    <select name="course_id" required>
        <?php while($row = $courses->fetch_assoc()) { echo "<option value='{$row['course_id']}'>{$row['name']}</option>"; } ?>
    </select><br>
    Title: <input type="text" name="title" required><br>
    Description: <textarea name="description"></textarea><br>
    Due Date: <input type="date" name="due_date" required><br>
    <button type="submit">Add Assignment</button>
</form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = $_POST['course_id'];
    $title = $_POST['title'];
    $desc = $_POST['description'];
    $due = $_POST['due_date'];

    // Find first lesson in this course to associate (simplification)
    $lesson = $conn->query("SELECT content_id FROM contents WHERE course_id = $course_id AND type = 'lesson' ORDER BY position ASC LIMIT 1")->fetch_assoc()['content_id'];

    $stmt = $conn->prepare("INSERT INTO assignments (lesson_id, title, description, due_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $lesson, $title, $desc, $due);
    $stmt->execute();
    echo "<p style='color:green;'>✅ Assignment uploaded.</p>";
}
?>