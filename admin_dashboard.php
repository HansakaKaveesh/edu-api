<?php
session_start();
include 'db_connect.php';

// Allow only admin users
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Handle announcement creation
$announce_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'], $_POST['message'], $_POST['audience'])) {
    $title = $conn->real_escape_string(trim($_POST['title']));
    $message = $conn->real_escape_string(trim($_POST['message']));
    $audience = $conn->real_escape_string($_POST['audience']);
    if ($title && $message && in_array($audience, ['students', 'teachers', 'all'])) {
        $conn->query("INSERT INTO announcements (title, message, audience) VALUES ('$title', '$message', '$audience')");
        $announce_message = '<div class="relative rounded-xl px-4 py-3 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">✅ Announcement posted!</div>';
    } else {
        $announce_message = '<div class="relative rounded-xl px-4 py-3 bg-red-50 text-red-700 ring-1 ring-red-200">❌ Please fill all fields.</div>';
    }
}

// Fetch statistics
$total_students = $conn->query("SELECT COUNT(*) AS count FROM students")->fetch_assoc()['count'];
$total_teachers = $conn->query("SELECT COUNT(*) AS count FROM teachers")->fetch_assoc()['count'];
$total_courses = $conn->query("SELECT COUNT(*) AS count FROM courses")->fetch_assoc()['count'];
$total_enrollments = $conn->query("SELECT COUNT(*) AS count FROM enrollments")->fetch_assoc()['count'];
$total_revenue = $conn->query("SELECT IFNULL(SUM(amount), 0) AS total FROM student_payments WHERE payment_status = 'completed'")->fetch_assoc()['total'];

// Fetch announcements
$announcements = $conn->query("SELECT id, title, message, audience, created_at FROM announcements ORDER BY created_at DESC LIMIT 10");

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial; }
    @keyframes wave { 0%, 60%, 100% { transform: rotate(0deg); } 30% { transform: rotate(15deg); } 50% { transform: rotate(-10deg); } }
    .animate-wave { display: inline-block; animation: wave 2s infinite; transform-origin: 70% 70%; }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 min-h-screen flex flex-col antialiased">

<?php include 'components/navbar.php'; ?>

<!-- Header Card -->
<section class="max-w-8xl mx-auto px-6 pt-28 ">
  <div class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-8">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
      <div class="absolute -top-16 -right-20 w-72 h-72 bg-blue-200/40 rounded-full blur-3xl"></div>
      <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
    </div>
    <div class="relative">
      <h1 class="text-3xl sm:text-4xl font-extrabold text-blue-700 tracking-tight">
        Welcome back, Admin! <span class="animate-wave">👋</span>
      </h1>
      <p class="mt-3 text-gray-600 max-w-3xl">
        Monitor students, teachers, courses, and revenue in one place. Manage your platform with ease.
      </p>
    </div>
    <!-- Quick stats -->
    <div class="relative mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
      <div class="rounded-xl bg-white ring-1 ring-gray-200 p-5 hover:shadow-md transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Students</span>
          <span class="h-6 w-6 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center">👨‍🎓</span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= $total_students ?></div>
      </div>
      <div class="rounded-xl bg-white ring-1 ring-gray-200 p-5 hover:shadow-md transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Teachers</span>
          <span class="h-6 w-6 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center">👩‍🏫</span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= $total_teachers ?></div>
      </div>
      <div class="rounded-xl bg-white ring-1 ring-gray-200 p-5 hover:shadow-md transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Courses</span>
          <span class="h-6 w-6 rounded-full bg-pink-50 text-pink-600 flex items-center justify-center">📘</span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= $total_courses ?></div>
      </div>
      <div class="rounded-xl bg-white ring-1 ring-gray-200 p-5 hover:shadow-md transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Enrollments</span>
          <span class="h-6 w-6 rounded-full bg-violet-50 text-violet-600 flex items-center justify-center">🧑‍🤝‍🧑</span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-gray-900"><?= $total_enrollments ?></div>
      </div>
      <div class="rounded-xl bg-white ring-1 ring-gray-200 p-5 hover:shadow-md transition">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500">Revenue</span>
          <span class="h-6 w-6 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center">💵</span>
        </div>
        <div class="text-3xl font-extrabold mt-2 text-emerald-700">$<?= number_format($total_revenue, 2) ?></div>
      </div>
    </div>
  </div>
</section>

<main class="max-w-8xl mx-auto px-6 py-10 flex-grow">
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
<?php
  // Optional customization before include:
  // $adminTools = [...]; // your existing array
  $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
  $createAnnouncementLink = '#create-announcement';
  include 'components/admin_tools_sidebar.php';
