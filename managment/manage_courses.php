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

$msg  = $_GET['msg'] ?? '';
$err  = $_GET['err'] ?? '';

// Check if enrollments table exists (for safe cascade/delete and metrics)
$hasEnrollmentsRes = $conn->query("SHOW TABLES LIKE 'enrollments'");
$hasEnrollments    = $hasEnrollmentsRes && $hasEnrollmentsRes->num_rows > 0;
if ($hasEnrollmentsRes) $hasEnrollmentsRes->free();

/* --------------------------------
   Handle POST actions: add/edit/delete
-----------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // CSRF
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        header("Location: manage_courses.php?err=" . urlencode('Invalid session. Please refresh and try again.'));
        exit;
    }

    // Basic fields (for add/edit)
    $course_id      = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
    $name           = trim($_POST['name'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $cover_image    = trim($_POST['cover_image'] ?? '');
    $course_type_id = isset($_POST['course_type_id']) ? (int)$_POST['course_type_id'] : 0;
    $priceRaw       = trim($_POST['price'] ?? '');
    $price          = null;

    if ($action === 'add' || $action === 'edit') {
        $errors = [];
        if ($name === '') {
            $errors[] = 'Course name is required.';
        }
        if ($course_type_id <= 0) {
            $errors[] = 'Please select a course type.';
        }
        if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
            $errors[] = 'Please enter a valid non-negative price.';
        } else {
            $price = (float)$priceRaw;
        }

        if (!empty($errors)) {
            header("Location: manage_courses.php?err=" . urlencode(implode(' ', $errors)));
            exit;
        }
    }

    if ($action === 'add') {
        $stmt = $conn->prepare("
            INSERT INTO courses (name, description, price, cover_image, course_type_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt === false) {
            header("Location: manage_courses.php?err=" . urlencode('Failed to prepare insert statement.'));
            exit;
        }
        $stmt->bind_param("ssdsi", $name, $description, $price, $cover_image, $course_type_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_courses.php?msg=" . urlencode('Course created successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_courses.php?err=" . urlencode('Failed to create course.'));
            exit;
        }
    }

    if ($action === 'edit' && $course_id > 0) {
        $stmt = $conn->prepare("
            UPDATE courses
               SET name = ?, description = ?, price = ?, cover_image = ?, course_type_id = ?
             WHERE course_id = ?
        ");
        if ($stmt === false) {
            header("Location: manage_courses.php?err=" . urlencode('Failed to prepare update statement.'));
            exit;
        }
        $stmt->bind_param("ssdsii", $name, $description, $price, $cover_image, $course_type_id, $course_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_courses.php?msg=" . urlencode('Course updated successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_courses.php?err=" . urlencode('Failed to update course.'));
            exit;
        }
    }

    if ($action === 'delete' && $course_id > 0) {
        $conn->begin_transaction();
        try {
            // Delete related teacher assignments
            if ($stmt = $conn->prepare("DELETE FROM teacher_courses WHERE course_id = ?")) {
                $stmt->bind_param("i", $course_id);
                $stmt->execute();
                $stmt->close();
            }

            // Delete related contents
            if ($stmt = $conn->prepare("DELETE FROM contents WHERE course_id = ?")) {
                $stmt->bind_param("i", $course_id);
                $stmt->execute();
                $stmt->close();
            }

            // Delete related enrollments if table exists
            if ($hasEnrollments && $stmt = $conn->prepare("DELETE FROM enrollments WHERE course_id = ?")) {
                $stmt->bind_param("i", $course_id);
                $stmt->execute();
                $stmt->close();
            }

            // Delete course
            if ($stmt = $conn->prepare("DELETE FROM courses WHERE course_id = ?")) {
                $stmt->bind_param("i", $course_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            header("Location: manage_courses.php?msg=" . urlencode("Course #{$course_id} and related data deleted."));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            header("Location: manage_courses.php?err=" . urlencode('Failed to delete course. Please try again.'));
            exit;
        }
    }

    // Fallback
    header("Location: manage_courses.php");
    exit;
}

/* --------------------------------
   Load course types for dropdown
-----------------------------------*/
$courseTypes = [];
$typesRes = $conn->query("
    SELECT course_type_id, board, level
    FROM course_types
    ORDER BY board, level
");
if ($typesRes) {
    while ($row = $typesRes->fetch_assoc()) {
        $courseTypes[] = [
            'id'    => (int)$row['course_type_id'],
            'board' => $row['board'] ?? '',
            'level' => $row['level'] ?? '',
        ];
    }
    $typesRes->free();
}

/* --------------------------------
   Fetch courses with stats
-----------------------------------*/
$courses = [];
$sql = "
  SELECT
    c.course_id,
    c.name,
    c.description,
    c.price,
    c.cover_image,
    c.course_type_id,
    ct.board,
    ct.level,
    COALESCE((
      SELECT COUNT(*) FROM teacher_courses tc WHERE tc.course_id = c.course_id
    ), 0) AS teacher_count,
    COALESCE((
      SELECT COUNT(*) FROM contents cnt WHERE cnt.course_id = c.course_id
    ), 0) AS content_count
    " . ($hasEnrollments ? ",
    COALESCE((
      SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.course_id
    ), 0) AS enrollment_count" : ", 0 AS enrollment_count") . "
  FROM courses c
  LEFT JOIN course_types ct ON c.course_type_id = ct.course_type_id
  ORDER BY c.name ASC
";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $courses[] = [
            'course_id'        => (int)$row['course_id'],
            'name'             => $row['name'] ?? '',
            'description'      => $row['description'] ?? '',
            'price'            => isset($row['price']) ? (float)$row['price'] : 0.0,
            'cover_image'      => $row['cover_image'] ?? '',
            'board'            => $row['board'] ?? '',
            'level'            => $row['level'] ?? '',
            'teacher_count'    => (int)$row['teacher_count'],
            'content_count'    => (int)$row['content_count'],
            'enrollment_count' => (int)$row['enrollment_count'],
            'type_display'     => trim(($row['board'] ?? '') . ' ' . ($row['level'] ?? '')),
            'course_type_id'   => isset($row['course_type_id']) ? (int)$row['course_type_id'] : 0,
        ];
    }
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Manage Courses</title>
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
    .line-clamp-2 {
      display:-webkit-box;
      -webkit-box-orient:vertical;
      -webkit-line-clamp:2;
      overflow:hidden;
    }
    th.sticky { position:sticky; top:0; z-index:10; backdrop-filter:blur(12px); }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<!-- Main Container -->
