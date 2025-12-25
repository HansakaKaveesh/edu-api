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

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

/*
Schema used:

CREATE TABLE contents (
  content_id INT PRIMARY KEY AUTO_INCREMENT,
  course_id INT NOT NULL,
  type ENUM('lesson','video','pdf','forum','quiz'),
  title VARCHAR(150),
  description TEXT,
  body TEXT,
  file_url VARCHAR(255),
  position INT,
  FOREIGN KEY (course_id) REFERENCES courses(course_id)
);
*/

/* --------------------------------
   Handle POST actions (delete)
-----------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['content_id'])) {
    $action     = $_POST['action'];
    $content_id = (int)$_POST['content_id'];

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        header("Location: manage_contents.php?err=" . urlencode('Invalid session. Please refresh and try again.'));
        exit;
    }

    if ($content_id <= 0) {
        header("Location: manage_contents.php?err=" . urlencode('Invalid content.'));
        exit;
    }

    if ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM contents WHERE content_id = ? LIMIT 1");
        if ($stmt === false) {
            header("Location: manage_contents.php?err=" . urlencode('Failed to prepare delete statement.'));
            exit;
        }
        $stmt->bind_param("i", $content_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_contents.php?msg=" . urlencode("Content #{$content_id} deleted."));
            exit;
        } else {
            $stmt->close();
            header("Location: manage_contents.php?err=" . urlencode('Failed to delete content.'));
            exit;
        }
    }

    header("Location: manage_contents.php");
    exit;
}

/* --------------------------------
   Fetch contents with course info
-----------------------------------*/
$contents = [];
$sql = "
  SELECT
    c.content_id,
    c.type,
    c.title,
    c.description,
    c.body,
    c.file_url,
    c.position,
    crs.course_id,
    crs.name       AS course_name,
    ct.board,
    ct.level
  FROM contents c
  JOIN courses crs
    ON c.course_id = crs.course_id
  LEFT JOIN course_types ct
    ON crs.course_type_id = ct.course_type_id
  ORDER BY crs.name ASC, c.position ASC, c.content_id ASC
";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $contents[] = [
            'content_id'  => (int)$row['content_id'],
            'type'        => $row['type'] ?? '',
            'title'       => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'body'        => $row['body'] ?? '',
            'file_url'    => $row['file_url'] ?? '',
            'position'    => $row['position'] ?? null,
            'course_id'   => (int)$row['course_id'],
            'course_name' => $row['course_name'] ?? '',
            'board'       => $row['board'] ?? '',
            'level'       => $row['level'] ?? '',
        ];
    }
    $res->free();
}

$contentCount = count($contents);

// For filters: unique courses, boards, levels, types
$coursesList = [];
$boardsList  = [];
$levelsList  = [];
$typesList   = [];

