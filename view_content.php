<?php
session_start();
include 'db_connect.php';

// --- 1. ACCESS CONTROL ---
$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$content_id = (int)($_GET['content_id'] ?? 0);
$course_id  = (int)($_GET['course_id'] ?? 0);

if (!$user_id || !$role || !$content_id) {
    die("Unauthorized or Invalid Request");
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- 2. FETCH CONTENT & STUDENT INFO ---
$stmt = $conn->prepare("SELECT title, body, course_id, type FROM contents WHERE content_id = ?");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$content = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$content || $content['type'] !== 'quiz') {
    die("Content not found or is not a quiz.");
}

$student_id = 0;
if ($role === 'student') {
    $sStmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $sStmt->bind_param("i", $user_id);
    $sStmt->execute();
    $student_id = (int)($sStmt->get_result()->fetch_assoc()['student_id'] ?? 0);
    $sStmt->close();
}

// --- 3. FETCH ASSIGNMENT META ---
$assignStmt = $conn->prepare("SELECT assignment_id, passing_score FROM assignments WHERE lesson_id = ? LIMIT 1");
$assignStmt->bind_param("i", $content_id);
$assignStmt->execute();
$assignment = $assignStmt->get_result()->fetch_assoc();
$assignStmt->close();

// Fetch Questions (Master List)
$questions = [];
if ($assignment) {
    $qStmt = $conn->prepare("SELECT * FROM assignment_questions WHERE assignment_id = ? ORDER BY question_id ASC");
    $qStmt->bind_param("i", $assignment['assignment_id']);
    $qStmt->execute();
    $questions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $qStmt->close();
}

