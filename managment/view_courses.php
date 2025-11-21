<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied");
}

// Optional: set your currency symbol here
$currencySymbol = '$'; // e.g. '$', 'Rs.', '€'

// CSRF token (for POST)
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$createErrors = [];
$prefill = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Create course
    if ($_POST['action'] === 'create') {
        // CSRF
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $createErrors[] = "Invalid session. Please refresh and try again.";
        }

        // Gather + prefill
        $prefill['name']           = trim($_POST['name'] ?? '');
        $prefill['course_type_id'] = (int)($_POST['course_type_id'] ?? 0);
        $prefill['price']          = trim($_POST['price'] ?? '');
        $prefill['description']    = trim($_POST['description'] ?? '');
        $prefill['teacher_id']     = (int)($_POST['teacher_id'] ?? 0);

        // Validate
        if ($prefill['name'] === '') {
            $createErrors[] = "Course name is required.";
        } elseif (mb_strlen($prefill['name']) > 150) {
            $createErrors[] = "Course name is too long.";
        }

        if ($prefill['course_type_id'] <= 0) {
            $createErrors[] = "Please select a course type.";
        }

        $priceRaw = str_replace([','], [''], $prefill['price']);
        if ($prefill['price'] === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
            $createErrors[] = "Enter a valid non-negative price.";
        }

        // Check course type exists
        if (empty($createErrors) && $prefill['course_type_id'] > 0) {
            $stmt = $conn->prepare("SELECT 1 FROM course_types WHERE course_type_id = ?");
            $stmt->bind_param("i", $prefill['course_type_id']);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) $createErrors[] = "Selected course type does not exist.";
            $stmt->close();
        }

        // Check teacher exists if provided
        if ($prefill['teacher_id'] > 0) {
            $stmt = $conn->prepare("SELECT 1 FROM teachers WHERE teacher_id = ?");
            $stmt->bind_param("i", $prefill['teacher_id']);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) $createErrors[] = "Selected teacher not found.";
            $stmt->close();
        }

        // Insert
        if (empty($createErrors)) {
            $conn->begin_transaction();
            try {
                $priceNum = (float)$priceRaw;

                // Insert into courses
                $stmt = $conn->prepare("INSERT INTO courses (name, description, price, course_type_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssdi", $prefill['name'], $prefill['description'], $priceNum, $prefill['course_type_id']);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $newCourseId = $stmt->insert_id;
                $stmt->close();

                // Optional teacher assignment
                if ($prefill['teacher_id'] > 0) {
                    $stmt = $conn->prepare("INSERT INTO teacher_courses (teacher_id, course_id) VALUES (?, ?)");
                    $stmt->bind_param("ii", $prefill['teacher_id'], $newCourseId);
                    if (!$stmt->execute()) throw new Exception($stmt->error);
                    $stmt->close();
                }

                $conn->commit();
                header("Location: view_courses.php?msg=" . urlencode("Course '{$prefill['name']}' created successfully."));
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $createErrors[] = "Failed to create course. " . $e->getMessage();
            }
        }
    }

    // Delete course
    elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            header("Location: view_courses.php?msg=" . urlencode("Invalid session. Please refresh and try again."));
            exit;
        }

        $course_id = (int)$_POST['id'];
        if ($course_id > 0) {
            $conn->begin_transaction();
            try {
                // Remove child rows that may block deletion
                if ($stmt = $conn->prepare("DELETE FROM enrollments WHERE course_id = ?")) {
                    $stmt->bind_param("i", $course_id);
                    if (!$stmt->execute()) throw new Exception($stmt->error);
                    $stmt->close();
                }
                if ($stmt = $conn->prepare("DELETE FROM teacher_courses WHERE course_id = ?")) {
                    $stmt->bind_param("i", $course_id);
                    if (!$stmt->execute()) throw new Exception($stmt->error);
                    $stmt->close();
                }

                // Delete the course
                if ($stmt = $conn->prepare("DELETE FROM courses WHERE course_id = ?")) {
                    $stmt->bind_param("i", $course_id);
                    if (!$stmt->execute()) throw new Exception($stmt->error);
                    $affected = $stmt->affected_rows;
                    $stmt->close();

                    if ($affected === 0) {
                        $conn->rollback();
                        header("Location: view_courses.php?msg=" . urlencode("Course #$course_id not found or already deleted."));
                        exit;
                    }
                }

                $conn->commit();
                header("Location: view_courses.php?msg=" . urlencode("Course #$course_id deleted successfully."));
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                header("Location: view_courses.php?msg=" . urlencode("Failed to delete course #$course_id. " . $e->getMessage()));
                exit;
            }
        }

        header("Location: view_courses.php?msg=" . urlencode("Invalid course ID."));
        exit;
    }
}