?>

    <!-- Main column -->
    <section class="lg:col-span-9 space-y-8">
      <!-- Mobile: open tools button -->
      <div class="lg:hidden flex justify-end">
        <button id="toolsOpen"
                class="inline-flex items-center gap-2 bg-white ring-1 ring-gray-200 px-3 py-2 rounded-lg shadow-sm text-blue-700 hover:bg-blue-50 transition"
                aria-controls="toolsDrawer" aria-expanded="false" aria-label="Open admin tools">
          <span class="fa-solid fa-sliders"></span> Admin Tools
        </button>
      </div>

      <!-- Recent Announcements -->
      <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-xl font-bold text-gray-900">📢 Recent Announcements</h3>
          <a href="#create-announcement" class="text-blue-700 text-sm hover:underline">Create new</a>
        </div>

        <?php if ($announcements && $announcements->num_rows > 0): ?>
          <ul class="space-y-4">
            <?php while ($a = $announcements->fetch_assoc()):
              $aud = ucfirst($a['audience']);
              $badge = $a['audience'] === 'teachers' ? 'bg-indigo-100 text-indigo-700' : ($a['audience'] === 'students' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700');
            ?>
              <li class="rounded-xl bg-blue-50/60 ring-1 ring-blue-100 p-4">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-blue-100 text-blue-700 text-sm">📣</span>
                      <span class="font-semibold text-blue-800 truncate"><?= htmlspecialchars($a['title']) ?></span>
                    </div>
                    <div class="text-gray-700 mt-2 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
                  </div>
                  <div class="text-right shrink-0">
                    <div class="text-xs text-gray-500"><?= date('M d, Y', strtotime($a['created_at'])) ?></div>
                    <div class="mt-1 inline-block text-[11px] px-2 py-0.5 rounded-full <?= $badge ?>"><?= $aud ?></div>
                  </div>
                </div>
              </li>
            <?php endwhile; ?>
          </ul>
        <?php else: ?>
          <div class="text-gray-600 text-center py-10">
            <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">📄</div>
            No announcements yet.
          </div>
        <?php endif; ?>
      </section>

      <!-- Create Announcement -->
      <section id="create-announcement" class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-6 mb-2">
        <h3 class="text-xl font-bold text-gray-900 mb-4">📢 Create Announcement</h3>
        <?php if (!empty($announce_message)): ?>
          <div class="mb-4"><?= $announce_message ?></div>
        <?php endif; ?>

        <form method="post" class="space-y-5">
          <div>
            <label for="title" class="block font-medium mb-1">Title</label>
            <input id="title" type="text" name="title" required
                  class="w-full border border-gray-200 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300" />
          </div>
          <div>
            <label for="message" class="block font-medium mb-1">Message</label>
            <textarea id="message" name="message" rows="4" required
                      class="w-full border border-gray-200 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"></textarea>
          </div>
          <div>
            <span class="block font-medium mb-2">Audience</span>
            <div class="flex flex-wrap gap-2">
              <label class="cursor-pointer">
                <input type="radio" name="audience" value="all" class="peer sr-only" checked>
                <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-block px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">All</span>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="audience" value="students" class="peer sr-only">
                <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-block px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">Students</span>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="audience" value="teachers" class="peer sr-only">
                <span class="peer-checked:bg-blue-600 peer-checked:text-white inline-block px-4 py-2 rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200">Teachers</span>
              </label>
            </div>
          </div>
          <div class="pt-2">
            <button type="submit"
                    class="bg-blue-600 text-white px-6 py-2.5 rounded-lg hover:bg-blue-700 shadow-sm">
              Post Announcement
            </button>
          </div>
        </form>
      </section>
    </section>
  </div>
</main>

<!-- Mobile Tools Drawer -->
<div id="toolsOverlay" class="fixed inset-0 bg-black/40 hidden lg:hidden z-40"></div>
<aside id="toolsDrawer"
       class="fixed top-0 left-0 h-full w-5/6 max-w-[320px] bg-white p-4 shadow-2xl z-50 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden"
       role="dialog" aria-modal="true" aria-labelledby="toolsDrawerTitle" aria-hidden="true">
  <div class="flex items-center justify-between mb-3">
    <h3 id="toolsDrawerTitle" class="text-lg font-bold text-gray-900">⚙️ Admin Tools</h3>
    <button id="toolsClose" class="p-2 rounded-lg hover:bg-gray-100" aria-label="Close admin tools">
      <span class="fa-solid fa-xmark"></span>
    </button>
  </div>
  <nav class="space-y-2">
    <?php foreach ($adminTools as $tool):
      $link = $tool[0]; $icon = $tool[1]; $label = $tool[2];
      $isActive = (basename(parse_url($link, PHP_URL_PATH)) === $currentPath);
      $classes = 'group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition';
      if ($isActive) $classes .= ' bg-blue-50 ring-blue-300';
    ?>
      <a href="<?= htmlspecialchars($link) ?>"
         class="<?= $classes ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>>
        <span class="fa-solid <?= htmlspecialchars($icon) ?> text-blue-600 w-5 text-center"></span>
        <span class="font-medium text-gray-800 group-hover:text-blue-800"><?= htmlspecialchars($label) ?></span>
      </a>
    <?php endforeach; ?>
    <a href="#create-announcement" class="group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition">
      <span class="fa-solid fa-bullhorn text-blue-600 w-5 text-center"></span>
      <span class="font-medium text-gray-800 group-hover:text-blue-800">Create Announcement</span>
    </a>
  </nav>
</aside>

<?php include 'components/footer.php'; ?>

<!-- Scripts: mobile drawer -->
<script>
  (function() {
    const openBtn = document.getElementById('toolsOpen');
    const closeBtn = document.getElementById('toolsClose');
    const drawer = document.getElementById('toolsDrawer');
    const overlay = document.getElementById('toolsOverlay');
    let prevFocus = null;

    function openDrawer() {
      prevFocus = document.activeElement;
      drawer.style.transform = 'translateX(0)';
      overlay.classList.remove('hidden');
      drawer.setAttribute('aria-hidden', 'false');
      openBtn?.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
      const first = drawer.querySelector('a,button');
      first && first.focus();
      document.addEventListener('keydown', onKeydown);
      overlay.addEventListener('click', closeDrawer, { once: true });
    }
    function closeDrawer() {
      drawer.style.transform = 'translateX(-100%)';
      overlay.classList.add('hidden');
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
    // Close when clicking any link inside the drawer
    drawer.addEventListener('click', (e) => {
      const t = e.target.closest('a,button');
      if (!t) return;
      // If it's an in-page anchor, still close
      closeDrawer();
    });
    window.addEventListener('resize', () => { if (window.innerWidth >= 1024) closeDrawer(); });
  })();
</script>
</body>
</html>