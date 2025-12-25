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

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

/*
Expected schema:

CREATE TABLE course_types (
  course_type_id INT AUTO_INCREMENT PRIMARY KEY,
  board VARCHAR(100) NOT NULL,
  level VARCHAR(100) NOT NULL
);

And courses.course_type_id references course_types.course_type_id.
*/

/* --------------------------------
   Handle POST actions: add/edit/delete
-----------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        header("Location: course_types.php?err=" . urlencode('Invalid session. Please refresh and try again.'));
        exit;
    }

    $course_type_id = isset($_POST['course_type_id']) ? (int)$_POST['course_type_id'] : 0;
    $board          = trim($_POST['board'] ?? '');
    $level          = trim($_POST['level'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        $errors = [];
        if ($board === '') {
            $errors[] = 'Board is required.';
        }
        if ($level === '') {
            $errors[] = 'Level is required.';
        }
        if (!empty($errors)) {
            header("Location: course_types.php?err=" . urlencode(implode(' ', $errors)));
            exit;
        }
    }

    if ($action === 'add') {
        $stmt = $conn->prepare("
            INSERT INTO course_types (board, level)
            VALUES (?, ?)
        ");
        if ($stmt === false) {
            header("Location: course_types.php?err=" . urlencode('Failed to prepare insert statement.'));
            exit;
        }
        $stmt->bind_param("ss", $board, $level);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: course_types.php?msg=" . urlencode('Course type created successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: course_types.php?err=" . urlencode('Failed to create course type.'));
            exit;
        }
    }

    if ($action === 'edit' && $course_type_id > 0) {
        $stmt = $conn->prepare("
            UPDATE course_types
               SET board = ?, level = ?
             WHERE course_type_id = ?
        ");
        if ($stmt === false) {
            header("Location: course_types.php?err=" . urlencode('Failed to prepare update statement.'));
            exit;
        }
        $stmt->bind_param("ssi", $board, $level, $course_type_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: course_types.php?msg=" . urlencode('Course type updated successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: course_types.php?err=" . urlencode('Failed to update course type.'));
            exit;
        }
    }

    if ($action === 'delete' && $course_type_id > 0) {
        // Prevent deletion if courses still use this type
        $chk = $conn->prepare("SELECT COUNT(*) AS c FROM courses WHERE course_type_id = ?");
        $chk->bind_param("i", $course_type_id);
        $chk->execute();
        $res = $chk->get_result();
        $row = $res->fetch_assoc();
        $courseCount = (int)($row['c'] ?? 0);
        $chk->close();

        if ($courseCount > 0) {
            header("Location: course_types.php?err=" . urlencode("Cannot delete type: {$courseCount} course(s) are still linked to it."));
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM course_types WHERE course_type_id = ? LIMIT 1");
        if ($stmt === false) {
            header("Location: course_types.php?err=" . urlencode('Failed to prepare delete statement.'));
            exit;
        }
        $stmt->bind_param("i", $course_type_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: course_types.php?msg=" . urlencode("Course type #{$course_type_id} deleted."));
            exit;
        } else {
            $stmt->close();
            header("Location: course_types.php?err=" . urlencode('Failed to delete course type.'));
            exit;
        }
    }

    header("Location: course_types.php");
    exit;
}

/* --------------------------------
   Fetch course types with usage count
-----------------------------------*/
$courseTypes = [];
$sql = "
  SELECT
    ct.course_type_id,
    ct.board,
    ct.level,
    COALESCE((
      SELECT COUNT(*) FROM courses c WHERE c.course_type_id = ct.course_type_id
    ), 0) AS course_count
  FROM course_types ct
  ORDER BY ct.board ASC, ct.level ASC
";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $courseTypes[] = [
            'course_type_id' => (int)$row['course_type_id'],
            'board'          => $row['board'] ?? '',
            'level'          => $row['level'] ?? '',
            'course_count'   => (int)$row['course_count'],
        ];
    }
    $res->free();
}

$typeCount = count($courseTypes);

