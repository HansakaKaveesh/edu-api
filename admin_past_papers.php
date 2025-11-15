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
  // allow local relative paths like uploads/papers/xxx.pdf
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
  // Return relative path
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

    // If a course is selected, auto-fill subject if empty
    if ($courseId > 0 && $subject === '') {
      if ($stmt = $conn->prepare("SELECT name FROM courses WHERE course_id=?")) {
        $stmt->bind_param('i', $courseId);
        $stmt->execute(); $stmt->bind_result($cname);
        if ($stmt->fetch()) $subject = $cname ?: $subject;
        $stmt->close();
      }
    }
    if ($subject === '') $errors[] = 'Subject is required (or select a course)';

    // Save or accept files
    $qp_url = save_pdf_upload('qp_file', $qp_url_in);
    $ms_url = save_pdf_upload('ms_file', $ms_url_in);
    if (!$qp_url) $errors[] = 'Question paper (PDF) is required via URL or file upload.';
    if ($sol_url !== '' && !filter_var($sol_url, FILTER_VALIDATE_URL)) {
      $errors[] = 'Solution URL must be a valid link';
    }

    // Optional: verify course matches board+level
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

    // Duplicate guard
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
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-blue-50 text-gray-800">

<?php if (file_exists(__DIR__.'/components/navbar.php')) include __DIR__.'/components/navbar.php'; ?>

<!-- Hero -->
<section class="relative overflow-hidden rounded-3xl shadow max-w-7xl mx-auto mt-20 mb-8">
  <div class="absolute inset-0 bg-gradient-to-br from-primary-900 via-cyan-700 to-primary-600"></div>
  <div class="relative z-10 text-white p-6 sm:p-8">
    <div class="flex items-center justify-between">
      <div class="text-sm opacity-90 inline-flex items-center gap-2">
        <i class="ph ph-files"></i> Admin · Past Papers
      </div>
      <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/20 px-3 py-1 rounded-full text-xs">
        <i class="ph ph-shield-check"></i> Admin access
      </span>
    </div>
    <div class="mt-3 text-center">
      <h1 class="text-2xl sm:text-3xl font-extrabold">Add, manage, and export past papers</h1>
      <p class="text-white/90 text-sm">Upload PDFs or link by URL. Toggle visibility. Bulk delete. Export CSV.</p>
    </div>
  </div>
</section>

