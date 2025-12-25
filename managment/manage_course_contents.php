<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? null;
$allowedRoles = ['admin', 'ceo', 'accountant', 'coordinator'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    die("Access denied.");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Course id (from GET by default)
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

/* --------------------------------
   Handle POST actions: add/edit/delete content
   Schema used:

   CREATE TABLE contents (
     content_id INT PRIMARY KEY AUTO_INCREMENT,
     course_id INT NOT NULL,
     type ENUM('lesson','video','pdf','forum','quiz'),
     title VARCHAR(150),
     description TEXT,
     body TEXT,
     file_url VARCHAR(255),
     position INT,
     FOREIGN KEY (course_id) REFERENCES courses(course_id)
   );
-----------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $postedCid = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;

    // Always work with posted course_id on POST
    $course_id = $postedCid;

    if ($course_id <= 0) {
        header("Location: manage_courses.php?err=" . urlencode('Invalid course.'));
        exit;
    }

    // CSRF
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Invalid session. Please refresh and try again.'));
        exit;
    }

    $content_id  = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $body        = trim($_POST['body'] ?? '');
    $type        = trim($_POST['type'] ?? '');
    $file_url    = trim($_POST['file_url'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        $errors = [];
        if ($title === '') {
            $errors[] = 'Content title is required.';
        }
        if ($type === '') {
            $errors[] = 'Please select a content type.';
        }
        if ($file_url === '') {
            $errors[] = 'Content resource (URL or path) is required.';
        }

        if (!empty($errors)) {
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode(implode(' ', $errors)));
            exit;
        }
    }

    if ($action === 'add') {
        $stmt = $conn->prepare("
            INSERT INTO contents (course_id, type, title, description, body, file_url)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($stmt === false) {
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Failed to prepare insert statement.'));
            exit;
        }
        $stmt->bind_param("isssss", $course_id, $type, $title, $description, $body, $file_url);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_course_contents.php?course_id={$course_id}&msg=" . urlencode('Content created successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Failed to create content.'));
            exit;
        }
    }

    if ($action === 'edit' && $content_id > 0) {
        // Ensure the content belongs to this course
        $check = $conn->prepare("SELECT 1 FROM contents WHERE content_id = ? AND course_id = ? LIMIT 1");
        $check->bind_param("ii", $content_id, $course_id);
        $check->execute();
        $check->store_result();
        if ($check->num_rows === 0) {
            $check->close();
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Content not found for this course.'));
            exit;
        }
        $check->close();

        $stmt = $conn->prepare("
            UPDATE contents
               SET type = ?, title = ?, description = ?, body = ?, file_url = ?
             WHERE content_id = ? AND course_id = ?
        ");
        if ($stmt === false) {
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Failed to prepare update statement.'));
            exit;
        }
        $stmt->bind_param("sssssii", $type, $title, $description, $body, $file_url, $content_id, $course_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_course_contents.php?course_id={$course_id}&msg=" . urlencode('Content updated successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Failed to update content.'));
            exit;
        }
    }

    if ($action === 'delete' && $content_id > 0) {
        $stmt = $conn->prepare("DELETE FROM contents WHERE content_id = ? AND course_id = ? LIMIT 1");
        if ($stmt === false) {
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Failed to prepare delete statement.'));
            exit;
        }
        $stmt->bind_param("ii", $content_id, $course_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_course_contents.php?course_id={$course_id}&msg=" . urlencode("Content #{$content_id} deleted."));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_course_contents.php?course_id={$course_id}&err=" . urlencode('Failed to delete content.'));
            exit;
        }
    }

    header("Location: manage_course_contents.php?course_id={$course_id}");
    exit;
}

/* --------------------------------
   Ensure course id is valid (for GET)
-----------------------------------*/
if ($course_id <= 0) {
    header("Location: manage_courses.php?err=" . urlencode('Course not specified.'));
    exit;
}

// Load course details
$stmt = $conn->prepare("
    SELECT
      c.course_id,
      c.name,
      c.description,
      ct.board,
      ct.level
    FROM courses c
    LEFT JOIN course_types ct ON c.course_type_id = ct.course_type_id
    WHERE c.course_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$course) {
    header("Location: manage_courses.php?err=" . urlencode('Course not found.'));
    exit;
}

$courseName  = $course['name'] ?? '';
$courseBoard = $course['board'] ?? '';
$courseLevel = $course['level'] ?? '';
$courseDesc  = $course['description'] ?? '';

/* --------------------------------
   Fetch contents for this course
-----------------------------------*/
$contents = [];
$stmt = $conn->prepare("
    SELECT content_id, type, title, description, body, file_url
    FROM contents
    WHERE course_id = ?
    ORDER BY position ASC, content_id ASC
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $contents[] = [
        'content_id'  => (int)$row['content_id'],
        'type'        => $row['type'] ?? '',
        'title'       => $row['title'] ?? '',
        'description' => $row['description'] ?? '',
        'body'        => $row['body'] ?? '',
        'file_url'    => $row['file_url'] ?? '',
    ];
}
$stmt->close();

$contentCount = count($contents);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Manage Course Contents – <?= htmlspecialchars($courseName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body {
      font-family: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI",
                   Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      min-height: 100vh;
    }
    body::before {
      content:"";
      position:fixed;
      inset:0;
      background:
        radial-gradient(circle at 0% 0%, rgba(56,189,248,0.18) 0, transparent 55%),
        radial-gradient(circle at 100% 100%, rgba(129,140,248,0.22) 0, transparent 55%);
      pointer-events:none;
      z-index:-1;
    }
    .glass-card {
      background: linear-gradient(to bottom right, rgba(255,255,255,0.96), rgba(248,250,252,0.95));
      border: 1px solid rgba(226,232,240,0.9);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow: 0 18px 40px rgba(15,23,42,0.06);
    }
    .soft-card {
      background: linear-gradient(to bottom right, rgba(248,250,252,0.96), rgba(239,246,255,0.96));
      border: 1px solid rgba(222,231,255,0.9);
      box-shadow: 0 14px 30px rgba(15,23,42,0.05);
    }
    .line-clamp-2,
    .line-clamp-3 {
      display:-webkit-box;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .line-clamp-2 { -webkit-line-clamp:2; }
    .line-clamp-3 { -webkit-line-clamp:3; }
    th.sticky { position:sticky; top:0; z-index:10; backdrop-filter:blur(12px); }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>


<!-- Main Container -->
<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 flex flex-col lg:flex-row gap-8">
 
<!-- Sidebar --> 
<?php include 'components/sidebar_coordinator.php'; ?>

  <main class="w-full space-y-10 animate-fadeUp">
  <!-- Header -->
  <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-indigo-950 via-slate-900 to-sky-900 text-white shadow-2xl mb-6">
    <div class="absolute -left-24 -top-24 w-64 h-64 bg-indigo-500/40 rounded-full blur-3xl"></div>
    <div class="absolute -right-24 top-10 w-60 h-60 bg-sky-400/40 rounded-full blur-3xl"></div>

    <div class="relative z-10 px-5 py-6 sm:px-7 sm:py-7 flex flex-col gap-4">
      <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/25">
            <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
          </span>
          <span><?= ($role === 'coordinator' ? 'Coordinator' : 'Admin') ?> · Course Contents</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
          <span><?= htmlspecialchars(ucfirst($role)) ?> access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Contents for “<?= htmlspecialchars($courseName) ?>”
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            Manage learning materials, links, and resources for this course.
          </p>
          <p class="text-[11px] sm:text-xs text-sky-100/80">
            <?= htmlspecialchars($courseBoard ?: 'Board not set') ?>
            <?= $courseLevel ? ' • ' . htmlspecialchars($courseLevel) : '' ?>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a href="manage_courses.php"
             class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white/95 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 shadow-sm">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to courses
          </a>
          <button id="openContentModal"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-indigo-700 shadow-sm active:scale-[0.99]">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add content
          </button>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="mt-1">
          <div class="relative inline-flex items-start gap-2 rounded-xl px-3 py-2 bg-emerald-50/95 text-emerald-800 ring-1 ring-emerald-200 text-xs">
            <button type="button" onclick="this.parentElement.remove()"
                    class="absolute right-2 top-1.5 text-emerald-400 hover:text-emerald-700"
                    aria-label="Dismiss">
              <i data-lucide="x" class="w-3 h-3"></i>
            </button>
            <div class="flex items-center gap-1.5">
              <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
              <span><?= htmlspecialchars($msg) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="mt-1">
          <div class="relative inline-flex items-start gap-2 rounded-xl px-3 py-2 bg-rose-50/95 text-rose-800 ring-1 ring-rose-200 text-xs">
            <button type="button" onclick="this.parentElement.remove()"
                    class="absolute right-2 top-1.5 text-rose-400 hover:text-rose-700"
                    aria-label="Dismiss">
              <i data-lucide="x" class="w-3 h-3"></i>
            </button>
            <div class="flex items-center gap-1.5">
              <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
              <span><?= htmlspecialchars($err) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Grid: Sidebar + Main -->
  <div class="grid grid-cols-1 lg:grid-cols-14 gap-4">


    <!-- Main Column -->
    <section class="lg:col-span-9 space-y-4">
      <div class="soft-card rounded-2xl p-4 sm:p-5">
        <div class="flex items-center justify-between mb-3">
          <div>
            <h2 class="text-sm sm:text-base font-semibold text-slate-800 flex items-center gap-2">
              <i data-lucide="files" class="w-4 h-4 text-indigo-600"></i>
              Course contents
            </h2>
            <p class="text-[11px] sm:text-xs text-slate-500 mt-0.5">
              All learning materials attached to this course.
            </p>
          </div>
          <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 text-slate-700 text-[11px] px-2 py-0.5 border border-slate-200">
            <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
            <?= $contentCount ?>
          </span>
        </div>

        <?php if ($contentCount > 0): ?>
          <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
            <table class="min-w-full text-left text-[11px] sm:text-xs">
              <thead>
                <tr class="bg-slate-50/90 text-slate-600 uppercase tracking-wide">
                  <th class="sticky px-3 py-2 border-b border-slate-200">ID</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Title</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Type</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Resource</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($contents as $ct): ?>
                  <?php
                    $cid   = $ct['content_id'];
                    $title = $ct['title'];
                    $type  = $ct['type'];
                    $url   = $ct['file_url'];
                    $desc  = $ct['description'] ?: $ct['body'];
                  ?>
                  <tr class="hover:bg-slate-50">
                    <td class="px-3 py-2 border-b border-slate-100 text-slate-600">
                      <?= $cid ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <div class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($title) ?></div>
                      <div class="text-[10px] text-slate-500 line-clamp-2">
                        <?= htmlspecialchars($desc ?: 'No description.') ?>
                      </div>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 px-2 py-0.5 text-[10px] font-medium">
                        <i data-lucide="tag" class="w-3.5 h-3.5"></i>
                        <?= htmlspecialchars($type ?: '—') ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <?php if ($url): ?>
                        <a href="<?= htmlspecialchars($url) ?>" target="_blank"
                           class="inline-flex items-center gap-1 text-sky-700 hover:text-sky-900 truncate max-w-[180px]">
                          <i data-lucide="external-link" class="w-3.5 h-3.5 text-slate-400"></i>
                          <span class="truncate"><?= htmlspecialchars($url) ?></span>
                        </a>
                      <?php else: ?>
                        <span class="text-slate-400 text-[11px]">No resource set</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 text-right whitespace-nowrap">
                      <div class="flex flex-wrap justify-end gap-1.5">
                        <button type="button"
                                class="inline-flex items-center gap-1 text-indigo-700 hover:text-indigo-900 px-2 py-0.5 rounded-md ring-1 ring-indigo-200 bg-indigo-50 text-[10px] font-medium"
                                onclick='openEditContentModal(<?= json_encode([
                                  "content_id"  => $cid,
                                  "title"       => $title,
                                  "description" => $ct["description"],
                                  "body"        => $ct["body"],
                                  "type"        => $type,
                                  "file_url"    => $url,
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                          <i data-lucide="pen-square" class="w-3.5 h-3.5"></i> Edit
                        </button>

                        <form method="POST" class="inline"
                              onsubmit="return confirm('Delete this content item?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="course_id" value="<?= $course_id ?>">
                          <input type="hidden" name="content_id" value="<?= $cid ?>">
                          <button type="submit"
                                  class="inline-flex items-center gap-1 text-rose-700 hover:text-rose-900 px-2 py-0.5 rounded-md ring-1 ring-rose-200 bg-rose-50 text-[10px] font-medium">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Delete
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="flex items-center gap-3 text-slate-600 text-sm sm:text-base bg-white/95 border border-slate-200 rounded-2xl px-4 py-4">
            <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
              <i data-lucide="info" class="w-5 h-5"></i>
            </span>
            <div>
              <p class="font-medium">No contents have been added yet.</p>
              <p class="text-xs text-slate-500 mt-0.5">
                Use the “Add content” button above to attach learning materials to this course.
              </p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<!-- Add/Edit Content Modal -->
<div id="contentModal" class="fixed inset-0 z-[60] hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div id="contentOverlay" class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm"></div>

  <div class="absolute inset-0 flex items-start justify-center p-4 sm:p-6">
    <div class="w-full max-w-xl mt-16 sm:mt-24 rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 overflow-hidden">
      <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 bg-slate-50/70">
        <h3 id="contentModalTitle" class="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
          <i data-lucide="plus-circle" class="w-4 h-4 text-indigo-600"></i>
          <span>Add content</span>
        </h3>
        <button id="contentModalClose" class="text-slate-400 hover:text-slate-600 p-1 rounded-md hover:bg-slate-100"
                aria-label="Close content form">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <form method="POST" class="p-4 space-y-3" id="contentForm" aria-labelledby="contentModalTitle">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" id="contentAction" value="add">
        <input type="hidden" name="course_id" value="<?= $course_id ?>">
        <input type="hidden" name="content_id" id="contentIdField" value="">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Title</label>
            <input name="title" id="contentTitleField" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Short description
            </label>
            <textarea name="description" id="contentDescField" rows="2"
                      class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300 resize-y"></textarea>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Body (optional, full text)
            </label>
            <textarea name="body" id="contentBodyField" rows="3"
                      class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300 resize-y"></textarea>
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Content type
            </label>
            <select name="type" id="contentTypeField" required
                    class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">Select type</option>
              <option value="lesson">Lesson</option>
              <option value="video">Video</option>
              <option value="pdf">PDF / Document</option>
              <option value="forum">Forum</option>
              <option value="quiz">Quiz</option>
            </select>
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Resource (URL or path)
            </label>
            <input name="file_url" id="contentUrlField" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   placeholder="e.g., https://..., uploads/files/lesson1.pdf" />
          </div>
        </div>

        <div class="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 mt-1">
          <button type="button"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.75 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  id="contentCancelBtn">
            Cancel
          </button>
          <button type="submit"
                  class="inline-flex items-center gap-1 rounded-md bg-indigo-600 text-white px-3.5 py-1.75 text-xs font-semibold hover:bg-indigo-700 shadow-sm">
            <i data-lucide="save" class="w-3.5 h-3.5"></i>
            <span id="contentSubmitLabel">Create content</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
</main>
<script>
  if (window.lucide) {
    window.lucide.createIcons();
  }

  (function() {
    const modal        = document.getElementById('contentModal');
    const overlay      = document.getElementById('contentOverlay');
    const openBtn      = document.getElementById('openContentModal');
    const closeBtn     = document.getElementById('contentModalClose');
    const cancelBtn    = document.getElementById('contentCancelBtn');
    const titleEl      = document.getElementById('contentModalTitle');
    const actionField  = document.getElementById('contentAction');
    const idField      = document.getElementById('contentIdField');
    const titleField   = document.getElementById('contentTitleField');
    const descField    = document.getElementById('contentDescField');
    const bodyField    = document.getElementById('contentBodyField');
    const typeField    = document.getElementById('contentTypeField');
    const urlField     = document.getElementById('contentUrlField');
    const submitLabel  = document.getElementById('contentSubmitLabel');

    let prevFocus = null;

    function openModal() {
      prevFocus = document.activeElement;
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(() => titleField?.focus(), 20);
      document.addEventListener('keydown', onKeydown);
    }

    function closeModal() {
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeydown);
      prevFocus && prevFocus.focus && prevFocus.focus();
    }

    function onKeydown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal();
      }
    }

    function resetToAdd() {
      titleEl.querySelector('span').textContent = 'Add content';
      actionField.value = 'add';
      idField.value = '';
      titleField.value = '';
      descField.value = '';
      bodyField.value = '';
      typeField.value = '';
      urlField.value = '';
      submitLabel.textContent = 'Create content';
    }

    openBtn?.addEventListener('click', () => {
      resetToAdd();
      openModal();
    });

    overlay?.addEventListener('click', closeModal);
    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);

    window.openEditContentModal = function(data) {
      resetToAdd();
      if (!data) return;
      titleEl.querySelector('span').textContent = 'Edit content';
      actionField.value = 'edit';
      idField.value = data.content_id || '';
      titleField.value = data.title || '';
      descField.value = data.description || '';
      bodyField.value = data.body || '';
      typeField.value = data.type || '';
      urlField.value = data.file_url || '';
      submitLabel.textContent = 'Save changes';
      openModal();
    };
  })();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>