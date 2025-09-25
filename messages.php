<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

/**
 * Message encryption helpers (AES-256-GCM at rest).
 * Configure a 32-byte key via environment or constant:
 *   putenv('MESSAGE_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=');
 * or define('MESSAGE_KEY', 'base64:xxxxxxxxxxxx...');
 *
 * Accepted formats:
 * - base64:... (base64 of 32 raw bytes)
 * - hex:...    (64 hex chars)
 * - any string (will be hashed with SHA-256 to 32 bytes)
 */

function get_message_key_bin(): ?string {
    static $key = null;
    if ($key !== null) return $key;

    $raw = getenv('MESSAGE_KEY');
    if (!$raw && defined('MESSAGE_KEY')) $raw = MESSAGE_KEY;

    if (!$raw) { // no key configured -> will store plaintext (compat fallback)
        $key = null;
        return null;
    }

    if (str_starts_with($raw, 'base64:')) {
        $b64 = substr($raw, 7);
        $bin = base64_decode($b64, true);
        if ($bin !== false && strlen($bin) === 32) {
            $key = $bin;
            return $key;
        }
    } elseif (str_starts_with($raw, 'hex:')) {
        $hex = substr($raw, 4);
        if (preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            $key = hex2bin($hex);
            return $key;
        }
    }

    // Fallback: derive 32 bytes from the given string
    $key = hash('sha256', $raw, true);
    return $key;
}

/**
 * Encrypt plaintext -> "ENCv1:base64(iv):base64(tag):base64(ciphertext)"
 */
function encrypt_message(string $plaintext): string {
    $key = get_message_key_bin();
    if (!$key) return $plaintext; // fallback to plaintext if no key

    $cipher = 'aes-256-gcm';
    $iv = random_bytes(12); // GCM 96-bit IV
    $tag = '';
    $ct = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        error_log('Message encrypt failed; storing plaintext');
        return $plaintext;
    }
    return 'ENCv1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ct);
}

/**
 * Decrypt payload produced by encrypt_message; returns plaintext or a safe fallback.
 */
function decrypt_message(string $payload): string {
    if (!str_starts_with($payload, 'ENCv1:')) return $payload; // plaintext or older rows

    $parts = explode(':', $payload, 4);
    if (count($parts) !== 4) return '[Unable to decrypt]';
    [, $biv, $btag, $bct] = $parts;

    $iv = base64_decode($biv, true);
    $tag = base64_decode($btag, true);
    $ct  = base64_decode($bct, true);
    if ($iv === false || $tag === false || $ct === false) return '[Unable to decrypt]';

    $key = get_message_key_bin();
    if (!$key) return '[Unable to decrypt]';

    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '[Unable to decrypt]' : $pt;
}

// Get all teachers and students except self (prepared)
$users_stmt = $conn->prepare("
    SELECT u.user_id, u.username,
           COALESCE(s.first_name, t.first_name) AS first_name,
           COALESCE(s.last_name, t.last_name) AS last_name,
           u.role
    FROM users u
    LEFT JOIN students s ON u.user_id = s.user_id
    LEFT JOIN teachers t ON u.user_id = t.user_id
    WHERE u.user_id != ? AND u.role IN ('student', 'teacher')
    ORDER BY u.role, first_name
");
$users_stmt->bind_param("i", $user_id);
$users_stmt->execute();
$users = $users_stmt->get_result();

$chat_with = isset($_GET['user']) ? (int)$_GET['user'] : 0;

// Send message (encrypt before saving)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'], $_POST['receiver_id'])) {
    $msg = trim($_POST['message']);
    $receiver_id = (int)$_POST['receiver_id'];
    if ($msg !== '') {
        $enc = encrypt_message($msg);
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $receiver_id, $enc);
        $stmt->execute();
    }
    header("Location: messages.php?user=$receiver_id");
    exit;
}

