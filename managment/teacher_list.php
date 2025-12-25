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

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// Check if enrollments table exists (for metrics)
$hasEnrollmentsRes = $conn->query("SHOW TABLES LIKE 'enrollments'");
$hasEnrollments    = $hasEnrollmentsRes && $hasEnrollmentsRes->num_rows > 0;
if ($hasEnrollmentsRes) $hasEnrollmentsRes->free();

/* --------------------------------
   Fetch teachers with metrics
-----------------------------------*/
$teachers = [];
$sql = "
  SELECT
    t.teacher_id,
    t.user_id,
    t.first_name,
    t.last_name,
    t.email,
    u.username,
    u.status,
    u.created_at,
    COALESCE((
      SELECT COUNT(DISTINCT tc.course_id)
      FROM teacher_courses tc
      WHERE tc.teacher_id = t.teacher_id
    ), 0) AS course_count,
    COALESCE((
      SELECT COUNT(*)
      FROM contents cnt
      WHERE cnt.course_id IN (
        SELECT tc2.course_id
        FROM teacher_courses tc2
        WHERE tc2.teacher_id = t.teacher_id
      )
    ), 0) AS content_count
    " . ($hasEnrollments ? ",
    COALESCE((
      SELECT COUNT(*)
      FROM enrollments e
      WHERE e.course_id IN (
        SELECT tc3.course_id
        FROM teacher_courses tc3
        WHERE tc3.teacher_id = t.teacher_id
      )
    ), 0) AS enrollment_count" : ", 0 AS enrollment_count") . "
  FROM teachers t
  JOIN users u ON u.user_id = t.user_id
  ORDER BY t.first_name, t.last_name
";

if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $teachers[] = [
            'teacher_id'        => (int)$row['teacher_id'],
            'user_id'           => (int)$row['user_id'],
            'first_name'        => $row['first_name'] ?? '',
            'last_name'         => $row['last_name'] ?? '',
            'email'             => $row['email'] ?? '',
            'username'          => $row['username'] ?? '',
            'status'            => $row['status'] ?? '',
            'created_at'        => $row['created_at'] ?? null,
            'course_count'      => (int)$row['course_count'],
            'content_count'     => (int)$row['content_count'],
            'enrollment_count'  => (int)$row['enrollment_count'],
        ];
    }
    $res->free();
}

$totalTeachers      = count($teachers);
$totalCoursesLinked = 0;
$totalContents      = 0;
$totalEnrollments   = 0;
$withCourses        = 0;
$withContent        = 0;

foreach ($teachers as $t) {
    $totalCoursesLinked += $t['course_count'];
    $totalContents      += $t['content_count'];
    $totalEnrollments   += $t['enrollment_count'];
    if ($t['course_count']  > 0) $withCourses++;
    if ($t['content_count'] > 0) $withContent++;
}

