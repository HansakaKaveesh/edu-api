<?php
declare(strict_types=1);
session_start();
require 'db_connect.php';

// Access control
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'teacher') {
    header("Location: login.php");
    exit;
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Fetch teacher_id
$user_id = (int)$_SESSION['user_id'];
$teacher_id = null;
if ($stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($teacher_id);
    $stmt->fetch();
    $stmt->close();
}
if (!$teacher_id) {
    http_response_code(403);
    die("Access denied (no teacher record).");
}

// Fetch teacher courses
$courses = [];
if ($stmt = $conn->prepare("
    SELECT c.course_id, c.name
    FROM courses c
    JOIN teacher_courses tc ON tc.course_id = c.course_id
    WHERE tc.teacher_id = ?
    ORDER BY c.name ASC
")) {
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $courses[] = $row;
    }
    $stmt->close();
}

$courseIdsForTeacher = array_map(fn($c) => (int)$c['course_id'], $courses);

// Form state
$message = '';
$messageType = 'success';
$errors = [];

$posted_course_id = $_POST['course_id'] ?? '';
$posted_quiz_title = $_POST['quiz_title'] ?? '';
$posted_quiz_desc = $_POST['quiz_desc'] ?? '';
$posted_due_date = $_POST['due_date'] ?? '';
$posted_questions = $_POST['question'] ?? [];
$posted_a = $_POST['a'] ?? [];
$posted_b = $_POST['b'] ?? [];
$posted_c = $_POST['c'] ?? [];
$posted_d = $_POST['d'] ?? [];
$posted_correct = $_POST['correct'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    }

    // Validate course_id belongs to teacher
    $course_id = filter_var($posted_course_id, FILTER_VALIDATE_INT);
    if (!$course_id) {
        $errors[] = "Invalid course selected.";
    } elseif (!in_array((int)$course_id, $courseIdsForTeacher, true)) {
        $errors[] = "You do not have access to the selected course.";
    }

    // Title / desc / due date
    $quiz_title = trim($posted_quiz_title);
    $quiz_desc  = trim($posted_quiz_desc);
    $due_date   = trim($posted_due_date);
    if ($quiz_title === '') {
        $errors[] = "Quiz title is required.";
    } elseif (mb_strlen($quiz_title) > 200) {
        $errors[] = "Quiz title is too long (max 200 characters).";
    }
    if ($quiz_desc !== '' && mb_strlen($quiz_desc) > 2000) {
        $errors[] = "Description is too long (max 2000 characters).";
    }
    if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        // If your DB expects DATETIME, adjust the regex accordingly or add a datetime-local input.
        $errors[] = "Due date must be a valid date (YYYY-MM-DD) or left blank.";
    }

    // Questions validation
    $countQ = max(count($posted_questions), count($posted_a), count($posted_b), count($posted_c), count($posted_d), count($posted_correct));
    if ($countQ < 1) {
        $errors[] = "Add at least one question.";
    }
    $cleanQuestions = [];
    for ($i = 0; $i < $countQ; $i++) {
        $q = trim($posted_questions[$i] ?? '');
        $a = trim($posted_a[$i] ?? '');
        $b = trim($posted_b[$i] ?? '');
        $c = trim($posted_c[$i] ?? '');
        $d = trim($posted_d[$i] ?? '');
        $correct = strtoupper(trim($posted_correct[$i] ?? ''));

        if ($q === '' || $a === '' || $b === '' || $c === '' || $d === '') {
            $errors[] = "Question " . ($i + 1) . ": all fields (question and options A–D) are required.";
            continue;
        }
        if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
            $errors[] = "Question " . ($i + 1) . ": correct option must be A, B, C, or D.";
            continue;
        }
        // Optional: length limits
        if (mb_strlen($q) > 1000) $errors[] = "Question " . ($i + 1) . ": too long (max 1000).";
        foreach (['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d] as $label => $opt) {
            if (mb_strlen($opt) > 500) {
                $errors[] = "Question " . ($i + 1) . " option $label: too long (max 500).";
            }
        }

        $cleanQuestions[] = [
            'q' => $q, 'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'correct' => $correct
        ];
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Determine next position in contents
            $position = 1;
            if ($st = $conn->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM contents WHERE course_id = ?")) {
                $st->bind_param("i", $course_id);
                $st->execute();
                $st->bind_result($position);
                $st->fetch();
                $st->close();
            }

            // Insert into contents (type=quiz)
            $content_id = null;
            if ($st = $conn->prepare("INSERT INTO contents (course_id, type, title, body, position) VALUES (?, 'quiz', ?, ?, ?)")) {
                $st->bind_param("issi", $course_id, $quiz_title, $quiz_desc, $position);
                if (!$st->execute()) { throw new Exception($st->error); }
                $content_id = $conn->insert_id;
                $st->close();
            } else {
                throw new Exception("Failed to prepare insert into contents.");
            }

            // Insert into assignments (lesson_id references contents.id)
            $assignment_id = null;
            if ($st = $conn->prepare("INSERT INTO assignments (lesson_id, title, description, due_date) VALUES (?, ?, ?, ?)")) {
                // If due_date is empty, send NULL
                $dueParam = $due_date !== '' ? $due_date : null;
                $st->bind_param("isss", $content_id, $quiz_title, $quiz_desc, $dueParam);
                if (!$st->execute()) { throw new Exception($st->error); }
                $assignment_id = $conn->insert_id;
                $st->close();
            } else {
                throw new Exception("Failed to prepare insert into assignments.");
            }

            // Insert questions
            if ($st = $conn->prepare("
                INSERT INTO assignment_questions
                (assignment_id, question_text, option_a, option_b, option_c, option_d, correct_option)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")) {
                foreach ($cleanQuestions as $row) {
                    $st->bind_param(
                        "issssss",
                        $assignment_id,
                        $row['q'],
                        $row['a'],
                        $row['b'],
                        $row['c'],
                        $row['d'],
                        $row['correct']
                    );
                    if (!$st->execute()) { throw new Exception($st->error); }
                }
                $st->close();
            } else {
                throw new Exception("Failed to prepare insert into assignment_questions.");
            }

            $conn->commit();
            $message = "✅ Quiz created with " . count($cleanQuestions) . " question(s).";
            $messageType = 'success';

            // Clear form after success
            $posted_course_id = (string)$course_id;
            $posted_quiz_title = '';
            $posted_quiz_desc = '';
            $posted_due_date = '';
            $posted_questions = [];
            $posted_a = $posted_b = $posted_c = $posted_d = $posted_correct = [];

        } catch (Throwable $e) {
            $conn->rollback();
            $message = "❌ Failed to create quiz. " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "❌ Please fix the errors below.";
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add New Quiz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @keyframes blob {
      0% { transform: translate(0,0) scale(1); }
      33% { transform: translate(20px,-25px) scale(1.05); }
      66% { transform: translate(-18px,20px) scale(0.98); }
      100% { transform: translate(0,0) scale(1); }
    }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-cyan-50">
  <!-- Decorative blobs -->
  <div aria-hidden class="pointer-events-none absolute inset-0 overflow-hidden">
    <div class="absolute -top-24 -left-24 h-72 w-72 rounded-full bg-cyan-200/40 blur-3xl" style="animation: blob 12s infinite;"></div>
    <div class="absolute top-1/3 -right-24 h-80 w-80 rounded-full bg-blue-200/40 blur-3xl" style="animation: blob 14s infinite;"></div>
  </div>

  <div class="relative mx-auto max-w-5xl px-4 py-10">
    <div class="mb-4">
      <a href="teacher_dashboard.php" class="inline-flex items-center gap-2 text-blue-700 hover:underline">
        <span>⬅</span> <span>Back to Dashboard</span>
      </a>
    </div>

    <div class="rounded-2xl bg-white/80 backdrop-blur-md shadow-xl ring-1 ring-blue-100">
      <div class="border-b px-6 py-5">
        <h2 class="text-2xl font-bold text-slate-800">🧠 Add New Quiz</h2>
        <p class="mt-1 text-sm text-slate-600">Create a quiz for one of your courses. Add questions, options, and select the correct answer.</p>
      </div>

      <div class="px-6 py-5">
        <?php if ($message): ?>
          <div class="mb-5 rounded-lg border px-4 py-3 text-sm <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
            <?= e($message) ?>
            <?php if (!empty($errors)): ?>
              <ul class="mt-2 list-disc pl-5">
                <?php foreach ($errors as $err): ?>
                  <li><?= e($err) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <form id="quizForm" method="POST" class="space-y-6">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

          <!-- Course -->
          <div>
            <label class="block font-semibold mb-1">Select Course:</label>
            <select name="course_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-blue-400 focus:ring focus:ring-blue-200">
              <?php if (empty($courses)): ?>
                <option value="">No courses found</option>
              <?php else: ?>
                <?php foreach ($courses as $cr): ?>
                  <option value="<?= (int)$cr['course_id'] ?>" <?= ((string)$cr['course_id'] === (string)$posted_course_id) ? 'selected' : '' ?>>
                    <?= e($cr['name']) ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>

          <!-- Title -->
          <div>
            <div class="flex items-center justify-between">
              <label class="block font-semibold">Quiz Title:</label>
              <span class="text-xs text-slate-500"><span id="titleCount">0</span>/200</span>
            </div>
            <input
              id="titleInput"
              type="text"
              name="quiz_title"
              maxlength="200"
              required
              value="<?= e($posted_quiz_title) ?>"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="e.g., Midterm Quiz — Chapters 1–3"
            />
          </div>

          <!-- Description -->
          <div>
            <div class="flex items-center justify-between">
              <label class="block font-semibold">Short Description / Instructions:</label>
              <span class="text-xs text-slate-500"><span id="descCount">0</span>/2000</span>
            </div>
            <textarea
              id="descInput"
              name="quiz_desc"
              rows="3"
              maxlength="2000"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="Optional: instructions, time limits, or allowed materials."
            ><?= e($posted_quiz_desc) ?></textarea>
          </div>

          <!-- Due Date -->
          <div class="max-w-sm">
            <label class="block font-semibold mb-1">Due Date (optional):</label>
            <input
              type="date"
              name="due_date"
              value="<?= e($posted_due_date) ?>"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-blue-400 focus:ring focus:ring-blue-200"
            />
          </div>

          <!-- Questions -->
          <div class="rounded-xl border border-slate-200 bg-white/70 p-4">
            <div class="mb-3 flex items-center justify-between">
              <h3 class="text-lg font-semibold text-slate-800">Questions</h3>
              <div class="text-sm text-slate-600">Total: <span id="qTotal">0</span></div>
            </div>

            <div id="questions" class="space-y-4">
              <?php
                // Re-render posted questions, or at least one empty block
                $qCount = max(
                  1,
                  count($posted_questions),
                  count($posted_a),
                  count($posted_b),
                  count($posted_c),
                  count($posted_d),
                  count($posted_correct)
                );
                for ($i = 0; $i < $qCount; $i++):
                  $qVal = $posted_questions[$i] ?? '';
                  $aVal = $posted_a[$i] ?? '';
                  $bVal = $posted_b[$i] ?? '';
                  $cVal = $posted_c[$i] ?? '';
                  $dVal = $posted_d[$i] ?? '';
                  $corr = strtoupper(trim($posted_correct[$i] ?? 'A'));
              ?>
              <div class="question-block rounded-lg border border-slate-200 bg-gray-50 p-4">
                <div class="mb-3 flex items-center justify-between">
                  <div class="font-semibold text-slate-800">Question <span class="question-index"><?= $i + 1 ?></span></div>
                  <button type="button" class="remove-question text-red-600 text-sm hover:underline">Remove</button>
                </div>

                <label class="block font-medium mb-1">Question:</label>
                <textarea name="question[]" rows="2" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm mb-3"><?= e($qVal) ?></textarea>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <div>
                    <label class="block mb-1">Option A:</label>
                    <input type="text" name="a[]" required value="<?= e($aVal) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
                  </div>
                  <div>
                    <label class="block mb-1">Option B:</label>
                    <input type="text" name="b[]" required value="<?= e($bVal) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
                  </div>
                  <div>
                    <label class="block mb-1">Option C:</label>
                    <input type="text" name="c[]" required value="<?= e($cVal) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
                  </div>
                  <div>
                    <label class="block mb-1">Option D:</label>
                    <input type="text" name="d[]" required value="<?= e($dVal) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
                  </div>
                </div>

                <div class="mt-3">
                  <label class="block font-medium mb-1">Correct Option:</label>
                  <select name="correct[]" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm">
                    <option value="A" <?= $corr === 'A' ? 'selected' : '' ?>>A</option>
                    <option value="B" <?= $corr === 'B' ? 'selected' : '' ?>>B</option>
                    <option value="C" <?= $corr === 'C' ? 'selected' : '' ?>>C</option>
                    <option value="D" <?= $corr === 'D' ? 'selected' : '' ?>>D</option>
                  </select>
                </div>
              </div>
              <?php endfor; ?>
            </div>

            <div class="mt-4">
              <button type="button" id="addQuestionBtn" class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-white shadow hover:bg-green-700 transition">
                <span>➕</span> <span>Add Another Question</span>
              </button>
            </div>
          </div>

          <div class="flex items-center justify-end gap-3">
            <button type="reset" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-slate-700 shadow-sm hover:bg-slate-50">Reset</button>
            <button type="submit" class="rounded-lg bg-blue-700 px-6 py-2 font-semibold text-white shadow hover:bg-blue-800">
              💾 Create Quiz
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Template for new question blocks -->
  <template id="questionTemplate">
    <div class="question-block rounded-lg border border-slate-200 bg-gray-50 p-4">
      <div class="mb-3 flex items-center justify-between">
        <div class="font-semibold text-slate-800">Question <span class="question-index"></span></div>
        <button type="button" class="remove-question text-red-600 text-sm hover:underline">Remove</button>
      </div>

      <label class="block font-medium mb-1">Question:</label>
      <textarea name="question[]" rows="2" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm mb-3"></textarea>

      <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div>
          <label class="block mb-1">Option A:</label>
          <input type="text" name="a[]" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
        </div>
        <div>
          <label class="block mb-1">Option B:</label>
          <input type="text" name="b[]" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
        </div>
        <div>
          <label class="block mb-1">Option C:</label>
          <input type="text" name="c[]" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
        </div>
        <div>
          <label class="block mb-1">Option D:</label>
          <input type="text" name="d[]" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm" />
        </div>
      </div>

      <div class="mt-3">
        <label class="block font-medium mb-1">Correct Option:</label>
        <select name="correct[]" required class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm">
          <option value="A" selected>A</option>
          <option value="B">B</option>
          <option value="C">C</option>
          <option value="D">D</option>
        </select>
      </div>
    </div>
  </template>

  <script>
    // Char counters
    const titleInput = document.getElementById('titleInput');
    const descInput = document.getElementById('descInput');
    const titleCount = document.getElementById('titleCount');
    const descCount = document.getElementById('descCount');
    const updateCounts = () => {
      if (titleInput && titleCount) titleCount.textContent = String(titleInput.value.length);
      if (descInput && descCount) descCount.textContent = String(descInput.value.length);
    };
    updateCounts();
    titleInput?.addEventListener('input', updateCounts);
    descInput?.addEventListener('input', updateCounts);

    // Questions dynamic handling
    const questionsWrap = document.getElementById('questions');
    const addBtn = document.getElementById('addQuestionBtn');
    const qTotal = document.getElementById('qTotal');
    const tpl = document.getElementById('questionTemplate');

    function renumberQuestions() {
      const blocks = questionsWrap.querySelectorAll('.question-block');
      blocks.forEach((block, idx) => {
        const idxSpan = block.querySelector('.question-index');
        if (idxSpan) idxSpan.textContent = String(idx + 1);
      });
      if (qTotal) qTotal.textContent = String(blocks.length);
      // Ensure at least one question always
      const removeButtons = questionsWrap.querySelectorAll('.remove-question');
      removeButtons.forEach(btn => {
        btn.disabled = (questionsWrap.children.length <= 1);
        btn.classList.toggle('opacity-50', btn.disabled);
        btn.classList.toggle('pointer-events-none', btn.disabled);
      });
    }

    function attachBlockEvents(block) {
      const removeBtn = block.querySelector('.remove-question');
      removeBtn?.addEventListener('click', () => {
        if (questionsWrap.children.length > 1) {
          block.remove();
          renumberQuestions();
        }
      });
    }

    // Attach on existing blocks
    questionsWrap.querySelectorAll('.question-block').forEach(attachBlockEvents);
    renumberQuestions();

    addBtn?.addEventListener('click', () => {
      const node = tpl.content.firstElementChild.cloneNode(true);
      // Clean inputs
      node.querySelectorAll('input, textarea, select').forEach(el => { el.value = ''; });
      // Set default correct option to A
      const sel = node.querySelector('select[name="correct[]"]');
      if (sel) sel.value = 'A';
      questionsWrap.appendChild(node);
      attachBlockEvents(node);
      renumberQuestions();
      // Focus the question textarea
      node.querySelector('textarea[name="question[]"]')?.focus();
    });
  </script>
</body>
</html>