// --- 4. HANDLE SUBMISSION ---
if ($role === 'student' && isset($_POST['submit_quiz']) && $assignment) {
    if (empty($_SESSION['csrf_token']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
        die("Invalid Session");
    }

    $score = 0;
    $answers = $_POST['quiz'] ?? [];
    
    // 1. Create Attempt
    $ins = $conn->prepare("INSERT INTO student_assignment_attempts (student_id, assignment_id, score, passed, attempted_at) VALUES (?, ?, 0, 0, NOW())");
    $ins->bind_param("ii", $student_id, $assignment['assignment_id']);
    $ins->execute();
    $attempt_id = $conn->insert_id;
    $ins->close();

    // 2. Grade & Save Details
    $qMetaStmt = $conn->prepare("INSERT INTO assignment_attempt_questions (attempt_id, question_id, selected_option, is_correct) VALUES (?, ?, ?, ?)");
    
    foreach ($questions as $q) {
        $qid = $q['question_id'];
        $selected = $answers[$qid] ?? '';
        $is_correct = ($selected === $q['correct_option']) ? 1 : 0;
        if ($is_correct) $score++;
        
        $qMetaStmt->bind_param("iisi", $attempt_id, $qid, $selected, $is_correct);
        $qMetaStmt->execute();
    }
    $qMetaStmt->close();

    // 3. Update Final Score
    $passed = ($score >= $assignment['passing_score']) ? 1 : 0;
    $upd = $conn->prepare("UPDATE student_assignment_attempts SET score = ?, passed = ? WHERE attempt_id = ?");
    $upd->bind_param("iii", $score, $passed, $attempt_id);
    $upd->execute();
    $upd->close();

    // Redirect to View Result of THIS attempt
    header("Location: view_content.php?content_id=$content_id&course_id=$course_id&view_attempt=$attempt_id&submitted=1");
    exit;
}

// --- 5. LOGIC FOR REVIEW MODE ---
$review_mode = false;
$review_data = [];
$review_attempt_info = [];

if (isset($_GET['view_attempt']) && $role === 'student') {
    $view_id = (int)$_GET['view_attempt'];
    
    // Verify this attempt belongs to this student
    $chk = $conn->prepare("SELECT * FROM student_assignment_attempts WHERE attempt_id = ? AND student_id = ?");
    $chk->bind_param("ii", $view_id, $student_id);
    $chk->execute();
    $review_attempt_info = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($review_attempt_info) {
        $review_mode = true;
        // Fetch specific answers joined with question text
        $rStmt = $conn->prepare("
            SELECT aq.question_id, aq.selected_option, aq.is_correct, 
                   q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_option
            FROM assignment_attempt_questions aq
            JOIN assignment_questions q ON aq.question_id = q.question_id
            WHERE aq.attempt_id = ?
        ");
        $rStmt->bind_param("i", $view_id);
        $rStmt->execute();
        $review_data = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rStmt->close();
    }
}

// --- 6. FETCH HISTORY LIST ---
$attempts_arr = [];
if ($role === 'student' && $assignment) {
    $hist = $conn->prepare("SELECT * FROM student_assignment_attempts WHERE student_id = ? AND assignment_id = ? ORDER BY attempted_at DESC");
    $hist->bind_param("ii", $student_id, $assignment['assignment_id']);
    $hist->execute();
    $attempts_arr = $hist->get_result()->fetch_all(MYSQLI_ASSOC);
    $hist->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= h($content['title']) ?> - Quiz</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <?php include 'components/navbar.php'; ?>

    <main class="max-w-7xl mx-auto px-4 py-10 pt-24">
        
        <a href="student_quizzes.php" class="inline-flex items-center gap-2 text-gray-500 hover:text-blue-600 mb-6 transition">
            <ion-icon name="arrow-back-outline"></ion-icon> Back to quizzes
        </a>

        <?php if (isset($_GET['submitted'])): ?>
            <div class="mb-6 bg-emerald-100 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-lg flex items-center gap-2">
                <ion-icon name="checkmark-circle"></ion-icon> 
                Quiz submitted! Review your results below.
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900"><?= h($content['title']) ?></h1>
                    <?php if (!empty($content['body'])): ?>
                        <div class="mt-2 text-gray-600 prose"><?= nl2br(h($content['body'])) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($assignment): ?>
                <div class="text-right hidden sm:block">
                    <div class="text-sm text-gray-500">Passing Score</div>
                    <div class="text-2xl font-bold text-blue-600"><?= (int)$assignment['passing_score'] ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$assignment): ?>
            <div class="bg-yellow-50 text-yellow-800 p-4 rounded-lg">No questions assigned yet.</div>
        <?php else: ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- LEFT COLUMN: Content (Either Form or Review) -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- *** REVIEW MODE *** -->
                    <?php if ($review_mode): ?>
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold text-gray-800">Result Breakdown</h2>
                            <a href="view_content.php?content_id=<?=$content_id?>&course_id=<?=$course_id?>" 
                               class="text-sm bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1.5 rounded-lg transition">
                               Take Quiz Again
                            </a>
                        </div>

                        <!-- Score Summary Card -->
                        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                            <div>
                                <div class="text-gray-500 text-sm">Attempt Date</div>
                                <div class="font-medium"><?= date('F j, Y, g:i a', strtotime($review_attempt_info['attempted_at'])) ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-3xl font-bold <?= $review_attempt_info['passed'] ? 'text-emerald-600' : 'text-rose-600' ?>">
                                    <?= $review_attempt_info['score'] ?> / <?= count($review_data) ?>
                                </div>
                                <div class="text-sm font-bold uppercase tracking-wide <?= $review_attempt_info['passed'] ? 'text-emerald-600' : 'text-rose-600' ?>">
                                    <?= $review_attempt_info['passed'] ? 'Passed' : 'Failed' ?>
                                </div>
                            </div>
                        </div>

                        <!-- Question Analysis -->
                        <?php foreach ($review_data as $index => $r): 
                            $user_ans = $r['selected_option'];
                            $correct_ans = $r['correct_option'];
                            $is_right = ($user_ans === $correct_ans);
                            
                            // Card styling based on correctness
                            $card_border = $is_right ? 'border-emerald-200 bg-emerald-50/30' : 'border-rose-200 bg-rose-50/30';
                            $icon = $is_right ? '<ion-icon name="checkmark-circle" class="text-emerald-500 text-xl"></ion-icon>' : '<ion-icon name="close-circle" class="text-rose-500 text-xl"></ion-icon>';
                        ?>
                            <div class="bg-white rounded-xl shadow-sm border <?= $card_border ?> p-5 transition hover:shadow-md">
                                <div class="flex gap-3 mb-4">
                                    <div class="mt-0.5"><?= $icon ?></div>
                                    <div>
                                        <span class="text-xs font-bold text-gray-400 uppercase">Question <?= $index + 1 ?></span>
                                        <h3 class="text-lg font-medium text-gray-900"><?= h($r['question_text']) ?></h3>
                                    </div>
                                </div>
                                
                                <div class="ml-8 space-y-2 text-sm">
                                    <?php foreach (['A','B','C','D'] as $opt): 
                                        $opt_text = $r['option_'.strtolower($opt)];
                                        
                                        // Determine CSS for this option
                                        $opt_class = "border-gray-100 bg-white text-gray-600"; // default
                                        
                                        if ($opt === $correct_ans) {
                                            // Always highlight correct answer Green
                                            $opt_class = "border-emerald-300 bg-emerald-100 text-emerald-800 font-medium";
                                        } elseif ($opt === $user_ans && !$is_right) {
                                            // Highlight wrong user selection Red
                                            $opt_class = "border-rose-300 bg-rose-100 text-rose-800 font-medium";
                                        } elseif ($opt === $user_ans && $is_right) {
                                             // User selected correct (already covered by first if, but explicit here)
                                            $opt_class = "border-emerald-300 bg-emerald-100 text-emerald-800 font-medium";
                                        }
                                    ?>
                                        <div class="flex items-center justify-between p-3 rounded-lg border <?= $opt_class ?>">
                                            <span class="flex items-center gap-2">
                                                <span class="font-bold opacity-60"><?= $opt ?>.</span> 
                                                <?= h($opt_text) ?>
                                            </span>
                                            
                                            <?php if($opt === $user_ans): ?>
                                                <span class="text-xs font-bold px-2 py-1 rounded bg-white/50 border border-black/10">Your Answer</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                    <!-- *** TAKE QUIZ MODE *** -->
                    <?php else: ?>
                        
                        <?php if ($role === 'student'): ?>
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            
                            <?php foreach ($questions as $index => $q): ?>
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                                    <div class="flex gap-3 mb-3">
                                        <span class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-blue-50 text-blue-700 font-bold text-sm">
                                            <?= $index + 1 ?>
                                        </span>
                                        <h3 class="text-lg font-medium text-gray-900 pt-0.5"><?= h($q['question_text']) ?></h3>
                                    </div>
                                    
                                    <div class="ml-11 space-y-2">
                                        <?php foreach (['A','B','C','D'] as $opt): ?>
                                            <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 hover:bg-blue-50 hover:border-blue-200 cursor-pointer transition">
                                                <input type="radio" name="quiz[<?= $q['question_id'] ?>]" value="<?= $opt ?>" required class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                                <span class="text-gray-700">
                                                    <span class="font-bold text-gray-400 mr-1"><?= $opt ?>.</span> 
                                                    <?= h($q['option_'.strtolower($opt)]) ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="flex justify-end pt-4">
                                <button type="submit" name="submit_quiz" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-xl font-bold shadow-lg shadow-blue-200 transition transform hover:-translate-y-1">
                                    Submit Answers <ion-icon name="paper-plane-outline"></ion-icon>
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                            <!-- Teacher View (Preview) -->
                            <div class="bg-indigo-50 border border-indigo-100 p-4 rounded-xl">
                                <h3 class="font-bold text-indigo-900 mb-2">Teacher Preview Mode</h3>
                                <!-- (Teacher preview code...) -->
                            </div>
                        <?php endif; ?>

                    <?php endif; // End Review/Take Check ?>
                </div>

                <!-- RIGHT COLUMN: History Sidebar -->
                <div class="lg:col-span-1 space-y-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 sticky top-24">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-bold text-gray-900 flex items-center gap-2">
                                <ion-icon name="time-outline" class="text-blue-500"></ion-icon> History
                            </h3>
                            <?php if($review_mode): ?>
                                <a href="view_content.php?content_id=<?=$content_id?>&course_id=<?=$course_id?>" class="text-xs text-blue-600 hover:underline">Take New</a>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (empty($attempts_arr)): ?>
                            <p class="text-sm text-gray-500 italic">No attempts yet.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($attempts_arr as $att): 
                                    $isPass = $att['passed'];
                                    $activeClass = (isset($_GET['view_attempt']) && $_GET['view_attempt'] == $att['attempt_id']) ? 'ring-2 ring-blue-500 ring-offset-1' : '';
                                ?>
                                    <div class="border rounded-lg p-3 bg-white hover:bg-gray-50 transition <?= $activeClass ?>">
                                        <div class="flex justify-between items-start mb-2">
                                            <div>
                                                <div class="text-xs text-gray-400"><?= date('M d, Y', strtotime($att['attempted_at'])) ?></div>
                                                <div class="font-bold text-gray-800">Score: <?= $att['score'] ?></div>
                                            </div>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border <?= $isPass ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-rose-600 bg-rose-50 border-rose-100' ?>">
                                                <?= $isPass ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                        
                                        <a href="view_content.php?content_id=<?=$content_id?>&course_id=<?=$course_id?>&view_attempt=<?=$att['attempt_id']?>" 
                                           class="block w-full text-center text-xs font-semibold py-1.5 rounded bg-gray-100 text-gray-600 hover:bg-blue-100 hover:text-blue-700 transition">
                                            Review Details
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        <?php endif; ?>

    </main>
</body>
</html>