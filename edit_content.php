<?php
declare(strict_types=1);
session_start();
require 'db_connect.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'teacher') {
    header("Location: login.php");
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Flash (PRG)
$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType    = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Teacher lookup
$user_id = (int)$_SESSION['user_id'];
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

// Content ID
$content_id = filter_input(INPUT_GET, 'content_id', FILTER_VALIDATE_INT);
if (!$content_id) {
    http_response_code(400);
    die("Invalid content ID");
}

// Fetch content and verify ownership via join
$content = null;
if ($st = $conn->prepare("
    SELECT c.content_id, c.course_id, c.type, c.title, c.body, c.file_url, c.position
    FROM contents c
    JOIN teacher_courses tc ON tc.course_id = c.course_id
    WHERE c.content_id = ? AND tc.teacher_id = ?
    LIMIT 1
")) {
    $st->bind_param("ii", $content_id, $teacher_id);
    $st->execute();
    $res = $st->get_result();
    $content = $res->fetch_assoc();
    $st->close();
}
if (!$content) {
    http_response_code(403);
    die("You do not have permission to edit this content or it does not exist.");
}

// Form state defaults
$errors = [];
$posted_title   = $_POST['title']    ?? $content['title'];
$posted_position= $_POST['position'] ?? (string)$content['position'];
$posted_body    = $_POST['body']     ?? $content['body'];
$posted_fileurl = $_POST['file_url'] ?? $content['file_url'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    }

    // Validate
    $title    = trim($posted_title ?? '');
    $body     = trim($posted_body ?? '');
    $file_url = trim($posted_fileurl ?? '');
    $position = filter_var($posted_position, FILTER_VALIDATE_INT);

    if ($title === '') {
        $errors[] = "Title is required.";
    } elseif (mb_strlen($title) > 200) {
        $errors[] = "Title is too long (max 200 characters).";
    }
    if (!$position || $position < 1) {
        $errors[] = "Position must be a positive number.";
    }
    if ($body !== '' && mb_strlen($body) > 10000) {
        $errors[] = "Body is too long (max 10000 characters).";
    }
    if ($file_url !== '' && !preg_match('#^(https?://|/|uploads/)#i', $file_url) && !filter_var($file_url, FILTER_VALIDATE_URL)) {
        $errors[] = "File URL must be a valid URL or a site-relative path.";
    }

    if (empty($errors)) {
        // Secure update with ownership condition
        if ($st = $conn->prepare("
            UPDATE contents
            SET title = ?, position = ?, body = ?, file_url = ?
            WHERE content_id = ?
              AND course_id IN (SELECT course_id FROM teacher_courses WHERE teacher_id = ?)
        ")) {
            $st->bind_param("sissii", $title, $position, $body, $file_url, $content_id, $teacher_id);
            $ok = $st->execute();
            $st->close();

            if ($ok) {
                $_SESSION['flash_message'] = "✅ Content updated successfully.";
                $_SESSION['flash_type']    = 'success';
                header("Location: edit_content.php?content_id=" . urlencode((string)$content_id));
                exit;
            } else {
                $errors[] = "Database error while updating content.";
            }
        } else {
            $errors[] = "Failed to prepare update statement.";
        }
    }

    // If errors, fall through and redisplay with error messages
    $flashMessage = "❌ Please fix the errors below.";
    $flashType    = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Content</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @keyframes blob {
      0% { transform: translate(0,0) scale(1); }
      33% { transform: translate(16px, -22px) scale(1.04); }
      66% { transform: translate(-14px, 18px) scale(0.98); }
      100% { transform: translate(0,0) scale(1); }
    }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-cyan-50">
  <!-- Background blobs -->
  <div aria-hidden class="pointer-events-none absolute inset-0 overflow-hidden">
    <div class="absolute -top-24 -left-24 h-72 w-72 rounded-full bg-cyan-200/40 blur-3xl" style="animation: blob 12s infinite;"></div>
    <div class="absolute top-1/3 -right-24 h-80 w-80 rounded-full bg-blue-200/40 blur-3xl" style="animation: blob 14s infinite;"></div>
  </div>

  <div class="relative mx-auto w-full max-w-3xl px-4 py-10">
    <div class="mb-4">
      <a href="teacher_dashboard.php" class="inline-flex items-center gap-2 text-blue-700 hover:underline">
        <span>⬅</span> <span>Back to Dashboard</span>
      </a>
    </div>

    <div class="rounded-2xl bg-white/80 backdrop-blur-md shadow-2xl ring-1 ring-blue-100">
      <div class="flex items-center justify-between border-b px-6 py-5">
        <h2 class="text-2xl font-bold text-slate-800">✏️ Edit Content</h2>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700 ring-1 ring-inset ring-blue-200">
          ID: <?= e((string)$content['content_id']) ?> • Course: <?= e((string)$content['course_id']) ?>
        </span>
      </div>

      <div class="px-6 py-5">
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

        <form id="editForm" method="POST" autocomplete="off" class="space-y-6">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

          <!-- Title -->
          <div>
            <div class="flex items-center justify-between">
              <label for="title" class="block font-semibold text-slate-800">Title</label>
              <span class="text-xs text-slate-500"><span id="titleCount">0</span>/200</span>
            </div>
            <input
              type="text"
              id="title"
              name="title"
              maxlength="200"
              value="<?= e((string)$posted_title) ?>"
              required
              class="mt-1 w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="Enter a clear, descriptive title"
            >
          </div>

          <!-- Position -->
          <div>
            <label for="position" class="block font-semibold text-slate-800">Position</label>
            <input
              type="number"
              id="position"
              name="position"
              min="1"
              value="<?= e((string)$posted_position) ?>"
              required
              class="mt-1 w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
            >
            <p class="mt-1 text-xs text-slate-500">Tip: Lower numbers appear earlier in the course outline.</p>
          </div>

          <!-- Body -->
          <div>
            <div class="flex items-center justify-between">
              <label for="body" class="block font-semibold text-slate-800">Body</label>
              <span class="text-xs text-slate-500"><span id="bodyCount">0</span>/10000</span>
            </div>
            <textarea
              id="body"
              name="body"
              rows="6"
              maxlength="10000"
              class="mt-1 w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="Add the main content, notes, or instructions..."
            ><?= e((string)$posted_body) ?></textarea>
          </div>

          <!-- File URL -->
          <div>
            <label for="file_url" class="block font-semibold text-slate-800">File URL (optional)</label>
            <input
              type="text"
              id="file_url"
              name="file_url"
              value="<?= e((string)$posted_fileurl) ?>"
              class="mt-1 w-full rounded-lg border border-slate-300 px-4 py-2 shadow-sm outline-none focus:border-blue-400 focus:ring focus:ring-blue-200"
              placeholder="https://example.com/file.pdf or /uploads/file.pdf"
            >
            <?php if (!empty($posted_fileurl)): ?>
              <p class="mt-2 text-sm">
                Current file:
                <a href="<?= e((string)$posted_fileurl) ?>" target="_blank" class="text-blue-700 hover:underline">Open in new tab</a>
              </p>
            <?php endif; ?>
          </div>

          <div class="flex items-center gap-4 pt-2">
            <button
              type="submit"
              id="saveBtn"
              class="rounded-full bg-blue-600 px-6 py-2 font-semibold text-white shadow hover:bg-blue-700 transition"
            >
              💾 Save Changes
            </button>
            <a href="teacher_dashboard.php" class="text-blue-700 hover:underline font-medium">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // Disable double submit
    const form = document.getElementById('editForm');
    const saveBtn = document.getElementById('saveBtn');
    form?.addEventListener('submit', () => {
      saveBtn?.setAttribute('disabled', 'true');
      saveBtn?.classList.add('opacity-70', 'cursor-not-allowed');
    });

    // Char counters
    const titleInput = document.getElementById('title');
    const bodyInput  = document.getElementById('body');
    const titleCount = document.getElementById('titleCount');
    const bodyCount  = document.getElementById('bodyCount');

    function updateCounts() {
      if (titleInput && titleCount) titleCount.textContent = String(titleInput.value.length);
      if (bodyInput && bodyCount)   bodyCount.textContent  = String(bodyInput.value.length);
    }
    updateCounts();
    titleInput?.addEventListener('input', updateCounts);
    bodyInput?.addEventListener('input', updateCounts);
  </script>
</body>
</html>