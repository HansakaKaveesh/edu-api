<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// Get all teachers and students except self
$users = $conn->query("
    SELECT u.user_id, u.username, 
        COALESCE(s.first_name, t.first_name) AS first_name, 
        COALESCE(s.last_name, t.last_name) AS last_name, 
        u.role
    FROM users u
    LEFT JOIN students s ON u.user_id = s.user_id
    LEFT JOIN teachers t ON u.user_id = t.user_id
    WHERE u.user_id != $user_id AND u.role IN ('student', 'teacher')
    ORDER BY u.role, first_name
");

$chat_with = isset($_GET['user']) ? intval($_GET['user']) : 0;

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'], $_POST['receiver_id'])) {
    $msg = trim($_POST['message']);
    $receiver_id = intval($_POST['receiver_id']);
    if ($msg !== '') {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $receiver_id, $msg);
        $stmt->execute();
    }
    header("Location: messages.php?user=$receiver_id");
    exit;
}

// Fetch chat history if chatting
$chat_history = [];
if ($chat_with) {
    $stmt = $conn->prepare("
        SELECT m.*, u.username, 
            COALESCE(s.first_name, t.first_name) AS first_name, 
            COALESCE(s.last_name, t.last_name) AS last_name, 
            u.role
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        LEFT JOIN students s ON u.user_id = s.user_id
        LEFT JOIN teachers t ON u.user_id = t.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.sent_at ASC
    ");
    $stmt->bind_param("iiii", $user_id, $chat_with, $chat_with, $user_id);
    $stmt->execute();
    $chat_history = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Messages</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 min-h-screen text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<div class="max-w-8xl mx-auto px-6 py-28">
  <div class="flex flex-col lg:flex-row gap-8">
    <!-- Sidebar -->
    <?php include 'components/sidebar_student.php'; ?>

    <!-- Main Content -->
    <main class="w-full space-y-6">
      <!-- Header card -->
      <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
          <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
          <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
        </div>
        <div class="relative flex items-center justify-between">
          <div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-blue-700 tracking-tight">💬 Messages</h1>
            <p class="text-gray-600 mt-1">Chat with teachers and classmates.</p>
          </div>
          <a href="student_courses.php"
             class="hidden sm:inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
            ← Back
          </a>
        </div>
      </div>

      <div class="flex flex-col md:flex-row gap-6">
        <!-- User List -->
        <aside class="w-full md:w-1/3 bg-white rounded-2xl shadow-sm ring-1 ring-gray-200 p-4 h-fit">
          <div class="flex items-center gap-2 mb-3">
            <h2 class="text-lg font-semibold text-gray-900">Users</h2>
            <span class="text-xs text-gray-500">(Students & Teachers)</span>
          </div>

          <div class="relative mb-3">
            <input id="userSearch" type="text" placeholder="Search users..."
                   class="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M8.5 3.5a5 5 0 013.905 8.132l3.231 3.232a.75.75 0 11-1.06 1.06l-3.232-3.23A5 5 0 118.5 3.5zm0 1.5a3.5 3.5 0 100 7 3.5 3.5 0 000-7z" clip-rule="evenodd"/>
            </svg>
          </div>

          <ul id="userList" class="space-y-1 max-h-[60vh] overflow-y-auto pr-1">
            <?php while ($u = $users->fetch_assoc()):
              $fname = $u['first_name'] ?: $u['username'];
              $lname = $u['last_name'] ?: '';
              $initials = strtoupper(mb_substr($fname, 0, 1) . ($lname ? mb_substr($lname, 0, 1) : ''));
              $fullName = trim($fname . ' ' . $lname);
              $isActive = ($chat_with == $u['user_id']);
              $roleBadge = $u['role'] === 'teacher' ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700';
            ?>
              <li data-name="<?= htmlspecialchars(strtolower($fullName . ' ' . $u['username'])) ?>">
                <a href="messages.php?user=<?= $u['user_id'] ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-blue-50 <?= $isActive ? 'bg-blue-100 ring-1 ring-blue-200' : '' ?>">
                  <div class="flex items-center justify-center h-9 w-9 rounded-full bg-blue-100 text-blue-700 font-semibold ring-1 ring-blue-200">
                    <?= htmlspecialchars($initials ?: '👤') ?>
                  </div>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="block font-medium text-gray-900 truncate"><?= htmlspecialchars($fullName) ?></span>
                      <span class="text-[10px] px-2 py-0.5 rounded-full <?= $roleBadge ?>">
                        <?= ucfirst($u['role']) ?>
                      </span>
                    </div>
                    <span class="text-xs text-gray-500 truncate">@<?= htmlspecialchars($u['username']) ?></span>
                  </div>
                </a>
              </li>
            <?php endwhile; ?>
          </ul>
        </aside>

        <!-- Chat Area -->
        <section class="w-full md:w-2/3 bg-white rounded-2xl shadow-sm ring-1 ring-gray-200 p-0 flex flex-col">
          <?php if ($chat_with): ?>
            <?php
              $chat_user = $conn->query("
                SELECT COALESCE(s.first_name, t.first_name) AS first_name, 
                       COALESCE(s.last_name, t.last_name) AS last_name, role 
                FROM users u 
                LEFT JOIN students s ON u.user_id = s.user_id 
                LEFT JOIN teachers t ON u.user_id = t.user_id 
                WHERE u.user_id = $chat_with
              ")->fetch_assoc();
              $cuName = htmlspecialchars(trim(($chat_user['first_name'] ?? '') . ' ' . ($chat_user['last_name'] ?? '')));
              $cuRole = ucfirst($chat_user['role'] ?? '');
              $cuBadge = ($chat_user['role'] ?? '') === 'teacher' ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700';
              $cuInitials = strtoupper(mb_substr($chat_user['first_name'] ?? 'U', 0, 1) . mb_substr($chat_user['last_name'] ?? '', 0, 1));
            ?>
            <!-- Chat Header -->
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
              <div class="flex items-center gap-3 min-w-0">
                <div class="flex items-center justify-center h-10 w-10 rounded-full bg-blue-100 text-blue-700 font-semibold ring-1 ring-blue-200"><?= $cuInitials ?: '👤' ?></div>
                <div class="min-w-0">
                  <h3 class="text-base font-semibold text-gray-900 truncate"><?= $cuName ?></h3>
                  <span class="text-[10px] px-2 py-0.5 rounded-full <?= $cuBadge ?>"><?= $cuRole ?></span>
                </div>
              </div>
              <a href="#userList" class="md:hidden text-blue-700 text-sm hover:underline">← Users</a>
            </div>

            <!-- Messages -->
            <div id="chatBox" class="flex-1 overflow-y-auto px-4 md:px-5 py-4 space-y-2 max-h-[65vh]">
              <?php if ($chat_history && $chat_history->num_rows > 0): ?>
                <?php $prevDate = null; ?>
                <ul class="space-y-2">
                  <?php while ($msg = $chat_history->fetch_assoc()):
                    $isMine = ($msg['sender_id'] == $user_id);
                    $dateStr = date('M j, Y', strtotime($msg['sent_at']));
                    $timeStr = date('g:i A', strtotime($msg['sent_at']));
                    $bubbleClasses = $isMine
                      ? 'bg-blue-600 text-white rounded-2xl rounded-tr-sm'
                      : 'bg-slate-100 text-gray-800 rounded-2xl rounded-tl-sm';
                    $align = $isMine ? 'justify-end' : 'justify-start';
                  ?>
                    <?php if ($prevDate !== $dateStr): ?>
                      <li class="flex justify-center py-2">
                        <span class="text-xs text-gray-500 bg-gray-100 px-3 py-1 rounded-full ring-1 ring-gray-200"><?= $dateStr ?></span>
                      </li>
                      <?php $prevDate = $dateStr; ?>
                    <?php endif; ?>

                    <li class="flex <?= $align ?>">
                      <div class="max-w-[75%] px-4 py-2 shadow-sm ring-1 ring-gray-200/50 <?= $bubbleClasses ?>">
                        <div class="text-[10px] opacity-80 mb-1"><?= $timeStr ?></div>
                        <div class="whitespace-pre-wrap break-words"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                      </div>
                    </li>
                  <?php endwhile; ?>
                </ul>
              <?php else: ?>
                <div class="text-gray-500 text-center py-12">
                  No messages yet. Say hi 👋
                </div>
              <?php endif; ?>
            </div>

            <!-- Composer -->
            <form id="chatForm" method="post" class="border-t border-gray-100 p-3 md:p-4">
              <input type="hidden" name="receiver_id" value="<?= $chat_with ?>" />
              <div class="flex items-end gap-2">
                <textarea id="message" name="message" required rows="1" placeholder="Type your message..."
                          class="flex-1 max-h-40 border border-gray-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300 resize-none"></textarea>
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-xl hover:bg-blue-700 shadow-sm">
                  ➤ Send
                </button>
              </div>
            </form>
          <?php else: ?>
            <div class="flex-1 flex flex-col items-center justify-center px-6 py-24 text-center">
              <div class="mx-auto w-16 h-16 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-3xl mb-4">💬</div>
              <h3 class="text-xl font-semibold text-gray-900">Select a user to start chatting</h3>
              <p class="text-gray-600 mt-1">Choose someone from the list to begin your conversation.</p>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </main>
  </div>
</div>

<script>
  // Filter users
  const userSearch = document.getElementById('userSearch');
  const list = document.getElementById('userList');
  if (userSearch && list) {
    userSearch.addEventListener('input', () => {
      const q = userSearch.value.trim().toLowerCase();
      [...list.querySelectorAll('li')].forEach(li => {
        const name = li.getAttribute('data-name') || '';
        li.style.display = name.includes(q) ? '' : 'none';
      });
    });
  }

  // Auto-scroll to bottom on load
  const chatBox = document.getElementById('chatBox');
  if (chatBox) {
    chatBox.scrollTop = chatBox.scrollHeight;
  }

  // Textarea auto-resize and submit on Enter (Shift+Enter for newline)
  const ta = document.getElementById('message');
  const form = document.getElementById('chatForm');
  if (ta) {
    const autoResize = () => {
      ta.style.height = 'auto';
      ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
    };
    ta.addEventListener('input', autoResize);
    autoResize();
    ta.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (ta.value.trim() !== '') form.submit();
      }
    });
  }
</script>
</body>
</html>