<?php
// teacher_students.php — list and manage students enrolled in the teacher's courses

session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: login.php");
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$user_id = (int)$_SESSION['user_id'];

/* fetch teacher record with name + id */
$teacher_id = 0;
$teacher_name = 'Teacher';
if ($stmt = $conn->prepare("SELECT teacher_id, first_name, last_name FROM teachers WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $teacher_id = (int)$row['teacher_id'];
        $teacher_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Teacher';
    }
    $stmt->close();
}
if ($teacher_id <= 0) { header("Location: login.php"); exit; }

/* flash */
$flash = $_SESSION['flash'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash'], $_SESSION['flash_type']);

/* Filters */
$filter_course = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filter_status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
$allowed_status = ['active','pending','suspended'];
if ($filter_status && !in_array($filter_status, $allowed_status, true)) $filter_status = '';

/* Actions: update status / remove */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'Invalid session. Please refresh and try again.';
        $_SESSION['flash_type'] = 'error';
        header("Location: teacher_students.php");
        exit;
    }

    if ($action === 'update_status') {
        $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
        $new_status = strtolower(trim($_POST['new_status'] ?? ''));
        if ($enrollment_id <= 0 || !in_array($new_status, $allowed_status, true)) {
            $_SESSION['flash'] = 'Invalid request.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_students.php");
            exit;
        }
        // verify ownership
        $own = $conn->prepare("
            SELECT 1
            FROM enrollments e
            JOIN teacher_courses tc ON tc.course_id = e.course_id
            WHERE e.enrollment_id = ? AND tc.teacher_id = ?
            LIMIT 1
        ");
        $own->bind_param("ii", $enrollment_id, $teacher_id);
        $own->execute();
        $own->store_result();
        if ($own->num_rows === 0) {
            $own->close();
            $_SESSION['flash'] = 'You do not own this enrollment.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_students.php");
            exit;
        }
        $own->close();

        if ($stmt = $conn->prepare("UPDATE enrollments SET status = ? WHERE enrollment_id = ?")) {
            $stmt->bind_param("si", $new_status, $enrollment_id);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $ok ? "Status updated to ".ucfirst($new_status)."." : "Failed to update status.";
            $_SESSION['flash_type'] = $ok ? 'success' : 'error';
        } else {
            $_SESSION['flash'] = 'Failed to prepare update.';
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: teacher_students.php");
        exit;
    }

    if ($action === 'remove_enrollment') {
        $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
        if ($enrollment_id <= 0) {
            $_SESSION['flash'] = 'Invalid request.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_students.php");
            exit;
        }
        // verify ownership
        $own = $conn->prepare("
            SELECT 1
            FROM enrollments e
            JOIN teacher_courses tc ON tc.course_id = e.course_id
            WHERE e.enrollment_id = ? AND tc.teacher_id = ?
            LIMIT 1
        ");
        $own->bind_param("ii", $enrollment_id, $teacher_id);
        $own->execute();
        $own->store_result();
        if ($own->num_rows === 0) {
            $own->close();
            $_SESSION['flash'] = 'You do not own this enrollment.';
            $_SESSION['flash_type'] = 'error';
            header("Location: teacher_students.php");
            exit;
        }
        $own->close();

        if ($stmt = $conn->prepare("DELETE FROM enrollments WHERE enrollment_id = ?")) {
            $stmt->bind_param("i", $enrollment_id);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $ok ? "Enrollment removed." : "Failed to remove enrollment.";
            $_SESSION['flash_type'] = $ok ? 'success' : 'error';
        } else {
            $_SESSION['flash'] = 'Failed to prepare delete.';
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: teacher_students.php");
        exit;
    }
}

/* fetch teacher courses for filter */
$courses_list = [];
if ($stmt = $conn->prepare("
    SELECT c.course_id, c.name
    FROM teacher_courses tc
    JOIN courses c ON c.course_id = tc.course_id
    WHERE tc.teacher_id = ?
    ORDER BY c.name
")) {
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $courses_list[] = $r;
    $stmt->close();
}

/* fetch students under the teacher */
$students = [];
$sql = "
    SELECT 
      e.enrollment_id, e.status, e.course_id,
      c.name AS course_name,
      s.student_id, s.first_name, s.last_name, s.email,
      u.username
    FROM enrollments e
    JOIN teacher_courses tc ON tc.course_id = e.course_id AND tc.teacher_id = ?
    JOIN courses c ON c.course_id = e.course_id
    JOIN students s ON s.user_id = e.user_id
    JOIN users u ON u.user_id = s.user_id
";
$cond = [];
$params = [$teacher_id];
$types  = "i";
if ($filter_course > 0) { $cond[] = "e.course_id = ?"; $params[] = $filter_course; $types .= "i"; }
if ($filter_status)     { $cond[] = "e.status = ?";     $params[] = $filter_status; $types .= "s"; }
if ($cond) $sql .= " WHERE " . implode(" AND ", $cond);
$sql .= " ORDER BY c.name, s.first_name, s.last_name";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $students[] = $r;
    $stmt->close();
}

/* quick metrics */
$total_students = count($students);
$by_status = ['active'=>0,'pending'=>0,'suspended'=>0];
foreach ($students as $st) {
  if (isset($by_status[$st['status']])) $by_status[$st['status']]++;
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <title>My Students • <?= e($teacher_name) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: { 50:'#eef2ff', 100:'#e0e7ff', 200:'#c7d2fe', 300:'#a5b4fc', 400:'#818cf8', 500:'#6366f1', 600:'#4f46e5', 700:'#4338ca', 800:'#3730a3', 900:'#312e81' }
          },
          boxShadow: {
            glow: '0 0 0 3px rgba(99,102,241,.12), 0 16px 32px rgba(2,6,23,.10)'
          },
          keyframes: {
            'fade-in-up': { '0%': { opacity: 0, transform: 'translateY(8px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } }
          },
          animation: { 'fade-in-up': 'fade-in-up .5s ease-out both' }
        }
      }
    }
  </script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI'; }
    html { scroll-behavior: smooth; }
    .hover-raise { transition:.2s ease; }
    .hover-raise:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(2,6,23,.08); }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50 dark:from-slate-950 dark:via-slate-950 dark:to-slate-900 text-slate-900 dark:text-slate-100 min-h-screen">
<?php include 'components/navbar.php'; ?>

<!-- Layout: sidebar + main -->
<div class="max-w-8xl mx-auto px-6 mt-24 relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-6 mb-20">
  <!-- Sidebar (desktop include) -->
  <aside class="hidden lg:block lg:col-span-3">
    <?php
      $active = 'students';
      include __DIR__ . '/components/teacher_sidebar.php';
    ?>

  </aside>

  <main class="lg:col-span-9 space-y-6">
    <!-- Hero moved inside main -->
    <section class="relative overflow-hidden rounded-2xl ring-1 ring-indigo-100 shadow-sm">
      <div aria-hidden="true" class="absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?q=80&w=1600&auto=format&fit=crop')] bg-cover bg-center"></div>
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/90 via-blue-900/80 to-sky-900/80"></div>
      </div>

      <div class="px-6 pt-16 pb-12">
        <div class="flex flex-wrap items-end justify-between gap-4 text-white">
          <div>
            <h1 class="text-3xl md:text-4xl font-extrabold flex items-center gap-2">
              <i class="ph ph-users-three"></i> My Students
            </h1>
            <p class="text-white/90">Manage enrollments for your courses, filter, and export lists.</p>
            <div class="mt-4 flex items-center gap-2">
              <a href="teacher_dashboard.php#dashboard" class="inline-flex items-center gap-2 bg-white/20 px-4 py-2 rounded-lg font-medium hover:bg-white/30 transition">
                <i class="ph ph-arrow-left"></i> Back to Dashboard
              </a>
              <a href="teacher_sidebar_page.php?active=students" class="inline-flex lg:hidden items-center gap-2 bg-white/10 px-4 py-2 rounded-lg border border-white/20 hover:bg-white/20 transition">
                <i class="ph ph-list"></i> Menu
              </a>
            </div>
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div class="rounded-xl bg-white/10 backdrop-blur p-3 ring-1 ring-white/20 text-center">
              <div class="text-xs text-white/80">Total</div>
              <div class="text-2xl font-extrabold"><?= (int)$total_students ?></div>
            </div>
            <div class="rounded-xl bg-white/10 backdrop-blur p-3 ring-1 ring-white/20 text-center">
              <div class="text-xs text-white/80">Active</div>
              <div class="text-2xl font-extrabold"><?= (int)$by_status['active'] ?></div>
            </div>
            <div class="rounded-xl bg-white/10 backdrop-blur p-3 ring-1 ring-white/20 text-center">
              <div class="text-xs text-white/80">Pending</div>
              <div class="text-2xl font-extrabold"><?= (int)$by_status['pending'] ?></div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Flash -->
    <?php if (!empty($flash)): 
      $cls = $flash_type === 'success' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' :
             ($flash_type === 'error' ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-blue-50 text-blue-700 ring-blue-200'); ?>
      <div class="animate-fade-in-up rounded-xl px-4 py-3 ring-1 <?= $cls ?>">
        <?= e($flash) ?>
      </div>
    <?php endif; ?>

    <!-- Filters + Search -->
    <section class="rounded-2xl bg-white/80 dark:bg-slate-900/60 ring-1 ring-slate-200 dark:ring-slate-800 shadow-sm p-4">
      <form method="get" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Course</label>
          <select name="course_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
            <option value="0">All courses</option>
            <?php foreach ($courses_list as $c): ?>
              <option value="<?= (int)$c['course_id'] ?>" <?= $filter_course===(int)$c['course_id']?'selected':'' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Status</label>
          <select name="status" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 focus:ring-indigo-600 focus:border-indigo-600">
            <option value="">All</option>
            <option value="active"    <?= $filter_status==='active'?'selected':'' ?>>Active</option>
            <option value="pending"   <?= $filter_status==='pending'?'selected':'' ?>>Pending</option>
            <option value="suspended" <?= $filter_status==='suspended'?'selected':'' ?>>Suspended</option>
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Search</label>
          <div class="relative mt-1">
            <input id="qSearch" type="text" placeholder="Name, username, email, course..."
                   class="w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 pl-10 pr-3 py-2 focus:ring-indigo-600 focus:border-indigo-600" />
            <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
          </div>
        </div>
        <div class="md:col-span-5 flex gap-2">
          <button class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg shadow">
            <i class="ph ph-funnel"></i> Apply Filters
          </button>
          <a href="teacher_students.php" class="inline-flex items-center gap-2 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-4 py-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700">
            <i class="ph ph-arrow-counter-clockwise"></i> Reset
          </a>
          <div class="ml-auto flex items-center gap-2">
            <button type="button" id="toggleViewTable" class="inline-flex items-center gap-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 px-4 py-2 rounded-lg text-sm">
              <i class="ph ph-table"></i> Table
            </button>
            <button type="button" id="toggleViewCards" class="inline-flex items-center gap-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 px-4 py-2 rounded-lg text-sm">
              <i class="ph ph-rows"></i> Cards
            </button>
          </div>
        </div>
      </form>
    </section>

    <!-- Students table -->
    <section class="rounded-2xl bg-white/80 dark:bg-slate-900/60 ring-1 ring-slate-200 dark:ring-slate-800 shadow-sm p-4">
      <?php if (!$students): ?>
        <div class="p-6 text-center text-slate-600 dark:text-slate-400">No students found for the current filters.</div>
      <?php else: ?>
        <!-- Table view -->
        <div id="viewTable" class="overflow-x-auto">
          <table id="studentsTable" class="min-w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-700 dark:text-slate-200 text-left">
              <tr>
                <th class="px-4 py-2">Student</th>
                <th class="px-4 py-2">Username</th>
                <th class="px-4 py-2">Email</th>
                <th class="px-4 py-2">Course</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800" id="rowsContainer">
              <?php foreach ($students as $st): 
                $badge = 'bg-slate-100 text-slate-700';
                if ($st['status']==='active') $badge = 'bg-emerald-100 text-emerald-700';
                elseif ($st['status']==='pending') $badge = 'bg-amber-100 text-amber-700';
                elseif ($st['status']==='suspended') $badge = 'bg-rose-100 text-rose-700';
                $fullName = trim(($st['first_name'] ?? '').' '.($st['last_name'] ?? ''));
                $initials = strtoupper(mb_substr($st['first_name'] ?? '',0,1).mb_substr($st['last_name'] ?? '',0,1));
                $searchKey = strtolower($fullName.' '.$st['username'].' '.$st['email'].' '.$st['course_name']);
              ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40" data-key="<?= e($searchKey) ?>">
                  <td class="px-4 py-2">
                    <div class="flex items-center gap-3">
                      <div class="h-9 w-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold">
                        <?= e($initials ?: 'ST') ?>
                      </div>
                      <div>
                        <div class="font-medium text-slate-900 dark:text-slate-100"><?= e($fullName) ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="px-4 py-2 text-slate-700 dark:text-slate-300">@<?= e($st['username']) ?></td>
                  <td class="px-4 py-2 text-slate-700 dark:text-slate-300"><?= e($st['email']) ?></td>
                  <td class="px-4 py-2 text-slate-700 dark:text-slate-300"><?= e($st['course_name']) ?></td>
                  <td class="px-4 py-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $badge ?>">
                      <?= e(ucfirst($st['status'])) ?>
                    </span>
                  </td>
                  <td class="px-4 py-2">
                    <div class="flex justify-end gap-2">
                      <!-- Update status -->
                      <form method="post" class="inline-flex items-center gap-2">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="enrollment_id" value="<?= (int)$st['enrollment_id'] ?>">
                        <select name="new_status" class="rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-600 focus:border-indigo-600">
                          <option value="active"    <?= $st['status']==='active'?'selected':'' ?>>Active</option>
                          <option value="pending"   <?= $st['status']==='pending'?'selected':'' ?>>Pending</option>
                          <option value="suspended" <?= $st['status']==='suspended'?'selected':'' ?>>Suspended</option>
                        </select>
                        <button class="inline-flex items-center gap-1 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm">
                          <i class="ph ph-check"></i> Save
                        </button>
                      </form>
                      <!-- Remove enrollment -->
                      <form method="post" class="inline-flex" onsubmit="return confirm('Remove this enrollment?');">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="remove_enrollment">
                        <input type="hidden" name="enrollment_id" value="<?= (int)$st['enrollment_id'] ?>">
                        <button class="inline-flex items-center gap-1 bg-rose-600 hover:bg-rose-700 text-white px-3 py-2 rounded-lg text-sm">
                          <i class="ph ph-trash"></i> Remove
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Cards view -->
        <div id="viewCards" class="hidden grid sm:grid-cols-2 lg:grid-cols-3 gap-4" aria-hidden="true">
          <?php foreach ($students as $st):
            $badge = 'bg-slate-100 text-slate-700';
            if ($st['status']==='active') $badge = 'bg-emerald-100 text-emerald-700';
            elseif ($st['status']==='pending') $badge = 'bg-amber-100 text-amber-700';
            elseif ($st['status']==='suspended') $badge = 'bg-rose-100 text-rose-700';
            $fullName = trim(($st['first_name'] ?? '').' '.($st['last_name'] ?? ''));
            $initials = strtoupper(mb_substr($st['first_name'] ?? '',0,1).mb_substr($st['last_name'] ?? '',0,1));
            $searchKey = strtolower($fullName.' '.$st['username'].' '.$st['email'].' '.$st['course_name']);
          ?>
            <div class="group rounded-2xl ring-1 ring-slate-200 dark:ring-slate-800 bg-white dark:bg-slate-900 shadow-sm hover-raise p-4" data-key="<?= e($searchKey) ?>">
              <div class="flex items-center gap-3 mb-2">
                <div class="h-10 w-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold">
                  <?= e($initials ?: 'ST') ?>
                </div>
                <div class="min-w-0">
                  <div class="font-semibold text-slate-900 dark:text-slate-100 truncate"><?= e($fullName) ?></div>
                  <div class="text-xs text-slate-500 dark:text-slate-400 truncate">@<?= e($st['username']) ?></div>
                </div>
              </div>
              <div class="text-sm text-slate-700 dark:text-slate-300 truncate"><?= e($st['email']) ?></div>
              <div class="text-sm text-slate-700 dark:text-slate-300 truncate"><?= e($st['course_name']) ?></div>
              <div class="mt-2">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $badge ?>">
                  <?= e(ucfirst($st['status'])) ?>
                </span>
              </div>
              <div class="mt-3 flex gap-2">
                <form method="post" class="inline-flex items-center gap-2">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="enrollment_id" value="<?= (int)$st['enrollment_id'] ?>">
                  <select name="new_status" class="rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-600 focus:border-indigo-600">
                    <option value="active"    <?= $st['status']==='active'?'selected':'' ?>>Active</option>
                    <option value="pending"   <?= $st['status']==='pending'?'selected':'' ?>>Pending</option>
                    <option value="suspended" <?= $st['status']==='suspended'?'selected':'' ?>>Suspended</option>
                  </select>
                  <button class="inline-flex items-center gap-1 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm">
                    <i class="ph ph-check"></i> Save
                  </button>
                </form>
                <form method="post" class="inline-flex ml-auto" onsubmit="return confirm('Remove this enrollment?');">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="remove_enrollment">
                  <input type="hidden" name="enrollment_id" value="<?= (int)$st['enrollment_id'] ?>">
                  <button class="inline-flex items-center gap-1 bg-rose-600 hover:bg-rose-700 text-white px-3 py-2 rounded-lg text-sm">
                    <i class="ph ph-trash"></i> Remove
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>

<script>
// Theme toggle (persist)
(function(){
  const root = document.documentElement;
  const pref = localStorage.getItem('theme');
  if (pref) root.classList.toggle('dark', pref === 'dark');
})();

// Client search for both table & cards
(function(){
  const search = document.getElementById('qSearch');
  const tableRows = Array.from(document.querySelectorAll('#rowsContainer > tr'));
  const cards = Array.from(document.querySelectorAll('#viewCards [data-key]'));
  if (!search) return;
  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    tableRows.forEach(tr => {
      const key = tr.getAttribute('data-key') || '';
      tr.style.display = (!q || key.includes(q)) ? '' : 'none';
    });
    cards.forEach(card => {
      const key = card.getAttribute('data-key') || '';
      card.style.display = (!q || key.includes(q)) ? '' : 'none';
    });
  });
})();

