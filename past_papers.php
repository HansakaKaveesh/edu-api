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
          fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial'] },
          colors: {
            primary: {
              50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',300:'#a5b4fc',
              400:'#818cf8',500:'#6366f1',600:'#4f46e5',700:'#4338ca',
              800:'#3730a3',900:'#312e81'
            }
          },
          boxShadow: {
            soft: '0 10px 30px -12px rgba(99,102,241,.25)',
          }
        }
      }
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-blue-50 text-gray-800 min-h-screen font-sans">
<?php if (file_exists(__DIR__ . '/components/navbar.php')) include __DIR__ . '/components/navbar.php'; ?>

<!-- Hero -->
<section class="relative overflow-hidden rounded-3xl shadow soft max-w-7xl mx-auto mt-20 mb-8">
  <div class="absolute inset-0 bg-gradient-to-br from-primary-900 via-indigo-700 to-primary-600"></div>

  <!-- Gradient orbs -->
  <div aria-hidden="true" class="pointer-events-none absolute inset-0">
    <div class="absolute -top-14 -left-10 h-56 w-56 rounded-full bg-cyan-400/30 blur-3xl"></div>
    <div class="absolute -bottom-16 -right-8 h-64 w-64 rounded-full bg-fuchsia-400/30 blur-3xl"></div>
  </div>

  <div class="relative z-10 text-white p-6 sm:p-8">
    <div class="flex items-center justify-between gap-3">
      <div class="text-xs sm:text-sm opacity-90 inline-flex items-center gap-2">
        <i class="ph ph-file-text"></i> Past Papers Library
      </div>

      <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/20 px-3 py-1 rounded-full text-xs">
        <i class="ph ph-magnifying-glass"></i> Search & Filter
      </span>
    </div>

    <div class="mt-3">
      <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Find past papers and mark schemes</h1>
      <p class="text-white/90 text-sm sm:text-base">Filter by board, level, subject, year, and session.</p>
    </div>
  </div>
</section>

