<?php
session_start();
include 'db_connect.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flashErr = '';
$flashOk  = '';

// Handle new post/reply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die("Invalid request.");
    }

    $content_id = (int)($_POST['content_id'] ?? 0);
    $parent_post_id = isset($_POST['parent_post_id']) && $_POST['parent_post_id'] !== '' ? (int)$_POST['parent_post_id'] : null;
    $body = trim($_POST['body'] ?? '');

    if ($content_id <= 0) {
        $flashErr = 'Invalid forum.';
    } elseif ($body === '' || mb_strlen($body) > 5000) {
        $flashErr = 'Message must be between 1 and 5000 characters.';
    } else {
        // Check forum access: global (course_id NULL) or enrolled-active
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM contents co
            WHERE co.type='forum'
              AND (co.course_id IS NULL OR co.course_id IN (
                    SELECT course_id FROM enrollments WHERE user_id=? AND status='active'
                  ))
              AND co.content_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $content_id);
        $stmt->execute();
        $allowed = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($allowed === 0) {
            $flashErr = 'You do not have access to this forum.';
        }

        // If replying, ensure parent belongs to the same forum
        if (!$flashErr && $parent_post_id) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM forum_posts WHERE post_id = ? AND content_id = ?");
            $stmt->bind_param("ii", $parent_post_id, $content_id);
            $stmt->execute();
            $parentOk = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
            if ($parentOk === 0) {
                $flashErr = 'Invalid parent post.';
            }
        }

        if (!$flashErr) {
            if ($parent_post_id) {
                $stmt = $conn->prepare("INSERT INTO forum_posts (content_id, user_id, body, parent_post_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iisi", $content_id, $user_id, $body, $parent_post_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO forum_posts (content_id, user_id, body) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $content_id, $user_id, $body);
            }
            $stmt->execute();
            $new_post_id = $stmt->insert_id;
            $stmt->close();

            // Redirect to avoid resubmission and jump to the new post
            header("Location: forum.php#post-$new_post_id");
            exit;
        }
    }
}

// Fetch accessible forum contents (global or for enrolled-active courses)
$forums = [];
$stmt = $conn->prepare("
    SELECT co.content_id, co.title, co.course_id, c.name AS course_name
    FROM contents co
    LEFT JOIN courses c ON c.course_id = co.course_id
    WHERE co.type = 'forum'
      AND (co.course_id IS NULL OR co.course_id IN (
            SELECT course_id FROM enrollments WHERE user_id=? AND status='active'
          ))
    ORDER BY COALESCE(c.name, 'General') ASC, co.title ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $forums[] = $row;
}
$stmt->close();