// Fetch chat history if chatting
$chat_history = [];
$chat_user = null;
if ($chat_with) {
    // Chat user (prepared)
    $cu_stmt = $conn->prepare("
        SELECT COALESCE(s.first_name, t.first_name) AS first_name,
               COALESCE(s.last_name, t.last_name) AS last_name, u.role
        FROM users u
        LEFT JOIN students s ON u.user_id = s.user_id
        LEFT JOIN teachers t ON u.user_id = t.user_id
        WHERE u.user_id = ?
    ");
    $cu_stmt->bind_param("i", $chat_with);
    $cu_stmt->execute();
    $chat_user = $cu_stmt->get_result()->fetch_assoc();

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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 min-h-screen text-gray-800 antialiased pb-[env(safe-area-inset-bottom)]">
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
          <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/50 rounded-full blur-3xl"></div>
          <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/50 rounded-full blur-3xl"></div>
        </div>
        <div class="relative flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-sm">
              <i data-lucide="message-circle" class="w-5 h-5"></i>
            </span>
            <div>
              <h1 class="text-2xl md:text-3xl font-extrabold text-gradient bg-clip-text text-transparent bg-gradient-to-r from-blue-700 to-indigo-600 tracking-tight">Messages</h1>
              <p class="text-gray-600 mt-1">Chat with teachers and classmates.</p>
            </div>
          </div>
          <a href="student_courses.php"
             class="hidden sm:inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-blue-700 hover:bg-blue-50 hover:border-blue-300 transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
          </a>
        </div>
      </div>

      <div class="flex flex-col md:flex-row gap-6">
        <!-- User List -->
        <aside class="w-full md:w-1/3 bg-white rounded-2xl shadow-sm ring-1 ring-gray-200 p-4 h-fit">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <i data-lucide="users" class="w-5 h-5 text-blue-600"></i>
              <h2 class="text-lg font-semibold text-gray-900">Users</h2>
            </div>
            <span class="text-xs text-gray-500">(Students & Teachers)</span>
          </div>

          <div class="relative mb-3">
            <i data-lucide="search" class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
            <input id="userSearch" type="text" placeholder="Search users..."
                   class="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
          </div>

          <ul id="userList" class="space-y-1 max-h-[60vh] overflow-y-auto pr-1">
            <?php while ($u = $users->fetch_assoc()):
              $fname = $u['first_name'] ?: $u['username'];
              $lname = $u['last_name'] ?: '';
              $initials = strtoupper(mb_substr($fname, 0, 1) . ($lname ? mb_substr($lname, 0, 1) : ''));
              $fullName = trim($fname . ' ' . $lname);
              $isActive = ($chat_with == $u['user_id']);
              $isTeacher = ($u['role'] === 'teacher');
              $roleBadge = $isTeacher ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700';
              $roleIcon  = $isTeacher ? 'graduation-cap' : 'user';
            ?>
              <li data-name="<?= htmlspecialchars(strtolower($fullName . ' ' . $u['username'])) ?>">
                <a href="messages.php?user=<?= (int)$u['user_id'] ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-blue-50 <?= $isActive ? 'bg-blue-100 ring-1 ring-blue-200' : '' ?>">
                  <div class="relative">
                    <div class="flex items-center justify-center h-9 w-9 rounded-full bg-blue-100 text-blue-700 font-semibold ring-1 ring-blue-200">
                      <?= htmlspecialchars($initials ?: '👤') ?>
                    </div>
                    <span class="absolute -bottom-1 -right-1 inline-flex items-center justify-center h-5 w-5 rounded-full bg-white ring-1 ring-gray-200">
                      <i data-lucide="<?= $roleIcon ?>" class="w-3.5 h-3.5 <?= $isTeacher ? 'text-indigo-600' : 'text-emerald-600' ?>"></i>
                    </span>
                  </div>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="block font-medium text-gray-900 truncate"><?= htmlspecialchars($fullName) ?></span>
                      <span class="text-[10px] px-2 py-0.5 rounded-full <?= $roleBadge ?>">
                        <i data-lucide="<?= $roleIcon ?>" class="w-3 h-3 mr-1"></i><?= ucfirst($u['role']) ?>
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
              $cuName = htmlspecialchars(trim(($chat_user['first_name'] ?? '') . ' ' . ($chat_user['last_name'] ?? '')));
              $cuRole = ucfirst($chat_user['role'] ?? '');
              $isTeacher = (($chat_user['role'] ?? '') === 'teacher');
              $cuBadge = $isTeacher ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700';
              $cuIcon  = $isTeacher ? 'graduation-cap' : 'user';
              $cuInitials = strtoupper(mb_substr($chat_user['first_name'] ?? 'U', 0, 1) . mb_substr($chat_user['last_name'] ?? '', 0, 1));
            ?>
            <!-- Chat Header -->
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
              <div class="flex items-center gap-3 min-w-0">
                <div class="relative">
                  <div class="flex items-center justify-center h-10 w-10 rounded-full bg-gradient-to-br from-blue-100 to-indigo-100 text-blue-700 font-semibold ring-1 ring-blue-200"><?= $cuInitials ?: '👤' ?></div>
                  <span class="absolute -bottom-1 -right-1 inline-flex items-center justify-center h-5 w-5 rounded-full bg-white ring-1 ring-gray-200">
                    <i data-lucide="<?= $cuIcon ?>" class="w-3.5 h-3.5 <?= $isTeacher ? 'text-indigo-600' : 'text-emerald-600' ?>"></i>
                  </span>
                </div>
                <div class="min-w-0">
                  <h3 class="text-base font-semibold text-gray-900 truncate"><?= $cuName ?></h3>
                  <span class="text-[10px] px-2 py-0.5 rounded-full <?= $cuBadge ?>"><?= $cuRole ?></span>
                </div>
              </div>
              <a href="#userList" class="md:hidden text-blue-700 text-sm hover:underline inline-flex items-center gap-1">
                <i data-lucide="users" class="w-4 h-4"></i> Users
              </a>
            </div>

            <!-- Messages -->
            <div id="chatBox" class="flex-1 overflow-y-auto px-4 md:px-5 py-4 space-y-2 max-h-[65vh]">
              <?php if ($chat_history && $chat_history->num_rows > 0): ?>
                <?php $prevDate = null; ?>
                <ul class="space-y-2">
                  <?php while ($msg = $chat_history->fetch_assoc()):
                    $isMine = ((int)$msg['sender_id'] === $user_id);
                    $dateStr = date('M j, Y', strtotime($msg['sent_at']));
                    $timeStr = date('g:i A', strtotime($msg['sent_at']));
                    $bubbleClasses = $isMine
                      ? 'bg-blue-600 text-white rounded-2xl rounded-tr-sm'
                      : 'bg-slate-100 text-gray-800 rounded-2xl rounded-tl-sm';
                    $align = $isMine ? 'justify-end' : 'justify-start';
                    $timeColor = $isMine ? 'text-blue-100/80' : 'text-gray-500';

                    $plain = decrypt_message($msg['message']);
                  ?>
                    <?php if ($prevDate !== $dateStr): ?>
                      <li class="flex justify-center py-2">
                        <span class="inline-flex items-center gap-1 text-xs text-gray-600 bg-gray-100 px-3 py-1 rounded-full ring-1 ring-gray-200">
                          <i data-lucide="calendar" class="w-3.5 h-3.5"></i><?= $dateStr ?>
                        </span>
                      </li>
                      <?php $prevDate = $dateStr; ?>
                    <?php endif; ?>

                    <li class="flex <?= $align ?>">
                      <div class="max-w-[75%] px-4 py-2 shadow-sm ring-1 ring-gray-200/50 <?= $bubbleClasses ?>">
                        <div class="flex items-center gap-1 text-[10px] <?= $timeColor ?> mb-1">
                          <i data-lucide="clock-3" class="w-3 h-3"></i>
                          <span><?= $timeStr ?></span>
                        </div>
                        <div class="whitespace-pre-wrap break-words"><?= nl2br(htmlspecialchars($plain)) ?></div>
                      </div>
                    </li>
                  <?php endwhile; ?>
                </ul>
              <?php else: ?>
                <div class="text-gray-500 text-center py-12 flex flex-col items-center gap-2">
                  <i data-lucide="message-square" class="w-8 h-8 text-blue-600"></i>
                  No messages yet. Say hi 👋
                </div>
              <?php endif; ?>
            </div>

            <!-- Composer -->
            <form id="chatForm" method="post" class="border-t border-gray-100 p-3 md:p-4">
              <input type="hidden" name="receiver_id" value="<?= (int)$chat_with ?>" />
              <div class="flex items-end gap-2">
                <div class="flex items-center gap-2">
                  <button type="button" class="p-2 text-gray-500 hover:text-blue-700 rounded-lg hover:bg-blue-50" title="Attach file" aria-label="Attach file">
                    <i data-lucide="paperclip" class="w-5 h-5"></i>
                  </button>
                  <button type="button" class="p-2 text-gray-500 hover:text-blue-700 rounded-lg hover:bg-blue-50" title="Emoji" aria-label="Emoji">
                    <i data-lucide="smile" class="w-5 h-5"></i>
                  </button>
                </div>
                <textarea id="message" name="message" required rows="1" placeholder="Type your message..."
                          class="flex-1 max-h-40 border border-gray-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300 resize-none"></textarea>
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-xl hover:bg-blue-700 shadow-sm">
                  <i data-lucide="send" class="w-4 h-4"></i> Send
                </button>
              </div>
            </form>
          <?php else: ?>
            <div class="flex-1 flex flex-col items-center justify-center px-6 py-24 text-center">
              <div class="mx-auto w-16 h-16 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center mb-4">
                <i data-lucide="messages-square" class="w-8 h-8"></i>
              </div>
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
  // Lucide icons init
  if (window.lucide) { lucide.createIcons(); }

  // Filter users
  (function(){
    const userSearch = document.getElementById('userSearch');
    const list = document.getElementById('userList');
    if (!userSearch || !list) return;
    userSearch.addEventListener('input', () => {
      const q = userSearch.value.trim().toLowerCase();
      [...list.querySelectorAll('li')].forEach(li => {
        const name = li.getAttribute('data-name') || '';
        li.style.display = name.includes(q) ? '' : 'none';
      });
    });
  })();

  // Auto-scroll to bottom on load
  (function(){
    const chatBox = document.getElementById('chatBox');
    chatBox && (chatBox.scrollTop = chatBox.scrollHeight);
  })();

  // Textarea auto-resize and submit on Enter (Shift+Enter for newline)
  (function(){
    const ta = document.getElementById('message');
    const form = document.getElementById('chatForm');
    if (!ta || !form) return;
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
  })();
</script>
</body>
</html>