<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'student') {
    die("Unauthorized access");
}

$user_id = $_SESSION['user_id'];
$content_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$content_id) die("Invalid content ID");

// Fetch content
$stmt = $conn->prepare("SELECT c.*, cs.name AS course_name FROM contents c 
                        JOIN courses cs ON c.course_id = cs.course_id 
                        WHERE c.content_id = ?");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Content not found.");
}

$content = $result->fetch_assoc();
$course_id = $content['course_id'];
$content_type = $content['type'];

// Check enrollment
$checkEnroll = $conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
$checkEnroll->bind_param("ii", $user_id, $course_id);
$checkEnroll->execute();
$enrollRes = $checkEnroll->get_result();
if ($enrollRes->num_rows === 0) die("You are not enrolled in this course.");

// Log view if not already logged
$logged = $conn->prepare("SELECT 1 FROM activity_logs WHERE user_id = ? AND content_id = ? AND action = 'view'");
$logged->bind_param("ii", $user_id, $content_id);
$logged->execute();
$loggedRes = $logged->get_result();

if ($loggedRes->num_rows === 0) {
    $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, content_id, action) VALUES (?, ?, 'view')");
    $logStmt->bind_param("ii", $user_id, $content_id);
    $logStmt->execute();

    $student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'];
    $conn->query("
        INSERT INTO student_progress (student_id, course_id, chapters_completed)
        VALUES ($student_id, $course_id, 1)
        ON DUPLICATE KEY UPDATE chapters_completed = chapters_completed + 1
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($content['title']) ?> - View Content</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none; }</style>
</head>
<body class="bg-gray-50 text-gray-800 p-6">

<?php include 'components/navbar.php'; ?>