// Fetch lists for UI
// Course types for dropdown
$courseTypes = [];
if ($res = $conn->query("SELECT course_type_id, board, level FROM course_types ORDER BY board, level")) {
    while ($r = $res->fetch_assoc()) $courseTypes[] = $r;
}

// Teachers for dropdown (optional assignment)
$teachers = [];
if ($res = $conn->query("SELECT teacher_id, first_name, last_name FROM teachers ORDER BY first_name, last_name")) {
    while ($r = $res->fetch_assoc()) $teachers[] = $r;
}

// Fetch courses
$query = $conn->query("
    SELECT 
        c.course_id,
        c.name AS course_name,
        c.description,
        c.price,
        ct.board,
        ct.level,
        CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
    FROM courses c
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    LEFT JOIN teacher_courses tc ON c.course_id = tc.course_id
    LEFT JOIN teachers t ON tc.teacher_id = t.teacher_id
    ORDER BY c.course_id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>All Courses</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    body { 
      overscroll-behavior: none; 
    }
    /* subtle background pattern */
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background:
        radial-gradient(circle at 10% 20%, rgba(129,140,248,0.18) 0, transparent 55%),
        radial-gradient(circle at 80% 0%, rgba(56,189,248,0.15) 0, transparent 55%);
      opacity: 0.8;
      z-index: -1;
      pointer-events: none;
    }
  </style>
