<?php
session_start();
include __DIR__ . '/db_connect.php';
$conn->set_charset('utf8mb4');

// Only admin
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  header('Location: login.php'); exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function delete_local_if_uploaded(?string $url): void {
  if (!$url) return;
  if (preg_match('#^/?uploads/papers/#i', $url)) {
    $path = __DIR__ . '/' . ltrim($url, '/');
    if (is_file($path)) @unlink($path);
  }
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

$BOARD_OPTS = ['Cambridge','Edexcel','Local','Other'];
$LEVEL_OPTS = ['IGCSE','A/L','O/L','Others'];
$SESSIONS   = ['Feb/Mar','May/Jun','Oct/Nov','Specimen','Other'];
$VIS_OPTS   = ['public','private'];
$SORTS      = [
  'newest'     => 'created_at DESC, year DESC',
  'oldest'     => 'created_at ASC, year ASC',
  'downloads'  => 'download_count DESC, created_at DESC',
  'year_desc'  => 'year DESC, session DESC, created_at DESC',
];

// Helpers
function is_valid_url_or_path(string $v): bool {
  if ($v === '') return false;
  if (filter_var($v, FILTER_VALIDATE_URL)) return true;
  return (bool)preg_match('#^(?:/)?uploads/papers/[\w\-.]+\.pdf$#i', $v);
}
function save_pdf_upload(string $field, ?string $fallbackUrl = null): ?string {
  if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
    return $fallbackUrl && is_valid_url_or_path($fallbackUrl) ? $fallbackUrl : null;
  }
  $f = $_FILES[$field];
  if ($f['error'] !== UPLOAD_ERR_OK) return $fallbackUrl ?? null;
  if ($f['size'] > 25 * 1024 * 1024) return $fallbackUrl ?? null; // 25MB max

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($f['tmp_name']);
  if ($mime !== 'application/pdf') return $fallbackUrl ?? null;

  $dir = __DIR__ . '/uploads/papers';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $dest = $dir . '/' . $name;

  if (!move_uploaded_file($f['tmp_name'], $dest)) return $fallbackUrl ?? null;
  return 'uploads/papers/' . $name;
}

