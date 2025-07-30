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
</head>
<body class="bg-gray-50 min-h-screen">
<?php include 'components/navbar.php'; ?>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-8 py-28 gap-8">
  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full max-w-full mx-auto space-y-10">
    <div class="flex flex-col md:flex-row gap-8">
      <!-- User List -->
      <aside class="w-full md:w-1/3 bg-white rounded-xl shadow p-4 h-fit">
        <h2 class="text-xl font-bold mb-4 text-blue-700">Users</h2>
        <ul class="space-y-2">
          <?php while ($u = $users->fetch_assoc()): ?>
            <li>
              <a href="messages.php?user=<?= $u['user_id'] ?>"
                 class="block px-3 py-2 rounded hover:bg-blue-50 <?= $chat_with == $u['user_id'] ? 'bg-blue-100 font-bold' : '' ?>">
                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                <span class="text-xs text-gray-500">(<?= ucfirst($u['role']) ?>)</span>
              </a>
            </li>
          <?php endwhile; ?>
        </ul>
      </aside>

      <!-- Chat Area -->
      <section class="w-full md:w-2/3 bg-white rounded-xl shadow p-6 flex flex-col">
        <?php if ($chat_with): ?>
          <div class="mb-4 border-b pb-2">
            <h3 class="text-lg font-semibold text-blue-700">
              Chat with 
              <?php
              $chat_user = $conn->query("SELECT COALESCE(s.first_name, t.first_name) AS first_name, COALESCE(s.last_name, t.last_name) AS last_name, role FROM users u LEFT JOIN students s ON u.user_id = s.user_id LEFT JOIN teachers t ON u.user_id = t.user_id WHERE u.user_id = $chat_with")->fetch_assoc();
              echo htmlspecialchars($chat_user['first_name'] . ' ' . $chat_user['last_name']) . " (" . ucfirst($chat_user['role']) . ")";
              ?>
            </h3>
          </div>
          <div class="flex-1 overflow-y-auto mb-4 max-h-96">
            <?php if ($chat_history && $chat_history->num_rows > 0): ?>
              <ul class="space-y-2">
                <?php while ($msg = $chat_history->fetch_assoc()): ?>
                  <li class="flex <?= $msg['sender_id'] == $user_id ? 'justify-end' : 'justify-start' ?>">
                    <div class="max-w-xs px-4 py-2 rounded-lg shadow <?= $msg['sender_id'] == $user_id ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-800' ?>">
                      <div class="text-xs mb-1"><?= date('M j, g:i A', strtotime($msg['sent_at'])) ?></div>
                      <div><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                    </div>
                  </li>
                <?php endwhile; ?>
              </ul>
            <?php else: ?>
              <div class="text-gray-500">No messages yet.</div>
            <?php endif; ?>
          </div>
          <form method="post" class="flex gap-2">
            <input type="hidden" name="receiver_id" value="<?= $chat_with ?>" />
            <input type="text" name="message" required placeholder="Type your message..." class="flex-1 border rounded px-3 py-2 focus:outline-none focus:ring focus:border-blue-400" />
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Send</button>
          </form>
        <?php else: ?>
          <div class="text-gray-500 text-center mt-20">Select a user to start chatting.</div>
        <?php endif; ?>
      </section>
    </div>
  </main>
</div>
</body>
</html>