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

// Check if enrollments table exists (maybe needed in related views)
$hasEnrollmentsRes = $conn->query("SHOW TABLES LIKE 'enrollments'");
$hasEnrollments    = $hasEnrollmentsRes && $hasEnrollmentsRes->num_rows > 0;
if ($hasEnrollmentsRes) $hasEnrollmentsRes->free();

/* --------------------------------
   Handle POST actions: assign/remove teacher
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
        header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Invalid session. Please refresh and try again.'));
        exit;
    }

    $teacher_id = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;

    if ($teacher_id <= 0) {
        header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Invalid teacher.'));
        exit;
    }

    if ($action === 'assign') {

        // Ensure teacher exists
        $checkT = $conn->prepare("SELECT teacher_id FROM teachers WHERE teacher_id = ? LIMIT 1");
        $checkT->bind_param("i", $teacher_id);
        $checkT->execute();
        $checkT->store_result();
        if ($checkT->num_rows === 0) {
            $checkT->close();
            header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Teacher not found.'));
            exit;
        }
        $checkT->close();

        // Check if already assigned
        $chk = $conn->prepare("SELECT 1 FROM teacher_courses WHERE course_id = ? AND teacher_id = ? LIMIT 1");
        $chk->bind_param("ii", $course_id, $teacher_id);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();

        if ($exists) {
            header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Teacher is already assigned to this course.'));
            exit;
        }

        // Insert mapping
        $stmt = $conn->prepare("INSERT INTO teacher_courses (teacher_id, course_id) VALUES (?, ?)");
        if ($stmt === false) {
            header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Failed to prepare assignment.'));
            exit;
        }
        $stmt->bind_param("ii", $teacher_id, $course_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_course_teachers.php?course_id={$course_id}&msg=" . urlencode('Teacher assigned successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Failed to assign teacher.'));
            exit;
        }

    } elseif ($action === 'remove') {

        $stmt = $conn->prepare("DELETE FROM teacher_courses WHERE course_id = ? AND teacher_id = ? LIMIT 1");
        if ($stmt === false) {
            header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Failed to prepare removal.'));
            exit;
        }
        $stmt->bind_param("ii", $course_id, $teacher_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_course_teachers.php?course_id={$course_id}&msg=" . urlencode('Teacher unassigned successfully.'));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Failed to unassign teacher.'));
            exit;
        }

    } else {
        header("Location: manage_course_teachers.php?course_id={$course_id}&err=" . urlencode('Unknown action.'));
        exit;
    }
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
   Assigned teachers
-----------------------------------*/
$assignedTeachers = [];
$stmt = $conn->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name, t.email
    FROM teacher_courses tc
    INNER JOIN teachers t ON t.teacher_id = tc.teacher_id
    WHERE tc.course_id = ?
    ORDER BY t.first_name, t.last_name
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $assignedTeachers[] = [
        'teacher_id' => (int)$row['teacher_id'],
        'first_name' => $row['first_name'] ?? '',
        'last_name'  => $row['last_name'] ?? '',
        'email'      => $row['email'] ?? '',
    ];
}
$stmt->close();

$assignedCount = count($assignedTeachers);

/* --------------------------------
   Available (unassigned) teachers
-----------------------------------*/
$availableTeachers = [];
$stmt = $conn->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name, t.email
    FROM teachers t
    WHERE NOT EXISTS (
      SELECT 1 FROM teacher_courses tc
      WHERE tc.teacher_id = t.teacher_id
        AND tc.course_id = ?
    )
    ORDER BY t.first_name, t.last_name
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $availableTeachers[] = [
        'teacher_id' => (int)$row['teacher_id'],
        'first_name' => $row['first_name'] ?? '',
        'last_name'  => $row['last_name'] ?? '',
        'email'      => $row['email'] ?? '',
    ];
}
$stmt->close();

