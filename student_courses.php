<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die("Access denied");
}

$user_id = $_SESSION['user_id'];

$result = $conn->query("
    SELECT c.course_id, c.name, ct.board, ct.level
    FROM enrollments e
    JOIN courses c ON e.course_id = c.course_id
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    WHERE e.user_id = $user_id AND e.status = 'active'
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Enrolled Courses</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen font-sans text-gray-800">

<?php include 'components/navbar.php'; ?>

<div class="flex flex-col lg:flex-row max-w-full mx-auto px-8 py-28 gap-8">

    <!-- Sidebar -->
    <?php include 'components/sidebar_student.php'; ?>

    <!-- Main Content -->
    <main class="w-full max-w-3x2 space-y-10">

        <div class="flex justify-between items-center">
            <h2 class="text-2xl font-bold text-blue-600">📚 My Enrolled Courses</h2>
            <a href="student_dashboard.php" class="text-blue-500 hover:underline">⬅ Back to Dashboard</a>
        </div>

        <?php if ($result->num_rows === 0): ?>
            <div class="bg-white p-6 rounded-lg shadow text-center">
                <p class="text-gray-700 text-lg">No courses enrolled yet.</p>
                <a href="enroll_course.php" class="mt-4 inline-block bg-blue-500 text-white px-5 py-2 rounded hover:bg-blue-600">
                    ➕ Enroll Here
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <?php while ($course = $result->fetch_assoc()): ?>
                    <div class="bg-white rounded-lg shadow hover:shadow-md transition p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-2"><?= htmlspecialchars($course['name']) ?></h3>
                        <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($course['board']) ?> - <?= htmlspecialchars($course['level']) ?></p>
                        <a href="course.php?course_id=<?= $course['course_id'] ?>"
                           class="inline-block bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition">
                            ▶ Go to Course
                        </a>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>

    </main>
</div>

<?php include 'components/footer.php'; ?>
</body>
</html>
