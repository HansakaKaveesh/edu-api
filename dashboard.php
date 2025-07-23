<?php include 'db_connect.php'; session_start(); if (!isset($_SESSION['user_id'])) header("Location: login.php"); ?>

<!DOCTYPE html>
<html>
<head><title>Dashboard</title></head>
<body>
<h2>Welcome to Your Dashboard (<?php echo $_SESSION['role']; ?>)</h2>
<p><a href="logout.php">Logout</a></p>

<?php
if ($_SESSION['role'] == 'student') {
    echo "<p><a href='enroll_course.php'>Enroll in Course</a></p>";
} elseif ($_SESSION['role'] == 'teacher') {
    echo "<p><a href='create_course.php'>Create Course</a></p>";
} else {
    echo "<p>Admin Panel</p>";
}
?>
</body>
</html>