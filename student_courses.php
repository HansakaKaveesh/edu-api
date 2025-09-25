<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die("Access denied");
}

$user_id = (int)$_SESSION['user_id'];

// Fetch active enrollments with course + type + teacher
$courses = [];
$boards = [];
$levels = [];

$sql = "
    SELECT c.course_id, c.name, ct.board, ct.level,
           GROUP_CONCAT(CONCAT(t.first_name, ' ', t.last_name) SEPARATOR ', ') AS teachers
    FROM enrollments e
    JOIN courses c ON e.course_id = c.course_id
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    LEFT JOIN teacher_courses tc ON c.course_id = tc.course_id
    LEFT JOIN teachers t ON tc.teacher_id = t.teacher_id
    WHERE e.user_id = ? AND e.status = 'active'
    GROUP BY c.course_id, c.name, ct.board, ct.level
    ORDER BY c.name ASC
";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $courses[] = [
            'course_id' => (int)$row['course_id'],
            'name'      => $row['name'],
            'board'     => $row['board'],
            'level'     => $row['level'],
            'teacher'   => $row['teachers'] ?? ''
        ];
        if (!empty($row['board'])) $boards[$row['board']] = true;
        if (!empty($row['level'])) $levels[$row['level']] = true;
    }
    $stmt->close();
}
$boardOptions = array_keys($boards);
$levelOptions = array_keys($levels);
sort($boardOptions);
sort($levelOptions);
$total = count($courses);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Enrolled Courses</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="./images/logo.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Ionicons -->
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
      html, body { font-family: "Inter", ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; }
      @keyframes fadeUp { from { opacity:0; transform: translateY(10px);} to { opacity:1; transform: translateY(0);} }
      .animate-fadeUp { animation: fadeUp .45s ease-out both; }
      .bg-bubbles::before, .bg-bubbles::after {
        content:""; position:absolute; border-radius:9999px; filter: blur(40px); opacity:.25; z-index:0; pointer-events:none;
      }
      .bg-bubbles::before { width:420px; height:420px; background: radial-gradient(closest-side,#60a5fa,transparent 70%); top:-80px; left:-80px; }
      .bg-bubbles::after  { width:500px; height:500px; background: radial-gradient(closest-side,#a78bfa,transparent 70%); bottom:-120px; right:-120px; }

      /* Chips */
      .chip { display:inline-flex; align-items:center; gap:.4rem; padding:.28rem .6rem; border-radius:9999px; font-size:.72rem; font-weight:600; border-width:1px; white-space:nowrap; }
      .chip-gray   { background:#f8fafc;   color:#334155; border-color:#e2e8f0; }
      .chip-blue   { background:#eff6ff;   color:#1e3a8a; border-color:#bfdbfe; }
      .chip-purple { background:#f5f3ff;   color:#5b21b6; border-color:#ddd6fe; }
      .chip-rose   { background:#fff1f2;   color:#9f1239; border-color:#fecdd3; }
      .chip-emerald{ background:#ecfdf5;   color:#065f46; border-color:#a7f3d0; }

      /* Card hover */
      .card { transition: box-shadow .2s ease, transform .2s ease; }
      .card:hover { box-shadow: 0 14px 28px rgba(15,23,42,.09); transform: translateY(-1px); }
    </style>
</head>
<body class="bg-gradient-to-br from-sky-50 via-white to-indigo-50 min-h-screen font-sans text-gray-800 antialiased">

<?php include 'components/navbar.php'; ?>
<div class="fixed inset-0 bg-bubbles -z-10"></div>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-6 lg:px-10 py-28 gap-8">

  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full space-y-8 animate-fadeUp">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-2">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600/90 text-white shadow-sm">
          <ion-icon name="library-outline" class="text-xl"></ion-icon>
        </span>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-800">My Enrolled Courses</h2>
        <span class="chip chip-gray ml-1">
          <ion-icon name="albums-outline"></ion-icon>
          <?= (int)$total ?>
        </span>
      </div>
      <a href="student_dashboard.php" class="inline-flex items-center gap-1.5 text-indigo-600 hover:text-indigo-800 font-medium">
        <ion-icon name="arrow-back-outline" class="text-lg"></ion-icon>
        Back to Dashboard
      </a>
    </div>

    <?php if ($total === 0): ?>
      <div class="bg-white/80 backdrop-blur-sm p-8 rounded-2xl shadow-xl border border-gray-100 text-center">
        <div class="inline-flex items-center justify-center h-12 w-12 rounded-full bg-indigo-50 text-indigo-600 mb-2">
          <ion-icon name="information-circle-outline" class="text-2xl"></ion-icon>
        </div>
        <p class="text-gray-700 text-lg">No courses enrolled yet.</p>
        <a href="enroll_course.php" class="mt-4 inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition">
          <ion-icon name="add-circle-outline" class="text-xl"></ion-icon>
          Enroll Here
        </a>
      </div>
    <?php else: ?>

      <!-- Controls -->
      <div class="bg-white/80 backdrop-blur-sm p-4 sm:p-5 rounded-2xl shadow border border-gray-100">
        <div class="flex items-center gap-2 text-sm text-gray-600 mb-3">
          <ion-icon name="filter-outline" class="text-indigo-600"></ion-icon>
          Refine your list
        </div>
        <div class="flex flex-col md:flex-row md:items-center gap-3 md:gap-4">
          <div class="relative flex-1 min-w-[240px]">
            <input id="searchInput" type="text" placeholder="Search by course, board, or level..."
                   class="w-full rounded-full bg-white/80 border border-gray-200 px-4 py-2.5 pl-11 shadow-sm focus:ring-2 focus:ring-indigo-500/40 focus:outline-none" aria-label="Search courses">
            <ion-icon name="search-outline" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xl"></ion-icon>
          </div>
          <div class="flex gap-2">
            <div class="relative">
              <ion-icon name="school-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
              <select id="boardFilter" class="pl-9 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500/40">
                <option value="">All Boards</option>
                <?php foreach ($boardOptions as $b): ?>
                  <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="relative">
              <ion-icon name="layers-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
              <select id="levelFilter" class="pl-9 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500/40">
                <option value="">All Levels</option>
                <?php foreach ($levelOptions as $l): ?>
                  <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="relative">
              <ion-icon name="swap-vertical-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></ion-icon>
              <select id="sortSelect" class="pl-9 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500/40">
                <option value="name-asc">Sort: Name A–Z</option>
                <option value="name-desc">Sort: Name Z–A</option>
              </select>
            </div>
          </div>
        </div>
        <div class="mt-2 text-sm text-gray-500 inline-flex items-center gap-2">
          <ion-icon name="stats-chart-outline" class="text-slate-500"></ion-icon>
          Showing <span id="shownCount"><?= $total ?></span> of <?= $total ?> courses
        </div>
      </div>

      <!-- Courses Grid -->
      <div id="coursesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($courses as $c): ?>
          <div class="course-card card group bg-white/80 backdrop-blur-sm rounded-2xl shadow-md border border-gray-100 p-6 relative overflow-hidden"
               data-name="<?= htmlspecialchars(mb_strtolower($c['name'])) ?>"
               data-board="<?= htmlspecialchars(mb_strtolower($c['board'])) ?>"
               data-level="<?= htmlspecialchars(mb_strtolower($c['level'])) ?>">
            <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition bg-gradient-to-br from-indigo-500/5 via-transparent to-cyan-500/10"></div>
            <div class="relative z-10">
              <div class="flex items-start justify-between gap-3">
                <h3 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($c['name']) ?></h3>
                <span class="inline-flex items-center justify-center h-9 w-9 rounded-full bg-indigo-50 text-indigo-600 border border-indigo-100">
                  <ion-icon name="book-outline" class="text-xl"></ion-icon>
                </span>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <?php if (!empty($c['board'])): ?>
                  <span class="chip chip-blue">
                    <ion-icon name="school-outline"></ion-icon>
                    <?= htmlspecialchars($c['board']) ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($c['level'])): ?>
                  <span class="chip chip-purple">
                    <ion-icon name="layers-outline"></ion-icon>
                    <?= htmlspecialchars($c['level']) ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($c['teacher'])): ?>
                  <span class="chip chip-rose">
                    <ion-icon name="people-outline"></ion-icon>
                    <?= htmlspecialchars($c['teacher']) ?>
                  </span>
                <?php endif; ?>
                <span class="chip chip-emerald">
                  <ion-icon name="checkmark-circle-outline"></ion-icon>
                  Active
                </span>
              </div>
              <div class="mt-5 flex items-center justify-between">
                <a href="course.php?course_id=<?= (int)$c['course_id'] ?>"
                   class="inline-flex items-center gap-2 bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition">
                  <ion-icon name="arrow-forward-circle-outline" class="text-xl"></ion-icon>
                  Go to Course
                </a>
                <a href="course.php?course_id=<?= (int)$c['course_id'] ?>" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 text-sm group-hover:translate-x-0.5 transition">
                  Details
                  <ion-icon name="chevron-forward-outline"></ion-icon>
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- No results (filtered) -->
      <div id="noResults" class="hidden bg-white/80 backdrop-blur-sm p-8 rounded-2xl shadow border border-gray-100 text-center">
        <div class="inline-flex items-center justify-center h-12 w-12 rounded-full bg-slate-50 text-slate-600 mb-2">
          <ion-icon name="filter-circle-outline" class="text-2xl"></ion-icon>
        </div>
        <p class="text-gray-700">No courses match your filters.</p>
        <button id="clearFilters" class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50">
          <ion-icon name="refresh-outline"></ion-icon>
          Clear filters
        </button>
      </div>

    <?php endif; ?>
  </main>
</div>

<?php include 'components/footer.php'; ?>

<script>
  // Filtering + sorting
  const grid = document.getElementById('coursesGrid');
  const cards = grid ? Array.from(grid.getElementsByClassName('course-card')) : [];
  const shownCount = document.getElementById('shownCount');
  const searchInput = document.getElementById('searchInput');
  const boardFilter = document.getElementById('boardFilter');
  const levelFilter = document.getElementById('levelFilter');
  const sortSelect = document.getElementById('sortSelect');
  const noResults = document.getElementById('noResults');
  const clearBtn = document.getElementById('clearFilters');

  function apply() {
    const q = (searchInput?.value || '').toLowerCase().trim();
    const b = (boardFilter?.value || '').toLowerCase();
    const l = (levelFilter?.value || '').toLowerCase();

    let visible = [];

    cards.forEach(card => {
      const name = card.dataset.name || '';
      const board = card.dataset.board || '';
      const level = card.dataset.level || '';

      const matchesText  = !q || name.includes(q) || board.includes(q) || level.includes(q);
      const matchesBoard = !b || board === b;
      const matchesLevel = !l || level === l;

      const show = matchesText && matchesBoard && matchesLevel;
      card.style.display = show ? '' : 'none';
      if (show) visible.push(card);
    });

    // Sort visible cards
    if (sortSelect) {
      const mode = sortSelect.value;
      visible.sort((a, b) => {
        const an = a.dataset.name || '';
        const bn = b.dataset.name || '';
        if (mode === 'name-desc') return bn.localeCompare(an);
        return an.localeCompare(bn);
      });
      visible.forEach(el => grid.appendChild(el));
    }

    if (shownCount) shownCount.textContent = String(visible.length);
    if (noResults) noResults.classList.toggle('hidden', visible.length > 0);
  }

  searchInput?.addEventListener('input', apply);
  boardFilter?.addEventListener('change', apply);
  levelFilter?.addEventListener('change', apply);
  sortSelect?.addEventListener('change', apply);
  clearBtn?.addEventListener('click', () => {
    if (searchInput) searchInput.value = '';
    if (boardFilter) boardFilter.value = '';
    if (levelFilter) levelFilter.value = '';
    apply();
  });

  apply();
</script>
</body>
</html>