// Handle POST actions
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
    http_response_code(403); exit('Invalid CSRF token');
  }
  $action = $_POST['action'] ?? '';

  if ($action === 'add_paper') {
    $board   = $_POST['board']  ?? '';
    $level   = $_POST['level']  ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $syll    = trim($_POST['syllabus_code'] ?? '');
    $year    = (int)($_POST['year'] ?? 0);
    $session = $_POST['session'] ?? '';
    $paper   = trim($_POST['paper_code'] ?? '');
    $variant = trim($_POST['variant'] ?? '');
    $tags    = trim($_POST['tags'] ?? '');
    $vis     = $_POST['visibility'] ?? 'public';
    $courseId= (int)($_POST['course_id'] ?? 0);
    $uploader= (int)$_SESSION['user_id'];

    $qp_url_in = trim($_POST['qp_url'] ?? '');
    $ms_url_in = trim($_POST['ms_url'] ?? '');
    $sol_url   = trim($_POST['solution_url'] ?? '');

    $errors = [];
    if (!in_array($board, $BOARD_OPTS, true))  $errors[] = 'Invalid board';
    if (!in_array($level, $LEVEL_OPTS, true))  $errors[] = 'Invalid level';
    if ($year < 2000 || $year > (int)date('Y') + 1) $errors[] = 'Invalid year';
    if (!in_array($session, $SESSIONS, true)) $errors[] = 'Invalid session';
    if (!in_array($vis, $VIS_OPTS, true))     $errors[] = 'Invalid visibility';

    if ($courseId > 0 && $subject === '') {
      if ($stmt = $conn->prepare("SELECT name FROM courses WHERE course_id=?")) {
        $stmt->bind_param('i', $courseId);
        $stmt->execute(); $stmt->bind_result($cname);
        if ($stmt->fetch()) $subject = $cname ?: $subject;
        $stmt->close();
      }
    }
    if ($subject === '') $errors[] = 'Subject is required (or select a course)';

    $qp_url = save_pdf_upload('qp_file', $qp_url_in);
    $ms_url = save_pdf_upload('ms_file', $ms_url_in);
    if (!$qp_url) $errors[] = 'Question paper (PDF) is required via URL or file upload.';
    if ($sol_url !== '' && !filter_var($sol_url, FILTER_VALIDATE_URL)) {
      $errors[] = 'Solution URL must be a valid link';
    }

    if ($courseId > 0) {
      $okMatch = false;
      $stmt = $conn->prepare("
        SELECT 1
        FROM courses c
        JOIN course_types ct ON ct.course_type_id=c.course_type_id
        WHERE c.course_id=? AND ct.board=? AND ct.level=? LIMIT 1
      ");
      $stmt->bind_param('iss', $courseId, $board, $level);
      $stmt->execute(); $stmt->store_result(); $okMatch = $stmt->num_rows>0; $stmt->close();
      if (!$okMatch) $errors[] = 'Selected course does not match chosen board and level.';
    }

    if (!$errors) {
      $stmt = $conn->prepare("
        SELECT COUNT(*) FROM past_papers
        WHERE board=? AND level=? AND subject=? AND year=? AND session=? AND COALESCE(paper_code,'')=? AND COALESCE(variant,'')=?
      ");
      $pc = $paper === '' ? '' : $paper;
      $vr = $variant === '' ? '' : $variant;
      $stmt->bind_param('sssisss', $board, $level, $subject, $year, $session, $pc, $vr);
      $stmt->execute(); $stmt->bind_result($dups); $stmt->fetch(); $stmt->close();
      if ($dups > 0) $errors[] = 'A matching paper already exists.';
    }

    if ($errors) {
      $flash = ['type'=>'error','msg'=>implode(' • ', $errors)];
    } else {
      $sql = "INSERT INTO past_papers
        (course_id, board, level, subject, syllabus_code, year, session, paper_code, variant,
         qp_url, ms_url, solution_url, tags, visibility, uploaded_by)
        VALUES (NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param(
        'issssissssssssi',
        $courseId, $board, $level, $subject, $syll, $year, $session, $paper, $variant,
        $qp_url, $ms_url, $sol_url, $tags, $vis, $uploader
      );
      if ($stmt->execute()) {
        $flash = ['type'=>'success','msg'=>"Past paper added (ID #{$stmt->insert_id})."];
      } else {
        $flash = ['type'=>'error','msg'=>'Database error: '.$stmt->error];
      }
      $stmt->close();
    }
  }

  if ($action === 'delete_paper') {
    $paperId = (int)($_POST['paper_id'] ?? 0);
    if ($paperId > 0) {
      $st = $conn->prepare("SELECT qp_url, ms_url FROM past_papers WHERE paper_id=?");
      $st->bind_param('i', $paperId); $st->execute();
      $st->bind_result($u1,$u2); $st->fetch(); $st->close();
      $stmt = $conn->prepare("DELETE FROM past_papers WHERE paper_id=?");
      $stmt->bind_param('i', $paperId);
      if ($stmt->execute() && $stmt->affected_rows>0) {
        delete_local_if_uploaded($u1);
        delete_local_if_uploaded($u2);
        $flash = ['type'=>'success','msg'=>"Paper #$paperId deleted."];
      } else {
        $flash = ['type'=>'error','msg'=>"Delete failed (ID #$paperId)."];
      }
      $stmt->close();
    } else {
      $flash = ['type'=>'error','msg'=>'Invalid paper ID.'];
    }
  }

  if ($action === 'toggle_vis') {
    $paperId = (int)($_POST['paper_id'] ?? 0);
    if ($paperId > 0) {
      $st = $conn->prepare("SELECT visibility FROM past_papers WHERE paper_id=?");
      $st->bind_param('i', $paperId); $st->execute(); $st->bind_result($v); $ok = $st->fetch(); $st->close();
      if ($ok) {
        $new = ($v === 'public') ? 'private' : 'public';
        $u = $conn->prepare("UPDATE past_papers SET visibility=? WHERE paper_id=?");
        $u->bind_param('si', $new, $paperId); $u->execute();
        if ($u->affected_rows >= 0) $flash = ['type'=>'success','msg'=>"Visibility toggled to $new for #$paperId."];
        $u->close();
      }
    }
  }

  if ($action === 'bulk_delete') {
    $idsStr = $_POST['ids'] ?? '';
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsStr)), fn($x)=>$x>0)));
    if (!$ids) {
      $flash = ['type'=>'error','msg'=>'No items selected for bulk delete.'];
    } else {
      $deleted=0;
      foreach ($ids as $id) {
        $st = $conn->prepare("SELECT qp_url, ms_url FROM past_papers WHERE paper_id=?");
        $st->bind_param('i', $id); $st->execute(); $st->bind_result($u1,$u2); $has = $st->fetch(); $st->close();
        if (!$has) continue;
        $d = $conn->prepare("DELETE FROM past_papers WHERE paper_id=?");
        $d->bind_param('i', $id); $d->execute();
        if ($d->affected_rows>0) {
          $deleted++;
          delete_local_if_uploaded($u1);
          delete_local_if_uploaded($u2);
        }
        $d->close();
      }
      $flash = ['type'=>'success','msg'=>"Bulk deleted $deleted item(s)."];
    }
  }
}

// Filters for list
$q        = trim($_GET['q'] ?? '');
$fBoard   = $_GET['board'] ?? '';
$fLevel   = $_GET['level'] ?? '';
$fYear    = (int)($_GET['year'] ?? 0);
$fSession = $_GET['session'] ?? '';
$fVis     = $_GET['vis'] ?? '';
$sortKey  = $_GET['sort'] ?? 'newest';
$orderBy  = $SORTS[$sortKey] ?? $SORTS['newest'];
$perPage  = max(5, min(60, (int)($_GET['pp'] ?? 15)));
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page-1)*$perPage;