<main class="max-w-7xl mx-auto px-6 pb-16">

  <?php if ($flash): ?>
    <div id="toast" class="mb-4 px-4 py-3 rounded <?= $flash['type']==='success'?'bg-green-100 text-green-800':($flash['type']==='error'?'bg-red-100 text-red-800':'bg-blue-100 text-blue-800') ?>">
      <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="mb-4 flex items-center gap-3">
    <a href="#add" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-slate-200">
      <i class="ph ph-plus-circle"></i> Add Paper
    </a>
    <a href="#manage" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-slate-200">
      <i class="ph ph-list-bullets"></i> Manage
    </a>
    <a href="<?= e(qs(['export'=>'csv'])) ?>" class="ml-auto inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-primary-200 text-primary-700 hover:bg-primary-50">
      <i class="ph ph-download-simple"></i> Export CSV
    </a>
  </div>

  <!-- Add paper -->
  <section id="add" class="bg-white rounded-2xl border border-slate-100 shadow p-6 mb-8">
    <h2 class="text-xl font-semibold mb-4 inline-flex items-center gap-2">
      <i class="ph ph-plus-circle text-primary-600"></i> Add Past Paper
    </h2>
    <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="add_paper">

      <div>
        <label class="block text-sm text-slate-700 mb-1">Board</label>
        <select name="board" id="boardSel" required class="w-full rounded-lg border-slate-300 px-3 py-2">
          <option value="">Select board</option>
          <?php foreach($BOARD_OPTS as $b): ?>
            <option value="<?= e($b) ?>"><?= e($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Level</label>
        <select name="level" id="levelSel" required class="w-full rounded-lg border-slate-300 px-3 py-2">
          <option value="">Select level</option>
          <?php foreach($LEVEL_OPTS as $lv): ?>
            <option value="<?= e($lv) ?>"><?= e($lv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Link to Course (optional)</label>
        <select name="course_id" id="courseSel" class="w-full rounded-lg border-slate-300 px-3 py-2">
          <option value="0">— None —</option>
        </select>
        <p class="text-xs text-slate-500 mt-1">Choosing a course helps search and may auto-fill subject.</p>
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Subject</label>
        <input type="text" name="subject" id="subjectInput" required class="w-full rounded-lg border-slate-300 px-3 py-2" placeholder="e.g., Chemistry">
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Syllabus Code</label>
        <input type="text" name="syllabus_code" class="w-full rounded-lg border-slate-300 px-3 py-2" placeholder="e.g., 0620 or 9709">
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Year</label>
        <input type="number" name="year" min="2000" max="<?= date('Y')+1 ?>" required class="w-full rounded-lg border-slate-300 px-3 py-2">
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Session</label>
        <select name="session" required class="w-full rounded-lg border-slate-300 px-3 py-2">
          <?php foreach($SESSIONS as $s): ?>
            <option value="<?= e($s) ?>"><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Paper Code</label>
        <input type="text" name="paper_code" class="w-full rounded-lg border-slate-300 px-3 py-2" placeholder="e.g., P11, Paper 1">
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Variant</label>
        <input type="text" name="variant" class="w-full rounded-lg border-slate-300 px-3 py-2" placeholder="e.g., 11, 12">
      </div>

      <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="border rounded-lg p-3">
          <label class="block text-sm text-slate-700 mb-1">Question Paper (URL or upload)</label>
          <input type="url" name="qp_url" class="w-full rounded-lg border-slate-300 px-3 py-2 mb-2" placeholder="https://...pdf">
          <input type="file" name="qp_file" accept="application/pdf" class="block w-full text-sm">
        </div>
        <div class="border rounded-lg p-3">
          <label class="block text-sm text-slate-700 mb-1">Mark Scheme (URL or upload)</label>
          <input type="url" name="ms_url" class="w-full rounded-lg border-slate-300 px-3 py-2 mb-2" placeholder="https://...pdf">
          <input type="file" name="ms_file" accept="application/pdf" class="block w-full text-sm">
        </div>
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Solution URL (optional)</label>
        <input type="url" name="solution_url" class="w-full rounded-lg border-slate-300 px-3 py-2" placeholder="https://video.example.com/solution">
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Tags (comma separated)</label>
        <input type="text" name="tags" class="w-full rounded-lg border-slate-300 px-3 py-2" placeholder="igcse,chemistry,0620">
      </div>

      <div>
        <label class="block text-sm text-slate-700 mb-1">Visibility</label>
        <select name="visibility" class="w-full rounded-lg border-slate-300 px-3 py-2">
          <?php foreach($VIS_OPTS as $v): ?>
            <option value="<?= e($v) ?>"><?= e(ucfirst($v)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="md:col-span-2">
        <button class="inline-flex items-center gap-2 bg-primary-600 text-black px-5 py-2.5 rounded-lg hover:bg-primary-700">
          <i class="ph ph-plus-circle"></i> Add Paper
        </button>
      </div>
    </form>
    <p class="text-xs text-slate-500 mt-3">Tip: If you upload PDFs, ensure uploads/papers exists and is writable (chmod 775/777).</p>
  </section>

  <!-- Manage -->
  <section id="manage" class="bg-white rounded-2xl border border-slate-100 shadow p-6">
    <div class="flex flex-col md:flex-row md:items-end gap-3 mb-4">
      <form method="get" class="flex-1 grid grid-cols-2 md:grid-cols-6 gap-2">
        <input type="hidden" name="page" value="1">
        <div class="md:col-span-2">
          <label class="block text-xs text-slate-600 mb-1">Search</label>
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Subject/code/tags..." class="w-full rounded-lg border-slate-300 px-3 py-2">
        </div>
        <div>
          <label class="block text-xs text-slate-600 mb-1">Board</label>
          <select name="board" class="w-full rounded-lg border-slate-300 px-3 py-2">
            <option value="">All</option>
            <?php foreach($BOARD_OPTS as $b): ?>
              <option value="<?= e($b) ?>" <?= $fBoard===$b?'selected':'' ?>><?= e($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-600 mb-1">Level</label>
          <select name="level" class="w-full rounded-lg border-slate-300 px-3 py-2">
            <option value="">All</option>
            <?php foreach($LEVEL_OPTS as $lv): ?>
              <option value="<?= e($lv) ?>" <?= $fLevel===$lv?'selected':'' ?>><?= e($lv) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-600 mb-1">Year</label>
          <input type="number" name="year" value="<?= $fYear?:'' ?>" class="w-full rounded-lg border-slate-300 px-3 py-2">
        </div>
        <div>
          <label class="block text-xs text-slate-600 mb-1">Session</label>
          <select name="session" class="w-full rounded-lg border-slate-300 px-3 py-2">
            <option value="">All</option>
            <?php foreach($SESSIONS as $s): ?>
              <option value="<?= e($s) ?>" <?= $fSession===$s?'selected':'' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-600 mb-1">Visibility</label>
          <select name="vis" class="w-full rounded-lg border-slate-300 px-3 py-2">
            <option value="">All</option>
            <?php foreach($VIS_OPTS as $v): ?>
              <option value="<?= e($v) ?>" <?= $fVis===$v?'selected':'' ?>><?= e(ucfirst($v)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-600 mb-1">Sort</label>
          <select name="sort" class="w-full rounded-lg border-slate-300 px-3 py-2">
            <option value="newest" <?= $sortKey==='newest'?'selected':'' ?>>Newest</option>
            <option value="oldest" <?= $sortKey==='oldest'?'selected':'' ?>>Oldest</option>
            <option value="downloads" <?= $sortKey==='downloads'?'selected':'' ?>>Most downloads</option>
            <option value="year_desc" <?= $sortKey==='year_desc'?'selected':'' ?>>Year (desc)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-600 mb-1">Per page</label>
          <select name="pp" class="w-full rounded-lg border-slate-300 px-3 py-2">
            <?php foreach([10,15,20,30,50] as $pp): ?>
              <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-2 flex items-center gap-2">
          <button class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white border border-slate-300 hover:bg-slate-50">
            <i class="ph ph-funnel"></i> Filter
          </button>
          <a href="admin_past_papers.php#manage" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
            <i class="ph ph-arrow-counter-clockwise"></i> Reset
          </a>
        </div>
      </form>

      <div class="flex items-center gap-2 md:ml-auto">
        <button id="bulkDelBtn" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100">
          <i class="ph ph-trash"></i> Bulk Delete
        </button>
      </div>
    </div>

    <?php if (!$rows): ?>
      <div class="text-slate-600">No past papers match your filters.</div>
    <?php else: ?>
      <form id="tableForm" onsubmit="return false;">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-3 py-2"><input type="checkbox" id="checkAll" class="w-4 h-4"></th>
                <th class="px-3 py-2 text-left">ID</th>
                <th class="px-3 py-2 text-left">Subject</th>
                <th class="px-3 py-2 text-left">Board · Level</th>
                <th class="px-3 py-2 text-left">Year/Session</th>
                <th class="px-3 py-2 text-left">Paper</th>
                <th class="px-3 py-2 text-left">Files</th>
                <th class="px-3 py-2 text-left">Visibility</th>
                <th class="px-3 py-2 text-left">Downloads</th>
                <th class="px-3 py-2 text-left">Created</th>
                <th class="px-3 py-2 text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                  <td class="px-3 py-2 align-top">
                    <input type="checkbox" class="row-check w-4 h-4" value="<?= (int)$r['paper_id'] ?>">
                  </td>
                  <td class="px-3 py-2 align-top"><?= (int)$r['paper_id'] ?></td>
                  <td class="px-3 py-2 align-top">
                    <div class="font-medium"><?= e($r['subject']) ?></div>
                    <?php if ($r['syllabus_code']): ?>
                      <div class="text-xs text-slate-500">Code: <?= e($r['syllabus_code']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-2 align-top"><?= e($r['board']) ?> · <?= e($r['level']) ?></td>
                  <td class="px-3 py-2 align-top"><?= (int)$r['year'] ?> <?= e($r['session']) ?></td>
                  <td class="px-3 py-2 align-top"><?= e($r['paper_code'] ?: '—') ?><?= $r['variant'] ? ' · '.e($r['variant']) : '' ?></td>
                  <td class="px-3 py-2 align-top">
                    <div class="flex items-center gap-2">
                      <a href="<?= e($r['qp_url']) ?>" target="_blank" class="text-primary-700 hover:underline" title="Question paper"><i class="ph ph-file-pdf"></i> QP</a>
                      <?php if ($r['ms_url']): ?>
                        <a href="<?= e($r['ms_url']) ?>" target="_blank" class="text-slate-700 hover:underline" title="Mark scheme"><i class="ph ph-clipboard-text"></i> MS</a>
                      <?php endif; ?>
                      <?php if ($r['solution_url']): ?>
                        <a href="<?= e($r['solution_url']) ?>" target="_blank" class="text-slate-700 hover:underline" title="Solution"><i class="ph ph-play-circle"></i> Sol</a>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="px-3 py-2 align-top">
                    <?php
                      $st = $r['visibility']; $badge='bg-slate-100 text-slate-800';
                      if ($st==='public') $badge='bg-green-100 text-green-800';
                      if ($st==='private') $badge='bg-amber-100 text-amber-800';
                    ?>
                    <div class="flex items-center gap-2">
                      <span class="text-xs px-2 py-1 rounded <?= $badge ?>"><?= e(ucfirst($st)) ?></span>
                      <form method="post" class="inline-block">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="toggle_vis">
                        <input type="hidden" name="paper_id" value="<?= (int)$r['paper_id'] ?>">
                        <button class="text-slate-600 hover:text-slate-900 text-xs underline">Toggle</button>
                      </form>
                    </div>
                  </td>
                  <td class="px-3 py-2 align-top"><?= (int)$r['download_count'] ?></td>
                  <td class="px-3 py-2 align-top text-slate-500"><?= e(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                  <td class="px-3 py-2 align-top text-center">
                    <form method="post" onsubmit="return confirm('Delete this past paper?');" class="inline-block">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_paper">
                      <input type="hidden" name="paper_id" value="<?= (int)$r['paper_id'] ?>">
                      <button class="inline-flex items-center gap-1 text-red-600 hover:text-red-800">
                        <i class="ph ph-trash"></i> Remove
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
      <nav class="mt-4 flex items-center justify-between">
        <a class="px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 <?= $page<=1?'opacity-50 pointer-events-none':'' ?>" href="<?= e(qs(['page'=>$page-1])) ?>#manage">
          <i class="ph ph-arrow-left"></i> Prev
        </a>
        <div class="text-sm text-slate-600">Page <?= $page ?> of <?= $totalPages ?> · Showing <?= $total ? ($offset+1) : 0 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></div>
        <a class="px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 <?= $page>=$totalPages?'opacity-50 pointer-events-none':'' ?>" href="<?= e(qs(['page'=>$page+1])) ?>#manage">
          Next <i class="ph ph-arrow-right"></i>
        </a>
      </nav>
    <?php endif; ?>
  </section>
</main>

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
    const b = boardSel.value || '';
    const l = levelSel.value || '';
    const list = (COURSES[b] && COURSES[b][l]) ? COURSES[b][l] : [];
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

  // Bulk delete
  const bulkBtn = document.getElementById('bulkDelBtn');
  const tableForm = document.getElementById('tableForm');
  const checkAll = document.getElementById('checkAll');

  checkAll?.addEventListener('change', () => {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = checkAll.checked);
  });

  bulkBtn?.addEventListener('click', () => {
    const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(x => x.value);
    if (!ids.length) { alert('Select at least one row.'); return; }
    if (!confirm(`Delete ${ids.length} selected item(s)?`)) return;

    const f = document.createElement('form');
    f.method = 'post'; f.action = '';
    f.innerHTML = `
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="bulk_delete">
      <input type="hidden" name="ids" value="${ids.join(',')}">
    `;
    document.body.appendChild(f);
    f.submit();
  });
</script>

</body>
</html>