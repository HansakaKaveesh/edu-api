<?php
declare(strict_types=1);
session_start();
require 'db_connect.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'teacher') {
    header("Location: login.php");
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Flash messages (PRG)
$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType    = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Fetch teacher_id
$user_id = (int)($_SESSION['user_id'] ?? 0);
$teacher_id = null;
if ($st = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?")) {
    $st->bind_param("i", $user_id);
    $st->execute();
    $st->bind_result($teacher_id);
    $st->fetch();
    $st->close();
}
if (!$teacher_id) {
    http_response_code(403);
    die("Access denied (no teacher record).");
}

// Allowed presets
$allowedBoards = ['Cambridge', 'Edexcel', 'Local', 'Other'];
$allowedLevels = ['O/L', 'A/L', 'IGCSE', 'Other'];

// Form state
$errors = [];
$posted_name        = trim($_POST['name'] ?? '');
$posted_description = trim($_POST['description'] ?? '');
$posted_board       = $_POST['board'] ?? '';
$posted_board_other = trim($_POST['board_other'] ?? '');
$posted_level       = $_POST['level'] ?? '';
$posted_level_other = trim($_POST['level_other'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    }

    // Validate name/description
    if ($posted_name === '') {
        $errors[] = "Course name is required.";
    } elseif (mb_strlen($posted_name) > 120) {
        $errors[] = "Course name is too long (max 120 characters).";
    }
    if ($posted_description === '') {
        $errors[] = "Description is required.";
    } elseif (mb_strlen($posted_description) > 2000) {
        $errors[] = "Description is too long (max 2000 characters).";
    }

    // Validate board
    if (!in_array($posted_board, $allowedBoards, true)) {
        $errors[] = "Invalid board selection.";
    }
    $boardValue = $posted_board === 'Other' ? $posted_board_other : $posted_board;
    $boardValue = trim($boardValue);
    if ($boardValue === '') {
        $errors[] = "Please specify a board.";
    } elseif (mb_strlen($boardValue) > 60) {
        $errors[] = "Board is too long (max 60 characters).";
    }

    // Validate level
    if (!in_array($posted_level, $allowedLevels, true)) {
        $errors[] = "Invalid level selection.";
    }
    $levelValue = $posted_level === 'Other' ? $posted_level_other : $posted_level;
    $levelValue = trim($levelValue);
    if ($levelValue === '') {
        $errors[] = "Please specify a level.";
    } elseif (mb_strlen($levelValue) > 60) {
        $errors[] = "Level is too long (max 60 characters).";
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Reuse existing course_types if available
            $course_type_id = null;
            if ($st = $conn->prepare("SELECT course_type_id FROM course_types WHERE board = ? AND level = ? LIMIT 1")) {
                $st->bind_param("ss", $boardValue, $levelValue);
                $st->execute();
                $st->bind_result($course_type_id);
                $st->fetch();
                $st->close();
            }
            if (!$course_type_id) {
                if ($st = $conn->prepare("INSERT INTO course_types (board, level) VALUES (?, ?)")) {
                    $st->bind_param("ss", $boardValue, $levelValue);
                    if (!$st->execute()) { throw new Exception("Failed to insert course type."); }
                    $course_type_id = $conn->insert_id;
                    $st->close();
                } else {
                    throw new Exception("Failed to prepare course type insert.");
                }
            }

            // Insert course
            $course_id = null;
            if ($st = $conn->prepare("INSERT INTO courses (name, description, course_type_id) VALUES (?, ?, ?)")) {
                $st->bind_param("ssi", $posted_name, $posted_description, $course_type_id);
                if (!$st->execute()) { throw new Exception("Failed to insert course."); }
                $course_id = $conn->insert_id;
                $st->close();
            } else {
                throw new Exception("Failed to prepare course insert.");
            }

            // Link teacher to course (avoid duplicates)
            $exists = 0;
            if ($st = $conn->prepare("SELECT 1 FROM teacher_courses WHERE teacher_id = ? AND course_id = ? LIMIT 1")) {
                $st->bind_param("ii", $teacher_id, $course_id);
                $st->execute();
                $st->store_result();
                $exists = $st->num_rows > 0 ? 1 : 0;
                $st->close();
            }
            if (!$exists) {
                if ($st = $conn->prepare("INSERT INTO teacher_courses (teacher_id, course_id) VALUES (?, ?)")) {
                    $st->bind_param("ii", $teacher_id, $course_id);
                    if (!$st->execute()) { throw new Exception("Failed to link teacher to course."); }
                    $st->close();
                } else {
                    throw new Exception("Failed to prepare teacher_course insert.");
                }
            }

            $conn->commit();
            $_SESSION['flash_message'] = "✅ Course “" . $posted_name . "” created and assigned.";
            $_SESSION['flash_type']    = "success";
            header("Location: " . basename(__FILE__));
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['flash_message'] = "❌ Please fix the errors below.";
        $_SESSION['flash_type']    = "error";
        // fall through to re-render with posted values and error list
        $flashMessage = $_SESSION['flash_message'];
        $flashType    = $_SESSION['flash_type'];
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create New Course</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @keyframes blob {
      0% { transform: translate(0,0) scale(1); }
      33% { transform: translate(18px,-22px) scale(1.04); }
      66% { transform: translate(-16px,18px) scale(0.98); }
      100% { transform: translate(0,0) scale(1); }
    }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-cyan-50 text-gray-800">
  <!-- Background blobs -->
  <div aria-hidden class="pointer-events-none absolute inset-0 overflow-hidden">
    <div class="absolute -top-24 -left-24 h-72 w-72 rounded-full bg-cyan-200/40 blur-3xl" style="animation: blob 12s infinite;"></div>
    <div class="absolute top-1/3 -right-24 h-80 w-80 rounded-full bg-blue-200/40 blur-3xl" style="animation: blob 14s infinite;"></div>
  </div>

  <!-- Overlay on submit -->
  <div id="overlay" class="fixed inset-0 z-50 hidden items-center justify-center bg-white/70 backdrop-blur-sm">
    <div class="flex flex-col items-center gap-3">
      <svg class="h-8 w-8 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4A4 4 0 008 12H4z"></path>
      </svg>
      <p class="text-sm text-gray-700">Creating course…</p>
    </div>
  </div>

  <main class="relative mx-auto w-full max-w-2xl px-4 py-12">
    <div class="rounded-2xl bg-white/80 backdrop-blur-md shadow-2xl ring-1 ring-blue-100">
      <div class="border-b px-6 py-5">
        <h2 class="text-2xl font-bold text-slate-800">➕ Create New Course</h2>
        <p class="mt-1 text-sm text-slate-600">Add a course name, description, and choose the board and level.</p>
      </div>

      <div class="px-6 py-6">
        <?php if ($flashMessage): ?>
          <div class="mb-5 rounded-lg border px-4 py-3 text-sm <?= $flashType === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
            <?= e($flashMessage) ?>
            <?php if (!empty($errors)): ?>
              <ul class="mt-2 list-disc pl-5">
                <?php foreach ($errors as $err): ?>
                  <li><?= e($err) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <form id="createForm" method="POST" class="space-y-6" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

          <!-- Course Name -->
          <div>
            <div class="flex items-center justify-between">
              <label class="block font-semibold">Course Name</label>
              <span class="text-xs text-slate-500"><span id="nameCount">0</span>/120</span>
            </div>
            <input
              id="nameInput"
              type="text"
              name="name"
              maxlength="120"
              required
              value="<?= e($posted_name) ?>"
              class="mt-1 w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="e.g., Physics for IGCSE"
            />
          </div>

          <!-- Description -->
          <div>
            <div class="flex items-center justify-between">
              <label class="block font-semibold">Description</label>
              <span class="text-xs text-slate-500"><span id="descCount">0</span>/2000</span>
            </div>
            <textarea
              id="descInput"
              name="description"
              rows="4"
              maxlength="2000"
              required
              class="mt-1 w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="Briefly describe the course content and goals."
            ><?= e($posted_description) ?></textarea>
          </div>

          <!-- Board -->
          <div>
            <label class="block font-semibold mb-1">Board</label>
            <select
              id="boardSelect"
              name="board"
              required
              class="w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
            >
              <?php foreach ($allowedBoards as $b): ?>
                <option value="<?= e($b) ?>" <?= ($posted_board === $b) ? 'selected' : '' ?>><?= e($b) ?></option>
              <?php endforeach; ?>
            </select>
            <input
              type="text"
              id="boardOther"
              name="board_other"
              maxlength="60"
              value="<?= e($posted_board_other) ?>"
              class="mt-2 hidden w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="Type the board name"
            />
          </div>

          <!-- Level -->
          <div>
            <label class="block font-semibold mb-1">Level</label>
            <select
              id="levelSelect"
              name="level"
              required
              class="w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
            >
              <?php foreach ($allowedLevels as $lv): ?>
                <option value="<?= e($lv) ?>" <?= ($posted_level === $lv) ? 'selected' : '' ?>><?= e($lv) ?></option>
              <?php endforeach; ?>
            </select>
            <input
              type="text"
              id="levelOther"
              name="level_other"
              maxlength="60"
              value="<?= e($posted_level_other) ?>"
              class="mt-2 hidden w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="Type the level"
            />
          </div>

          <div class="flex items-center gap-4 pt-2">
            <a href="teacher_dashboard.php" class="text-blue-700 hover:underline font-medium">⬅ Back to Dashboard</a>
            <button
              type="submit"
              id="submitBtn"
              class="ml-auto rounded-lg bg-blue-600 px-6 py-2 font-semibold text-white shadow hover:bg-blue-700 transition"
            >
              ➕ Create Course
            </button>
          </div>
        </form>
      </div>
    </div>
  </main>

  <script>
    // Counters
    const nameInput = document.getElementById('nameInput');
    const descInput = document.getElementById('descInput');
    const nameCount = document.getElementById('nameCount');
    const descCount = document.getElementById('descCount');
    const updateCounts = () => {
      if (nameInput) nameCount.textContent = String(nameInput.value.length);
      if (descInput) descCount.textContent = String(descInput.value.length);
    };
    updateCounts();
    nameInput?.addEventListener('input', updateCounts);
    descInput?.addEventListener('input', updateCounts);

    // Other toggles
    const boardSelect = document.getElementById('boardSelect');
    const boardOther  = document.getElementById('boardOther');
    const levelSelect = document.getElementById('levelSelect');
    const levelOther  = document.getElementById('levelOther');

    function toggleOther(selectEl, otherEl) {
      const isOther = selectEl?.value === 'Other';
      if (isOther) {
        otherEl?.classList.remove('hidden');
        otherEl?.setAttribute('required', 'true');
      } else {
        otherEl?.classList.add('hidden');
        otherEl?.removeAttribute('required');
        // Optionally clear
        // otherEl.value = '';
      }
    }
    boardSelect?.addEventListener('change', () => toggleOther(boardSelect, boardOther));
    levelSelect?.addEventListener('change', () => toggleOther(levelSelect, levelOther));
    // Initialize on load with posted values
    toggleOther(boardSelect, boardOther);
    toggleOther(levelSelect, levelOther);

    // Submit overlay + disable
    const form = document.getElementById('createForm');
    const submitBtn = document.getElementById('submitBtn');
    const overlay = document.getElementById('overlay');
    form?.addEventListener('submit', () => {
      overlay?.classList.remove('hidden');
      overlay?.classList.add('flex');
      submitBtn?.setAttribute('disabled', 'true');
      submitBtn?.classList.add('opacity-70', 'cursor-not-allowed');
    });
  </script>
</body>
</html>