// WHERE builder
$where = ['1=1'];
$types = ''; $params = [];
if ($q !== '') {
  $where[] = "(subject LIKE ? OR syllabus_code LIKE ? OR tags LIKE ?)";
  $like = "%$q%"; $types .= 'sss';
  $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($fBoard && in_array($fBoard, $BOARD_OPTS,true)) { $where[]="board=?";   $types.='s'; $params[]=$fBoard; }
if ($fLevel && in_array($fLevel, $LEVEL_OPTS,true)) { $where[]="level=?";   $types.='s'; $params[]=$fLevel; }
if ($fYear) { $where[]="year=?"; $types.='i'; $params[]=$fYear; }
if ($fSession && in_array($fSession,$SESSIONS,true)) { $where[]="session=?"; $types.='s'; $params[]=$fSession; }
if ($fVis && in_array($fVis,$VIS_OPTS,true)) { $where[]="visibility=?"; $types.='s'; $params[]=$fVis; }
$whereSql = 'WHERE '.implode(' AND ',$where);

// CSV export
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=past_papers_export_'.date('Ymd_His').'.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Board','Level','Subject','Syllabus','Year','Session','Paper','Variant','QP URL','MS URL','Solution URL','Tags','Visibility','Downloads','Created']);
  $sql = "SELECT paper_id, board, level, subject, syllabus_code, year, session, paper_code, variant,
                 qp_url, ms_url, solution_url, tags, visibility, download_count, created_at
          FROM past_papers $whereSql ORDER BY $orderBy";
  $st = $conn->prepare($sql);
  if ($types!=='') $st->bind_param($types, ...$params);
  $st->execute();
  $rs = $st->get_result();
  while ($r = $rs->fetch_assoc()) {
    fputcsv($out, [
      $r['paper_id'],$r['board'],$r['level'],$r['subject'],$r['syllabus_code'],$r['year'],$r['session'],
      $r['paper_code'],$r['variant'],$r['qp_url'],$r['ms_url'],$r['solution_url'],$r['tags'],$r['visibility'],
      $r['download_count'],$r['created_at']
    ]);
  }
  fclose($out);
  exit;
}

// Count
$total = 0;
$sqlc = "SELECT COUNT(*) FROM past_papers $whereSql";
$st=$conn->prepare($sqlc);
if ($types!=='') $st->bind_param($types, ...$params);
$st->execute(); $st->bind_result($total); $st->fetch(); $st->close();

// Rows
$sql = "SELECT paper_id, board, level, subject, syllabus_code, year, session, paper_code, variant,
               qp_url, ms_url, solution_url, tags, visibility, download_count, created_at
        FROM past_papers
        $whereSql
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset";
$st=$conn->prepare($sql);
if ($types!=='') $st->bind_param($types, ...$params);
$st->execute();
$res = $st->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$st->close();

$totalPages = max(1,(int)ceil($total/$perPage));