// For filters
$boardsList = [];
$levelsList = [];
foreach ($courseTypes as $ct) {
    $b = trim($ct['board']);
    if ($b !== '' && !in_array($b, $boardsList, true)) {
        $boardsList[] = $b;
    }
    $l = trim($ct['level']);
    if ($l !== '' && !in_array($l, $levelsList, true)) {
        $levelsList[] = $l;
    }
}
sort($boardsList);
sort($levelsList);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Course Types</title>
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
        radial-gradient(circle at 0% 0%, rgba(56,189,248,0.16) 0, transparent 55%),
        radial-gradient(circle at 100% 100%, rgba(129,140,248,0.20) 0, transparent 55%);
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
    th.sticky { position:sticky; top:0; z-index:10; backdrop-filter:blur(12px); }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 flex flex-col lg:flex-row gap-8">
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
            <i data-lucide="grid" class="w-3.5 h-3.5"></i>
          </span>
          <span><?= ($role === 'coordinator' ? 'Coordinator' : 'Admin') ?> · Course Types</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
          <span><?= htmlspecialchars(ucfirst($role)) ?> access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Manage Course Types
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            Define boards and levels used when creating courses.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <?php if ($role === 'coordinator'): ?>
            <a href="managment/coordinator_dashboard.php"
               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white/95 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 shadow-sm">
              <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to dashboard
            </a>
          <?php else: ?>
            <a href="admin_dashboard.php"
               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white/95 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 shadow-sm">
              <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to dashboard
            </a>
          <?php endif; ?>
          <button id="openTypeModal"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-indigo-700 shadow-sm active:scale-[0.99]">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add type
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
    <section class="lg:col-span-9 space-y-3">
      <!-- Filters card -->
      <div class="rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200 p-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2.5 items-center">
          <div class="relative md:col-span-2">
            <input id="searchInput" type="text"
                   placeholder="Search by board or level..."
                   class="w-full pl-8 pr-2.5 py-2 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   aria-label="Search course types">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
          </div>
          <div class="flex gap-2">
            <select id="boardFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All boards</option>
              <?php foreach ($boardsList as $b): ?>
                <option value="<?= htmlspecialchars(strtolower($b), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($b) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select id="levelFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All levels</option>
              <?php foreach ($levelsList as $l): ?>
                <option value="<?= htmlspecialchars(strtolower($l), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($l) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <!-- Types table -->
      <div class="overflow-hidden rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200">
        <?php if ($typeCount > 0): ?>
          <div class="overflow-x-auto" id="typesTableWrapper">
            <table id="typesTable" class="min-w-full text-left border-collapse">
              <thead>
                <tr class="text-slate-700 text-[11px] sm:text-xs bg-slate-50/90 uppercase tracking-wide">
                  <th class="sticky px-3 py-2 border-b border-slate-200">ID</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Board</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Level</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Courses</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Actions</th>
                </tr>
              </thead>
              <tbody class="text-[11px] sm:text-xs">
                <?php foreach ($courseTypes as $ct):
                    $id     = $ct['course_type_id'];
                    $board  = $ct['board'];
                    $level  = $ct['level'];
                    $count  = $ct['course_count'];
                    $searchKey = strtolower(trim(($board ?? '') . ' ' . ($level ?? '')));
                ?>
                  <tr class="hover:bg-slate-50 even:bg-slate-50/40"
                      data-board="<?= htmlspecialchars(strtolower($board), ENT_QUOTES) ?>"
                      data-level="<?= htmlspecialchars(strtolower($level), ENT_QUOTES) ?>"
                      data-key="<?= htmlspecialchars($searchKey, ENT_QUOTES) ?>">
                    <td class="px-3 py-2 border-b border-slate-100 text-slate-600">
                      <?= $id ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="font-medium text-slate-900"><?= htmlspecialchars($board) ?></span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="font-medium text-slate-900"><?= htmlspecialchars($level) ?></span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="layers" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $count ?> course<?= $count === 1 ? '' : 's' ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <div class="flex flex-wrap gap-1.5">
                        <button type="button"
                                class="inline-flex items-center gap-1 text-indigo-700 hover:text-indigo-900 px-2 py-0.5 rounded-md ring-1 ring-indigo-200 bg-indigo-50 text-[10px] font-medium"
                                onclick='openEditTypeModal(<?= json_encode([
                                  "course_type_id" => $id,
                                  "board"          => $board,
                                  "level"          => $level,
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                          <i data-lucide="pen-square" class="w-3.5 h-3.5"></i> Edit
                        </button>

                        <?php if ($count === 0): ?>
                          <form method="POST" class="inline"
                                onsubmit="return confirm('Delete this course type? It is not used by any course.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="course_type_id" value="<?= $id ?>">
                            <button type="submit"
                                    class="inline-flex items-center gap-1 text-rose-700 hover:text-rose-900 px-2 py-0.5 rounded-md ring-1 ring-rose-200 bg-rose-50 text-[10px] font-medium">
                              <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Delete
                            </button>
                          </form>
                        <?php else: ?>
                          <span class="inline-flex items-center gap-1 text-[10px] text-slate-400 italic">
                            <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                            In use
                          </span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="p-6 text-sm text-slate-600">
            <div class="flex items-center gap-3">
              <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
                <i data-lucide="info" class="w-5 h-5"></i>
              </span>
              <div>
                <p class="font-medium">No course types found.</p>
                <p class="text-xs text-slate-500 mt-0.5">
                  Use the “Add type” button above to create your first course type.
                </p>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<!-- Add/Edit Type Modal -->
