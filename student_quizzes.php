<?php
session_start();
include 'db_connect.php';

// Must be logged in as a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get student_id
$student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")
                  ->fetch_assoc()['student_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>🧠 My Quizzes</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">

<div class="max-w-6xl mx-auto px-4 py-10">
    <h2 class="text-3xl font-bold mb-4">🧠 Quizzes for Your Courses</h2>
    <a href="student_dashboard.php" class="text-blue-600 hover:underline mb-6 inline-block">⬅ Back to Dashboard</a>

    <?php
    // Get enrolled courses
    $courses = $conn->query("
        SELECT c.course_id, c.name 
        FROM enrollments e
        JOIN courses c ON c.course_id = e.course_id
        WHERE e.user_id = $user_id AND e.status = 'active'
    ");

    if ($courses->num_rows === 0) {
        echo "<p class='text-red-500'>You are not enrolled in any courses.</p>";
    } else {
        while ($course = $courses->fetch_assoc()) {
            $course_id = $course['course_id'];
            $course_name = $course['name'];

            // Get quizzes for this course
            $quizzes = $conn->query("
                SELECT co.content_id, co.title, a.assignment_id
                FROM contents co
                JOIN assignments a ON a.lesson_id = co.content_id
                WHERE co.course_id = $course_id AND co.type = 'quiz'
            ");

            if ($quizzes->num_rows > 0) {
                echo "<h3 class='text-xl font-semibold mt-8 mb-2'>📘 {$course_name}</h3>";
                echo "<div class='overflow-x-auto'><table class='w-full table-auto border border-gray-200 shadow-sm rounded-lg bg-white'>
                        <thead class='bg-gray-100'>
                            <tr class='text-left'>
                                <th class='p-3 border'>Quiz Title</th>
                                <th class='p-3 border'>Status</th>
                                <th class='p-3 border'>Score</th>
                                <th class='p-3 border'>Result</th>
                                <th class='p-3 border'>Action</th>
                            </tr>
                        </thead>
                        <tbody>";

                while ($quiz = $quizzes->fetch_assoc()) {
                    $quiz_title = htmlspecialchars($quiz['title']);
                    $assignment_id = $quiz['assignment_id'];
                    $content_id = $quiz['content_id'];

                    // Check if student attempted
                    $attempt = $conn->query("
                        SELECT score, passed
                        FROM student_assignment_attempts
                        WHERE assignment_id = $assignment_id AND student_id = $student_id
                        ORDER BY attempted_at DESC
                        LIMIT 1
                    ")->fetch_assoc();

                    if ($attempt) {
                        $status = "<span class='text-green-600 font-medium'>✅ Completed</span>";
                        $score = "<span class='text-blue-700 font-semibold'>{$attempt['score']}</span>";
                        $result = $attempt['passed']
                            ? "<span class='text-green-600 font-bold'>Pass</span>"
                            : "<span class='text-red-600 font-bold'>Fail</span>";
                    } else {
                        $status = "<span class='text-gray-500'>❌ Not Attempted</span>";
                        $score = "-";
                        $result = "-";
                    }

                    echo "<tr class='hover:bg-gray-50'>
                            <td class='p-3 border'>{$quiz_title}</td>
                            <td class='p-3 border'>{$status}</td>
                            <td class='p-3 border'>{$score}</td>
                            <td class='p-3 border'>{$result}</td>
                            <td class='p-3 border'>
                                <a href='view_content.php?id=$content_id' target='_blank' class='text-blue-600 hover:underline'>Attempt / View</a>
                            </td>
                          </tr>";
                }

                echo "</tbody></table></div>";
            } else {
                echo "<h3 class='text-xl font-semibold mt-8 mb-2'>📘 {$course_name}</h3><p class='text-gray-500'><em>No quizzes added yet.</em></p>";
            }
        }
    }
    ?>
</div>

</body>
</html>