// Helper to fetch posts for a forum and return a tree
function fetchForumPostsTree(mysqli $conn, int $contentId): array {
    $stmt = $conn->prepare("
        SELECT p.post_id, p.parent_post_id, p.body, p.posted_at,
               u.username, u.user_id
        FROM forum_posts p
        JOIN users u ON u.user_id = p.user_id
        WHERE p.content_id = ?
        ORDER BY p.posted_at ASC
    ");
    $stmt->bind_param("i", $contentId);
    $stmt->execute();
    $res = $stmt->get_result();

    $postsByParent = [];
    $all = [];
    while ($r = $res->fetch_assoc()) {
        $r['post_id'] = (int)$r['post_id'];
        $r['parent_post_id'] = $r['parent_post_id'] !== null ? (int)$r['parent_post_id'] : null;
        $parentKey = $r['parent_post_id'] ?? 0;
        $postsByParent[$parentKey][] = $r;
        $all[] = $r;
    }
    $stmt->close();
    return [$postsByParent, count($all)];
}

// Recursive renderer (prettified with icons)
function renderThread(array $postsByParent, int $contentId, string $csrf, int $parentKey = 0, int $depth = 0) {
    if (empty($postsByParent[$parentKey])) return;

    foreach ($postsByParent[$parentKey] as $p) {
        $pid = (int)$p['post_id'];
        $username = htmlspecialchars($p['username'] ?? 'User', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $initial = strtoupper(mb_substr($username, 0, 1));
        $timeText = date('M d, Y, g:i A', strtotime($p['posted_at']));
        $bodySafe = htmlspecialchars($p['body'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $indentPx = min($depth, 8) * 16; // max indent
        $withBorder = $depth > 0 ? ' border-l border-indigo-100 pl-3' : '';

        echo '<div id="post-' . $pid . '" class="relative mb-3' . $withBorder . '" style="margin-left:' . $indentPx . 'px">';
        echo '  <div class="bg-white/80 border border-gray-100 rounded-xl p-4 shadow-sm">';
        echo '    <div class="flex items-start gap-3">';
        echo '      <div class="h-9 w-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold">' . $initial . '</div>';
        echo '      <div class="flex-1">';
        echo '        <div class="flex items-center justify-between gap-3">';
        echo '          <div class="font-semibold text-gray-800 inline-flex items-center gap-2">';
        echo '            <ion-icon name="person-circle-outline" class="text-indigo-600"></ion-icon>' . $username;
        echo '          </div>';
        echo '          <div class="text-xs text-gray-500 inline-flex items-center gap-1">';
        echo '            <ion-icon name="time-outline" class="text-gray-400"></ion-icon>';
        echo '            <time datetime="' . htmlspecialchars($p['posted_at']) . '">' . $timeText . '</time>';
        echo '          </div>';
        echo '        </div>';
        echo '        <div class="mt-2 text-gray-700 whitespace-pre-line">' . $bodySafe . '</div>';
        echo '        <div class="mt-3 flex items-center gap-3">';
        echo '          <button type="button" onclick="toggleReply(' . $pid . ')" class="inline-flex items-center gap-1.5 text-indigo-600 text-sm hover:text-indigo-800">';
        echo '            <ion-icon name="chatbox-ellipses-outline"></ion-icon> Reply';
        echo '          </button>';
        echo '          <button type="button" onclick="copyLink(' . $pid . ')" class="inline-flex items-center gap-1.5 text-gray-600 text-sm hover:text-gray-800">';
        echo '            <ion-icon name="link-outline"></ion-icon> Copy link';
        echo '          </button>';
        echo '        </div>';
        // Inline reply form
        echo '        <form id="reply-form-' . $pid . '" method="POST" class="mt-3 hidden">';
        echo '          <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">';
        echo '          <input type="hidden" name="content_id" value="' . $contentId . '">';
        echo '          <input type="hidden" name="parent_post_id" value="' . $pid . '">';
        echo '          <div class="relative">';
        echo '            <ion-icon name="create-outline" class="absolute left-3 top-3.5 text-gray-400"></ion-icon>';
        echo '            <textarea name="body" rows="3" required maxlength="5000" class="w-full border border-gray-200 rounded-lg px-9 py-2.5 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none" placeholder="Write a reply..."></textarea>';
        echo '          </div>';
        echo '          <div class="mt-2 flex gap-2">';
        echo '            <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 text-white px-3 py-2 rounded-lg hover:bg-indigo-700 transition">';
        echo '              <ion-icon name="send-outline"></ion-icon> Post Reply';
        echo '            </button>';
        echo '            <button type="button" onclick="toggleReply(' . $pid . ')" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50">';
        echo '              <ion-icon name="close-outline"></ion-icon> Cancel';
        echo '            </button>';
        echo '          </div>';
        echo '        </form>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // Children
        renderThread($postsByParent, $contentId, $csrf, $pid, $depth + 1);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Forum</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
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
    .chip { display:inline-flex; align-items:center; gap:.4rem; padding:.28rem .6rem; border-radius:9999px; font-size:.72rem; font-weight:600; border:1px solid #e2e8f0; background:#f8fafc; color:#334155; white-space:nowrap; }
    .card { transition: box-shadow .2s ease, transform .2s ease; }
    .card:hover { box-shadow: 0 14px 28px rgba(15,23,42,.09); transform: translateY(-1px); }
    /* Toast */
    #toast { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 60; }
  </style>
</head>
<body class="bg-gradient-to-br from-sky-50 via-white to-indigo-50 min-h-screen text-gray-900 antialiased">

<?php include 'components/navbar.php'; ?>

<div class="fixed inset-0 bg-bubbles -z-10"></div>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-6 lg:px-10 py-28 gap-8">
  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full space-y-8 animate-fadeUp">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-2">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600/90 text-white shadow-sm">
          <ion-icon name="chatbubbles-outline" class="text-xl"></ion-icon>
        </span>
        <h2 class="text-3xl font-extrabold text-gray-800">Forum</h2>
      </div>
      <a href="student_dashboard.php" class="inline-flex items-center gap-1.5 text-indigo-600 hover:text-indigo-800 font-medium">
        <ion-icon name="arrow-back-outline"></ion-icon>
        Back to Dashboard
      </a>
    </div>

    <?php if ($flashErr): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl shadow-sm inline-flex items-center gap-2">
        <ion-icon name="alert-circle-outline"></ion-icon>
        <?= htmlspecialchars($flashErr) ?>
      </div>
    <?php endif; ?>

    <?php if (empty($forums)): ?>
      <div class="bg-white/80 backdrop-blur-sm p-8 rounded-2xl shadow-xl border border-gray-100 text-center">
        <div class="inline-flex items-center justify-center h-12 w-12 rounded-full bg-indigo-50 text-indigo-600 mb-2">
          <ion-icon name="information-circle-outline" class="text-2xl"></ion-icon>
        </div>
        <p class="text-gray-700 text-lg">No forums available for your courses yet.</p>
      </div>
    <?php else: ?>

      <?php foreach ($forums as $f): ?>
        <?php
          $contentId  = (int)$f['content_id'];
          $forumTitle = htmlspecialchars($f['title'] ?? 'Untitled Forum', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $courseName = $f['course_name'] ? htmlspecialchars($f['course_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'General';
          [$postsByParent, $postCount] = fetchForumPostsTree($conn, $contentId);
        ?>

        <section class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100 card">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div class="space-y-1">
              <h3 class="text-xl sm:text-2xl font-semibold text-gray-800 inline-flex items-center gap-2">
                <ion-icon name="chatbox-ellipses-outline" class="text-indigo-600"></ion-icon>
                <?= $forumTitle ?>
              </h3>
              <div class="text-sm text-gray-500 inline-flex items-center gap-2">
                <span class="chip">
                  <ion-icon name="book-outline"></ion-icon>
                  <?= $courseName ?>
                </span>
                <span class="chip">
                  <ion-icon name="albums-outline"></ion-icon>
                  <?= (int)$postCount ?> posts
                </span>
              </div>
            </div>
          </div>

          <!-- New post form -->
          <form method="POST" class="mb-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="content_id" value="<?= $contentId ?>">
            <div class="relative">
              <ion-icon name="create-outline" class="absolute left-3 top-3.5 text-gray-400"></ion-icon>
              <textarea name="body" rows="3" required maxlength="5000"
                class="w-full border border-gray-200 rounded-xl px-9 py-3 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none"
                placeholder="Start a new discussion..."></textarea>
            </div>
            <div class="mt-3">
              <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 text-white px-4 py-2.5 rounded-lg hover:bg-indigo-700 transition">
                <ion-icon name="add-circle-outline"></ion-icon>
                Post
              </button>
            </div>
          </form>

          <!-- Thread -->
          <?php if ($postCount === 0): ?>
            <p class="text-gray-600"><em>No posts yet. Be the first to start the conversation!</em></p>
          <?php else: ?>
            <div>
              <?php renderThread($postsByParent, $contentId, $csrf, 0, 0); ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>

    <?php endif; ?>
  </main>
</div>

<?php include 'components/footer.php'; ?>

<!-- Toast -->
<div id="toast" class="hidden">
  <div id="toastBox" class="rounded-md bg-slate-900/90 text-white px-3.5 py-2 shadow-lg text-sm inline-flex items-center gap-2">
    <ion-icon name="checkmark-circle-outline" class="text-emerald-400"></ion-icon>
    <span id="toastText">Copied</span>
  </div>
</div>

<script>
  function toggleReply(id) {
    const el = document.getElementById('reply-form-' + id);
    if (!el) return;
    el.classList.toggle('hidden');
    if (!el.classList.contains('hidden')) {
      const ta = el.querySelector('textarea');
      ta && ta.focus();
    }
  }

  // Copy link to a specific post
  function copyLink(pid) {
    const url = window.location.origin + window.location.pathname + window.location.search + '#post-' + pid;
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(() => showToast('Link copied'));
    } else {
      // Fallback
      const tmp = document.createElement('textarea');
      tmp.value = url;
      document.body.appendChild(tmp);
      tmp.select(); document.execCommand('copy');
      document.body.removeChild(tmp);
      showToast('Link copied');
    }
  }

  // Tiny toast
  let toastTimer = null;
  function showToast(text) {
    const toast = document.getElementById('toast');
    const box   = document.getElementById('toastBox');
    const span  = document.getElementById('toastText');
    if (!toast || !box || !span) return;
    span.textContent = text || 'Done';
    toast.classList.remove('hidden');
    box.classList.remove('opacity-0');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.classList.add('hidden');
    }, 1800);
  }
</script>
</body>
</html>