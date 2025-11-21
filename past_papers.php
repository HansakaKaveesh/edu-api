<?php
session_start();
include __DIR__ . '/db_connect.php';
$conn->set_charset('utf8mb4');

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

// Count total
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
$showFrom = $total ? ($offset + 1) : 0;
$showTo   = min($offset + $perPage, $total);

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
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Past Papers</title>

  <!-- Font + Tailwind config before CDN -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>
    window.tailwind = window.tailwind || {};
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','Segoe UI','Roboto','Helvetica Neue','Arial'] },
          colors: {
            brand: {
              50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',300:'#a5b4fc',
              400:'#818cf8',500:'#6366f1',600:'#4f46e5',700:'#4338ca',
              800:'#3730a3',900:'#312e81'
            }
          },
          boxShadow: {
            soft: '0 10px 30px -12px rgba(99,102,241,.25)',
            card: '0 10px 20px -10px rgba(15,23,42,.15)'
          }
        }
      }
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>

  <style>
    body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial; }

    .chip {
      display: inline-flex; align-items: center; gap: 0.5rem;
      padding: 0.25rem 0.625rem; border-radius: 9999px;
      background: #eef2ff; color: #4338ca; border: 1px solid #e0e7ff;
      font-size: 0.75rem; line-height: 1rem; transition: background-color .2s ease;
    }
    .chip:hover { background: #e0e7ff; }

    .btn-primary {
      display: inline-flex; align-items: center; gap: .5rem; color: #fff;
      padding: 0.5rem 1rem; border-radius: 0.5rem;
      background-image: linear-gradient(to right, #4f46e5, #6366f1);
      box-shadow: 0 10px 30px -12px rgba(99,102,241,.25);
      transition: filter .2s ease, box-shadow .2s ease, transform .15s ease;
    }
    .btn-primary:hover { filter: brightness(1.05); box-shadow: 0 14px 34px -12px rgba(99,102,241,.35); }

    .btn-ghost {
      display: inline-flex; align-items: center; gap: .5rem;
      padding: 0.5rem 0.75rem; border-radius: 0.5rem;
      background: rgba(255,255,255,.85); color: #334155; border: 1px solid #cbd5e1;
      transition: background-color .2s ease, color .2s ease, border-color .2s ease;
    }
    .btn-ghost:hover { background: #f1f5f9; }

    .card {
      background: #ffffff; border: 1px solid rgba(226,232,240,.9);
      border-radius: 1.25rem; box-shadow: 0 10px 20px -10px rgba(15,23,42,.15);
    }

    .toolbar {
      position: sticky; top: 0; z-index: 30;
      backdrop-filter: blur(10px); background: rgba(255,255,255,.75);
      border-bottom: 1px solid rgba(226,232,240,.9);
    }
  </style>
</head>
<body class="bg-slate-50 text-gray-800 min-h-screen font-sans">
<?php if (file_exists(__DIR__ . '/components/navbar.php')) include __DIR__ . '/components/navbar.php'; ?>

<!-- Hero section -->
<section class="relative overflow-hidden">
  <!-- Soft background gradient and blobs -->
  <div class="absolute inset-0 bg-gradient-to-br from-white via-brand-50 to-white"></div>
  <div aria-hidden="true" class="pointer-events-none absolute -top-24 -left-24 h-64 w-64 rounded-full bg-indigo-100 blur-3xl"></div>
  <div aria-hidden="true" class="pointer-events-none absolute -bottom-28 -right-24 h-72 w-72 rounded-full bg-blue-100 blur-3xl"></div>

  <div class="relative max-w-7xl mx-auto px-6 py-14 sm:py-20">
    <div class="grid md:grid-cols-2 items-center gap-8">
      <div class="max-w-2xl">
        <h1 class="text-3xl sm:text-4xl font-extrabold leading-tight tracking-tight text-slate-900">
          Find Past Papers Faster
        </h1>
        <p class="mt-2 text-slate-600">
          Browse by board, level, subject, year, or session. Download question papers and mark schemes with one click.
        </p>

        <!-- Hero search (preserves current filters) -->
        <form method="get" action="past_papers.php" class="mt-5">
          <div class="relative">
            <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search subject, code (0620), or tags..."
                   class="w-full rounded-lg border border-slate-300 bg-white/90 pl-10 pr-28 py-3 focus:outline-none focus:ring-2 focus:ring-brand-500 text-[15px]">
            <button class="btn-primary absolute right-1 top-1/2 -translate-y-1/2 px-3 py-2">
              <i class="ph ph-funnel"></i> Search
            </button>
          </div>
          <!-- Preserve current filters -->
          <input type="hidden" name="board" value="<?= e($board) ?>">
          <input type="hidden" name="level" value="<?= e($level) ?>">
          <input type="hidden" name="course" value="<?= (int)$courseId ?>">
          <input type="hidden" name="session" value="<?= e($session) ?>">
          <input type="hidden" name="year_from" value="<?= $yearFrom?:'' ?>">
          <input type="hidden" name="year_to" value="<?= $yearTo?:'' ?>">
          <input type="hidden" name="sort" value="<?= e($sortKey) ?>">
          <input type="hidden" name="view" value="<?= e($view) ?>">
        </form>

        <!-- Quick filters -->
        <div class="mt-4">
          <div class="text-xs text-slate-500 mb-1">Quick filters:</div>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($BOARD_OPTS as $b): ?>
              <a class="chip" href="<?= e(qs(['board'=>$b,'course'=>0,'page'=>1])) ?>">
                <i class="ph ph-buildings"></i> <?= e($b) ?>
              </a>
            <?php endforeach; ?>
            <?php foreach ($LEVEL_OPTS as $lv): ?>
              <a class="chip" href="<?= e(qs(['level'=>$lv,'course'=>0,'page'=>1])) ?>">
                <i class="ph ph-graduation-cap"></i> <?= e($lv) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- CTA to filters -->
        <div class="mt-5">
          <a href="#filters" class="btn-ghost">
            <i class="ph ph-sliders"></i> Explore all filters
          </a>
        </div>
      </div>

      <!-- Right: small stats card -->
      <div class="card p-5 sm:p-6">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg bg-brand-50 text-brand-700 flex items-center justify-center">
            <i class="ph ph-file-text"></i>
          </div>
          <div>
            <div class="text-sm text-slate-500">Available now</div>
            <div class="text-2xl font-extrabold text-slate-900"><?= number_format($total) ?></div>
          </div>
        </div>
        <ul class="mt-4 space-y-2 text-sm text-slate-600">
          <li class="flex items-center gap-2"><i class="ph ph-check-circle text-emerald-500"></i> Instant QP downloads</li>
          <li class="flex items-center gap-2"><i class="ph ph-check-circle text-emerald-500"></i> Mark schemes when available</li>
          <li class="flex items-center gap-2"><i class="ph ph-check-circle text-emerald-500"></i> Optional video solutions</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- Top toolbar (Search + Controls) -->
<header class="toolbar" id="filters">
  <div class="max-w-7xl mx-auto px-6 py-4">
    <form method="get" class="flex flex-col gap-3">
      <div class="flex items-center gap-2">
        <h1 class="text-lg sm:text-xl font-extrabold text-slate-800 flex items-center gap-2">
          <i class="ph ph-file-text text-brand-600"></i> Past Papers
        </h1>
        <div class="ml-auto flex items-center gap-1">
          <!-- View toggle -->
          <a href="<?= e(qs(['view'=>'grid','page'=>1])) ?>" class="btn-ghost <?= $view==='grid'?'ring-1 ring-brand-400 text-brand-700':'' ?>" title="Grid view">
            <i class="ph ph-grid-four"></i>
          </a>
          <a href="<?= e(qs(['view'=>'list','page'=>1])) ?>" class="btn-ghost <?= $view==='list'?'ring-1 ring-brand-400 text-brand-700':'' ?>" title="List view">
            <i class="ph ph-list"></i>
          </a>
        </div>
      </div>

      <!-- Search bar -->
      <div class="flex flex-col sm:flex-row gap-2">
        <div class="relative flex-1">
          <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search subject, code (0620), or tags..."
                 class="w-full rounded-lg border border-slate-300 bg-white/90 pl-10 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
        </div>
        <div class="flex items-center gap-2">
          <button type="submit" class="btn-primary">
            <i class="ph ph-funnel"></i> Apply
          </button>
          <a href="past_papers.php" class="btn-ghost">
            <i class="ph ph-arrow-counter-clockwise"></i> Reset
          </a>
          <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
            <a href="upload_paper.php" class="btn-ghost">
              <i class="ph ph-upload-simple"></i> Upload
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Advanced filters (collapsible) -->
      <details class="group">
        <summary class="cursor-pointer select-none flex items-center justify-between py-2 text-sm text-slate-600">
          <span class="inline-flex items-center gap-2">
            <i class="ph ph-sliders"></i> Advanced filters
          </span>
          <i class="ph ph-caret-down group-open:rotate-180 transition"></i>
        </summary>

        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 py-2">
          <div>
            <label class="block text-xs text-slate-500 mb-1">Board</label>
            <select name="board" id="boardSel" class="w-full rounded-lg border-slate-300 bg-white/90 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
              <option value="">All</option>
              <?php foreach ($BOARD_OPTS as $b): ?>
                <option value="<?= e($b) ?>" <?= $board===$b?'selected':'' ?>><?= e($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs text-slate-500 mb-1">Level</label>
            <select name="level" id="levelSel" class="w-full rounded-lg border-slate-300 bg-white/90 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
              <option value="">All</option>
              <?php foreach ($LEVEL_OPTS as $lv): ?>
                <option value="<?= e($lv) ?>" <?= $level===$lv?'selected':'' ?>><?= e($lv) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs text-slate-500 mb-1">Subject (Course)</label>
            <select name="course" id="courseSel" class="w-full rounded-lg border-slate-300 bg-white/90 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
              <option value="0">All</option>
              <!-- populated by JS -->
            </select>
          </div>
          <div>
            <label class="block text-xs text-slate-500 mb-1">Session</label>
            <select name="session" class="w-full rounded-lg border-slate-300 bg-white/90 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
              <option value="">All</option>
              <?php foreach ($SESSIONS as $s): ?>
                <option value="<?= e($s) ?>" <?= $session===$s?'selected':'' ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs text-slate-500 mb-1">Year from</label>
            <input type="number" name="year_from" min="2000" max="<?= date('Y') ?>" value="<?= $yearFrom?:'' ?>"
                   class="w-full rounded-lg border-slate-300 bg-white/90 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
          </div>
          <div>
            <label class="block text-xs text-slate-500 mb-1">Year to</label>
            <input type="number" name="year_to" min="2000" max="<?= date('Y') ?>" value="<?= $yearTo?:'' ?>"
                   class="w-full rounded-lg border-slate-300 bg-white/90 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
          </div>
          <div>
            <label class="block text-xs text-slate-500 mb-1">Sort</label>
            <select name="sort" class="w-full rounded-lg border-slate-300 bg-white/90 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500">
              <option value="newest" <?= $sortKey==='newest'?'selected':'' ?>>Newest</option>
              <option value="oldest" <?= $sortKey==='oldest'?'selected':'' ?>>Oldest</option>
              <option value="popular" <?= $sortKey==='popular'?'selected':'' ?>>Most downloaded</option>
              <option value="subject_az" <?= $sortKey==='subject_az'?'selected':'' ?>>Subject A–Z</option>
            </select>
          </div>
        </div>
      </details>

      <!-- Active chips -->
      <?php
        $hasChips = $q || $board || $level || $courseId || $session || $yearFrom || $yearTo;
        $xCls = "ph ph-x text-[13px]";
      ?>
      <?php if ($hasChips): ?>
        <div class="flex flex-wrap gap-2 pb-1">
          <span class="text-xs text-slate-500 mr-1">Active:</span>
          <?php if ($q): ?>
            <a class="chip" href="<?= e(qs(['q'=>''])) ?>"><i class="ph ph-magnifying-glass"></i> <?= e($q) ?> <i class="<?= $xCls ?>"></i></a>
          <?php endif; ?>
          <?php if ($board): ?>
            <a class="chip" href="<?= e(qs(['board'=>'','course'=>0])) ?>"><i class="ph ph-buildings"></i> <?= e($board) ?> <i class="<?= $xCls ?>"></i></a>
          <?php endif; ?>
          <?php if ($level): ?>
            <a class="chip" href="<?= e(qs(['level'=>'','course'=>0])) ?>"><i class="ph ph-graduation-cap"></i> <?= e($level) ?> <i class="<?= $xCls ?>"></i></a>
          <?php endif; ?>
          <?php if ($courseId): ?>
            <a class="chip" href="<?= e(qs(['course'=>0])) ?>"><i class="ph ph-book"></i> Subject <i class="<?= $xCls ?>"></i></a>
          <?php endif; ?>
          <?php if ($session): ?>
            <a class="chip" href="<?= e(qs(['session'=>''])) ?>"><i class="ph ph-calendar-blank"></i> <?= e($session) ?> <i class="<?= $xCls ?>"></i></a>
          <?php endif; ?>
          <?php if ($yearFrom || $yearTo): ?>
            <a class="chip" href="<?= e(qs(['year_from'=>'','year_to'=>''])) ?>"><i class="ph ph-clock"></i> <?= $yearFrom?:'...' ?>–<?= $yearTo?:'...' ?> <i class="<?= $xCls ?>"></i></a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <p class="text-xs text-slate-500">
        Showing <?= number_format($showFrom) ?>–<?= number_format($showTo) ?> of <?= number_format($total) ?> result(s).
      </p>
    </form>
  </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-6">
  <!-- Results -->
  <?php if (!$rows): ?>
    <div class="card p-10 text-center">
      <div class="mx-auto w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center">
        <i class="ph ph-folder-open text-xl text-slate-400"></i>
      </div>
      <p class="mt-3 text-slate-600">No past papers found. Try adjusting filters.</p>
      <a href="past_papers.php" class="mt-3 btn-ghost">
        <i class="ph ph-sliders"></i> Reset filters
      </a>
    </div>
  <?php else: ?>

    <?php if ($view === 'list'): ?>
      <!-- List view -->
      <div class="space-y-3">
        <?php foreach ($rows as $r): ?>
          <article class="card p-4">
            <div class="flex flex-col lg:flex-row lg:items-center gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <h3 class="font-semibold text-slate-900 truncate"><?= e($r['course_name'] ?: '—') ?></h3>
                    <p class="text-xs text-slate-500">
                      <?= e($r['board']) ?> · <?= e($r['level']) ?> · <?= e($r['year']) ?> <?= e($r['session']) ?>
                    </p>
                    <div class="mt-1 text-sm text-slate-700 space-x-3">
                      <?php if ($r['syllabus_code']): ?>
                        <span class="inline-flex items-center gap-1">
                          <i class="ph ph-hash text-slate-400"></i> <span class="font-medium"><?= e($r['syllabus_code']) ?></span>
                        </span>
                      <?php endif; ?>
                      <?php if ($r['paper_code']): ?>
                        <span class="inline-flex items-center gap-1">
                          <i class="ph ph-file text-slate-400"></i>
                          <span class="font-medium"><?= e($r['paper_code']) ?></span><?= $r['variant'] ? ' · '.e($r['variant']) : '' ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <span class="text-[11px] bg-slate-100 text-slate-700 px-2 py-1 rounded self-start">
                    <?= (int)$r['download_count'] ?> dl
                  </span>
                </div>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <a href="<?= e(qs(['dl'=>$r['paper_id']])) ?>" class="btn-primary">
                  <i class="ph ph-download-simple"></i> QP
                </a>
                <?php if (!empty($r['ms_url'])): ?>
                  <a href="<?= e($r['ms_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                    <i class="ph ph-clipboard-text"></i> Mark Scheme
                  </a>
                <?php else: ?>
                  <span class="btn-ghost opacity-60 cursor-not-allowed">
                    <i class="ph ph-clipboard-text"></i> Mark Scheme
                  </span>
                <?php endif; ?>
                <?php if (!empty($r['solution_url'])): ?>
                  <a href="<?= e($r['solution_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                    <i class="ph ph-play-circle"></i> Solution
                  </a>
                <?php endif; ?>
                <button type="button" data-copy="<?= e((qs(['dl'=>$r['paper_id']]))) ?>" class="btn-ghost" title="Copy download link">
                  <i class="ph ph-link-simple"></i>
                </button>

                <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
                  <a href="edit_paper.php?id=<?= (int)$r['paper_id'] ?>" class="btn-ghost">
                    <i class="ph ph-pencil-simple"></i> Edit
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <!-- Grid view -->
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($rows as $r): ?>
          <article class="card p-4 hover:-translate-y-0.5 transition">
            <div class="flex items-start justify-between">
              <div>
                <h3 class="font-semibold text-slate-900"><?= e($r['course_name'] ?: '—') ?></h3>
                <p class="text-xs text-slate-500"><?= e($r['board']) ?> · <?= e($r['level']) ?></p>
              </div>
              <span class="text-[11px] bg-slate-100 text-slate-700 px-2 py-1 rounded">
                <?= e($r['year']) ?> <?= e($r['session']) ?>
              </span>
            </div>

            <div class="mt-3 text-sm text-slate-700 space-y-1">
              <?php if ($r['syllabus_code']): ?>
                <div class="inline-flex items-center gap-2">
                  <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-brand-50 text-brand-700">
                    <i class="ph ph-hash text-[13px]"></i>
                  </span>
                  Syllabus: <span class="font-medium"><?= e($r['syllabus_code']) ?></span>
                </div><br>
              <?php endif; ?>
              <?php if ($r['paper_code']): ?>
                <div class="inline-flex items-center gap-2">
                  <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-slate-100">
                    <i class="ph ph-file text-[13px]"></i>
                  </span>
                  Paper: <span class="font-medium"><?= e($r['paper_code']) ?></span><?= $r['variant'] ? ' · '.e($r['variant']) : '' ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
              <a href="<?= e(qs(['dl'=>$r['paper_id']])) ?>" class="btn-primary">
                <i class="ph ph-download-simple"></i> Download QP
              </a>
              <?php if (!empty($r['ms_url'])): ?>
                <a href="<?= e($r['ms_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                  <i class="ph ph-clipboard-text"></i> Mark Scheme
                </a>
              <?php else: ?>
                <span class="btn-ghost opacity-60 cursor-not-allowed">
                  <i class="ph ph-clipboard-text"></i> Mark Scheme
                </span>
              <?php endif; ?>
              <?php if (!empty($r['solution_url'])): ?>
                <a href="<?= e($r['solution_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                  <i class="ph ph-play-circle"></i> Solution
                </a>
              <?php endif; ?>
              <button type="button" data-copy="<?= e((qs(['dl'=>$r['paper_id']]))) ?>" class="btn-ghost" title="Copy download link">
                <i class="ph ph-link-simple"></i>
              </button>
            </div>

            <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
              <span class="inline-flex items-center gap-1">
                <i class="ph ph-trend-up"></i> <?= (int)$r['download_count'] ?> downloads
              </span>
              <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
                <a href="edit_paper.php?id=<?= (int)$r['paper_id'] ?>" class="text-brand-700 hover:underline inline-flex items-center gap-1">
                  <i class="ph ph-pencil-simple"></i> Edit
                </a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Pagination -->
    <nav class="mt-8 flex items-center justify-between">
      <a class="btn-ghost <?= $page<=1?'opacity-50 pointer-events-none':'' ?>" href="<?= e(qs(['page'=>$page-1])) ?>">
        <i class="ph ph-arrow-left"></i> Prev
      </a>

      <div class="flex items-center gap-1">
        <?php foreach ($pagesArr as $p): ?>
          <?php if ($p === '...'): ?>
            <span class="px-2 text-slate-400">…</span>
          <?php else: ?>
            <a href="<?= e(qs(['page'=>$p])) ?>"
               class="px-3 py-2 rounded-lg border <?= $p==$page ? 'bg-gradient-to-r from-brand-600 to-indigo-600 border-brand-600 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50' ?>">
               <?= $p ?>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <a class="btn-ghost <?= $page>=$totalPages?'opacity-50 pointer-events-none':'' ?>" href="<?= e(qs(['page'=>$page+1])) ?>">
        Next <i class="ph ph-arrow-right"></i>
      </a>
    </nav>
  <?php endif; ?>
</main>

<?php if (file_exists(__DIR__ . '/components/footer.php')) include __DIR__ . '/components/footer.php'; ?>

<script>
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
        btn.innerHTML = '<i class="ph ph-check-circle text-emerald-500"></i>';
        setTimeout(() => btn.innerHTML = prev, 1200);
      } catch (e) {
        alert('Failed to copy link');
      }
    });
  });
</script>

</body>
</html>