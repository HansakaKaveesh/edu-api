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
$flashOk = '';

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

// Recursive renderer
function renderThread(array $postsByParent, int $contentId, string $csrf, int $parentKey = 0, int $depth = 0) {
    if (empty($postsByParent[$parentKey])) return;

    foreach ($postsByParent[$parentKey] as $p) {
        $pid = (int)$p['post_id'];
        $username = htmlspecialchars($p['username'] ?? 'User', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $initial = strtoupper(mb_substr($username, 0, 1));
        $timeText = date('M d, Y, g:i A', strtotime($p['posted_at']));
        $bodySafe = htmlspecialchars($p['body'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Indentation with a subtle left border
        $indentPx = min($depth, 8) * 16; // max indent
        echo '<div id="post-' . $pid . '" class="relative mb-3" style="margin-left:' . $indentPx . 'px">';
        echo '  <div class="bg-white/80 border border-gray-100 rounded-xl p-4 shadow-sm">';
        echo '    <div class="flex items-start gap-3">';
        echo '      <div class="h-9 w-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold">' . $initial . '</div>';
        echo '      <div class="flex-1">';
        echo '        <div class="flex items-center justify-between">';
        echo '          <div class="font-semibold text-gray-800">' . $username . '</div>';
        echo '          <time class="text-xs text-gray-500" datetime="' . htmlspecialchars($p['posted_at']) . '">' . $timeText . '</time>';
        echo '        </div>';
        echo '        <div class="mt-2 text-gray-700 whitespace-pre-line">' . $bodySafe . '</div>';
        echo '        <div class="mt-3">';
        echo '          <button type="button" onclick="toggleReply(' . $pid . ')" class="text-indigo-600 text-sm hover:text-indigo-800">Reply</button>';
        echo '        </div>';
        // Inline reply form
        echo '        <form id="reply-form-' . $pid . '" method="POST" class="mt-3 hidden">';
        echo '          <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">';
        echo '          <input type="hidden" name="content_id" value="' . $contentId . '">';
        echo '          <input type="hidden" name="parent_post_id" value="' . $pid . '">';
        echo '          <textarea name="body" rows="3" required maxlength="5000" class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none" placeholder="Write a reply..."></textarea>';
        echo '          <div class="mt-2 flex gap-2">';
        echo '            <button type="submit" class="bg-indigo-600 text-white px-3 py-2 rounded-lg hover:bg-indigo-700 transition">Post Reply</button>';
        echo '            <button type="button" onclick="toggleReply(' . $pid . ')" class="px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50">Cancel</button>';
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
  <style>
    html, body { font-family: "Inter", ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; }
    @keyframes fadeUp { from { opacity:0; transform: translateY(10px);} to { opacity:1; transform: translateY(0);} }
    .animate-fadeUp { animation: fadeUp .45s ease-out both; }
    .bg-bubbles::before, .bg-bubbles::after {
      content:""; position:absolute; border-radius:9999px; filter: blur(40px); opacity:.25; z-index:0; pointer-events:none;
    }
    .bg-bubbles::before { width:420px; height:420px; background: radial-gradient(closest-side,#60a5fa,transparent 70%); top:-80px; left:-80px; }
    .bg-bubbles::after  { width:500px; height:500px; background: radial-gradient(closest-side,#a78bfa,transparent 70%); bottom:-120px; right:-120px; }
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
      <h2 class="text-3xl font-extrabold text-gray-800">💬 Forum</h2>
      <a href="student_dashboard.php" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-medium">← Back to Dashboard</a>
    </div>

    <?php if ($flashErr): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl shadow-sm">
        <?= htmlspecialchars($flashErr) ?>
      </div>
    <?php endif; ?>

    <?php if (empty($forums)): ?>
      <div class="bg-white/80 backdrop-blur-sm p-8 rounded-2xl shadow-xl border border-gray-100 text-center">
        <p class="text-gray-700 text-lg">No forums available for your courses yet.</p>
      </div>
    <?php else: ?>

      <?php foreach ($forums as $f): ?>
        <?php
          $contentId = (int)$f['content_id'];
          $forumTitle = htmlspecialchars($f['title'] ?? 'Untitled Forum', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $courseName = $f['course_name'] ? htmlspecialchars($f['course_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'General';
          [$postsByParent, $postCount] = fetchForumPostsTree($conn, $contentId);
        ?>

        <section class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
              <h3 class="text-xl sm:text-2xl font-semibold text-gray-800"><?= $forumTitle ?></h3>
              <div class="mt-1 text-sm text-gray-500">Course: <span class="font-medium text-gray-700"><?= $courseName ?></span> • <?= (int)$postCount ?> posts</div>
            </div>
          </div>

          <!-- New post form -->
          <form method="POST" class="mb-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="content_id" value="<?= $contentId ?>">
            <textarea name="body" rows="3" required maxlength="5000"
              class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none"
              placeholder="Start a new discussion..."></textarea>
            <div class="mt-3">
              <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 text-white px-4 py-2.5 rounded-lg hover:bg-indigo-700 transition">
                ➕ Post
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

<script>
  function toggleReply(id) {
    const el = document.getElementById('reply-form-' + id);
    if (!el) return;
    el.classList.toggle('hidden');
  }
</script>
</body>
</html>