foreach ($contents as $ct) {
    if ($ct['course_id'] && $ct['course_name']) {
        $coursesList[$ct['course_id']] = $ct['course_name'];
    }
    $b = trim($ct['board']);
    if ($b !== '' && !in_array($b, $boardsList, true)) {
        $boardsList[] = $b;
    }
    $l = trim($ct['level']);
    if ($l !== '' && !in_array($l, $levelsList, true)) {
        $levelsList[] = $l;
    }
    $t = trim($ct['type']);
    if ($t !== '' && !in_array($t, $typesList, true)) {
        $typesList[] = $t;
    }
}
asort($coursesList);
sort($boardsList);
sort($levelsList);
sort($typesList);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Manage Contents</title>
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
            <i data-lucide="files" class="w-3.5 h-3.5"></i>
          </span>
          <span><?= ($role === 'coordinator' ? 'Coordinator' : 'Admin') ?> · Contents</span>
        </div>
        <span class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/25 px-3 py-1 rounded-full text-[11px] sm:text-xs">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
          <span><?= htmlspecialchars(ucfirst($role)) ?> access</span>
        </span>
      </div>

      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
          <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight">
            Manage All Contents
          </h1>
          <p class="text-xs sm:text-sm text-sky-100/90">
            View and manage all learning materials across every course.
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
    <section class="lg:col-span-9 space-y-3">
      <!-- Filters card -->
      <div class="rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200 p-3">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2.5 items-center">
          <div class="relative lg:col-span-2">
            <input id="searchInput" type="text"
                   placeholder="Search title, course, board or level..."
                   class="w-full pl-8 pr-2.5 py-2 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                   aria-label="Search contents">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
          </div>
          <div class="flex gap-2">
            <select id="typeFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All types</option>
              <?php foreach ($typesList as $t): ?>
                <option value="<?= htmlspecialchars(strtolower($t), ENT_QUOTES) ?>">
                  <?= htmlspecialchars(ucfirst($t)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="flex gap-2">
            <select id="boardFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All boards</option>
              <?php foreach ($boardsList as $b): ?>
                <option value="<?= htmlspecialchars(strtolower($b), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($b) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select id="levelFilter"
                    class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
              <option value="">All levels</option>
              <?php foreach ($levelsList as $l): ?>
                <option value="<?= htmlspecialchars(strtolower($l), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($l) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2">
          <select id="courseFilter"
                  class="w-full py-2 px-2.5 border border-slate-200 rounded-lg text-xs sm:text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
            <option value="">All courses</option>
            <?php foreach ($coursesList as $cid => $cname): ?>
              <option value="<?= (int)$cid ?>">
                <?= htmlspecialchars($cname) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Contents table -->
      <div class="overflow-hidden rounded-2xl bg-white/95 shadow-sm ring-1 ring-slate-200">
        <?php if ($contentCount > 0): ?>
          <div class="overflow-x-auto" id="contentsTableWrapper">
            <table id="contentsTable" class="min-w-full text-left border-collapse">
              <thead>
                <tr class="text-slate-700 text-[11px] sm:text-xs bg-slate-50/90 uppercase tracking-wide">
                  <th class="sticky px-3 py-2 border-b border-slate-200">ID</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Title</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Course</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200 whitespace-nowrap">Board / Level</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Type</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Resource</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Pos.</th>
                  <th class="sticky px-3 py-2 border-b border-slate-200">Actions</th>
                </tr>
              </thead>
              <tbody class="text-[11px] sm:text-xs">
                <?php foreach ($contents as $ct):
                    $cid = $ct['content_id'];
                    $courseId = $ct['course_id'];
                    $desc = $ct['description'] ?: $ct['body'];
                    $searchKey = strtolower(trim(
                        ($ct['title'] ?? '') . ' ' .
                        ($ct['course_name'] ?? '') . ' ' .
                        ($ct['board'] ?? '') . ' ' .
                        ($ct['level'] ?? '')
                    ));
                    $typeLower = strtolower($ct['type']);
                    // type badge
                    $typeBadge = 'bg-slate-100 text-slate-700 border-slate-200';
                    if ($typeLower === 'video')  $typeBadge = 'bg-rose-50 text-rose-700 border-rose-200';
                    if ($typeLower === 'pdf')    $typeBadge = 'bg-amber-50 text-amber-700 border-amber-200';
                    if ($typeLower === 'lesson') $typeBadge = 'bg-indigo-50 text-indigo-700 border-indigo-200';
                    if ($typeLower === 'forum')  $typeBadge = 'bg-violet-50 text-violet-700 border-violet-200';
                    if ($typeLower === 'quiz')   $typeBadge = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                ?>
                  <tr class="hover:bg-slate-50 even:bg-slate-50/40"
                      data-type="<?= htmlspecialchars($typeLower, ENT_QUOTES) ?>"
                      data-board="<?= htmlspecialchars(strtolower($ct['board']), ENT_QUOTES) ?>"
                      data-level="<?= htmlspecialchars(strtolower($ct['level']), ENT_QUOTES) ?>"
                      data-course="<?= (int)$courseId ?>"
                      data-key="<?= htmlspecialchars($searchKey, ENT_QUOTES) ?>">
                    <td class="px-3 py-2 border-b border-slate-100 text-slate-600">
                      <?= $cid ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <div class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($ct['title']) ?></div>
                      <div class="text-[10px] text-slate-500 line-clamp-2">
                        <?= htmlspecialchars($desc ?: 'No description.') ?>
                      </div>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <span class="text-[11px] font-medium text-slate-800 truncate block">
                        <?= htmlspecialchars($ct['course_name']) ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <span class="inline-flex flex-col">
                        <span class="text-[11px] font-medium text-slate-800">
                          <?= htmlspecialchars($ct['board'] ?: '—') ?>
                        </span>
                        <span class="text-[10px] text-slate-500">
                          <?= htmlspecialchars($ct['level'] ?: '') ?>
                        </span>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium border <?= $typeBadge ?>">
                        <i data-lucide="tag" class="w-3.5 h-3.5"></i>
                        <?= htmlspecialchars($ct['type'] ?: '—') ?>
                      </span>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 max-w-xs">
                      <?php if ($ct['file_url']): ?>
                        <a href="<?= htmlspecialchars($ct['file_url']) ?>" target="_blank"
                           class="inline-flex items-center gap-1 text-sky-700 hover:text-sky-900 truncate max-w-[160px]">
                          <i data-lucide="external-link" class="w-3.5 h-3.5 text-slate-400"></i>
                          <span class="truncate"><?= htmlspecialchars($ct['file_url']) ?></span>
                        </a>
                      <?php else: ?>
                        <span class="text-slate-400 text-[11px]">No resource</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 text-slate-600">
                      <?= htmlspecialchars((string)($ct['position'] ?? '—')) ?>
                    </td>
                    <td class="px-3 py-2 border-b border-slate-100 whitespace-nowrap">
                      <div class="flex flex-wrap gap-1.5">
                        <a href="course.php?course_id=<?= $courseId ?>"
                           class="inline-flex items-center gap-1 text-sky-700 hover:text-sky-900 px-2 py-0.5 rounded-md ring-1 ring-sky-200 bg-sky-50 text-[10px] font-medium">
                          <i data-lucide="book-open" class="w-3.5 h-3.5"></i> Course
                        </a>
                        <a href="managment/manage_course_contents.php?course_id=<?= $courseId ?>"
                           class="inline-flex items-center gap-1 text-indigo-700 hover:text-indigo-900 px-2 py-0.5 rounded-md ring-1 ring-indigo-200 bg-indigo-50 text-[10px] font-medium">
                          <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Manage in course
                        </a>
                        <form method="POST" class="inline"
                              onsubmit="return confirm('Delete this content item?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="content_id" value="<?= $cid ?>">
                          <button type="submit"
                                  class="inline-flex items-center gap-1 text-rose-700 hover:text-rose-900 px-2 py-0.5 rounded-md ring-1 ring-rose-200 bg-rose-50 text-[10px] font-medium">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Delete
                          </button>
                        </form>
                      </div>
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
                <p class="font-medium">No contents found.</p>
                <p class="text-xs text-slate-500 mt-0.5">
                  Use the course-level pages to add new content for each course.
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

  const searchInput  = document.getElementById('searchInput');
  const typeFilter   = document.getElementById('typeFilter');
  const boardFilter  = document.getElementById('boardFilter');
  const levelFilter  = document.getElementById('levelFilter');
  const courseFilter = document.getElementById('courseFilter');
  const rows         = [...document.querySelectorAll('#contentsTable tbody tr')];

  function applyFilters() {
    const q      = (searchInput?.value || '').toLowerCase().trim();
    const type   = (typeFilter?.value || '').toLowerCase();
    const board  = (boardFilter?.value || '').toLowerCase();
    const level  = (levelFilter?.value || '').toLowerCase();
    const course = (courseFilter?.value || '').toLowerCase();

    rows.forEach(tr => {
      const key   = (tr.getAttribute('data-key') || '').toLowerCase();
      const t     = (tr.getAttribute('data-type') || '').toLowerCase();
      const b     = (tr.getAttribute('data-board') || '').toLowerCase();
      const l     = (tr.getAttribute('data-level') || '').toLowerCase();
      const c     = (tr.getAttribute('data-course') || '').toLowerCase();

      const matchQ = !q      || key.includes(q);
      const matchT = !type   || t === type;
      const matchB = !board  || b === board;
      const matchL = !level  || l === level;
      const matchC = !course || c === course;

      const show = matchQ && matchT && matchB && matchL && matchC;
      tr.style.display = show ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  typeFilter?.addEventListener('change', applyFilters);
  boardFilter?.addEventListener('change', applyFilters);
  levelFilter?.addEventListener('change', applyFilters);
  courseFilter?.addEventListener('change', applyFilters);
  applyFilters();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>