<div id="typeModal" class="fixed inset-0 z-[60] hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div id="typeOverlay" class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm"></div>

  <div class="absolute inset-0 flex items-start justify-center p-4 sm:p-6">
    <div class="w-full max-w-xl mt-16 sm:mt-24 rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 overflow-hidden">
      <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 bg-slate-50/70">
        <h3 id="typeModalTitle" class="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
          <i data-lucide="plus-circle" class="w-4 h-4 text-indigo-600"></i>
          <span>Add course type</span>
        </h3>
        <button id="typeModalClose" class="text-slate-400 hover:text-slate-600 p-1 rounded-md hover:bg-slate-100"
                aria-label="Close type form">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <form method="POST" class="p-4 space-y-3" id="typeForm" aria-labelledby="typeModalTitle">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" id="typeAction" value="add">
        <input type="hidden" name="course_type_id" id="typeIdField" value="">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Board</label>
            <input name="board" id="boardField" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   placeholder="e.g., Local, Edexcel, Cambridge" />
          </div>
          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Level</label>
            <input name="level" id="levelField" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   placeholder="e.g., Grade 10, A/L Physics" />
          </div>
        </div>

        <div class="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 mt-1">
          <button type="button"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.75 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  id="typeCancelBtn">
            Cancel
          </button>
          <button type="submit"
                  class="inline-flex items-center gap-1 rounded-md bg-indigo-600 text-white px-3.5 py-1.75 text-xs font-semibold hover:bg-indigo-700 shadow-sm">
            <i data-lucide="save" class="w-3.5 h-3.5"></i>
            <span id="typeSubmitLabel">Create type</span>
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

  // Filters
  const searchInput = document.getElementById('searchInput');
  const boardFilter = document.getElementById('boardFilter');
  const levelFilter = document.getElementById('levelFilter');
  const rows        = [...document.querySelectorAll('#typesTable tbody tr')];

  function applyFilters() {
    const q     = (searchInput?.value || '').toLowerCase().trim();
    const board = (boardFilter?.value || '').toLowerCase();
    const level = (levelFilter?.value || '').toLowerCase();

    rows.forEach(tr => {
      const key = (tr.getAttribute('data-key') || '').toLowerCase();
      const b   = (tr.getAttribute('data-board') || '').toLowerCase();
      const l   = (tr.getAttribute('data-level') || '').toLowerCase();

      const matchQ = !q || key.includes(q);
      const matchB = !board || b === board;
      const matchL = !level || l === level;

      const show = matchQ && matchB && matchL;
      tr.style.display = show ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  boardFilter?.addEventListener('change', applyFilters);
  levelFilter?.addEventListener('change', applyFilters);
  applyFilters();

  // Modal logic
  (function() {
    const modal       = document.getElementById('typeModal');
    const overlay     = document.getElementById('typeOverlay');
    const openBtn     = document.getElementById('openTypeModal');
    const closeBtn    = document.getElementById('typeModalClose');
    const cancelBtn   = document.getElementById('typeCancelBtn');
    const titleEl     = document.getElementById('typeModalTitle');
    const actionField = document.getElementById('typeAction');
    const idField     = document.getElementById('typeIdField');
    const boardField  = document.getElementById('boardField');
    const levelField  = document.getElementById('levelField');
    const submitLabel = document.getElementById('typeSubmitLabel');

    let prevFocus = null;

    function openModal() {
      prevFocus = document.activeElement;
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(() => boardField?.focus(), 20);
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
      titleEl.querySelector('span').textContent = 'Add course type';
      actionField.value = 'add';
      idField.value = '';
      boardField.value = '';
      levelField.value = '';
      submitLabel.textContent = 'Create type';
    }

    openBtn?.addEventListener('click', () => {
      resetToAdd();
      openModal();
    });

    overlay?.addEventListener('click', closeModal);
    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);

    window.openEditTypeModal = function(data) {
      resetToAdd();
      if (!data) return;
      titleEl.querySelector('span').textContent = 'Edit course type';
      actionField.value = 'edit';
      idField.value = data.course_type_id || '';
      boardField.value = data.board || '';
      levelField.value = data.level || '';
      submitLabel.textContent = 'Save changes';
      openModal();
    };
  })();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>