<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 flex flex-col lg:flex-row gap-8">
<!-- Sidebar --> 
<?php include 'components/sidebar_coordinator.php'; ?>
  <!-- Main Content -->
  <main class="w-full space-y-10 animate-fadeUp">
  <!-- Header -->
  <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-indigo-950 via-slate-900 to-sky-900 text-white shadow-2xl mb-6">
    <div class="absolute -left-24 -top-24 w-64 h-64 bg-indigo-500/40 rounded-full blur-3xl"></div>
    <div class="absolute -right-24 top-10 w-60 h-60 bg-sky-400/40 rounded-full blur-3xl"></div>

    <div class="relative z-10 px-5 py-6 sm:px-7 sm:py-7 flex flex-col gap-4">
      <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/25">
            <i data-lucide="layers" class="w-3.5 h-3.5"></i>
          </span>
          <span><?= ($role === 'coordinator' ? 'Coordinator' : 'Admin') ?> · Courses</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
          <span><?= htmlspecialchars(ucfirst($role)) ?> access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Manage Courses
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            Create, edit, or remove courses. Set prices, assign teachers, and monitor content coverage.
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
          <button id="openCourseModal"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-indigo-700 shadow-sm active:scale-[0.99]">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add course
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
                   placeholder="Search by course name, board, or level..."
                   class="w-full pl-8 pr-2.5 py-2 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   aria-label="Search courses">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
          </div>
          <div class="flex gap-2">
            <select id="boardFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All boards</option>
              <?php
              $seenBoards = [];
              foreach ($courses as $c) {
                $b = trim($c['board']);
                if ($b !== '' && !in_array($b, $seenBoards, true)) {
                  $seenBoards[] = $b;
                  echo '<option value="' . htmlspecialchars(strtolower($b), ENT_QUOTES) . '">' .
                        htmlspecialchars($b) . '</option>';
                }
              }
              ?>
            </select>
            <select id="levelFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All levels</option>
              <?php
              $seenLevels = [];
              foreach ($courses as $c) {
                $l = trim($c['level']);
                if ($l !== '' && !in_array($l, $seenLevels, true)) {
                  $seenLevels[] = $l;
                  echo '<option value="' . htmlspecialchars(strtolower($l), ENT_QUOTES) . '">' .
                        htmlspecialchars($l) . '</option>';
                }
              }
              ?>
            </select>
          </div>
        </div>
      </div>

      <!-- Courses table -->
      <div class="overflow-hidden rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200">
        <?php if (!empty($courses)): ?>
          <div class="overflow-x-auto" id="coursesTableWrapper">
            <table id="coursesTable" class="min-w-full text-left border-collapse">
              <thead>
                <tr class="text-slate-700 text-[11px] sm:text-xs bg-slate-50/90 uppercase tracking-wide">
                  <th class="sticky px-3 py-2 border-b border-slate-200">ID</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Course</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Board / Level</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Price</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Teachers</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Content</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Enrollments</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Actions</th>
                </tr>
              </thead>
              <tbody class="text-[11px] sm:text-xs">
                <?php foreach ($courses as $c):
                    $searchKey = strtolower(trim(
                        ($c['name'] ?? '') . ' ' . ($c['board'] ?? '') . ' ' . ($c['level'] ?? '')
                    ));
                    $cid = $c['course_id'];
                ?>
                  <tr class="hover:bg-slate-50 even:bg-slate-50/40"
                      data-board="<?= htmlspecialchars(strtolower($c['board']), ENT_QUOTES) ?>"
                      data-level="<?= htmlspecialchars(strtolower($c['level']), ENT_QUOTES) ?>"
                      data-key="<?= htmlspecialchars($searchKey, ENT_QUOTES) ?>">
                    <td class="px-3 py-2 border-b border-slate-100 text-slate-600">
                      <?= $cid ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <div class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($c['name']) ?></div>
                      <div class="text-[10px] text-slate-500 line-clamp-2">
                        <?= htmlspecialchars($c['description'] ?: 'No description.') ?>
                      </div>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <span class="inline-flex flex-col">
                        <span class="text-[11px] font-medium text-slate-800"><?= htmlspecialchars($c['board'] ?: '—') ?></span>
                        <span class="text-[10px] text-slate-500"><?= htmlspecialchars($c['level'] ?: '') ?></span>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap text-slate-700">
                      <?= number_format($c['price'], 2) ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="users" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $c['teacher_count'] ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $c['content_count'] ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="users-2" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $c['enrollment_count'] ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <div class="flex flex-wrap gap-1.5">
                        <button type="button"
                                class="inline-flex items-center gap-1 text-indigo-700 hover:text-indigo-900 px-2 py-0.5 rounded-md ring-1 ring-indigo-200 bg-indigo-50 text-[10px] font-medium"
                                onclick='openEditCourseModal(<?= json_encode([
                                  "course_id"      => $cid,
                                  "name"           => $c["name"],
                                  "description"    => $c["description"],
                                  "cover_image"    => $c["cover_image"],
                                  "price"          => $c["price"],
                                  "course_type_id" => $c["course_type_id"],
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                          <i data-lucide="pen-square" class="w-3.5 h-3.5"></i> Edit
                        </button>

                        <a href="course.php?course_id=<?= $cid ?>"
                           class="inline-flex items-center gap-1 text-sky-700 hover:text-sky-900 px-2 py-0.5 rounded-md ring-1 ring-sky-200 bg-sky-50 text-[10px] font-medium">
                          <i data-lucide="external-link" class="w-3.5 h-3.5"></i> View
                        </a>

                        <a href="manage_course_teachers.php?course_id=<?= $cid ?>"
                           class="inline-flex items-center gap-1 text-emerald-700 hover:text-emerald-900 px-2 py-0.5 rounded-md ring-1 ring-emerald-200 bg-emerald-50 text-[10px] font-medium">
                          <i data-lucide="users" class="w-3.5 h-3.5"></i> Teachers
                        </a>

                        <a href="manage_course_contents.php?course_id=<?= $cid ?>"
                           class="inline-flex items-center gap-1 text-amber-700 hover:text-amber-900 px-2 py-0.5 rounded-md ring-1 ring-amber-200 bg-amber-50 text-[10px] font-medium">
                          <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Content
                        </a>

                        <form method="POST" class="inline"
                              onsubmit="return confirm('Delete this course and all related data (teachers, contents, enrollments)?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="course_id" value="<?= $cid ?>">
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
          <div class="p-6 text-sm text-slate-600">
            <div class="flex items-center gap-3">
              <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
                <i data-lucide="info" class="w-5 h-5"></i>
              </span>
              <div>
                <p class="font-medium">No courses found.</p>
                <p class="text-xs text-slate-500 mt-0.5">
                  Use the “Add course” button above to create your first course.
                </p>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<!-- Add/Edit Course Modal -->