</head>
<body class="bg-slate-100 min-h-screen text-slate-900">
  <?php include 'components/navbar.php'; ?>

  <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-28 space-y-6">

    <!-- Hero -->
    <section class="relative overflow-hidden rounded-3xl shadow-xl bg-gradient-to-br from-indigo-900 via-slate-900 to-sky-900 text-white">
      <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-indigo-500/40 blur-3xl"></div>
      <div class="absolute -right-10 top-10 h-52 w-52 rounded-full bg-sky-400/40 blur-3xl"></div>

      <div class="relative z-10 px-6 py-7 sm:px-8 sm:py-8 flex flex-col gap-4">
        <div class="flex items-center justify-between gap-3">
          <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/20">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
            </span>
            <span>Admin · Courses</span>
          </div>
          <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
            <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
            <span>Admin access</span>
          </span>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold tracking-tight">
              Courses Management
            </h1>
            <p class="mt-1 text-sm sm:text-base text-sky-100/90 max-w-xl">
              Create, update and organize all courses from a single, clean dashboard.
            </p>
          </div>
          <div class="flex flex-col items-start sm:items-end gap-2 text-xs sm:text-sm text-sky-100/90">
            <div class="inline-flex items-center gap-2 rounded-full bg-black/20 px-3 py-1 backdrop-blur">
              <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-400/90 text-emerald-950 text-[11px] font-semibold">
                ✓
              </span>
              <span>Secure actions with CSRF protection</span>
            </div>
            <span class="text-[11px] sm:text-xs">
              Tip: use the “Add Course” button below to quickly add a new course.
            </span>
          </div>
        </div>
      </div>
    </section>

    <!-- Grid: Sidebar + Main -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <?php
        $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $createAnnouncementLink = '#create-announcement';
      ?>
      <aside class="lg:col-span-3">
        <?php include 'components/admin_tools_sidebar.php'; ?>
      </aside>

      <!-- Main content -->
      <main class="lg:col-span-9">
        <div class="w-full rounded-3xl bg-white/80 backdrop-blur shadow-lg ring-1 ring-slate-200/70 p-5 sm:p-6 md:p-8 space-y-5">

          <!-- Header + messages -->
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
              <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-50 text-blue-700 ring-1 ring-blue-100">
                <i data-lucide="book-open" class="w-5 h-5"></i>
              </span>
              <div>
                <h2 class="text-xl sm:text-2xl md:text-3xl font-semibold tracking-tight">
                  All Courses
                </h2>
                <p class="text-xs sm:text-sm text-slate-500">
                  Overview of every course, with board, level, pricing and teacher.
                </p>
              </div>
            </div>

            <div class="flex flex-col items-stretch sm:items-end gap-2">
              <?php if (!empty($_GET['msg'])): ?>
                <div class="inline-flex items-start gap-2 rounded-2xl bg-emerald-50 px-3 py-2 text-xs sm:text-sm text-emerald-700 ring-1 ring-emerald-200 max-w-md">
                  <span class="mt-0.5">
                    <i class="fa-solid fa-circle-check"></i>
                  </span>
                  <div>
                    <p class="font-medium">Action completed</p>
                    <p class="text-[11px] sm:text-xs"><?= htmlspecialchars($_GET['msg']) ?></p>
                  </div>
                </div>
              <?php endif; ?>

              <?php if (!empty($createErrors)): ?>
                <div class="inline-flex items-start gap-2 rounded-2xl bg-red-50 px-3 py-2 text-xs sm:text-sm text-red-700 ring-1 ring-red-200 max-w-md">
                  <span class="mt-0.5">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                  </span>
                  <div>
                    <p class="font-medium">Couldn’t create course</p>
                    <p class="text-[11px] sm:text-xs">
                      <?= htmlspecialchars(implode(' ', $createErrors)) ?>
                    </p>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Actions row -->
          <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 justify-between">
            <div class="flex flex-wrap items-center gap-2">
              <a href="admin_dashboard.php" 
                 class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 transition">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Back to Dashboard
              </a>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <!-- Add Course opens modal; falls back to add_course.php if JS disabled -->
              <a href="add_course.php"
                 id="openAddCourseBtn"
                 class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 hover:shadow-md active:scale-[0.99] transition">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                Add Course
              </a>
            </div>
          </div>

          <!-- Table -->
          <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="bg-slate-50/80 backdrop-blur sticky top-0 z-10">
                  <tr class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th class="px-4 py-3 text-left">ID</th>
                    <th class="px-4 py-3 text-left">Name</th>
                    <th class="px-4 py-3 text-left">Board</th>
                    <th class="px-4 py-3 text-left">Level</th>
                    <th class="px-4 py-3 text-left">Price</th>
                    <th class="px-4 py-3 text-left">Description</th>
                    <th class="px-4 py-3 text-left">Teacher</th>
                    <th class="px-4 py-3 text-left">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <?php if ($query && $query->num_rows > 0): ?>
                    <?php while ($row = $query->fetch_assoc()): ?>
                      <tr class="hover:bg-slate-50/80 transition-colors duration-150">
                        <td class="px-4 py-3 align-top text-xs text-slate-500">
                          #<?= (int)$row['course_id'] ?>
                        </td>
                        <td class="px-4 py-3 align-top">
                          <div class="font-medium text-slate-800">
                            <?= htmlspecialchars($row['course_name']) ?>
                          </div>
                        </td>
                        <td class="px-4 py-3 align-top">
                          <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-700 ring-1 ring-slate-200">
                            <i data-lucide="layers" class="w-3.5 h-3.5"></i>
                            <?= htmlspecialchars($row['board']) ?>
                          </span>
                        </td>
                        <td class="px-4 py-3 align-top">
                          <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-1 text-[11px] font-medium text-indigo-700 ring-1 ring-indigo-100">
                            <i data-lucide="graduation-cap" class="w-3.5 h-3.5"></i>
                            <?= htmlspecialchars($row['level']) ?>
                          </span>
                        </td>
                        <td class="px-4 py-3 align-top">
                          <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">
                            <i data-lucide="badge-dollar-sign" class="w-3.5 h-3.5"></i>
                            <?= $currencySymbol . number_format((float)$row['price'], 2) ?>
                          </span>
                        </td>
                        <td class="px-4 py-3 align-top text-xs text-slate-600 max-w-xs">
                          <span class="block truncate" title="<?= htmlspecialchars($row['description']) ?>">
                            <?= htmlspecialchars($row['description']) ?>
                          </span>
                        </td>
                        <td class="px-4 py-3 align-top">
                          <?php if (!empty($row['teacher_name'])): ?>
                            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-[11px] font-medium text-sky-700 ring-1 ring-sky-100">
                              <i data-lucide="user" class="w-3.5 h-3.5"></i>
                              <?= htmlspecialchars($row['teacher_name']) ?>
                            </span>
                          <?php else: ?>
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-[11px] font-medium text-slate-500 ring-1 ring-slate-200">
                              <i data-lucide="minus-circle" class="w-3.5 h-3.5"></i>
                              Not assigned
                            </span>
                          <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 align-top">
                          <div class="flex flex-wrap items-center gap-2">
                            <a href="edit_course.php?id=<?= (int)$row['course_id'] ?>"
                               class="inline-flex items-center gap-1 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-amber-600 hover:shadow transition">
                              <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                              Edit
                            </a>

                            <!-- Delete via POST + CSRF -->
                            <form method="post" onsubmit="return confirm('Are you sure you want to delete this course?');" class="inline-flex">
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                              <input type="hidden" name="action" value="delete">
                              <input type="hidden" name="id" value="<?= (int)$row['course_id'] ?>">
                              <button type="submit"
                                class="inline-flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700 hover:shadow transition">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                Delete
                              </button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">
                        No courses found. Use the <span class="font-semibold text-emerald-600">“Add Course”</span> button above to create one.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </main>
    </div>
  </div>

  <!-- Add Course Modal -->
  <div id="addCourseModal" class="fixed inset-0 z-50 hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <!-- Backdrop -->
    <div id="addCourseOverlay" class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm"></div>

    <!-- Modal panel -->
    <div class="absolute inset-0 flex items-start justify-center p-4 sm:p-6">
      <div class="w-full max-w-2xl mt-16 sm:mt-20 rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200/80 overflow-hidden">
        <div class="flex items-center justify-between p-4 sm:p-5 border-b border-slate-100 bg-slate-50/80">
          <h3 class="text-sm sm:text-base font-semibold text-slate-900 flex items-center gap-2">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100">
              <i data-lucide="plus-circle" class="w-4 h-4"></i>
            </span>
            Add New Course
          </h3>
          <button id="closeAddCourseBtn" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-md hover:bg-slate-100" aria-label="Close add course form">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>

        <form method="post" class="p-4 sm:p-5 space-y-5" id="addCourseForm" action="view_courses.php" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="create">

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide">Course Name</label>
              <input name="name" required
                     class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                     value="<?= htmlspecialchars($prefill['name'] ?? '') ?>"/>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide">Course Type</label>
              <select name="course_type_id" required
                      class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                <option value="">Select Board / Level</option>
                <?php foreach ($courseTypes as $ct): ?>
                  <option value="<?= (int)$ct['course_type_id'] ?>"
                    <?= ((int)($prefill['course_type_id'] ?? 0) === (int)$ct['course_type_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ct['board'] . ' — ' . $ct['level']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide">Price</label>
              <div class="relative mt-1">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-500">
                  <?= htmlspecialchars($currencySymbol) ?>
                </span>
                <input name="price" required inputmode="decimal" step="0.01" min="0" pattern="^\d+(\.\d{1,2})?$"
                       class="w-full rounded-xl border border-slate-200 bg-white pl-7 pr-3 py-2.5 text-sm shadow-sm focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                       placeholder="0.00" value="<?= htmlspecialchars($prefill['price'] ?? '') ?>"/>
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide">Assign Teacher (optional)</label>
              <select name="teacher_id"
                      class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                <option value="0">Not Assigned</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= (int)$t['teacher_id'] ?>"
                    <?= ((int)($prefill['teacher_id'] ?? 0) === (int)$t['teacher_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide">Description (optional)</label>
              <textarea name="description" rows="3"
                        class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                        placeholder="Brief description of the course..."><?= htmlspecialchars($prefill['description'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="pt-4 flex items-center justify-end gap-2 border-t border-slate-100">
            <button type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50"
                    id="cancelAddCourseBtn">
              Cancel
            </button>
            <button type="submit"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 hover:shadow-md active:scale-[0.99] transition">
              <i data-lucide="save" class="w-4 h-4"></i>
              Create Course
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      if (window.lucide) { lucide.createIcons(); }

      const openBtn = document.getElementById('openAddCourseBtn');
      const modal = document.getElementById('addCourseModal');
      const overlay = document.getElementById('addCourseOverlay');
      const closeBtn = document.getElementById('closeAddCourseBtn');
      const cancelBtn = document.getElementById('cancelAddCourseBtn');
      const firstField = document.querySelector('#addCourseForm input[name="name"]');
      let prevFocus = null;

      function openModal(e) {
        if (e) e.preventDefault(); // fallback to add_course.php if JS disabled
        prevFocus = document.activeElement;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(() => firstField && firstField.focus(), 20);
        document.addEventListener('keydown', onKeydown);
      }
      function closeModal() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKeydown);
        if (prevFocus && prevFocus.focus) prevFocus.focus();
      }
      function onKeydown(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          closeModal();
        }
      }

      openBtn && openBtn.addEventListener('click', openModal);
      overlay && overlay.addEventListener('click', closeModal);
      closeBtn && closeBtn.addEventListener('click', closeModal);
      cancelBtn && cancelBtn.addEventListener('click', closeModal);

      // Auto-open modal if server-side validation errors exist
      const openOnLoad = <?= json_encode(!empty($createErrors)) ?>;
      if (openOnLoad) openModal();
    });
  </script>
</body>
</html>