$avgCoursesPerTeacher = $totalTeachers ? round($totalCoursesLinked / $totalTeachers, 1) : 0;
$avgContentsPerTeacher = $totalTeachers ? round($totalContents / $totalTeachers, 1) : 0;
$percentWithCourses   = $totalTeachers ? round(($withCourses / $totalTeachers) * 100) : 0;
$percentWithContent   = $totalTeachers ? round(($withContent / $totalTeachers) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Teachers Overview</title>
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
          <span><?= ($role === 'coordinator' ? 'Coordinator' : 'Admin') ?> · Teachers</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
          <span><?= htmlspecialchars(ucfirst($role)) ?> access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Teachers Overview
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            See all teachers, their course assignments, content contribution, and enrollment reach.
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
          <button type="button" onclick="downloadTeachersCSV()"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700 shadow-sm active:scale-[0.99]">
            <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Export CSV
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
    <section class="lg:col-span-9 space-y-5">
      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-[11px] text-slate-500 uppercase tracking-wide">Teachers</p>
              <p class="mt-1 text-xl sm:text-2xl font-semibold text-slate-900"><?= $totalTeachers ?></p>
            </div>
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
              <i data-lucide="users" class="w-4 h-4"></i>
            </span>
          </div>
          <p class="mt-1 text-[11px] text-slate-500">
            <?= $withCourses ?> with courses · <?= $withContent ?> with content
          </p>
        </div>

        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-[11px] text-slate-500 uppercase tracking-wide">Course load</p>
              <p class="mt-1 text-xl sm:text-2xl font-semibold text-slate-900"><?= $avgCoursesPerTeacher ?></p>
            </div>
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
              <i data-lucide="layers" class="w-4 h-4"></i>
            </span>
          </div>
          <p class="mt-1 text-[11px] text-slate-500">
            Avg courses per teacher
          </p>
        </div>

        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-[11px] text-slate-500 uppercase tracking-wide">Content</p>
              <p class="mt-1 text-xl sm:text-2xl font-semibold text-slate-900"><?= $avgContentsPerTeacher ?></p>
            </div>
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
              <i data-lucide="file-text" class="w-4 h-4"></i>
            </span>
          </div>
          <p class="mt-1 text-[11px] text-slate-500">
            Avg items per teacher
          </p>
        </div>

        <div class="glass-card rounded-2xl p-3 sm:p-4">
          <p class="text-[11px] text-slate-500 uppercase tracking-wide">Coverage</p>
          <div class="mt-1 space-y-1">
            <div class="flex items-center justify-between text-[11px] text-slate-600">
              <span>With courses</span>
              <span><?= $percentWithCourses ?>%</span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full bg-indigo-500 rounded-full" style="width: <?= $percentWithCourses ?>%"></div>
            </div>
            <div class="flex items-center justify-between text-[11px] text-slate-600 mt-1">
              <span>With content</span>
              <span><?= $percentWithContent ?>%</span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full bg-sky-500 rounded-full" style="width: <?= $percentWithContent ?>%"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200 p-3">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-2.5 items-center">
          <div class="relative md:col-span-2">
            <input id="searchInput" type="text"
                   placeholder="Search name, email or username..."
                   class="w-full pl-8 pr-2.5 py-2 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   aria-label="Search teachers">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
          </div>
          <div class="flex gap-2">
            <select id="statusFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
          <div class="flex gap-2">
            <select id="coverageFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">Course coverage</option>
              <option value="has">With courses</option>
              <option value="none">No course</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Teachers table -->
      <div class="overflow-hidden rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200">
        <?php if ($totalTeachers > 0): ?>
          <div class="overflow-x-auto" id="teachersTableWrapper">
            <table id="teachersTable" class="min-w-full text-left border-collapse">
              <thead>
                <tr class="text-slate-700 text-[11px] sm:text-xs bg-slate-50/90 uppercase tracking-wide">
                  <th class="sticky px-3 py-2 border-b border-slate-200">ID</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Name</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Email</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Username</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Status</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Courses</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Contents</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Enrollments</th>
                </tr>
              </thead>
              <tbody class="text-[11px] sm:text-xs">
                <?php foreach ($teachers as $t):
                    $tid   = $t['teacher_id'];
                    $uid   = $t['user_id'];
                    $fn    = $t['first_name'];
                    $ln    = $t['last_name'];
                    $full  = trim("$fn $ln") ?: '—';
                    $email = $t['email'];
                    $usern = $t['username'];
                    $status = strtolower($t['status'] ?? '');
                    $searchKey = strtolower(trim($full . ' ' . $email . ' ' . $usern));

                    // Status badge
                    $statusBadge = 'bg-slate-100 text-slate-700 border-slate-200';
                    $statusIcon  = 'circle-help';
                    if ($status === 'active') {
                        $statusBadge = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                        $statusIcon  = 'check-circle-2';
                    } elseif ($status === 'inactive') {
                        $statusBadge = 'bg-slate-100 text-slate-700 border-slate-200';
                        $statusIcon  = 'pause-circle';
                    } elseif ($status === 'suspended') {
                        $statusBadge = 'bg-amber-50 text-amber-700 border-amber-200';
                        $statusIcon  = 'ban';
                    }
                    $hasCourses = $t['course_count'] > 0;
                ?>
                  <tr class="hover:bg-slate-50 even:bg-slate-50/40"
                      data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>"
                      data-hascourses="<?= $hasCourses ? '1' : '0' ?>"
                      data-key="<?= htmlspecialchars($searchKey, ENT_QUOTES) ?>">
                    <td class="px-3 py-2 border-b border-slate-100 text-slate-600">
                      <?= $tid ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <div class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($full) ?></div>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <span class="inline-flex items-center gap-1 text-slate-700 truncate">
                        <i data-lucide="mail" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span class="truncate"><?= htmlspecialchars($email ?: '—') ?></span>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="at-sign" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= htmlspecialchars($usern ?: '—') ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium border <?= $statusBadge ?>">
                        <i data-lucide="<?= $statusIcon ?>" class="w-3.5 h-3.5"></i>
                        <?= htmlspecialchars(ucfirst($status ?: 'Unknown')) ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="layers" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $t['course_count'] ?>
                      </span>
                      <?php if (!$hasCourses): ?>
                        <span class="ml-1 inline-flex items-center gap-1 text-[10px] text-rose-600">
                          <i data-lucide="alert-circle" class="w-3 h-3"></i>No course
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $t['content_count'] ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100">
                      <span class="inline-flex items-center gap-1 text-slate-700">
                        <i data-lucide="users-2" class="w-3.5 h-3.5 text-slate-400"></i>
                        <?= $t['enrollment_count'] ?>
                      </span>
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
                <p class="font-medium">No teachers found.</p>
                <p class="text-xs text-slate-500 mt-0.5">
                  Teachers are created via the Users administration panel.
                </p>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
</main>
<script>
  if (window.lucide) {
    window.lucide.createIcons();
  }

  const searchInput   = document.getElementById('searchInput');
  const statusFilter  = document.getElementById('statusFilter');
  const coverageFilter= document.getElementById('coverageFilter');
  const rows          = [...document.querySelectorAll('#teachersTable tbody tr')];

  function applyFilters() {
    const q      = (searchInput?.value || '').toLowerCase().trim();
    const status = (statusFilter?.value || '').toLowerCase();
    const cov    = (coverageFilter?.value || '').toLowerCase();

    rows.forEach(tr => {
      const key   = (tr.getAttribute('data-key') || '').toLowerCase();
      const s     = (tr.getAttribute('data-status') || '').toLowerCase();
      const hasC  = tr.getAttribute('data-hascourses') === '1';

      const matchQ = !q || key.includes(q);
      const matchS = !status || s === status;
      const matchC = !cov ||
                     (cov === 'has' && hasC) ||
                     (cov === 'none' && !hasC);

      const show = matchQ && matchS && matchC;
      tr.style.display = show ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
  coverageFilter?.addEventListener('change', applyFilters);
  applyFilters();

  function downloadTeachersCSV() {
    const table = document.getElementById('teachersTable');
    if (!table) return;

    const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
    const visibleRows = [...table.querySelectorAll('tbody tr')].filter(r => r.style.display !== 'none');

    const data = [headers];
    visibleRows.forEach(row => {
      const cols = [...row.children].map(td =>
        td.innerText.replace(/\s+/g, ' ').trim()
      );
      data.push(cols);
    });

    const csv = data
      .map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))
      .join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'teachers_report.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>