<main class="max-w-7xl mx-auto px-6 pb-24">
  <!-- Filters -->
  <form method="get" class="bg-white/80 backdrop-blur rounded-2xl border border-slate-200/70 shadow p-4 md:p-5 mb-6 sticky top-6 z-30">
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
      <div class="md:col-span-2">
        <label class="block text-xs text-slate-600 mb-1">Search</label>
        <div class="relative">
          <i class="ph ph-magnifying-glass absolute left-3 top-2.5 text-slate-400"></i>
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Subject, code (0620), tags..."
                 class="w-full rounded-lg border-slate-300 bg-white/70 pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
        </div>
      </div>
      <div>
        <label class="block text-xs text-slate-600 mb-1">Board</label>
        <select name="board" id="boardSel" class="w-full rounded-lg border-slate-300 bg-white/70 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">All</option>
          <?php foreach ($BOARD_OPTS as $b): ?>
            <option value="<?= e($b) ?>" <?= $board===$b?'selected':'' ?>><?= e($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-600 mb-1">Level</label>
        <select name="level" id="levelSel" class="w-full rounded-lg border-slate-300 bg-white/70 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">All</option>
          <?php foreach ($LEVEL_OPTS as $lv): ?>
            <option value="<?= e($lv) ?>" <?= $level===$lv?'selected':'' ?>><?= e($lv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-600 mb-1">Subject (Course)</label>
        <select name="course" id="courseSel" class="w-full rounded-lg border-slate-300 bg-white/70 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="0">All</option>
          <!-- options populated by JS based on board/level -->
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-600 mb-1">Session</label>
        <select name="session" class="w-full rounded-lg border-slate-300 bg-white/70 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">All</option>
          <?php foreach ($SESSIONS as $s): ?>
            <option value="<?= e($s) ?>" <?= $session===$s?'selected':'' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-600 mb-1">Year from</label>
        <input type="number" name="year_from" min="2000" max="<?= date('Y') ?>" value="<?= $yearFrom?:'' ?>"
               class="w-full rounded-lg border-slate-300 bg-white/70 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
      </div>
      <div>
        <label class="block text-xs text-slate-600 mb-1">Year to</label>
        <input type="number" name="year_to" min="2000" max="<?= date('Y') ?>" value="<?= $yearTo?:'' ?>"
               class="w-full rounded-lg border-slate-300 bg-white/70 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
      </div>
      <div>
        <label class="block text-xs text-slate-600 mb-1">Sort</label>
        <select name="sort" class="w-full rounded-lg border-slate-300 bg-white/70 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="newest" <?= $sortKey==='newest'?'selected':'' ?>>Newest</option>
          <option value="oldest" <?= $sortKey==='oldest'?'selected':'' ?>>Oldest</option>
          <option value="popular" <?= $sortKey==='popular'?'selected':'' ?>>Most downloaded</option>
          <option value="subject_az" <?= $sortKey==='subject_az'?'selected':'' ?>>Subject A–Z</option>
        </select>
      </div>
    </div>

    <!-- Active filters chips -->
    <?php
      $hasChips = $q || $board || $level || $courseId || $session || $yearFrom || $yearTo;
      $chipCls = "inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-primary-50 text-primary-700 border border-primary-100 hover:bg-primary-100/80 text-xs";
      $xCls = "ph ph-x text-[13px]";
    ?>
    <?php if ($hasChips): ?>
      <div class="mt-3 flex flex-wrap gap-2">
        <span class="text-xs text-slate-500 mr-2">Active:</span>
        <?php if ($q): ?>
          <a class="<?= $chipCls ?>" href="<?= e(qs(['q'=>''])) ?>" title="Clear search">
            <i class="ph ph-magnifying-glass"></i> <?= e($q) ?> <i class="<?= $xCls ?>"></i>
          </a>
        <?php endif; ?>
        <?php if ($board): ?>
          <a class="<?= $chipCls ?>" href="<?= e(qs(['board'=>'','course'=>0])) ?>" title="Clear board">
            <i class="ph ph-buildings"></i> <?= e($board) ?> <i class="<?= $xCls ?>"></i>
          </a>
        <?php endif; ?>
        <?php if ($level): ?>
          <a class="<?= $chipCls ?>" href="<?= e(qs(['level'=>'','course'=>0])) ?>" title="Clear level">
            <i class="ph ph-graduation-cap"></i> <?= e($level) ?> <i class="<?= $xCls ?>"></i>
          </a>
        <?php endif; ?>
        <?php if ($courseId): ?>
          <a class="<?= $chipCls ?>" href="<?= e(qs(['course'=>0])) ?>" title="Clear subject">
            <i class="ph ph-book"></i> Subject <i class="<?= $xCls ?>"></i>
          </a>
        <?php endif; ?>
        <?php if ($session): ?>
          <a class="<?= $chipCls ?>" href="<?= e(qs(['session'=>''])) ?>" title="Clear session">
            <i class="ph ph-calendar-blank"></i> <?= e($session) ?> <i class="<?= $xCls ?>"></i>
          </a>
        <?php endif; ?>
        <?php if ($yearFrom || $yearTo): ?>
          <a class="<?= $chipCls ?>" href="<?= e(qs(['year_from'=>'','year_to'=>''])) ?>" title="Clear year range">
            <i class="ph ph-clock"></i> <?= $yearFrom?:'...' ?>–<?= $yearTo?:'...' ?> <i class="<?= $xCls ?>"></i>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="mt-4 flex items-center gap-2">
      <button class="inline-flex items-center gap-2 bg-gradient-to-r from-primary-600 to-indigo-600 text-gray px-4 py-2 rounded-lg shadow-soft hover:shadow-lg hover:from-primary-500 hover:to-indigo-500 transition">
        <i class="ph ph-funnel"></i> Apply
      </button>
      <a href="past_papers.php" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
        <i class="ph ph-arrow-counter-clockwise"></i> Reset
      </a>
      <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
        <a href="upload_paper.php" class="ml-auto inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white border border-primary-200 text-primary-700 hover:bg-primary-50">
          <i class="ph ph-upload-simple"></i> Upload Paper
        </a>
      <?php endif; ?>
    </div>
    <p class="mt-2 text-xs text-slate-500">
      Showing <?= number_format($showFrom) ?>–<?= number_format($showTo) ?> of <?= number_format($total) ?> result(s).
    </p>
  </form>

  <!-- Results -->
  <?php if (!$rows): ?>
    <div class="bg-white/80 backdrop-blur rounded-2xl border border-slate-200/70 shadow p-10 text-center">
      <div class="mx-auto w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center">
        <i class="ph ph-folder-open text-xl text-slate-400"></i>
      </div>
      <p class="mt-3 text-gray-600">No past papers found. Try adjusting filters.</p>
      <a href="past_papers.php" class="mt-3 inline-flex items-center gap-2 text-sm px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50">
        <i class="ph ph-sliders"></i> Reset filters
      </a>
    </div>
  <?php else: ?>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($rows as $r): ?>
        <article class="group bg-white/80 backdrop-blur rounded-2xl border border-slate-200/70 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition p-4">
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
                <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-primary-50 text-primary-700"><i class="ph ph-hash text-[13px]"></i></span>
                Syllabus: <span class="font-medium"><?= e($r['syllabus_code']) ?></span>
              </div><br>
            <?php endif; ?>
            <?php if ($r['paper_code']): ?>
              <div class="inline-flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-slate-100"><i class="ph ph-file text-[13px]"></i></span>
                Paper: <span class="font-medium"><?= e($r['paper_code']) ?></span><?= $r['variant'] ? ' · '.e($r['variant']) : '' ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="mt-4 flex flex-wrap items-center gap-2">
            <a href="<?= e(qs(['dl'=>$r['paper_id']])) ?>" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gradient-to-r from-primary-600 to-indigo-600 text-gray hover:from-primary-500 hover:to-indigo-500 shadow-soft transition">
              <i class="ph ph-download-simple"></i> Download QP
            </a>
            <?php if (!empty($r['ms_url'])): ?>
              <a href="<?= e($r['ms_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
                <i class="ph ph-clipboard-text"></i> Mark Scheme
              </a>
            <?php else: ?>
              <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-400 cursor-not-allowed">
                <i class="ph ph-clipboard-text"></i> Mark Scheme
              </span>
            <?php endif; ?>
            <?php if (!empty($r['solution_url'])): ?>
              <a href="<?= e($r['solution_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
                <i class="ph ph-play-circle"></i> Solution
              </a>
            <?php endif; ?>

            <!-- Copy link -->
            <button type="button" data-copy="<?= e((qs(['dl'=>$r['paper_id']]))) ?>" class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50" title="Copy download link">
              <i class="ph ph-link-simple"></i>
            </button>
          </div>

          <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
            <span class="inline-flex items-center gap-1">
              <i class="ph ph-trend-up"></i> <?= (int)$r['download_count'] ?> downloads
            </span>
            <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin','teacher'])): ?>
              <a href="edit_paper.php?id=<?= (int)$r['paper_id'] ?>" class="text-primary-700 hover:underline inline-flex items-center gap-1"><i class="ph ph-pencil-simple"></i> Edit</a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <nav class="mt-8 flex items-center justify-between">
      <a class="px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 <?= $page<=1?'opacity-50 pointer-events-none':'' ?>"
         href="<?= e(qs(['page'=>$page-1])) ?>">
         <i class="ph ph-arrow-left"></i> Prev
      </a>

      <div class="flex items-center gap-1">
        <?php foreach ($pagesArr as $p): ?>
          <?php if ($p === '...'): ?>
            <span class="px-2 text-slate-400">…</span>
          <?php else: ?>
            <a href="<?= e(qs(['page'=>$p])) ?>"
               class="px-3 py-2 rounded-lg border <?= $p==$page ? 'bg-primary-600 border-primary-600 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50' ?>">
               <?= $p ?>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <a class="px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 <?= $page>=$totalPages?'opacity-50 pointer-events-none':'' ?>"
         href="<?= e(qs(['page'=>$page+1])) ?>">
         Next <i class="ph ph-arrow-right"></i>
      </a>
    </nav>
  <?php endif; ?>
</main>

<?php if (file_exists(__DIR__ . '/components/footer.php')) include __DIR__ . '/components/footer.php'; ?>

<script>
  // Courses map: board -> level -> [ {id, name} ]
  const COURSES = <?= json_encode($coursesByBL, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const boardSel  = document.getElementById('boardSel');
  const levelSel  = document.getElementById('levelSel');
  const courseSel = document.getElementById('courseSel');

  function populateCourses() {
    const b = boardSel.value || '';
    const l = levelSel.value || '';
    const list = (COURSES[b] && COURSES[b][l]) ? COURSES[b][l] : [];
    const selected = <?= (int)$courseId ?>;
    courseSel.innerHTML = '<option value="0">All</option>';
    for (const c of list) {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      if (selected && selected == c.id) opt.selected = true;
      courseSel.appendChild(opt);
    }
  }

  boardSel.addEventListener('change', populateCourses);
  levelSel.addEventListener('change', populateCourses);
  // initial populate
  populateCourses();

  // Copy link
  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        const url = new URL(btn.getAttribute('data-copy'), window.location.href).toString();
        await navigator.clipboard.writeText(url);
        btn.innerHTML = '<i class="ph ph-check-circle text-emerald-500"></i>';
        setTimeout(() => btn.innerHTML = '<i class="ph ph-link-simple"></i>', 1200);
      } catch (e) {
        alert('Failed to copy link');
      }
    });
  });
</script>

</body>
</html>