// Export table (visible rows) to CSV
document.getElementById('btnExportCSV')?.addEventListener('click', () => {
  const table = document.getElementById('studentsTable');
  if (!table) return;
  const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim()).slice(0,5); // ignore actions
  const rows = Array.from(table.querySelectorAll('tbody tr')).filter(tr => tr.style.display !== 'none');
  const data = [headers];
  rows.forEach(tr => {
    const cols = Array.from(tr.children).slice(0, 5); // Student..Status
    data.push(cols.map(td => td.innerText.replace(/\s+/g, ' ').trim()));
  });
  const csv = data.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = 'students.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
});

// Copy filtered emails
document.getElementById('btnCopyEmails')?.addEventListener('click', async () => {
  const rows = Array.from(document.querySelectorAll('#rowsContainer > tr')).filter(tr => tr.style.display !== 'none');
  const emails = rows.map(tr => tr.children[2]?.innerText?.trim()).filter(Boolean);
  if (!emails.length) { alert('No emails to copy.'); return; }
  try {
    await navigator.clipboard.writeText(emails.join(', '));
    alert('Emails copied to clipboard.');
  } catch {
    // fallback
    const ta = document.createElement('textarea');
    ta.value = emails.join(', ');
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    alert('Emails copied to clipboard.');
  }
});

// Toggle views
(function(){
  const btnTable = document.getElementById('toggleViewTable');
  const btnCards = document.getElementById('toggleViewCards');
  const viewTable = document.getElementById('viewTable');
  const viewCards = document.getElementById('viewCards');
  if (!btnTable || !btnCards || !viewTable || !viewCards) return;

  const setView = (mode) => {
    if (mode === 'cards') {
      viewCards.classList.remove('hidden'); viewCards.setAttribute('aria-hidden','false');
      viewTable.classList.add('hidden'); viewTable.setAttribute('aria-hidden','true');
      localStorage.setItem('studentsView','cards');
    } else {
      viewTable.classList.remove('hidden'); viewTable.setAttribute('aria-hidden','false');
      viewCards.classList.add('hidden'); viewCards.setAttribute('aria-hidden','true');
      localStorage.setItem('studentsView','table');
    }
  };

  btnTable.addEventListener('click', () => setView('table'));
  btnCards.addEventListener('click', () => setView('cards'));

  // restore preference
  const pref = localStorage.getItem('studentsView') || 'table';
  setView(pref);
})();
</script>
</body>
</html>