// Courses map (board+level -> courses)
$cr = $conn->query("
  SELECT c.course_id, c.name, ct.board, ct.level
  FROM courses c
  JOIN course_types ct ON ct.course_type_id=c.course_type_id
  ORDER BY ct.board, ct.level, c.name
");
$coursesByBL = [];
if ($cr) {
  while ($r=$cr->fetch_assoc()) {
    $coursesByBL[$r['board']][$r['level']][] = ['id'=>(int)$r['course_id'],'name'=>$r['name']];
  }
}

// Helper for qs
function qs($more=[]){ $base=$_GET; unset($base['page']); $q=array_merge($base,$more); return '?'.http_build_query($q); }
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Past Papers</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    body {
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial;
      min-height: 100vh;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background:
        radial-gradient(circle at 0% 0%, rgba(129,140,248,0.18) 0, transparent 55%),
        radial-gradient(circle at 100% 50%, rgba(56,189,248,0.16) 0, transparent 55%);
      pointer-events: none;
      z-index: -1;
    }
    .btn-primary {
      display:inline-flex;align-items:center;gap:.5rem;color:#fff;
      padding:.6rem 1.05rem;border-radius:.8rem;
      background-image:linear-gradient(90deg,#4f46e5,#6366f1);
      box-shadow:0 12px 32px -14px rgba(79,70,229,.8);
      font-size:.875rem;font-weight:600;
      transition:filter .18s, box-shadow .18s, transform .12s;
    }
    .btn-primary:hover { filter:brightness(1.05); box-shadow:0 18px 40px -18px rgba(79,70,229,.95); transform:translateY(-1px); }
    .btn-primary:active { transform:translateY(0); box-shadow:0 8px 22px -14px rgba(79,70,229,.7); }

    .btn-ghost {
      display:inline-flex;align-items:center;gap:.5rem;
      padding:.55rem .9rem;border-radius:.75rem;
      background:#ffffff;border:1px solid #e2e8f0;color:#1f2937;
      font-size:.8rem;font-weight:500;
      transition:background-color .18s,border-color .18s, box-shadow .18s, transform .1s;
    }
    .btn-ghost:hover { background:#f8fafc;border-color:#cbd5e1; box-shadow:0 8px 18px -12px rgba(148,163,184,.7); transform:translateY(-1px); }
    .btn-ghost:active { transform:translateY(0); box-shadow:none; }

    .btn-danger {
      display:inline-flex;align-items:center;gap:.45rem;
      padding:.5rem .85rem;border-radius:.75rem;
      background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;
      font-size:.8rem;font-weight:500;
      transition:background-color .18s,border-color .18s,box-shadow .18s;
    }
    .btn-danger:hover { background:#fecaca;border-color:#fca5a5; box-shadow:0 10px 22px -14px rgba(248,113,113,.8); }

    .badge { display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .5rem;border-radius:.6rem;font-size:.7rem;font-weight:500; }

    .card {
      background:rgba(255,255,255,.94);
      border:1px solid rgba(226,232,240,.95);
      border-radius:1.5rem;
      box-shadow:0 18px 45px -22px rgba(15,23,42,.45);
      backdrop-filter:blur(18px);
    }
    .toast {
      position:fixed;right:1rem;top:1rem;z-index:50;padding:.75rem 1rem;border-radius:.9rem;
      box-shadow:0 14px 40px -18px rgba(15,23,42,.65);font-size:.85rem;font-weight:500;
    }
    .sticky-toolbar {
      position:sticky; top:0; z-index:30;
      backdrop-filter:blur(14px);
      background:linear-gradient(to bottom,rgba(255,255,255,.95),rgba(255,255,255,.9));
      border-bottom:1px solid rgba(226,232,240,.95);
    }
    .bulkbar {
      position:fixed;left:50%;transform:translateX(-50%);
      bottom:1.25rem;z-index:40;background:#020617;color:#f9fafb;
      display:none;align-items:center;
      padding:.6rem 1.1rem;border-radius:9999px;
      box-shadow:0 18px 40px -18px rgba(15,23,42,.9);
      font-size:.8rem;font-weight:500;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-slate-100 text-slate-900">

<?php if (file_exists(__DIR__.'/components/navbar.php')) include __DIR__.'/components/navbar.php'; ?>

<main class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-10 space-y-7">

  <!-- Hero -->
  <section class="relative overflow-hidden rounded-3xl shadow-xl bg-gradient-to-br from-indigo-950 via-slate-900 to-sky-900 text-white">
    <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-indigo-500/40 blur-3xl"></div>
    <div class="absolute -right-20 top-10 h-56 w-56 rounded-full bg-sky-400/40 blur-3xl"></div>

    <div class="relative z-10 px-6 py-7 sm:px-8 sm:py-8 flex flex-col gap-5">
      <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-2 text-xs sm:text-sm opacity-90">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/25">
            <i class="ph ph-files text-sm"></i>
          </span>
          <span>Admin · Past Papers</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i class="ph ph-shield-check text-sm"></i>
          <span>Admin access</span>
        </span>
      </div>

      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
        <div class="space-y-2">
          <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold tracking-tight">
            Add, manage & export past papers
          </h1>
          <p class="text-sm sm:text-base text-sky-100/90 max-w-2xl">
            Upload PDFs or link by URL, link to courses, control visibility, bulk delete and export everything to CSV in a few clicks.
          </p>
        </div>
        <div class="flex flex-col items-start sm:items-end gap-2 text-xs sm:text-sm text-sky-100/90">
          <div class="inline-flex items-center gap-2 rounded-2xl bg-black/25 px-3 py-1.5 backdrop-blur">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-400/95 text-emerald-950 text-[11px] font-semibold">
              ✓
            </span>
            <span>CSRF-protected admin tools</span>
          </div>
          <span class="text-[11px] sm:text-xs">
            Tip: start by adding a paper, then use filters to find and bulk-manage items.
          </span>
        </div>
      </div>
    </div>
  </section>

  <!-- Layout grid -->
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <?php
      $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
      $createAnnouncementLink = '#add';
      include 'components/admin_tools_sidebar.php';
    ?>

    <!-- Main content -->
    <section class="lg:col-span-9 space-y-6">

      <?php if ($flash): ?>
        <div id="toast" role="alert" aria-live="polite"
             class="toast <?= $flash['type']==='success' ? 'bg-emerald-100 text-emerald-900' : ($flash['type']==='error' ? 'bg-rose-100 text-rose-900' : 'bg-blue-100 text-blue-900') ?>">
          <?= e($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <!-- Top actions -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
          <a href="#add" class="btn-primary">
            <i class="ph ph-plus-circle text-sm"></i>
            <span>Add paper</span>
          </a>
          <a href="#manage" class="btn-ghost">
            <i class="ph ph-list-bullets text-sm"></i>
            <span>Go to list</span>
          </a>
        </div>
        <a href="<?= e(qs(['export'=>'csv'])) ?>"
           class="btn-ghost border-indigo-200 text-indigo-700 hover:bg-indigo-50">
          <i class="ph ph-download-simple text-sm"></i>
          <span>Export CSV</span>
        </a>
      </div>

      <!-- Add paper card -->
      <section id="add" class="card p-5 sm:p-7 space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-1">
          <div class="flex items-center gap-2">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 ring-1 ring-indigo-100">
              <i class="ph ph-plus-circle text-lg"></i>
            </span>
            <div>
              <h2 class="text-lg sm:text-xl font-semibold tracking-tight">Add Past Paper</h2>
              <p class="text-xs sm:text-sm text-slate-500">
                Link to a course, attach PDFs, and control who can see the paper.
              </p>
            </div>
          </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="space-y-6">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="add_paper">

          <!-- Row: Board / Level / Course -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Board</label>
              <select name="board" id="boardSel" required
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="">Select board</option>
                <?php foreach($BOARD_OPTS as $b): ?>
                  <option value="<?= e($b) ?>"><?= e($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Level</label>
              <select name="level" id="levelSel" required
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="">Select level</option>
                <?php foreach($LEVEL_OPTS as $lv): ?>
                  <option value="<?= e($lv) ?>"><?= e($lv) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">
                Link to Course <span class="text-slate-400 normal-case text-[11px] font-normal">(optional)</span>
              </label>
              <select name="course_id" id="courseSel"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="0">— None —</option>
              </select>
              <p class="mt-1 text-[11px] text-slate-500">
                If selected, subject can auto-fill from the course name.
              </p>
            </div>
          </div>

          <!-- Row: Subject / Syllabus -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-2">
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Subject</label>
              <input type="text" name="subject" id="subjectInput" required
                     class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                     placeholder="e.g., Chemistry">
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Syllabus code</label>
              <input type="text" name="syllabus_code"
                     class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                     placeholder="e.g., 0620 or 9709">
            </div>
          </div>

          <!-- Row: Year / Session / Visibility -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Year</label>
              <input type="number" name="year" min="2000" max="<?= date('Y')+1 ?>" required
                     class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Session</label>
              <select name="session"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <?php foreach($SESSIONS as $s): ?>
                  <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Visibility</label>
              <select name="visibility"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <?php foreach($VIS_OPTS as $v): ?>
                  <option value="<?= e($v) ?>"><?= e(ucfirst($v)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Row: Paper / Variant / Tags -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Paper code</label>
              <input type="text" name="paper_code"
                     class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                     placeholder="e.g., P11, Paper 1">
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Variant</label>
              <input type="text" name="variant"
                     class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                     placeholder="e.g., 11, 12">
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">Tags</label>
              <input type="text" name="tags"
                     class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                     placeholder="igcse,chemistry,0620">
            </div>
          </div>

          <!-- Files -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5">
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">
                Question paper <span class="text-rose-500">*</span>
              </label>
              <input type="url" name="qp_url"
                     class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 mb-2"
                     placeholder="https://...pdf (optional if you upload)">
              <input type="file" name="qp_file" accept="application/pdf"
                     class="block w-full text-xs text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100">
              <p class="mt-1 text-[11px] text-slate-500">
                Provide either a direct PDF URL or upload a file.
              </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5">
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">
                Mark scheme <span class="text-slate-400 normal-case text-[11px] font-normal">(optional)</span>
              </label>
              <input type="url" name="ms_url"
                     class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 mb-2"
                     placeholder="https://...pdf">
              <input type="file" name="ms_file" accept="application/pdf"
                     class="block w-full text-xs text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
            </div>
          </div>

          <!-- Solution URL -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-700 uppercase tracking-wide mb-1.5">
                Solution URL <span class="text-slate-400 normal-case text-[11px] font-normal">(optional)</span>
              </label>
              <input type="url" name="solution_url"
                     class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                     placeholder="https://video.example.com/solution">
            </div>
          </div>

          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pt-1">
            <p class="text-[11px] text-slate-500">
              Ensure <code class="px-1.5 py-0.5 rounded bg-slate-100 border border-slate-200 text-[10px]">uploads/papers</code>
              exists and is writable (e.g. <code>775</code> / <code>777</code>) for file uploads.
            </p>
            <button class="btn-primary self-start sm:self-auto">
              <i class="ph ph-plus-circle text-sm"></i>
              <span>Add paper</span>
            </button>
          </div>
        </form>
      </section>

      <!-- Manage card -->
      <section id="manage" class="card overflow-hidden">
        <!-- Sticky toolbar: header + filters -->
        <div id="listToolbar" class="sticky-toolbar px-5 sm:px-6 pt-4 pb-4 space-y-4">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-3">
              <span class="inline-flex h-9 w-9 items-center justify-center rounded-2xl bg-sky-50 text-sky-600 ring-1 ring-sky-100">
                <i class="ph ph-stack text-lg"></i>
              </span>
              <div>
                <h2 class="text-base sm:text-lg font-semibold tracking-tight">Manage Past Papers</h2>
                <p class="text-[11px] sm:text-xs text-slate-500">
                  Use filters, bulk select and quick actions to keep your archive organised.
                </p>
              </div>
            </div>
            <div class="flex items-center gap-2 md:ml-auto">
              <button id="bulkDelBtn" type="button" class="btn-danger">
                <i class="ph ph-trash text-sm"></i>
                <span>Bulk delete</span>
              </button>
            </div>
          </div>

          <!-- Filters -->
          <form method="get" class="grid grid-cols-2 md:grid-cols-6 gap-2">
            <input type="hidden" name="page" value="1">
            <div class="md:col-span-2">
              <label class="block text-[11px] text-slate-600 mb-1">Search</label>
              <input type="text" name="q" value="<?= e($q) ?>" placeholder="Subject / code / tags..."
                     class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
            </div>
            <div>
              <label class="block text-[11px] text-slate-600 mb-1">Board</label>
              <select name="board"
                      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="">All</option>
                <?php foreach($BOARD_OPTS as $b): ?>
                  <option value="<?= e($b) ?>" <?= $fBoard===$b?'selected':'' ?>><?= e($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-[11px] text-slate-600 mb-1">Level</label>
              <select name="level"
                      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="">All</option>
                <?php foreach($LEVEL_OPTS as $lv): ?>
                  <option value="<?= e($lv) ?>" <?= $fLevel===$lv?'selected':'' ?>><?= e($lv) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-[11px] text-slate-600 mb-1">Year</label>
              <input type="number" name="year" value="<?= $fYear?:'' ?>"
                     class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
            </div>
            <div>
              <label class="block text-[11px] text-slate-600 mb-1">Session</label>
              <select name="session"
                      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="">All</option>
                <?php foreach($SESSIONS as $s): ?>
                  <option value="<?= e($s) ?>" <?= $fSession===$s?'selected':'' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-[11px] text-slate-600 mb-1">Visibility</label>
              <select name="vis"
                      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="">All</option>
                <?php foreach($VIS_OPTS as $v): ?>
                  <option value="<?= e($v) ?>" <?= $fVis===$v?'selected':'' ?>><?= e(ucfirst($v)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-[11px] text-slate-600 mb-1">Sort</label>
              <select name="sort"
                      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <option value="newest" <?= $sortKey==='newest'?'selected':'' ?>>Newest</option>
                <option value="oldest" <?= $sortKey==='oldest'?'selected':'' ?>>Oldest</option>
                <option value="downloads" <?= $sortKey==='downloads'?'selected':'' ?>>Most downloads</option>
                <option value="year_desc" <?= $sortKey==='year_desc'?'selected':'' ?>>Year (desc)</option>
              </select>
            </div>
            <div>
              <label class="block text-[11px] text-slate-600 mb-1">Per page</label>
              <select name="pp"
                      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                <?php foreach([10,15,20,30,50] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="md:col-span-2 flex items-center gap-2 pt-1">
              <button class="btn-ghost">
                <i class="ph ph-funnel text-sm"></i>
                <span>Filter</span>
              </button>
              <a href="admin_past_papers.php#manage" class="btn-ghost">
                <i class="ph ph-arrow-counter-clockwise text-sm"></i>
                <span>Reset</span>
              </a>
            </div>
          </form>
        </div>

        <!-- List + pagination -->
        <div class="px-5 sm:px-6 pb-5 pt-3">
          <?php if (!$rows): ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 px-4 py-6 text-center text-sm text-slate-600">
              No past papers match your current filters. Try changing search or filters above.
            </div>
          <?php else: ?>
            <form id="tableForm" onsubmit="return false;">
              <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                  <thead id="tableHead"
                         class="bg-slate-50/95 backdrop-blur text-slate-600 sticky z-10"
                         style="top: var(--thead-offset, 0px);">
                    <tr class="text-xs font-semibold uppercase tracking-wide">
                      <th class="px-3 py-2">
                        <input type="checkbox" id="checkAll" class="w-4 h-4 text-indigo-600 rounded border-slate-300">
                      </th>
                      <th class="px-3 py-2 text-left">ID</th>
                      <th class="px-3 py-2 text-left">Subject</th>
                      <th class="px-3 py-2 text-left">Board · Level</th>
                      <th class="px-3 py-2 text-left">Year / Session</th>
                      <th class="px-3 py-2 text-left">Paper</th>
                      <th class="px-3 py-2 text-left">Files</th>
                      <th class="px-3 py-2 text-left">Visibility</th>
                      <th class="px-3 py-2 text-left">Downloads</th>
                      <th class="px-3 py-2 text-left">Created</th>
                      <th class="px-3 py-2 text-center">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <?php foreach($rows as $r): ?>
                      <tr class="hover:bg-slate-50/80">
                        <td class="px-3 py-2 align-top">
                          <input type="checkbox" class="row-check w-4 h-4 text-indigo-600 rounded border-slate-300"
                                 value="<?= (int)$r['paper_id'] ?>">
                        </td>
                        <td class="px-3 py-2 align-top text-xs text-slate-500">
                          #<?= (int)$r['paper_id'] ?>
                        </td>
                        <td class="px-3 py-2 align-top">
                          <div class="font-medium text-slate-900"><?= e($r['subject']) ?></div>
                          <?php if ($r['syllabus_code']): ?>
                            <div class="text-xs text-slate-500">Code: <?= e($r['syllabus_code']) ?></div>
                          <?php endif; ?>
                          <?php if ($r['tags']): ?>
                            <div class="mt-0.5 text-[11px] text-slate-400 truncate max-w-[180px]">
                              Tags: <?= e($r['tags']) ?>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 align-top text-sm">
                          <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-700 ring-1 ring-slate-200">
                            <i class="ph ph-layers text-xs"></i>
                            <?= e($r['board']) ?> · <?= e($r['level']) ?>
                          </span>
                        </td>
                        <td class="px-3 py-2 align-top text-sm">
                          <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-1 text-[11px] font-medium text-indigo-700 ring-1 ring-indigo-100">
                            <i class="ph ph-calendar text-xs"></i>
                            <?= (int)$r['year'] ?> · <?= e($r['session']) ?>
                          </span>
                        </td>
                        <td class="px-3 py-2 align-top text-sm">
                          <?= e($r['paper_code'] ?: '—') ?>
                          <?= $r['variant'] ? ' · '.e($r['variant']) : '' ?>
                        </td>
                        <td class="px-3 py-2 align-top">
                          <div class="flex flex-wrap items-center gap-2 text-xs">
                            <a href="<?= e($r['qp_url']) ?>" target="_blank"
                               class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-1 text-indigo-700 ring-1 ring-indigo-100 hover:bg-indigo-100"
                               title="Question paper">
                              <i class="ph ph-file-pdf text-xs"></i> QP
                            </a>
                            <?php if ($r['ms_url']): ?>
                              <a href="<?= e($r['ms_url']) ?>" target="_blank"
                                 class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-slate-700 ring-1 ring-slate-200 hover:bg-slate-200"
                                 title="Mark scheme">
                                <i class="ph ph-clipboard-text text-xs"></i> MS
                              </a>
                            <?php endif; ?>
                            <?php if ($r['solution_url']): ?>
                              <a href="<?= e($r['solution_url']) ?>" target="_blank"
                                 class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100"
                                 title="Solution">
                                <i class="ph ph-play-circle text-xs"></i> Sol
                              </a>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td class="px-3 py-2 align-top">
                          <?php
                            $st = $r['visibility']; $cls='bg-slate-100 text-slate-800';
                            if ($st==='public') $cls='bg-emerald-100 text-emerald-800';
                            if ($st==='private') $cls='bg-amber-100 text-amber-800';
                          ?>
                          <div class="flex items-center gap-2">
                            <span class="badge <?= $cls ?> ring-1 ring-black/5">
                              <?= e(ucfirst($st)) ?>
                            </span>
                            <form method="post" class="inline-block">
                              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                              <input type="hidden" name="action" value="toggle_vis">
                              <input type="hidden" name="paper_id" value="<?= (int)$r['paper_id'] ?>">
                              <button class="text-[11px] text-slate-600 hover:text-slate-900 underline">
                                Toggle
                              </button>
                            </form>
                          </div>
                        </td>
                        <td class="px-3 py-2 align-top text-sm">
                          <?= (int)$r['download_count'] ?>
                        </td>
                        <td class="px-3 py-2 align-top text-xs text-slate-500">
                          <?= e(date('Y-m-d H:i', strtotime($r['created_at']))) ?>
                        </td>
                        <td class="px-3 py-2 align-top text-center">
                          <form method="post" onsubmit="return confirm('Delete this past paper?');" class="inline-block">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete_paper">
                            <input type="hidden" name="paper_id" value="<?= (int)$r['paper_id'] ?>">
                            <button class="text-rose-600 hover:text-rose-800 inline-flex items-center gap-1 text-xs font-medium">
                              <i class="ph ph-trash text-xs"></i> Remove
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </form>

            <!-- Pagination -->
            <nav class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-sm">
              <a class="btn-ghost <?= $page<=1?'opacity-50 pointer-events-none':'' ?>"
                 href="<?= e(qs(['page'=>$page-1])) ?>#manage">
                <i class="ph ph-arrow-left text-sm"></i>
                <span>Previous</span>
              </a>
              <div class="text-xs sm:text-sm text-slate-600 text-center">
                Page <?= $page ?> of <?= $totalPages ?> ·
                Showing <?= $total ? ($offset+1) : 0 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?>
              </div>
              <a class="btn-ghost <?= $page>=$totalPages?'opacity-50 pointer-events-none':'' ?>"
                 href="<?= e(qs(['page'=>$page+1])) ?>#manage">
                <span>Next</span>
                <i class="ph ph-arrow-right text-sm"></i>
              </a>
            </nav>
          <?php endif; ?>
        </div>
      </section>
    </section>
  </div>
</main>

<!-- Floating bulk action bar -->
<div id="bulkBar" class="bulkbar">
  <span id="bulkCount" class="font-semibold">0</span> selected
  <button id="bulkBarBtn"
          class="ml-3 inline-flex items-center gap-1 bg-rose-500 hover:bg-rose-600 text-white px-3 py-1.5 rounded-md text-xs font-semibold">
    <i class="ph ph-trash text-xs"></i> Delete selected
  </button>
</div>

<?php if (file_exists(__DIR__.'/components/footer.php')) include __DIR__.'/components/footer.php'; ?>

<script>
  // Toast auto-hide
  const toast = document.getElementById('toast');
  if (toast) setTimeout(()=> toast.style.display='none', 3500);

  // Courses map: board -> level -> [ {id, name} ]
  const COURSES = <?= json_encode($coursesByBL, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const boardSel   = document.getElementById('boardSel');
  const levelSel   = document.getElementById('levelSel');
  const courseSel  = document.getElementById('courseSel');
  const subjectInp = document.getElementById('subjectInput');

  function populateCourses() {
    const b = boardSel?.value || '';
    const l = levelSel?.value || '';
    const list = (COURSES[b] && COURSES[b][l]) ? COURSES[b][l] : [];
    if (!courseSel) return;
    courseSel.innerHTML = '<option value="0">— None —</option>';
    list.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id; opt.textContent = c.name;
      courseSel.appendChild(opt);
    });
  }
  courseSel?.addEventListener('change', () => {
    const label = courseSel.selectedOptions[0]?.textContent || '';
    if (label && !subjectInp.value) subjectInp.value = label;
  });
  boardSel?.addEventListener('change', populateCourses);
  levelSel?.addEventListener('change', populateCourses);

  // Sticky table header offset under sticky toolbar
  const toolbar = document.getElementById('listToolbar');
  function adjustStickyOffset() {
    const h = toolbar ? toolbar.offsetHeight : 0;
    document.documentElement.style.setProperty('--thead-offset', (h + 8) + 'px'); // +8px gap
  }
  adjustStickyOffset();
  window.addEventListener('resize', adjustStickyOffset);

  // Bulk delete + selection bar
  const bulkBtn = document.getElementById('bulkDelBtn');
  const bulkBar = document.getElementById('bulkBar');
  const bulkBarBtn = document.getElementById('bulkBarBtn');
  const bulkCount = document.getElementById('bulkCount');
  const checkAll = document.getElementById('checkAll');

  function selectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(x => x.value);
  }
  function updateBulkBar() {
    const ids = selectedIds();
    if (ids.length) {
      bulkCount.textContent = ids.length;
      bulkBar.style.display = 'flex';
    } else {
      bulkBar.style.display = 'none';
    }
  }
  document.addEventListener('change', (e) => {
    if (e.target.matches('.row-check')) updateBulkBar();
  });
  checkAll?.addEventListener('change', () => {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = checkAll.checked);
    updateBulkBar();
  });

  function submitBulk(ids) {
    const f = document.createElement('form');
    f.method = 'post'; f.action = '';
    f.innerHTML = `
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="bulk_delete">
      <input type="hidden" name="ids" value="${ids.join(',')}">
    `;
    document.body.appendChild(f);
    f.submit();
  }

  bulkBtn?.addEventListener('click', () => {
    const ids = selectedIds();
    if (!ids.length) { alert('Select at least one row.'); return; }
    if (confirm(\`Delete \${ids.length} selected item(s)?\`)) submitBulk(ids);
  });
  bulkBarBtn?.addEventListener('click', () => {
    const ids = selectedIds();
    if (!ids.length) return;
    if (confirm(\`Delete \${ids.length} selected item(s)?\`)) submitBulk(ids);
  });

  // Initial UI setup
  document.addEventListener('DOMContentLoaded', () => {
    populateCourses();
    adjustStickyOffset();
  });

  // Mobile tools drawer controls (for admin_tools_sidebar.php)
  (function() {
    const openBtn = document.getElementById('toolsOpen');
    const closeBtn = document.getElementById('toolsClose');
    const drawer = document.getElementById('toolsDrawer');
    const overlay = document.getElementById('toolsOverlay');
    let prevFocus = null;

    function openDrawer() {
      prevFocus = document.activeElement;
      if (!drawer) return;
      drawer.style.transform = 'translateX(0)';
      overlay && overlay.classList.remove('hidden');
      drawer.setAttribute('aria-hidden', 'false');
      openBtn?.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
      const first = drawer.querySelector('a,button');
      first && first.focus();
      document.addEventListener('keydown', onKeydown);
      overlay && overlay.addEventListener('click', closeDrawer, { once: true });
    }
    function closeDrawer() {
      if (!drawer) return;
      drawer.style.transform = 'translateX(-100%)';
      overlay && overlay.classList.add('hidden');
      drawer.setAttribute('aria-hidden', 'true');
      openBtn?.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeydown);
      prevFocus && prevFocus.focus && prevFocus.focus();
    }
    function onKeydown(e) {
      if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); }
    }

    openBtn?.addEventListener('click', openDrawer);
    closeBtn?.addEventListener('click', closeDrawer);
    drawer && drawer.addEventListener('click', (e) => {
      const t = e.target.closest('a,button');
      if (!t) return;
      if (window.innerWidth < 1024) closeDrawer();
    });
    window.addEventListener('resize', () => { if (window.innerWidth >= 1024) closeDrawer(); });
  })();
</script>

</body>
</html>