$availableCount = count($availableTeachers);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Manage Course Teachers – <?= htmlspecialchars($courseName) ?></title>
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
    .line-clamp-2 {
      display:-webkit-box;
      -webkit-box-orient:vertical;
      -webkit-line-clamp:2;
      overflow:hidden;
    }
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
            <i data-lucide="users" class="w-3.5 h-3.5"></i>
          </span>
          <span><?= ($role === 'coordinator' ? 'Coordinator' : 'Admin') ?> · Course Teachers</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
          <span><?= htmlspecialchars(ucfirst($role)) ?> access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Teachers for “<?= htmlspecialchars($courseName) ?>”
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            Assign or unassign teachers for this course.
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
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Assigned Teachers -->
        <div class="soft-card rounded-2xl p-4 sm:p-5">
          <div class="flex items-center justify-between mb-3">
            <div>
              <h2 class="text-sm sm:text-base font-semibold text-slate-800 flex items-center gap-2">
                <i data-lucide="user-check" class="w-4 h-4 text-emerald-600"></i>
                Assigned teachers
              </h2>
              <p class="text-[11px] sm:text-xs text-slate-500 mt-0.5">
                Currently linked to this course.
              </p>
            </div>
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 text-emerald-700 text-[11px] px-2 py-0.5 border border-emerald-100">
              <i data-lucide="users" class="w-3.5 h-3.5"></i>
              <?= $assignedCount ?>
            </span>
          </div>

          <?php if ($assignedCount > 0): ?>
            <div class="border border-slate-200 rounded-xl bg-white/95 max-h-80 overflow-y-auto">
              <table class="min-w-full text-left text-[11px] sm:text-xs">
                <thead>
                  <tr class="bg-slate-50/90 text-slate-600 uppercase tracking-wide">
                    <th class="px-3 py-2 border-b border-slate-200">Name</th>
                    <th class="px-3 py-2 border-b border-slate-200">Email</th>
                    <th class="px-3 py-2 border-b border-slate-200 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($assignedTeachers as $t): ?>
                    <?php
                      $fullName = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                      if ($fullName === '') $fullName = 'Unknown';
                    ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-3 py-2 border-b border-slate-100">
                        <span class="font-medium text-slate-900"><?= htmlspecialchars($fullName) ?></span>
                      </td>
                      <td class="px-3 py-2 border-b border-slate-100">
                        <span class="inline-flex items-center gap-1 text-slate-700">
                          <i data-lucide="mail" class="w-3.5 h-3.5 text-slate-400"></i>
                          <?= htmlspecialchars($t['email'] ?: '—') ?>
                        </span>
                      </td>
                      <td class="px-3 py-2 border-b border-slate-100 text-right">
                        <form method="POST" class="inline"
                              onsubmit="return confirm('Unassign this teacher from the course?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                          <input type="hidden" name="action" value="remove">
                          <input type="hidden" name="course_id" value="<?= $course_id ?>">
                          <input type="hidden" name="teacher_id" value="<?= $t['teacher_id'] ?>">
                          <button type="submit"
                                  class="inline-flex items-center gap-1 text-rose-700 hover:text-rose-900 px-2 py-0.5 rounded-md ring-1 ring-rose-200 bg-rose-50 text-[10px] font-medium">
                            <i data-lucide="x-circle" class="w-3.5 h-3.5"></i>
                            Remove
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="mt-2 text-xs text-slate-500">
              No teachers are currently assigned to this course.
            </p>
          <?php endif; ?>
        </div>

        <!-- Available Teachers -->
        <div class="soft-card rounded-2xl p-4 sm:p-5">
          <div class="flex items-center justify-between mb-3">
            <div>
              <h2 class="text-sm sm:text-base font-semibold text-slate-800 flex items-center gap-2">
                <i data-lucide="user-plus" class="w-4 h-4 text-indigo-600"></i>
                Available teachers
              </h2>
              <p class="text-[11px] sm:text-xs text-slate-500 mt-0.5">
                Teachers not yet linked to this course.
              </p>
            </div>
            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 text-slate-700 text-[11px] px-2 py-0.5 border border-slate-200">
              <i data-lucide="users" class="w-3.5 h-3.5"></i>
              <?= $availableCount ?>
            </span>
          </div>

          <?php if ($availableCount > 0): ?>
            <div class="mb-2">
              <label class="block text-[11px] font-medium text-slate-600 mb-1">
                Quick assign
              </label>
              <form method="POST" class="flex gap-2 items-center">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="course_id" value="<?= $course_id ?>">
                <select name="teacher_id" required
                        class="flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] sm:text-xs shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
                  <option value="">Select teacher</option>
                  <?php foreach ($availableTeachers as $t): ?>
                    <?php
                      $fullName = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                      if ($fullName === '') $fullName = 'Unknown';
                    ?>
                    <option value="<?= $t['teacher_id'] ?>">
                      <?= htmlspecialchars($fullName . ' – ' . ($t['email'] ?? '')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit"
                        class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 text-white px-2.5 py-1.5 text-[11px] sm:text-xs font-semibold hover:bg-indigo-700 shadow-sm">
                  <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                  Add
                </button>
              </form>
            </div>

            <div class="border border-slate-200 rounded-xl bg-white/95 max-h-72 overflow-y-auto mt-3">
              <table class="min-w-full text-left text-[11px] sm:text-xs">
                <thead>
                  <tr class="bg-slate-50/90 text-slate-600 uppercase tracking-wide">
                    <th class="px-3 py-2 border-b border-slate-200">Name</th>
                    <th class="px-3 py-2 border-b border-slate-200">Email</th>
                    <th class="px-3 py-2 border-b border-slate-200 text-right">Assign</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($availableTeachers as $t): ?>
                    <?php
                      $fullName = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                      if ($fullName === '') $fullName = 'Unknown';
                    ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-3 py-2 border-b border-slate-100">
                        <span class="font-medium text-slate-900"><?= htmlspecialchars($fullName) ?></span>
                      </td>
                      <td class="px-3 py-2 border-b border-slate-100">
                        <span class="inline-flex items-center gap-1 text-slate-700">
                          <i data-lucide="mail" class="w-3.5 h-3.5 text-slate-400"></i>
                          <?= htmlspecialchars($t['email'] ?: '—') ?>
                        </span>
                      </td>
                      <td class="px-3 py-2 border-b border-slate-100 text-right">
                        <form method="POST" class="inline">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                          <input type="hidden" name="action" value="assign">
                          <input type="hidden" name="course_id" value="<?= $course_id ?>">
                          <input type="hidden" name="teacher_id" value="<?= $t['teacher_id'] ?>">
                          <button type="submit"
                                  class="inline-flex items-center gap-1 text-emerald-700 hover:text-emerald-900 px-2 py-0.5 rounded-md ring-1 ring-emerald-200 bg-emerald-50 text-[10px] font-medium">
                            <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i>
                            Assign
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="mt-2 text-xs text-slate-500">
              All teachers are already assigned to this course, or no teachers exist yet.
            </p>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
  if (window.lucide) {
    window.lucide.createIcons();
  }
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>