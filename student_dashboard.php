<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Safer fetch for student info
$stmt = $conn->prepare("SELECT student_id, first_name, last_name FROM students WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

$student_id = $student['student_id'] ?? null;
$full_name = $student ? $student['first_name'] . ' ' . $student['last_name'] : 'Student';
$role = ucfirst($_SESSION['role']);

// Pull data
$announcements = $conn->query("
  SELECT title, message, created_at
  FROM announcements
  WHERE audience IN ('students','all')
  ORDER BY created_at DESC
  LIMIT 5
");

$courses = $conn->query("
  SELECT c.course_id, c.name, c.description
  FROM enrollments e
  JOIN courses c ON e.course_id = c.course_id
  WHERE e.user_id = $user_id AND e.status = 'active'
");

$logs = $conn->query("
  SELECT a.action, a.timestamp, c.title 
  FROM activity_logs a
  JOIN contents c ON a.content_id = c.content_id
  WHERE a.user_id = $user_id
  ORDER BY a.timestamp DESC
  LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Student Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html, body { font-family: "Inter", ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; }

    /* Emoji wave */
    @keyframes wave {
      0%, 60%, 100% { transform: rotate(0deg); }
      30% { transform: rotate(15deg); }
      50% { transform: rotate(-10deg); }
    }
    .animate-wave { display: inline-block; animation: wave 2s infinite; transform-origin: 70% 70%; }

    /* Text clamp */
    .line-clamp-3 {
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    /* Fancy background bubbles */
    .bg-bubbles::before,
    .bg-bubbles::after {
      content: "";
      position: absolute;
      border-radius: 9999px;
      filter: blur(40px);
      opacity: .25;
      z-index: 0;
      pointer-events: none;
    }
    .bg-bubbles::before {
      width: 420px; height: 420px;
      background: radial-gradient(closest-side, #60a5fa, transparent 70%);
      top: -80px; left: -80px;
    }
    .bg-bubbles::after {
      width: 500px; height: 500px;
      background: radial-gradient(closest-side, #a78bfa, transparent 70%);
      bottom: -120px; right: -120px;
    }

    /* Soft fade-in */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(10px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .animate-fadeUp { animation: fadeUp .5s ease-out both; }

    ::-webkit-scrollbar-track { background: transparent; }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-sky-50 via-white to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<!-- Decorative background -->
<div class="fixed inset-0 bg-bubbles -z-10"></div>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-6 lg:px-10 py-28 gap-8">
  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full space-y-10 animate-fadeUp">

    <!-- Hero Section -->
    <section class="relative overflow-hidden rounded-3xl shadow-2xl p-6 sm:p-12">
      <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1523580846011-d3a5bc25702b?q=80&w=1786&auto=format&fit=crop')] bg-cover bg-center"></div>
      <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/90 via-blue-600/80 to-cyan-500/70"></div>

      <div class="relative z-10 text-white text-center space-y-6 sm:space-y-8">
        <div id="datetime" class="text-xs sm:text-sm text-right italic opacity-90 drop-shadow-sm" aria-live="polite"></div>
        <h1 class="text-2xl sm:text-3xl font-extrabold leading-tight drop-shadow-xl">
          🎓 Welcome back, <span class="underline decoration-white/30"><?php echo htmlspecialchars($full_name); ?></span>
          <span class="text-sm sm:text-xl font-light italic">(<?php echo $role; ?>)</span>!
          <span class="inline-block animate-wave">👋</span>
        </h1>
        <p class="text-base sm:text-lg font-light text-white/95 max-w-3xl mx-auto">
          Pick up where you left off, explore new topics, and stay on top of your goals.
          <span class="block opacity-90 mt-1">Access courses, take quizzes, and join vibrant discussions.</span>
        </p>
        <div class="flex items-center justify-center gap-3">
          <a href="enroll_course.php" class="inline-flex items-center gap-2 rounded-full bg-white/90 text-indigo-700 font-semibold px-5 py-2.5 shadow hover:shadow-lg hover:translate-y-[-1px] transition">
            <span>➕ Enroll in a course</span>
          </a>
          <a href="#courses" class="inline-flex items-center gap-2 rounded-full bg-indigo-900/30 text-white font-medium px-5 py-2.5 ring-1 ring-white/30 hover:bg-indigo-900/40 transition">
            Browse your courses
          </a>
        </div>
      </div>
    </section>

    <!-- Announcements -->
    <section class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100/80">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-xl sm:text-2xl font-semibold text-gray-700">📢 Announcements</h3>
      </div>

      <?php if ($announcements && $announcements->num_rows > 0): ?>
        <ul class="space-y-4">
          <?php while ($a = $announcements->fetch_assoc()): ?>
            <li class="rounded-xl p-4 bg-gradient-to-r from-blue-50/70 to-indigo-50/60 border border-blue-200/50 shadow-sm hover:shadow-md transition">
              <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-1">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-blue-500 ring-4 ring-blue-200/40"></span>
                    <span class="font-semibold text-blue-700"><?= htmlspecialchars($a['title']) ?></span>
                  </div>
                  <div class="text-gray-700 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
                </div>
                <span class="shrink-0 text-xs text-gray-500 mt-1">
                  <?= date('M d, Y', strtotime($a['created_at'])) ?>
                </span>
              </div>
            </li>
          <?php endwhile; ?>
        </ul>
      <?php else: ?>
        <div class="text-gray-600 text-base sm:text-lg">
          No announcements right now. Enjoy the calm ✨
        </div>
      <?php endif; ?>
    </section>

    <!-- Courses -->
    <section id="courses" class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100/80">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <h3 class="text-xl sm:text-2xl font-semibold text-gray-700">📚 Your Enrolled Courses</h3>
        <form action="courses.php" method="get" class="relative">
          <input name="q" type="text" placeholder="Search your courses..." class="w-full sm:w-72 rounded-full bg-white/70 border border-gray-200/70 px-4 py-2.5 pl-10 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none text-sm">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">🔎</span>
        </form>
      </div>

      <?php if ($courses && $courses->num_rows > 0): ?>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <?php while ($course = $courses->fetch_assoc()): ?>
            <a href="course.php?course_id=<?= (int)$course['course_id'] ?>"
               class="group block rounded-2xl p-6 bg-white/70 border border-indigo-100/60 shadow hover:shadow-2xl hover:-translate-y-0.5 transition relative overflow-hidden">
              <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition bg-gradient-to-br from-indigo-500/5 via-transparent to-cyan-500/10"></div>
              <div class="flex items-center gap-3 mb-3 relative z-10">
                <span class="text-2xl">📖</span>
                <span class="text-lg font-bold text-indigo-700 group-hover:underline"><?= htmlspecialchars($course['name']) ?></span>
              </div>
              <p class="text-gray-600 text-sm line-clamp-3 relative z-10">
                <?= htmlspecialchars($course['description'] ?? 'No description available.') ?>
              </p>

              <div class="mt-5 flex items-center justify-between relative z-10">
                <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-700 bg-indigo-50 border border-indigo-200/50 px-2.5 py-1 rounded-full">
                  • Active
                </span>
                <span class="text-indigo-600 text-sm group-hover:translate-x-0.5 transition">
                  View course →
                </span>
              </div>
            </a>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="text-gray-600 text-base sm:text-lg">
          No courses enrolled yet.
          <a href="enroll_course.php" class="text-indigo-600 font-semibold hover:underline">Enroll now</a>
        </div>
      <?php endif; ?>
    </section>

    <!-- Activity Timeline -->
    <section class="bg-white/80 backdrop-blur-sm p-6 sm:p-8 rounded-2xl shadow-xl border border-gray-100/80">
      <h3 class="text-xl sm:text-2xl font-semibold mb-6 text-gray-700">📝 Your Recent Activity</h3>

      <?php if ($logs && $logs->num_rows > 0): ?>
        <ul class="space-y-4">
          <?php while ($log = $logs->fetch_assoc()): ?>
            <li class="relative pl-6">
              <span class="absolute left-0 top-2 h-3 w-3 rounded-full bg-indigo-500 ring-4 ring-indigo-200/50"></span>
              <div class="border-l-2 border-indigo-200/70 pl-4">
                <div class="text-sm">
                  <strong class="text-indigo-700"><?= htmlspecialchars($log['action']) ?></strong>
                  on <span class="font-medium text-gray-800"><?= htmlspecialchars($log['title']) ?></span>
                </div>
                <div class="text-xs text-gray-500 italic">
                  <?= date('F j, Y, g:i A', strtotime($log['timestamp'])) ?>
                </div>
              </div>
            </li>
          <?php endwhile; ?>
        </ul>
      <?php else: ?>
        <div class="text-gray-600 text-base sm:text-lg">
          No activity logged yet. Start exploring your courses!
        </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<!-- Scripts -->
<script>
  // Live Date & Time
  function updateDateTime() {
    const now = new Date();
    const options = {
      weekday: 'long', year: 'numeric', month: 'long',
      day: 'numeric', hour: '2-digit', minute: '2-digit',
      second: '2-digit', hour12: true
    };
    const el = document.getElementById('datetime');
    if (el) el.textContent = `📅 ${now.toLocaleString('en-US', options)}`;
  }
  setInterval(updateDateTime, 1000);
  updateDateTime();
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>