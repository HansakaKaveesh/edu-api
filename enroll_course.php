<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die("Access denied.");
}

$user_id = $_SESSION['user_id'];
$student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $course_id = intval($_POST['course_id']);

    // Cancel pending enrollment
    if (isset($_POST['cancel'])) {
        $conn->query("DELETE FROM enrollments WHERE user_id = $user_id AND course_id = $course_id AND status = 'pending'");
    }
    // Remove any enrollment (active or pending)
    elseif (isset($_POST['remove'])) {
        $conn->query("DELETE FROM enrollments WHERE user_id = $user_id AND course_id = $course_id");
    }
    // Enroll in course
    else {
        $existing = $conn->query("SELECT * FROM enrollments WHERE user_id = $user_id AND course_id = $course_id");
        if ($existing->num_rows === 0) {
            $conn->query("INSERT INTO enrollments (user_id, course_id, status) VALUES ($user_id, $course_id, 'pending')");
            $enrollment_id = $conn->insert_id;
            header("Location: make_payment.php?course_id=$course_id&enrollment_id=$enrollment_id");
            exit;
        }
    }
}

// Get all courses
$courses = $conn->query("SELECT * FROM courses");

// Get user's enrollments
$enrollments = [];
$res = $conn->query("SELECT course_id, status FROM enrollments WHERE user_id = $user_id");
while ($row = $res->fetch_assoc()) {
    $enrollments[$row['course_id']] = $row['status'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enroll in Course</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen py-10 px-4">

    <div class="max-w-6xl mx-auto">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-6">📚 Enroll in a Course</h2>

        <div class="text-center mb-6">
            <a href="student_dashboard.php" class="text-blue-600 hover:text-blue-800 underline">⬅ Back to Dashboard</a>
        </div>

        <div class="overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full table-auto">
                <thead class="bg-blue-100 text-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Name</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Description</th>
                        <th class="px-4 py-3 text-center text-sm font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php while ($course = $courses->fetch_assoc()): 
                        $course_id = $course['course_id'];
                        $status = $enrollments[$course_id] ?? null;
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3"><?= htmlspecialchars($course['name']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($course['description']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($status === 'active' || $status === 'pending'): ?>
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="course_id" value="<?= $course_id ?>">
                                        <?php if ($status === 'pending'): ?>
                                            <button type="submit" name="cancel" class="bg-yellow-500 text-white px-3 py-2 rounded hover:bg-yellow-600 transition">
                                                Cancel
                                            </button>
                                        <?php endif; ?>
                                        <button type="submit" name="remove" class="bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600 transition ml-2">
                                            Remove
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="course_id" value="<?= $course_id ?>">
                                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition">
                                            Enroll & Pay
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>