<div class="max-w-full mt-12 mx-auto bg-white p-6 rounded-lg shadow" x-data="{ showModal: false }">

    <div class="text-center mb-8">
        <h1 class="text-3xl font-extrabold text-blue-700 mb-2">📘 <?= htmlspecialchars($content['title']) ?></h1>
        <p class="text-base text-gray-600 mb-2">
            <span class="font-semibold text-gray-800">Course:</span> <?= htmlspecialchars($content['course_name']) ?> |
            <span class="font-semibold text-gray-800">Type:</span> <?= ucfirst($content_type) ?>
        </p>
        <a href="student_courses.php" class="inline-block mt-4 text-blue-600 font-medium hover:text-blue-800 hover:underline transition">
            ⬅ Back to My Courses
        </a>
    </div>

    <hr class="mb-4">

    <?php if ($content['body']): ?>
        <div class="bg-gray-100 p-4 rounded mb-6">
            <?= nl2br(htmlspecialchars($content['body'])) ?>
        </div>
    <?php endif; ?>

    <?php if ($content['file_url']): ?>
        <?php
            $ext = strtolower(pathinfo($content['file_url'], PATHINFO_EXTENSION));
            $isVideo = $content_type === 'video' || $ext === 'mp4';
            $isPdf = $content_type === 'pdf' || $ext === 'pdf';
        ?>
        <div class="mb-6">
            <button @click="showModal = true" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded shadow">
                📂 View Content
            </button>
        </div>

        <div 
            x-show="showModal" 
            x-cloak 
            class="fixed inset-0 z-50 bg-black bg-opacity-90 flex items-center justify-center"
        >
            <div class="bg-white max-w-7xl w-full mx-4 p-6 rounded shadow-lg relative">
                <button @click="showModal = false" class="absolute top-2 right-3 text-gray-600 hover:text-red-600 text-3xl">&times;</button>
                <h2 class="text-xl font-semibold mb-4"><?= htmlspecialchars($content['title']) ?></h2>

                <?php if ($isVideo): ?>
                    <video controls class="w-full max-h-[70vh] rounded">
                        <source src="<?= $content['file_url'] ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                <?php elseif ($isPdf): ?>
                    <iframe src="<?= $content['file_url'] ?>" class="w-full h-[75vh] rounded" frameborder="0"></iframe>
                <?php else: ?>
                    <iframe src="<?= $content['file_url'] ?>" class="w-full h-[75vh] rounded" frameborder="0"></iframe>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($content_type === 'lesson'): ?>
        <h2 class="text-xl font-semibold mb-2">📝 Linked Assignment</h2>
        <?php
        $assignment = $conn->query("SELECT * FROM assignments WHERE lesson_id = $content_id")->fetch_assoc();
        if ($assignment):
        ?>
            <div class="bg-yellow-100 p-4 rounded mb-6">
                <p><strong><?= htmlspecialchars($assignment['title']) ?></strong><br>
                <?= nl2br(htmlspecialchars($assignment['description'])) ?><br>
                Due: <?= $assignment['due_date'] ?><br>
                Total: <?= $assignment['total_marks'] ?> | Pass: <?= $assignment['passing_score'] ?></p>
                <a href="attempt_assignment.php?assignment_id=<?= $assignment['assignment_id'] ?>" class="text-blue-600 underline">▶ Attempt Assignment</a>
            </div>
        <?php else: ?>
            <p><em>No assignment linked.</em></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($content_type === 'quiz'): ?>
        <h2 class="text-xl font-semibold mt-8 mb-2">🧠 Quiz</h2>
        <?php
        $assignment_id = $conn->query("SELECT assignment_id FROM assignments WHERE lesson_id = $content_id")->fetch_assoc()['assignment_id'] ?? null;
        $assignment = $assignment_id ? $conn->query("SELECT * FROM assignments WHERE assignment_id = $assignment_id")->fetch_assoc() : null;
        $questions = $assignment_id ? $conn->query("SELECT * FROM assignment_questions WHERE assignment_id = $assignment_id") : [];

        if (isset($_POST['submit_quiz']) && $questions):
            $answers = $_POST['quiz'];
            $score = 0;
            $total = $questions->num_rows;
            $student_id = $conn->query("SELECT student_id FROM students WHERE user_id = $user_id")->fetch_assoc()['student_id'];

            $conn->query("INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed)
                          VALUES ($student_id, $assignment_id, 0, 0)");
            $attempt_id = $conn->insert_id;

            foreach ($questions as $q) {
                $selected = $answers[$q['question_id']] ?? '';
                $correct = $q['correct_option'];
                $is_correct = ($selected === $correct) ? 1 : 0;
                $score += $is_correct;

                $stmt = $conn->prepare("INSERT INTO assignment_attempt_questions (attempt_id, question_id, selected_option, is_correct)
                                        VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iisi", $attempt_id, $q['question_id'], $selected, $is_correct);
                $stmt->execute();
            }

            $pass = ($score >= $assignment['passing_score']) ? 1 : 0;
            $conn->query("UPDATE student_assignment_attempts SET score = $score, passed = $pass WHERE attempt_id = $attempt_id");

            echo "<p class='text-green-600 font-semibold'>✅ Quiz submitted. Score: $score / $total</p>";
        elseif ($questions && $questions->num_rows > 0):
        ?>
            <form method="post" class="space-y-4">
                <?php foreach ($questions as $q): ?>
                    <div>
                        <p class="font-medium"><?= htmlspecialchars($q['question_text']) ?></p>
                        <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                            <label class="block ml-4">
                                <input type="radio" name="quiz[<?= $q['question_id'] ?>]" value="<?= $opt ?>" required>
                                <?= $opt ?>) <?= htmlspecialchars($q['option_' . strtolower($opt)]) ?>
                            </label>
                        <?php endforeach ?>
                    </div>
                <?php endforeach ?>
                <button type="submit" name="submit_quiz" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">Submit Quiz</button>
            </form>
        <?php else: ?>
            <p>No quiz questions found.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($content_type === 'forum'): ?>
        <h2 class="text-xl font-semibold mt-8 mb-2">💬 Forum Discussion</h2>
        <?php
        $posts = $conn->query("SELECT p.post_id, p.body, u.username, p.posted_at FROM forum_posts p 
                               JOIN users u ON u.user_id = p.user_id 
                               WHERE p.content_id = $content_id AND p.parent_post_id IS NULL ORDER BY posted_at");
        while ($post = $posts->fetch_assoc()):
        ?>
            <div class="border border-gray-300 rounded p-4 mb-4">
                <strong><?= htmlspecialchars($post['username']) ?></strong> said:
                <p><?= nl2br(htmlspecialchars($post['body'])) ?></p>
                <small class="text-gray-500"><?= $post['posted_at'] ?></small>

                <?php
                $replies = $conn->query("SELECT r.body, u.username, r.posted_at FROM forum_posts r 
                                         JOIN users u ON u.user_id = r.user_id 
                                         WHERE r.parent_post_id = {$post['post_id']} ORDER BY r.posted_at");
                while ($reply = $replies->fetch_assoc()):
                ?>
                    <div class="ml-6 mt-2 p-2 border-l-2 border-gray-300">
                        <strong><?= htmlspecialchars($reply['username']) ?></strong> replied:
                        <p><?= nl2br(htmlspecialchars($reply['body'])) ?></p>
                        <small class="text-gray-500"><?= $reply['posted_at'] ?></small>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endwhile; ?>

        <form method="POST" class="mt-6">
            <textarea name="forum_body" class="w-full border p-2 rounded" rows="3" placeholder="Type your reply..." required></textarea>
            <button type="submit" name="post_forum" class="mt-2 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">Post</button>
        </form>

        <?php
        if (isset($_POST['post_forum'])) {
            $msg = $_POST['forum_body'];
            $stmt = $conn->prepare("INSERT INTO forum_posts (content_id, user_id, body) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $content_id, $user_id, $msg);
            $stmt->execute();
            echo "<script>location.reload();</script>";
        }
        ?>
    <?php endif; ?>

</div>
</body>
</html>