<div id="courseModal" class="fixed inset-0 z-[60] hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div id="courseOverlay" class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm"></div>

  <div class="absolute inset-0 flex items-start justify-center p-4 sm:p-6">
    <div class="w-full max-w-xl mt-16 sm:mt-24 rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 overflow-hidden">
      <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 bg-slate-50/70">
        <h3 id="courseModalTitle" class="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
          <i data-lucide="plus-circle" class="w-4 h-4 text-indigo-600"></i>
          <span>Add course</span>
        </h3>
        <button id="courseModalClose" class="text-slate-400 hover:text-slate-600 p-1 rounded-md hover:bg-slate-100"
                aria-label="Close course form">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <form method="POST" class="p-4 space-y-3" id="courseForm" aria-labelledby="courseModalTitle">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" id="courseAction" value="add">
        <input type="hidden" name="course_id" id="courseIdField" value="">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Course name</label>
            <input name="name" id="courseNameField" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>

          <div>
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Price</label>
            <input name="price" id="coursePriceField" type="number" step="0.01" min="0" required
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   placeholder="e.g., 0.00 for free" />
          </div>

          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Description
            </label>
            <textarea name="description" id="courseDescField" rows="3"
                      class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300 resize-y"></textarea>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">
              Cover image (URL or relative path)
            </label>
            <input name="cover_image" id="courseCoverField"
                   class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   placeholder="e.g., uploads/covers/math.png" />
          </div>

          <div class="sm:col-span-2">
            <label class="block text-[11px] font-medium text-slate-700 uppercase tracking-wide">Course type</label>
            <select name="course_type_id" id="courseTypeField" required
                    class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.75 text-sm shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">Select type</option>
              <?php foreach ($courseTypes as $ct): ?>
                <option value="<?= $ct['id'] ?>">
                  <?= htmlspecialchars(($ct['board'] ?: 'Board') . ' • ' . ($ct['level'] ?: 'Level')) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($courseTypes)): ?>
              <p class="mt-1 text-[11px] text-rose-600">
                No course types found. Please create course types first.
              </p>
            <?php endif; ?>
          </div>
        </div>

        <div class="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 mt-1">
          <button type="button"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.75 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  id="courseCancelBtn">
            Cancel
          </button>
          <button type="submit"
                  class="inline-flex items-center gap-1 rounded-md bg-indigo-600 text-white px-3.5 py-1.75 text-xs font-semibold hover:bg-indigo-700 shadow-sm">
            <i data-lucide="save" class="w-3.5 h-3.5"></i>
            <span id="courseSubmitLabel">Create course</span>
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

  const searchInput  = document.getElementById('searchInput');
  const boardFilter  = document.getElementById('boardFilter');
  const levelFilter  = document.getElementById('levelFilter');
  const rows         = [...document.querySelectorAll('#coursesTable tbody tr')];

  function applyFilters() {
    const q      = (searchInput?.value || '').toLowerCase().trim();
    const board  = (boardFilter?.value || '').toLowerCase();
    const level  = (levelFilter?.value || '').toLowerCase();
    let visible  = 0;

    rows.forEach(tr => {
      const key   = (tr.getAttribute('data-key') || '').toLowerCase();
      const b     = (tr.getAttribute('data-board') || '').toLowerCase();
      const l     = (tr.getAttribute('data-level') || '').toLowerCase();

      const matchQ = !q     || key.includes(q);
      const matchB = !board || b === board;
      const matchL = !level || l === level;

      const show = matchQ && matchB && matchL;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  boardFilter?.addEventListener('change', applyFilters);
  levelFilter?.addEventListener('change', applyFilters);
  applyFilters();

  // Modal logic
  (function() {
    const modal        = document.getElementById('courseModal');
    const overlay      = document.getElementById('courseOverlay');
    const openBtn      = document.getElementById('openCourseModal');
    const closeBtn     = document.getElementById('courseModalClose');
    const cancelBtn    = document.getElementById('courseCancelBtn');
    const form         = document.getElementById('courseForm');
    const title        = document.getElementById('courseModalTitle');
    const actionField  = document.getElementById('courseAction');
    const idField      = document.getElementById('courseIdField');
    const nameField    = document.getElementById('courseNameField');
    const priceField   = document.getElementById('coursePriceField');
    const descField    = document.getElementById('courseDescField');
    const coverField   = document.getElementById('courseCoverField');
    const typeField    = document.getElementById('courseTypeField');
    const submitLabel  = document.getElementById('courseSubmitLabel');

    let prevFocus = null;

    function openModal() {
      prevFocus = document.activeElement;
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(() => nameField?.focus(), 20);
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
      title.querySelector('span').textContent = 'Add course';
      actionField.value = 'add';
      idField.value = '';
      nameField.value = '';
      priceField.value = '';
      descField.value = '';
      coverField.value = '';
      if (typeField) typeField.value = '';
      submitLabel.textContent = 'Create course';
    }

    openBtn?.addEventListener('click', () => {
      resetToAdd();
      openModal();
    });

    overlay?.addEventListener('click', closeModal);
    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);

    window.openEditCourseModal = function(data) {
      // data: { course_id, name, description, cover_image, price, course_type_id }
      resetToAdd();
      if (!data) return;
      title.querySelector('span').textContent = 'Edit course';
      actionField.value = 'edit';
      idField.value = data.course_id || '';
      nameField.value = data.name || '';
      priceField.value = (data.price !== undefined && data.price !== null) ? data.price : '';
      descField.value = data.description || '';
      coverField.value = data.cover_image || '';
      if (typeField && data.course_type_id) {
        typeField.value = String(data.course_type_id);
      }
      submitLabel.textContent = 'Save changes';
      openModal();
    };
  })();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>