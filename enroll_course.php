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

    if (isset($_POST['cancel'])) {
        $conn->query("DELETE FROM enrollments WHERE user_id = $user_id AND course_id = $course_id AND status = 'pending'");
    } elseif (isset($_POST['remove'])) {
        $conn->query("DELETE FROM enrollments WHERE user_id = $user_id AND course_id = $course_id");
    } else {
        $existing = $conn->query("SELECT * FROM enrollments WHERE user_id = $user_id AND course_id = $course_id");
        if ($existing->num_rows === 0) {
            $conn->query("INSERT INTO enrollments (user_id, course_id, status) VALUES ($user_id, $course_id, 'pending')");
            $enrollment_id = $conn->insert_id;
            header("Location: make_payment.php?course_id=$course_id&enrollment_id=$enrollment_id");
            exit;
        }
    }
}

$courses = $conn->query("SELECT * FROM courses");

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
      <link rel="icon" type="image/png" href="./images/logo.png" />
</head>
<body class="bg-gray-50 min-h-screen font-sans text-gray-800">

<?php include 'components/navbar.php'; ?>

<div class="flex flex-col lg:flex-row max-w-full mx-auto px-8 py-28 gap-8">

    <!-- Sidebar -->
    <?php include 'components/sidebar_student.php'; ?>

    <!-- Main Content -->
    <main class="w-full max-w-3x2 space-y-10">

        <div class="text-center">
            <h2 class="text-3xl font-bold text-gray-800 mb-2">📚 Enroll in a Course</h2>
            <a href="student_dashboard.php" class="text-blue-600 hover:text-blue-800 underline">⬅ Back to Dashboard</a>
        </div>

        <div class="overflow-x-auto bg-white rounded-xl shadow border border-gray-200">
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

    </main>
</div>

<?php include 'components/footer.php'; ?>
</body>
</html>
