<?php
// teacher_messages.php — direct messages for teachers
// If needed, create table:
// CREATE TABLE messages (
//   id INT AUTO_INCREMENT PRIMARY KEY,
//   sender_id INT NOT NULL,
//   receiver_id INT NOT NULL,
//   message TEXT NOT NULL,
//   sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
//   is_read BOOLEAN DEFAULT 0,
//   FOREIGN KEY (sender_id) REFERENCES users(user_id),
//   FOREIGN KEY (receiver_id) REFERENCES users(user_id)
// );

session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'teacher')) {
    header("Location: login.php");
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function initials($first, $last) {
  $i = '';
  if ($first) $i .= mb_substr($first, 0, 1);
  if ($last)  $i .= mb_substr($last, 0, 1);
  return strtoupper($i ?: 'U');
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$user_id = (int)$_SESSION['user_id'];

/* fetch teacher record with id + name */
$teacher_id = 0;
$teacher_name = 'Teacher';
if ($stmt = $conn->prepare("SELECT teacher_id, first_name, last_name FROM teachers WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $teacher_id = (int)$row['teacher_id'];
        $teacher_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Teacher';
    }
    $stmt->close();
}
if ($teacher_id <= 0) { header("Location: login.php"); exit; }

/* flash */
$flash = $_SESSION['flash'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash'], $_SESSION['flash_type']);

/* Build contacts: students enrolled in teacher's courses */
$contacts = [];           // list of ['user_id','first_name','last_name','email','course_count']
$contactsMap = [];        // user_id => contact array
$unreadBySender = [];     // sender_id => unread count

// Contacts
if ($stmt = $conn->prepare("
  SELECT s.user_id AS uid, s.first_name, s.last_name, s.email, COUNT(DISTINCT e.course_id) AS course_count
  FROM enrollments e
  JOIN teacher_courses tc ON tc.course_id = e.course_id AND tc.teacher_id = ?
  JOIN students s ON s.user_id = e.user_id
  GROUP BY s.user_id, s.first_name, s.last_name, s.email
  ORDER BY s.first_name, s.last_name
")) {
  $stmt->bind_param("i", $teacher_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $contacts[] = $r;
    $contactsMap[(int)$r['uid']] = $r;
  }
  $stmt->close();
}

// Unread counts per sender
if ($stmt = $conn->prepare("
  SELECT sender_id, COUNT(*) AS cnt
  FROM messages
  WHERE receiver_id = ? AND is_read = 0
  GROUP BY sender_id
")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $unreadBySender[(int)$r['sender_id']] = (int)$r['cnt'];
  }
  $stmt->close();
}

// Sidebar counts
$counts = ['courses'=>0, 'students'=>0, 'messages'=>0];
if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM teacher_courses WHERE teacher_id = ?")) {
  $stmt->bind_param("i", $teacher_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $counts['courses'] = (int)($row['cnt'] ?? 0);
  $stmt->close();
}
$counts['students'] = count($contacts);
if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $counts['messages'] = (int)($row['cnt'] ?? 0);
  $stmt->close();
}

/* Handle POST actions (send message) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $_SESSION['flash'] = 'Invalid session. Please refresh and try again.';
    $_SESSION['flash_type'] = 'error';
    header("Location: teacher_messages.php"); exit;
  }

  if ($action === 'send_message') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($receiver_id <= 0 || $message === '') {
      $_SESSION['flash'] = 'Please select a recipient and enter a message.';
      $_SESSION['flash_type'] = 'error';
      header("Location: teacher_messages.php" . ($receiver_id ? "?with=".$receiver_id : "")); exit;
    }
    // Validate recipient belongs to teacher's contacts
    if (!isset($contactsMap[$receiver_id])) {
      $_SESSION['flash'] = 'You can only message your students.';
      $_SESSION['flash_type'] = 'error';
      header("Location: teacher_messages.php"); exit;
    }
    // Insert
    if ($stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")) {
      $stmt->bind_param("iis", $user_id, $receiver_id, $message);
      $ok = $stmt->execute();
      $stmt->close();
      $_SESSION['flash'] = $ok ? 'Message sent.' : 'Failed to send message.';
      $_SESSION['flash_type'] = $ok ? 'success' : 'error';
    } else {
      $_SESSION['flash'] = 'Failed to prepare message.';
      $_SESSION['flash_type'] = 'error';
    }
    header("Location: teacher_messages.php?with=".$receiver_id."#end"); exit;
  }
}

/* Active conversation */
$with = isset($_GET['with']) ? (int)$_GET['with'] : 0;
if ($with > 0 && !isset($contactsMap[$with])) {
  // If invalid contact, reset
  $with = 0;
}
if ($with === 0 && !empty($contacts)) {
  $with = (int)$contacts[0]['uid'];
}

// Mark as read for the open conversation (messages received by teacher from 'with')
if ($with > 0) {
  if ($stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0")) {
    $stmt->bind_param("ii", $with, $user_id);
    $stmt->execute();
    $stmt->close();
  }
}

// Fetch conversation (last 200 messages)
$thread = [];
if ($with > 0) {
  if ($stmt = $conn->prepare("
    SELECT id, sender_id, receiver_id, message, sent_at, is_read
    FROM messages
    WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
    ORDER BY sent_at ASC, id ASC
    LIMIT 200
  ")) {
    $stmt->bind_param("iiii", $user_id, $with, $with, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $thread[] = $r;
    $stmt->close();
  }
}

// Current contact details
$contact = $with ? $contactsMap[$with] : null;
$contact_name = $contact ? trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) : '';
$contact_initials = $contact ? initials($contact['first_name'] ?? '', $contact['last_name'] ?? '') : 'U';

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8" />
  <title>Messages — Teacher</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link rel="icon" type="image/png" href="./images/logo.png" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: { 50:'#eef2ff', 100:'#e0e7ff', 200:'#c7d2fe', 300:'#a5b4fc', 400:'#818cf8', 500:'#6366f1', 600:'#4f46e5', 700:'#4338ca', 800:'#3730a3', 900:'#312e81' }
          },
          boxShadow: { glow: '0 0 0 3px rgba(99,102,241,.12), 0 16px 32px rgba(2,6,23,.10)' },
          keyframes: { 'fade-in-up': { '0%': { opacity: 0, transform: 'translateY(8px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } } },
          animation: { 'fade-in-up': 'fade-in-up .5s ease-out both' }
        }
      }
    }
  </script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI'; }
    html { scroll-behavior: smooth; }
    .hover-raise { transition:.2s ease; }
    .hover-raise:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(2,6,23,.08); }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50 text-slate-900 min-h-screen">
<?php include 'components/navbar.php'; ?>

<div class="max-w-8xl mx-auto px-6 mt-24 grid grid-cols-1 lg:grid-cols-12 gap-6 mb-20">
  <!-- Sidebar -->
  <aside class="hidden lg:block lg:col-span-3">
    <?php
      $active = 'messages';
      include __DIR__ . '/components/teacher_sidebar.php';
    ?>
  </aside>

  <!-- Main -->
  <main class="lg:col-span-9 space-y-6">
    <!-- Hero (inside main) -->
    <section class="relative overflow-hidden rounded-2xl ring-1 ring-indigo-100 shadow-sm">
      <div aria-hidden="true" class="absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1529156069898-49953e39b3ac?q=80&w=1600&auto=format&fit=crop')] bg-cover bg-center"></div>
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/90 via-blue-900/80 to-sky-900/80"></div>
      </div>
      <div class="px-6 py-10">
        <div class="flex items-center justify-between text-white">
          <h1 class="text-2xl md:text-3xl font-extrabold flex items-center gap-2">
            <i class="ph ph-chat-circle-dots"></i> Messages
          </h1>
          <div class="rounded-xl border border-white/20 bg-white/10 backdrop-blur px-4 py-2 text-sm hidden sm:block">
            <?= date('l, d M Y') ?> • <span class="text-white/80"><?= date('h:i A') ?></span>
          </div>
        </div>
      </div>
    </section>

    <!-- Flash -->
    <?php if (!empty($flash)): 
      $cls = $flash_type === 'success' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' :
             ($flash_type === 'error' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-indigo-50 text-indigo-700 ring-indigo-200'); ?>
      <div class="animate-fade-in-up rounded-xl px-4 py-3 ring-1 <?= $cls ?> shadow-sm">
        <?= e($flash) ?>
      </div>
    <?php endif; ?>

    <section class="grid grid-cols-1 lg:grid-cols-12 gap-4">
      <!-- Contacts -->
      <div class="lg:col-span-4 rounded-2xl bg-white ring-1 ring-slate-200 shadow-sm p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-slate-900 flex items-center gap-2">
            <i class="ph ph-users-three text-indigo-600"></i> Students
          </h2>
          <span class="text-xs text-slate-500"><?= count($contacts) ?> total</span>
        </div>
        <div class="relative mb-3">
          <input id="filterContacts" type="text" placeholder="Search students..."
                 class="w-full pl-9 pr-3 py-2 rounded-lg border-slate-300 focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 text-sm">
          <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
        </div>
        <ul id="contactsList" class="space-y-1 max-h-[60vh] overflow-y-auto pr-1">
          <?php if (!$contacts): ?>
            <li class="text-sm text-slate-600 p-3">No students found. Add students via enrollments first.</li>
          <?php else: ?>
            <?php foreach ($contacts as $c):
              $uid = (int)$c['uid'];
              $name = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')) ?: 'Student';
              $inits = initials($c['first_name'] ?? '', $c['last_name'] ?? '');
              $unread = $unreadBySender[$uid] ?? 0;
              $activeItem = $with === $uid;
            ?>
            <li>
              <a href="teacher_messages.php?with=<?= $uid ?>"
                 class="group flex items-center gap-3 rounded-lg px-3 py-2 ring-1 <?= $activeItem ? 'ring-indigo-200 bg-indigo-50/60' : 'ring-transparent hover:bg-slate-50' ?>"
                 data-key="<?= e(strtolower($name.' '.$c['email'])) ?>">
                <div class="h-10 w-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold"><?= e($inits) ?></div>
                <div class="min-w-0">
                  <div class="font-medium text-slate-900 truncate"><?= e($name) ?></div>
                  <div class="text-xs text-slate-500 truncate"><?= e($c['email']) ?></div>
                </div>
                <div class="ml-auto flex items-center gap-2">
                  <?php if ($unread > 0): ?>
                    <span class="inline-flex items-center justify-center rounded-full bg-rose-600 text-white text-[11px] px-2 py-0.5"><?= $unread ?></span>
                  <?php endif; ?>
                  <span class="text-[11px] text-slate-500 hidden sm:inline">in <?= (int)$c['course_count'] ?> course<?= (int)$c['course_count']===1?'':'s' ?></span>
                </div>
              </a>
            </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <!-- Thread -->
      <div class="lg:col-span-8 rounded-2xl bg-white ring-1 ring-slate-200 shadow-sm flex flex-col min-h-[60vh]">
        <?php if (!$with): ?>
          <div class="p-6 text-slate-600">Select a student to start messaging.</div>
        <?php else: ?>
          <!-- Header -->
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="h-10 w-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold"><?= e($contact_initials) ?></div>
              <div>
                <div class="font-semibold text-slate-900"><?= e($contact_name) ?></div>
                <div class="text-xs text-slate-500"><?= e($contact['email'] ?? '') ?></div>
              </div>
            </div>
            <a href="teacher_students.php" class="text-sm text-indigo-700 hover:underline inline-flex items-center gap-1">
              View student <i class="ph ph-caret-right"></i>
            </a>
          </div>

          <!-- Messages -->
          <div id="chatBox" class="flex-1 overflow-y-auto px-4 py-4 space-y-3">
            <?php if (!$thread): ?>
              <div class="text-center text-slate-500 py-8">No messages yet. Say hello!</div>
            <?php else: ?>
              <?php foreach ($thread as $m):
                $outgoing = ((int)$m['sender_id'] === $user_id);
                $bubble = $outgoing
                  ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm'
                  : 'bg-slate-100 text-slate-800 rounded-2xl rounded-bl-sm';
                $time = $m['sent_at'] ? date('M d, h:i A', strtotime($m['sent_at'])) : '';
              ?>
              <div class="flex <?= $outgoing ? 'justify-end' : 'justify-start' ?>">
                <div class="max-w-[80%]">
                  <div class="px-3 py-2 <?= $bubble ?>">
                    <div class="whitespace-pre-wrap break-words text-sm"><?= nl2br(e($m['message'])) ?></div>
                  </div>
                  <div class="mt-1 text-[11px] text-slate-500 <?= $outgoing ? 'text-right' : '' ?>"><?= e($time) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
            <div id="end"></div>
          </div>

          <!-- Composer -->
          <div class="p-3 border-t border-slate-200">
            <form method="post" class="flex items-end gap-2" id="messageForm">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="send_message">
              <input type="hidden" name="receiver_id" value="<?= (int)$with ?>">
              <textarea name="message" id="messageInput" rows="2" required
                        placeholder="Write a message..."
                        class="flex-1 rounded-lg border-slate-300 focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600"></textarea>
              <button class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg shadow">
                <i class="ph ph-paper-plane-tilt"></i> Send
              </button>
            </form>
            <div class="mt-1 text-[11px] text-slate-500">Tip: Press Ctrl+Enter to send</div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>
</div>

<script>
// Contacts filter
(function(){
  const input = document.getElementById('filterContacts');
  const list = document.getElementById('contactsList');
  if (!input || !list) return;
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    list.querySelectorAll('[data-key]').forEach(el => {
      const key = el.getAttribute('data-key') || '';
      el.parentElement.style.display = (!q || key.includes(q)) ? '' : 'none';
    });
  });
})();

// Auto scroll to bottom
(function(){
  const box = document.getElementById('chatBox');
  if (!box) return;
  box.scrollTop = box.scrollHeight;
})();

// Ctrl+Enter send
(function(){
  const form = document.getElementById('messageForm');
  const input = document.getElementById('messageInput');
  if (!form || !input) return;
  input.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.key === 'Enter') {
      form.submit();
    }
  });
})();
</script>
</body>
</html>