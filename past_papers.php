<?php
session_start();
require_once __DIR__ . '/db_connect.php';
if ($conn instanceof mysqli) {
  $conn->set_charset('utf8mb4');
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Track downloads and redirect
if (isset($_GET['dl'])) {
  $id = (int)$_GET['dl'];
  if ($id > 0) {
    if ($stmt = $conn->prepare("SELECT qp_url FROM past_papers WHERE paper_id=? AND visibility='public'")) {
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $stmt->bind_result($url);
      if ($stmt->fetch() && $url) {
        $stmt->close();
        $upd = $conn->prepare("UPDATE past_papers SET download_count = download_count + 1 WHERE paper_id=?");
        $upd->bind_param('i', $id);
        $upd->execute();
        $upd->close();
        header("Location: " . $url);
        exit;
      }
      $stmt->close();
    }
  }
  http_response_code(404);
  exit('Paper not found.');
}

// Filter params
$BOARD_OPTS  = ['Cambridge','Edexcel','Local','Other'];
$LEVEL_OPTS  = ['IGCSE','A/L','O/L','Others'];
$SESSIONS    = ['May/Jun','Oct/Nov','Feb/Mar','Specimen','Other'];
$SORTS       = [
  'newest'    => 'p.year DESC, p.session DESC, COALESCE(c.name, p.subject) ASC',
  'oldest'    => 'p.year ASC, p.session ASC, COALESCE(c.name, p.subject) ASC',
  'popular'   => 'p.download_count DESC, p.year DESC',
  'subject_az'=> 'COALESCE(c.name, p.subject) ASC, p.year DESC'
];

$q         = trim($_GET['q'] ?? '');
$board     = $_GET['board'] ?? '';
$level     = $_GET['level'] ?? '';
$courseId  = (int)($_GET['course'] ?? 0);
$yearFrom  = (int)($_GET['year_from'] ?? 0);
$yearTo    = (int)($_GET['year_to'] ?? 0);
$session   = $_GET['session'] ?? '';
$sortKey   = $_GET['sort'] ?? 'newest';
$sortSql   = $SORTS[$sortKey] ?? $SORTS['newest'];
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 12;
$offset    = ($page - 1) * $perPage;
$view      = $_GET['view'] ?? 'grid';
$view      = in_array($view, ['grid','list'], true) ? $view : 'grid';

// Build WHERE
$where = ["p.visibility='public'"];
$types = '';
$params = [];

if ($q !== '') {
  $where[] = "(COALESCE(c.name, p.subject) LIKE ? OR p.syllabus_code LIKE ? OR p.tags LIKE ?)";
  $like = "%$q%";
  $types .= 'sss';
  $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($board && in_array($board, $BOARD_OPTS, true)) {
  $where[] = "p.board = ?";
  $types .= 's'; $params[] = $board;
}
if ($level && in_array($level, $LEVEL_OPTS, true)) {
  $where[] = "p.level = ?";
  $types .= 's'; $params[] = $level;
}
if ($courseId > 0) {
  $where[] = "p.course_id = ?";
  $types .= 'i'; $params[] = $courseId;
}
if ($session && in_array($session, $SESSIONS, true)) {
  $where[] = "p.session = ?";
  $types .= 's'; $params[] = $session;
}
if ($yearFrom && $yearTo) {
  if ($yearFrom > $yearTo) { [$yearFrom, $yearTo] = [$yearTo, $yearFrom]; }
  $where[] = "p.year BETWEEN ? AND ?";
  $types .= 'ii'; $params[] = $yearFrom; $params[] = $yearTo;
} elseif ($yearFrom) {
  $where[] = "p.year >= ?";
  $types .= 'i'; $params[] = $yearFrom;
} elseif ($yearTo) {
  $where[] = "p.year <= ?";
  $types .= 'i'; $params[] = $yearTo;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count total for current filters
$total = 0;
$sqlCount = "SELECT COUNT(*)
             FROM past_papers p
             LEFT JOIN courses c ON c.course_id = p.course_id
             $whereSql";
$stmt = $conn->prepare($sqlCount);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($total);
$stmt->fetch();
$stmt->close();

// Fetch rows
$sql = "SELECT p.paper_id, p.board, p.level, p.subject, p.syllabus_code,
               p.year, p.session, p.paper_code, p.variant,
               p.qp_url, p.ms_url, p.solution_url, p.download_count,
               COALESCE(c.name, p.subject) AS course_name
        FROM past_papers p
        LEFT JOIN courses c ON c.course_id = p.course_id
        $whereSql
        ORDER BY $sortSql
        LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Courses for dependent select (board->level->courses)
$cr = $conn->query("
  SELECT c.course_id, c.name, ct.board, ct.level
  FROM courses c
  JOIN course_types ct ON ct.course_type_id = c.course_type_id
  ORDER BY ct.board, ct.level, c.name
");
$coursesByBL = [];
if ($cr) {
  while ($r = $cr->fetch_assoc()) {
    $coursesByBL[$r['board']][$r['level']][] = [
      'id' => (int)$r['course_id'],
      'name' => $r['name'],
    ];
  }
}

// Pagination data
$totalPages = max(1, (int)ceil($total / $perPage));
$showFrom   = $total ? ($offset + 1) : 0;
$showTo     = min($offset + $perPage, $total);

// Helper for querystring
function qs($more = []) {
  $base = $_GET; unset($base['page']);
  $q = array_merge($base, $more);
  return '?' . http_build_query($q);
}

// Build compact pagination window
function page_window($page, $totalPages) {
  if ($totalPages <= 7) return range(1, $totalPages);
  $set = [1, 2, $totalPages-1, $totalPages, $page-1, $page, $page+1];
  $set = array_values(array_unique(array_filter($set, function($p) use ($totalPages) {
    return $p >= 1 && $p <= $totalPages;
  })));
  sort($set);
  $out = [];
  $prev = null;
  foreach ($set as $p) {
    if ($prev !== null && $p - $prev > 1) $out[] = '...';
    $out[] = $p;
    $prev = $p;
  }
  return $out;
}
$pagesArr = page_window($page, $totalPages);

// Simple stats for hero (based on current filters)
$metaStats = [
  'total'    => $total,
  'boards'   => count($BOARD_OPTS),
  'levels'   => count($LEVEL_OPTS),
  'sessions' => count($SESSIONS),
];

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Past Papers</title>
  <meta name="description" content="Find and download past exam papers, mark schemes, and solutions with advanced filters." />
  <meta name="theme-color" content="#2563eb" />

  <script src="https://cdn.tailwindcss.com"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <link rel="icon" type="image/png" href="./images/logo.png" />

  <style>
    body{
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      color:#0f172a; background:#fff;
    }

    /* Reveal */
    .reveal{ opacity:0; transform:translateY(14px); transition:opacity .6s ease, transform .6s ease; }
    .reveal.in-view{ opacity:1; transform:translateY(0); }

    /* Hero */
    .hero-papers{
      position:relative; overflow:hidden;
      background:
        radial-gradient(circle at 12% 10%, rgba(191,219,254,.65), transparent 55%),
        radial-gradient(circle at 80% 20%, rgba(99,102,241,.45), transparent 55%),
        radial-gradient(circle at 70% 90%, rgba(34,211,238,.35), transparent 55%),
        linear-gradient(to bottom right, #0b1220, #1d4ed8);
    }
    .hero-grid{
      background-image:
        linear-gradient(to right, rgba(255,255,255,.06) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255,255,255,.06) 1px, transparent 1px);
      background-size: 46px 46px;
      mask-image: radial-gradient(circle at 30% 30%, black 35%, transparent 70%);
      opacity:.55;
      position:absolute; inset:0; pointer-events:none;
    }
    .glass{
      background: rgba(255,255,255,.08);
      border: 1px solid rgba(255,255,255,.14);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    /* Cards */
    .card-soft{
      background: rgba(255,255,255,.98);
      border-radius: 1.25rem;
      border: 1px solid rgba(226,232,240,.95);
      box-shadow: 0 18px 50px rgba(15,23,42,.06), 0 1px 0 rgba(148,163,184,.25);
    }
    .tilt{
      will-change: transform;
      transform-style: preserve-3d;
      transition: transform .18s ease, box-shadow .18s ease;
    }
    .tilt:hover{
      box-shadow: 0 24px 65px rgba(15,23,42,.16);
    }

    /* Filter chips */
    .filter-chip{
      display:inline-flex; align-items:center; gap:.5rem;
      padding:.55rem 1rem; border-radius:9999px;
      font-size:.78rem; font-weight:800; cursor:pointer;
      transition: all .2s ease; user-select:none; white-space:nowrap;
    }
    .filter-chip-active{
      background: linear-gradient(90deg, #2563eb, #4f46e5);
      color:#fff;
      box-shadow: 0 14px 24px rgba(37,99,235,.25);
    }
    .filter-chip-inactive{
      background:#fff; color:#334155; border:1px solid #e2e8f0;
    }
    .filter-chip-inactive:hover{ background:#f8fafc; }

    /* Small chips (active filter summary) */
    .chip{
      display:inline-flex; align-items:center; gap:.35rem;
      padding:.25rem .65rem; border-radius:9999px;
      font-size:.7rem; font-weight:600;
      background:#eff6ff; color:#1d4ed8; border:1px solid #dbeafe;
    }

    /* Buttons */
    .btn-primary{
      display:inline-flex; align-items:center; gap:.4rem;
      padding:.45rem 1rem; border-radius:.9rem;
      font-size:.8rem; font-weight:700; color:#fff;
      background: linear-gradient(120deg,#2563eb,#4f46e5);
      box-shadow:0 16px 30px rgba(37,99,235,.35);
      transition:all .18s ease;
    }
    .btn-primary:hover{
      filter:brightness(1.05);
      box-shadow:0 20px 40px rgba(37,99,235,.4);
      transform:translateY(-1px);
    }
    .btn-ghost{
      display:inline-flex; align-items:center; gap:.4rem;
      padding:.4rem .9rem; border-radius:.9rem;
      border:1px solid #e2e8f0; background:#fff;
      font-size:.8rem; font-weight:600; color:#334155;
      transition:all .18s ease;
    }
    .btn-ghost:hover{
      background:#f8fafc;
      box-shadow:0 10px 25px rgba(15,23,42,.08);
      transform:translateY(-1px);
    }

    /* Back-to-top */
    #backToTop{ box-shadow: 0 16px 30px rgba(37,99,235,.35); }
    #backToTop:hover{ box-shadow: 0 20px 40px rgba(37,99,235,.45); }

    @media (prefers-reduced-motion: reduce){
      .reveal, .tilt{ transition:none; }
    }
  </style>
</head>

<body class="bg-white text-gray-800 flex flex-col min-h-screen overflow-x-hidden">
  <div id="top"></div>

  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 bg-blue-600 text-white px-4 py-2 rounded">
    Skip to content
  </a>

  <?php if (file_exists(__DIR__ . '/components/navbar.php')) include __DIR__ . '/components/navbar.php'; ?>

  <!-- HERO -->
  <section class="hero-papers text-white">
    <div class="hero-grid"></div>

    <div class="max-w-6xl mx-auto px-6 pt-16 pb-12 sm:pt-20 sm:pb-16">
      <div id="main" class="reveal">
        <div class="inline-flex items-center gap-2 text-xs font-extrabold tracking-[0.22em] uppercase text-blue-100/90">
          <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl glass">
            <ion-icon name="document-text-outline" class="text-lg"></ion-icon>
          </span>
          Past Papers
        </div>

        <h1 class="mt-4 text-3xl sm:text-4xl md:text-5xl font-extrabold leading-tight">
          Find the right paper
          <span class="text-transparent bg-clip-text bg-gradient-to-r from-yellow-300 via-white to-cyan-200">
            in seconds
          </span>
        </h1>

        <p class="mt-4 max-w-2xl text-blue-100/90 text-base sm:text-lg">
          Search by subject, code, board, session, or year. Download question papers, mark schemes, and solutions instantly.
        </p>

        <!-- Stats (based on current filters) -->
        <div class="mt-7 grid grid-cols-2 sm:grid-cols-4 gap-3 max-w-2xl">
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Matching papers</p>
            <p class="text-xl font-extrabold mt-1"><?= number_format($metaStats['total']) ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Boards</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$metaStats['boards'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Levels</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$metaStats['levels'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Sessions</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$metaStats['sessions'] ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CONTENT -->
  <main class="flex-1 bg-gradient-to-b from-white via-blue-50/50 to-white py-12">
    <div class="max-w-6xl mx-auto px-6">

      <!-- Toolbar / Filters -->
      <div class="reveal card-soft p-4 sm:p-5 mb-8">
        <form method="get" class="flex flex-col gap-4">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
              <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900 flex items-center gap-2">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-2xl bg-blue-600 text-white">
                  <ion-icon name="document-text-outline"></ion-icon>
                </span>
                Browse Past Papers
              </h2>
              <p class="text-sm text-slate-600 mt-1">
                Use search and filters to quickly find the exam paper you need.
              </p>
            </div>

            <div class="flex items-center gap-2 justify-end">
              <!-- View toggle -->
              <a href="<?= e(qs(['view'=>'grid','page'=>1])) ?>"
                 class="btn-ghost <?= $view==='grid'?'ring-2 ring-blue-500/60 text-blue-700 bg-white shadow-sm':'' ?>"
                 title="Grid view">
                <ion-icon name="grid-outline"></ion-icon>
              </a>
              <a href="<?= e(qs(['view'=>'list','page'=>1])) ?>"
                 class="btn-ghost <?= $view==='list'?'ring-2 ring-blue-500/60 text-blue-700 bg-white shadow-sm':'' ?>"
                 title="List view">
                <ion-icon name="list-outline"></ion-icon>
              </a>

              <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
                <a href="upload_paper.php" class="btn-ghost">
                  <ion-icon name="cloud-upload-outline"></ion-icon> Upload
                </a>
              <?php endif; ?>
            </div>
          </div>

          <!-- Search + Quick filters -->
          <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
            <div class="w-full md:w-auto flex-1">
              <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                  <ion-icon name="search-outline"></ion-icon>
                </span>
                <input
                  type="text"
                  name="q"
                  value="<?= e($q) ?>"
                  placeholder="Search subject, code (0620), or tags..."
                  class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-slate-200 bg-white focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-300 text-sm"
                >
              </div>
            </div>

            <!-- Quick chips -->
            <div class="flex flex-wrap gap-2 justify-start md:justify-end">
              <a href="<?= e(qs(['board'=>'','level'=>'','course'=>0,'page'=>1])) ?>"
                 class="filter-chip <?= !$board && !$level ? 'filter-chip-active' : 'filter-chip-inactive' ?>">
                <ion-icon name="grid-outline"></ion-icon> All
              </a>

              <?php foreach ($BOARD_OPTS as $b): ?>
                <a href="<?= e(qs(['board'=>$b,'course'=>0,'page'=>1])) ?>"
                   class="filter-chip <?= $board===$b ? 'filter-chip-active' : 'filter-chip-inactive' ?>">
                  <ion-icon name="business-outline"></ion-icon> <?= e($b) ?>
                </a>
              <?php endforeach; ?>

              <?php foreach ($LEVEL_OPTS as $lv): ?>
                <a href="<?= e(qs(['level'=>$lv,'course'=>0,'page'=>1])) ?>"
                   class="filter-chip <?= $level===$lv ? 'filter-chip-active' : 'filter-chip-inactive' ?>">
                  <ion-icon name="school-outline"></ion-icon> <?= e($lv) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Advanced filters -->
          <details class="rounded-xl border border-slate-200 bg-white px-3 sm:px-4 pt-1 pb-2 shadow-sm">
            <summary class="cursor-pointer select-none flex items-center justify-between py-2 text-sm text-slate-600">
              <span class="inline-flex items-center gap-2 font-medium">
                <ion-icon name="funnel-outline"></ion-icon> Advanced filters
              </span>
              <ion-icon name="chevron-down-outline" class="text-slate-400"></ion-icon>
            </summary>

            <div class="grid grid-cols-2 md:grid-cols-6 gap-3 pt-2">
              <div>
                <label class="block text-xs text-slate-500 mb-1 font-medium">Board</label>
                <select
                  name="board"
                  id="boardSel"
                  class="w-full rounded-lg border-slate-200 bg-white/95 px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/80 focus:border-blue-400"
                >
                  <option value="">All</option>
                  <?php foreach ($BOARD_OPTS as $b): ?>
                    <option value="<?= e($b) ?>" <?= $board===$b?'selected':'' ?>><?= e($b) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="block text-xs text-slate-500 mb-1 font-medium">Level</label>
                <select
                  name="level"
                  id="levelSel"
                  class="w-full rounded-lg border-slate-200 bg-white/95 px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/80 focus:border-blue-400"
                >
                  <option value="">All</option>
                  <?php foreach ($LEVEL_OPTS as $lv): ?>
                    <option value="<?= e($lv) ?>" <?= $level===$lv?'selected':'' ?>><?= e($lv) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="md:col-span-2">
                <label class="block text-xs text-slate-500 mb-1 font-medium">Subject (Course)</label>
                <select
                  name="course"
                  id="courseSel"
                  class="w-full rounded-lg border-slate-200 bg-white/95 px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/80 focus:border-blue-400"
                >
                  <option value="0">All</option>
                  <!-- populated by JS -->
                </select>
              </div>

              <div>
                <label class="block text-xs text-slate-500 mb-1 font-medium">Session</label>
                <select
                  name="session"
                  class="w-full rounded-lg border-slate-200 bg-white/95 px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/80 focus:border-blue-400"
                >
                  <option value="">All</option>
                  <?php foreach ($SESSIONS as $s): ?>
                    <option value="<?= e($s) ?>" <?= $session===$s?'selected':'' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="block text-xs text-slate-500 mb-1 font-medium">Year from</label>
                <input
                  type="number"
                  name="year_from"
                  min="2000"
                  max="<?= date('Y') ?>"
                  value="<?= $yearFrom?:'' ?>"
                  class="w-full rounded-lg border-slate-200 bg-white/95 px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/80 focus:border-blue-400"
                >
              </div>

              <div>
                <label class="block text-xs text-slate-500 mb-1 font-medium">Year to</label>
                <input
                  type="number"
                  name="year_to"
                  min="2000"
                  max="<?= date('Y') ?>"
                  value="<?= $yearTo?:'' ?>"
                  class="w-full rounded-lg border-slate-200 bg-white/95 px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/80 focus:border-blue-400"
                >
              </div>

              <div>
                <label class="block text-xs text-slate-500 mb-1 font-medium">Sort</label>
                <select
                  name="sort"
                  class="w-full rounded-lg border-slate-200 bg-white/95 px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/80 focus:border-blue-400"
                >
                  <option value="newest"      <?= $sortKey==='newest'?'selected':'' ?>>Newest</option>
                  <option value="oldest"      <?= $sortKey==='oldest'?'selected':'' ?>>Oldest</option>
                  <option value="popular"     <?= $sortKey==='popular'?'selected':'' ?>>Most downloaded</option>
                  <option value="subject_az"  <?= $sortKey==='subject_az'?'selected':'' ?>>Subject A–Z</option>
                </select>
              </div>
            </div>
          </details>

          <!-- Active filters + actions -->
          <?php
            $hasChips = $q || $board || $level || $courseId || $session || $yearFrom || $yearTo;
          ?>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-2">
              <?php if ($hasChips): ?>
                <span class="text-xs text-slate-500 flex items-center gap-1">
                  <ion-icon name="funnel-outline" class="text-[13px]"></ion-icon>
                  Active filters:
                </span>
                <?php if ($q): ?>
                  <a class="chip" href="<?= e(qs(['q'=>''])) ?>">
                    <ion-icon name="search-outline" class="text-[12px]"></ion-icon> <?= e($q) ?>
                    <ion-icon name="close-outline" class="text-[12px]"></ion-icon>
                  </a>
                <?php endif; ?>
                <?php if ($board): ?>
                  <a class="chip" href="<?= e(qs(['board'=>'','course'=>0])) ?>">
                    <ion-icon name="business-outline" class="text-[12px]"></ion-icon> <?= e($board) ?>
                    <ion-icon name="close-outline" class="text-[12px]"></ion-icon>
                  </a>
                <?php endif; ?>
                <?php if ($level): ?>
                  <a class="chip" href="<?= e(qs(['level'=>'','course'=>0])) ?>">
                    <ion-icon name="school-outline" class="text-[12px]"></ion-icon> <?= e($level) ?>
                    <ion-icon name="close-outline" class="text-[12px]"></ion-icon>
                  </a>
                <?php endif; ?>
                <?php if ($courseId): ?>
                  <a class="chip" href="<?= e(qs(['course'=>0])) ?>">
                    <ion-icon name="book-outline" class="text-[12px]"></ion-icon> Subject
                    <ion-icon name="close-outline" class="text-[12px]"></ion-icon>
                  </a>
                <?php endif; ?>
                <?php if ($session): ?>
                  <a class="chip" href="<?= e(qs(['session'=>''])) ?>">
                    <ion-icon name="calendar-outline" class="text-[12px]"></ion-icon> <?= e($session) ?>
                    <ion-icon name="close-outline" class="text-[12px]"></ion-icon>
                  </a>
                <?php endif; ?>
                <?php if ($yearFrom || $yearTo): ?>
                  <a class="chip" href="<?= e(qs(['year_from'=>'','year_to'=>''])) ?>">
                    <ion-icon name="time-outline" class="text-[12px]"></ion-icon>
                    <?= $yearFrom?:'...' ?>–<?= $yearTo?:'...' ?>
                    <ion-icon name="close-outline" class="text-[12px]"></ion-icon>
                  </a>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-xs text-slate-500 flex items-center gap-1">
                  <ion-icon name="information-circle-outline" class="text-[14px]"></ion-icon>
                  No filters applied.
                </span>
              <?php endif; ?>
            </div>

            <div class="flex items-center gap-2">
              <button type="submit" class="btn-primary">
                <ion-icon name="funnel-outline"></ion-icon> Apply filters
              </button>
              <a href="past_papers.php" class="btn-ghost">
                <ion-icon name="refresh-outline"></ion-icon> Reset
              </a>
            </div>
          </div>

          <p class="text-xs text-slate-500">
            Showing <span class="font-semibold text-slate-700"><?= number_format($showFrom) ?>–<?= number_format($showTo) ?></span>
            of <span class="font-semibold text-slate-700"><?= number_format($total) ?></span> result<?= $total==1?'':'s' ?>.
          </p>

          <!-- Preserve view in form submit -->
          <input type="hidden" name="view" value="<?= e($view) ?>">
        </form>
      </div>

      <!-- RESULTS -->
      <?php if (!$rows): ?>
        <div class="reveal card-soft p-10 text-center max-w-xl mx-auto">
          <div class="mx-auto w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center">
            <ion-icon name="folder-open-outline" class="text-xl text-slate-400"></ion-icon>
          </div>
          <p class="mt-3 text-slate-700 font-semibold">No past papers found.</p>
          <p class="mt-1 text-sm text-slate-600">Try adjusting your search or clearing some filters.</p>
          <a href="past_papers.php" class="mt-4 inline-flex btn-ghost">
            <ion-icon name="funnel-outline"></ion-icon> Reset filters
          </a>
        </div>
      <?php else: ?>

        <?php if ($view === 'list'): ?>
          <!-- LIST VIEW -->
          <section class="grid grid-cols-1 gap-4">
            <?php foreach ($rows as $r): ?>
              <article class="tilt reveal card-soft overflow-hidden flex flex-col">
                <!-- Top band -->
                <div class="p-4 sm:p-5 bg-gradient-to-r from-blue-600 to-indigo-700 text-white">
                  <div class="flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3 min-w-0">
                      <div class="shrink-0 w-10 h-10 rounded-2xl bg-white/15 border border-white/20 flex items-center justify-center">
                        <ion-icon name="book-outline"></ion-icon>
                      </div>
                      <div class="min-w-0">
                        <h3 class="font-extrabold text-base sm:text-lg leading-snug truncate">
                          <?= e($r['course_name'] ?: '—') ?>
                        </h3>
                        <p class="mt-1 text-[11px] sm:text-xs text-blue-100/90 flex flex-wrap items-center gap-1.5">
                          <span class="inline-flex items-center gap-1">
                            <ion-icon name="business-outline" class="text-[12px]"></ion-icon> <?= e($r['board']) ?>
                          </span>
                          <span class="text-blue-100/50">•</span>
                          <span class="inline-flex items-center gap-1">
                            <ion-icon name="school-outline" class="text-[12px]"></ion-icon> <?= e($r['level']) ?>
                          </span>
                          <span class="text-blue-100/50">•</span>
                          <span class="inline-flex items-center gap-1">
                            <ion-icon name="calendar-outline" class="text-[12px]"></ion-icon>
                            <?= e($r['year']) ?> <?= e($r['session']) ?>
                          </span>
                        </p>
                        <div class="mt-1.5 text-[11px] sm:text-xs text-blue-100/90 flex flex-wrap gap-3">
                          <?php if ($r['syllabus_code']): ?>
                            <span class="inline-flex items-center gap-1.5">
                              <ion-icon name="pricetag-outline" class="text-[12px]"></ion-icon>
                              <span class="font-semibold"><?= e($r['syllabus_code']) ?></span>
                            </span>
                          <?php endif; ?>
                          <?php if ($r['paper_code']): ?>
                            <span class="inline-flex items-center gap-1.5">
                              <ion-icon name="document-outline" class="text-[12px]"></ion-icon>
                              <span class="font-semibold"><?= e($r['paper_code']) ?></span><?= $r['variant'] ? ' · '.e($r['variant']) : '' ?>
                            </span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                    <span class="inline-flex items-center gap-1 text-[11px] bg-white/10 border border-white/20 px-2 py-1 rounded-full">
                      <ion-icon name="download-outline" class="text-[12px]"></ion-icon>
                      <?= (int)$r['download_count'] ?> dl
                    </span>
                  </div>
                </div>

                <!-- Body + actions -->
                <div class="p-4 sm:p-5 flex flex-col gap-3">
                  <div class="flex flex-wrap items-center gap-2">
                    <a href="<?= e(qs(['dl'=>$r['paper_id']])) ?>" class="btn-primary">
                      <ion-icon name="download-outline"></ion-icon> QP
                    </a>

                    <?php if (!empty($r['ms_url'])): ?>
                      <a href="<?= e($r['ms_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                        <ion-icon name="clipboard-outline"></ion-icon> Mark Scheme
                      </a>
                    <?php else: ?>
                      <span class="btn-ghost opacity-60 cursor-not-allowed">
                        <ion-icon name="clipboard-outline"></ion-icon> Mark Scheme
                      </span>
                    <?php endif; ?>

                    <?php if (!empty($r['solution_url'])): ?>
                      <a href="<?= e($r['solution_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                        <ion-icon name="play-circle-outline"></ion-icon> Solution
                      </a>
                    <?php endif; ?>

                    <button type="button"
                            data-copy="<?= e(qs(['dl'=>$r['paper_id']])) ?>"
                            class="btn-ghost"
                            title="Copy download link">
                      <ion-icon name="link-outline"></ion-icon>
                    </button>

                    <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
                      <a href="edit_paper.php?id=<?= (int)$r['paper_id'] ?>" class="btn-ghost">
                        <ion-icon name="pencil-outline"></ion-icon> Edit
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </section>

        <?php else: ?>
          <!-- GRID VIEW -->
          <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($rows as $r): ?>
              <article class="tilt reveal card-soft overflow-hidden flex flex-col">
                <!-- Top band -->
                <div class="p-4 bg-gradient-to-r from-blue-600 to-indigo-700 text-white">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <h3 class="font-extrabold text-base leading-snug truncate">
                        <?= e($r['course_name'] ?: '—') ?>
                      </h3>
                      <p class="mt-1 text-[11px] text-blue-100/90 flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1">
                          <ion-icon name="business-outline" class="text-[12px]"></ion-icon> <?= e($r['board']) ?>
                        </span>
                        <span class="text-blue-100/50">•</span>
                        <span class="inline-flex items-center gap-1">
                          <ion-icon name="school-outline" class="text-[12px]"></ion-icon> <?= e($r['level']) ?>
                        </span>
                      </p>
                    </div>
                    <span class="inline-flex items-center gap-1 text-[10px] bg-white/10 border border-white/20 px-2 py-1 rounded-full">
                      <ion-icon name="calendar-outline" class="text-[12px]"></ion-icon>
                      <?= e($r['year']) ?> <?= e($r['session']) ?>
                    </span>
                  </div>
                </div>

                <!-- Body -->
                <div class="p-4 flex-1 flex flex-col gap-3">
                  <div class="text-xs text-slate-700 space-y-1.5">
                    <?php if ($r['syllabus_code']): ?>
                      <div class="inline-flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-50 text-blue-700">
                          <ion-icon name="pricetag-outline" class="text-[11px]"></ion-icon>
                        </span>
                        <span class="text-slate-500">Syllabus:</span>
                        <span class="font-semibold"><?= e($r['syllabus_code']) ?></span>
                      </div>
                    <?php endif; ?>
                    <?php if ($r['paper_code']): ?>
                      <div class="inline-flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-100 text-slate-700">
                          <ion-icon name="document-outline" class="text-[11px]"></ionicon>
                        </span>
                        <span class="text-slate-500">Paper:</span>
                        <span class="font-semibold"><?= e($r['paper_code']) ?></span><?= $r['variant'] ? ' · '.e($r['variant']) : '' ?>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="mt-auto flex flex-wrap items-center gap-2 pt-1">
                    <a href="<?= e(qs(['dl'=>$r['paper_id']])) ?>" class="btn-primary w-full sm:w-auto justify-center">
                      <ion-icon name="download-outline"></ion-icon> Download QP
                    </a>

                    <?php if (!empty($r['ms_url'])): ?>
                      <a href="<?= e($r['ms_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                        <ion-icon name="clipboard-outline"></ion-icon> Mark Scheme
                      </a>
                    <?php else: ?>
                      <span class="btn-ghost opacity-60 cursor-not-allowed">
                        <ion-icon name="clipboard-outline"></ionicon> Mark Scheme
                      </span>
                    <?php endif; ?>

                    <?php if (!empty($r['solution_url'])): ?>
                      <a href="<?= e($r['solution_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                        <ion-icon name="play-circle-outline"></ionicon> Solution
                      </a>
                    <?php endif; ?>

                    <button type="button"
                            data-copy="<?= e(qs(['dl'=>$r['paper_id']])) ?>"
                            class="btn-ghost"
                            title="Copy download link">
                      <ion-icon name="link-outline"></ionicon>
                    </button>
                  </div>

                  <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                    <span class="inline-flex items-center gap-1">
                      <ion-icon name="download-outline" class="text-[12px]"></ionicon>
                      <?= (int)$r['download_count'] ?> downloads
                    </span>

                    <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
                      <a href="edit_paper.php?id=<?= (int)$r['paper_id'] ?>" class="inline-flex items-center gap-1 text-blue-700 hover:underline">
                        <ion-icon name="pencil-outline" class="text-[12px]"></ionicon> Edit
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>

        <!-- Pagination -->
        <nav class="mt-8 flex items-center justify-between gap-2 text-sm reveal">
          <a class="btn-ghost <?= $page<=1?'opacity-50 pointer-events-none':'' ?>"
             href="<?= e(qs(['page'=>$page-1])) ?>">
            <ion-icon name="arrow-back-outline"></ionicon> Prev
          </a>

          <div class="flex items-center gap-1">
            <?php foreach ($pagesArr as $p): ?>
              <?php if ($p === '...'): ?>
                <span class="px-2 text-slate-400">…</span>
              <?php else: ?>
                <a href="<?= e(qs(['page'=>$p])) ?>"
                   class="px-3 py-2 rounded-lg border text-xs sm:text-sm
                     <?= $p==$page
                          ? 'bg-gradient-to-r from-blue-600 to-indigo-600 border-blue-600 text-white shadow-md'
                          : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                      ?>">
                  <?= $p ?>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <a class="btn-ghost <?= $page>=$totalPages?'opacity-50 pointer-events-none':'' ?>"
             href="<?= e(qs(['page'=>$page+1])) ?>">
            Next <ion-icon name="arrow-forward-outline"></ionicon>
          </a>
        </nav>
      <?php endif; ?>
    </div>
  </main>

  <!-- Back to top -->
  <a href="#top" id="backToTop"
     class="hidden fixed bottom-6 right-6 z-40 bg-blue-600 text-white p-3 rounded-full hover:bg-blue-700 transition"
     aria-label="Back to top">
    <ion-icon name="arrow-up-outline" class="text-xl"></ionicon>
  </a>

  <?php if (file_exists(__DIR__ . '/components/footer.php')) include __DIR__ . '/components/footer.php'; ?>

  <script>
    // Reveal on scroll
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('in-view'); });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

    // Back to top visibility
    const backBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 400) backBtn.classList.remove('hidden');
      else backBtn.classList.add('hidden');
    });

    // Courses dependent select
    const COURSES = <?= json_encode($coursesByBL, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const boardSel  = document.getElementById('boardSel');
    const levelSel  = document.getElementById('levelSel');
    const courseSel = document.getElementById('courseSel');

    function populateCourses() {
      const b = boardSel?.value || '';
      const l = levelSel?.value || '';
      const list = (COURSES[b] && COURSES[b][l]) ? COURSES[b][l] : [];
      const selected = <?= (int)$courseId ?>;
      if (!courseSel) return;
      courseSel.innerHTML = '<option value="0">All</option>';
      for (const c of list) {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        if (selected && selected == c.id) opt.selected = true;
        courseSel.appendChild(opt);
      }
    }
    boardSel?.addEventListener('change', populateCourses);
    levelSel?.addEventListener('change', populateCourses);
    populateCourses();

    // Copy link
    document.querySelectorAll('[data-copy]').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          const url = new URL(btn.getAttribute('data-copy'), window.location.href).toString();
          await navigator.clipboard.writeText(url);
          const prev = btn.innerHTML;
          btn.innerHTML = '<ion-icon name="checkmark-circle-outline" class="text-emerald-500"></ion-icon>';
          setTimeout(() => btn.innerHTML = prev, 1200);
        } catch (e) {
          alert('Failed to copy link');
        }
      });
    });

    // Tilt (subtle)
    (function() {
      const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const fine = window.matchMedia('(pointer: fine)').matches;
      if (!fine || prefersReduced) return;

      const SENS = 9;
      document.querySelectorAll('.tilt').forEach(card => {
        let raf = null;
        const leave = () => { card.style.transform = ''; };
        const move = (e) => {
          if (raf) cancelAnimationFrame(raf);
          raf = requestAnimationFrame(() => {
            const r = card.getBoundingClientRect();
            const px = (e.clientX - r.left) / r.width;
            const py = (e.clientY - r.top) / r.height;
            const rx = (0.5 - py) * SENS;
            const ry = (px - 0.5) * SENS;
            card.style.transform = `rotateX(${rx}deg) rotateY(${ry}deg) translateZ(0)`;
          });
        };
        card.addEventListener('mousemove', move);
        card.addEventListener('mouseleave', leave);
      });
    })();
  </script>
</body>
</html>