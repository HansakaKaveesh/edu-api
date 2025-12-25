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
$stmt->close();

$student_id = $student['student_id'] ?? null;
$full_name  = $student ? ($student['first_name'] . ' ' . $student['last_name']) : 'Student';
$role       = ucfirst($_SESSION['role']);

// Announcements
$announcements = [];
$annStmt = $conn->prepare("
  SELECT title, message, created_at
  FROM announcements
  WHERE audience IN ('students','all')
  ORDER BY created_at DESC
  LIMIT 5
");
$annStmt->execute();
$announcements = $annStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$annStmt->close();

// Enrolled courses (+ cover image from courses.cover_image)
$coursesArr = [];
$coursesStmt = $conn->prepare("
  SELECT c.course_id, c.name, c.description, c.cover_image
  FROM enrollments e
  JOIN courses c ON e.course_id = c.course_id
  WHERE e.user_id = ? AND e.status = 'active'
");
$coursesStmt->bind_param("i", $user_id);
$coursesStmt->execute();
$coursesArr = $coursesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$coursesStmt->close();

// Activity logs
$logsArr = [];
$logsStmt = $conn->prepare("
  SELECT a.action, a.timestamp, c.title 
  FROM activity_logs a
  JOIN contents c ON a.content_id = c.content_id
  WHERE a.user_id = ?
  ORDER BY a.timestamp DESC
  LIMIT 10
");
$logsStmt->bind_param("i", $user_id);
$logsStmt->execute();
$logsArr = $logsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$logsStmt->close();

// Quick metrics
$coursesCount  = count($coursesArr);
$annCount      = count($announcements);
$activityCount = count($logsArr);
$lastActivity  = $activityCount ? date('M j, Y g:i A', strtotime($logsArr[0]['timestamp'])) : null;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Student Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
    }
    html, body {
      font-family: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
    }

    /* Background blobs */
    .bg-bubbles::before,
    .bg-bubbles::after {
      content: "";
      position: absolute;
      border-radius: 9999px;
      filter: blur(48px);
      opacity: .45;
      z-index: 0;
      pointer-events: none;
      transform: translate3d(0,0,0);
    }
    .bg-bubbles::before {
      width: 460px;
      height: 460px;
      background: radial-gradient(circle at 30% 20%, rgba(56,189,248,0.9), transparent 65%);
      top: -80px;
      left: -80px;
    }
    .bg-bubbles::after {
      width: 520px;
      height: 520px;
      background: radial-gradient(circle at 70% 80%, rgba(129,140,248,0.95), transparent 65%);
      bottom: -140px;
      right: -120px;
    }

    /* Wave emoji */
    @keyframes wave { 0%, 60%, 100% { transform: rotate(0deg); } 30% { transform: rotate(15deg); } 50% { transform: rotate(-10deg); } }
    .animate-wave { display: inline-block; animation: wave 2s infinite; transform-origin: 70% 70%; }

    /* Fade up */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fadeUp { animation: fadeUp .6s ease-out both; }

    /* Cards & glass effect */
    .glass-card {
      background: linear-gradient(to bottom right, rgba(255,255,255,0.92), rgba(249,250,251,0.9));
      border: 1px solid rgba(226,232,240,0.8);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow: 0 18px 40px rgba(15,23,42,0.08);
    }

    .soft-card {
      background: linear-gradient(to bottom right, rgba(248,250,252,0.9), rgba(239,246,255,0.9));
      border: 1px solid rgba(222,231,255,0.9);
      box-shadow: 0 14px 30px rgba(15,23,42,0.07);
    }

    .hover-raise {
      transition: transform .2s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease;
    }
    .hover-raise:hover {
      transform: translateY(-3px);
      box-shadow: 0 20px 40px rgba(15,23,42,0.16);
    }

    .line-clamp-3 {
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .text-gradient {
      background: linear-gradient(120deg,#4f46e5,#2563eb,#06b6d4);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    /* Timeline */
    .timeline::before {
      content: "";
      position: absolute;
      inset-y: 0.5rem;
      left: 0.55rem;
      width: 2px;
      background: linear-gradient(to bottom, rgba(129,140,248,.5), rgba(59,130,246,.1));
    }

    /* Scrollbar subtle */
    ::-webkit-scrollbar {
      width: 8px;
    }
    ::-webkit-scrollbar-track {
      background: transparent;
    }
    ::-webkit-scrollbar-thumb {
      background: rgba(148,163,184,0.45);
      border-radius: 9999px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: rgba(107,114,128,0.7);
    }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<!-- Decorative background -->
<div class="fixed inset-0 bg-bubbles -z-10"></div>

<div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 flex flex-col lg:flex-row gap-8">
  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full space-y-10 animate-fadeUp">

    <!-- HERO SECTION -->
    <section class="relative overflow-hidden rounded-[2rem] glass-card">
      <!-- Background image & overlays -->
      <div class="absolute inset-0">
        <img src="https://www.vedamo.com/wp-content/uploads/cache/2017/06/what-is-virtual-learning-1/4148946552.png"
             alt="Campus" class="w-full h-full object-cover object-center scale-[1.02]" loading="eager" decoding="async">
        <div class="absolute inset-0 bg-gradient-to-br from-slate-900/80 via-blue-500/90 to-sky-800/60 mix-blend-multiply"></div>
        <div class="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-slate-900/75 to-transparent"></div>
      </div>

      <!-- Content -->
      <div class="relative z-10 px-4 sm:px-6 lg:px-10 py-6 sm:py-7 lg:py-8 text-white space-y-5">
        <!-- Top row -->
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div id="datetime" class="text-xs sm:text-sm italic opacity-90 drop-shadow-sm" aria-live="polite"></div>

          <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/10 ring-1 ring-white/25 px-3 py-1.5 text-xs uppercase tracking-wide">
              <i data-lucide="graduation-cap" class="w-4 h-4"></i>
              <span>Student Dashboard</span>
            </span>
          </div>
        </div>

        <!-- Main greeting -->
        <div class="text-center space-y-3 sm:space-y-4 mt-1">
          <h1 class="text-xl sm:text-2xl md:text-3xl lg:text-4xl font-extrabold leading-tight drop-shadow-xl">
            <span class="block ">Welcome back,</span>
            <span class="inline-flex flex-wrap items-center gap-2 justify-center">
              <span class="underline decoration-white/30 underline-offset-4">
                <?php echo htmlspecialchars($full_name); ?>
              </span>
              <span class="text-xs sm:text-sm md:text-base font-light italic text-white/90">
                (<?php echo htmlspecialchars($role); ?>)
              </span>
              <span class="inline-block animate-wave" aria-hidden="true">👋</span>
            </span>
          </h1>

          <p class="text-sm sm:text-base md:text-lg font-light text-white/95 max-w-3xl mx-auto">
            Continue your learning journey, track your progress, and stay on top of what matters most—
            from courses and quizzes to announcements and activity.
          </p>
        </div>

        <!-- Actions & metrics -->
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-5 mt-2">
          <!-- Actions -->
          <div class="flex flex-wrap items-center justify-center gap-3">
            <a href="enroll_course.php"
               class="inline-flex items-center gap-2 rounded-full bg-white text-indigo-700 font-semibold px-4 sm:px-5 py-2.5 shadow-lg shadow-slate-900/25 hover-raise">
              <i data-lucide="plus-circle" class="w-5 h-5" aria-hidden="true"></i>
              <span>Enroll in a course</span>
            </a>
            <a href="#courses"
               class="inline-flex items-center gap-2 rounded-full bg-indigo-900/40 text-white font-medium px-4 sm:px-5 py-2.5 ring-1 ring-white/30 hover:bg-indigo-900/55 hover-raise">
              <i data-lucide="compass" class="w-5 h-5" aria-hidden="true"></i>
              <span>Browse your courses</span>
            </a>
          </div>

          <!-- Quick metrics -->
          <div class="grid grid-cols-3 gap-2 sm:gap-3 max-w-md mx-auto lg:mx-0">
            <div class="rounded-2xl bg-white/10 ring-1 ring-white/20 p-3 sm:p-4 backdrop-blur">
              <div class="text-xs text-white/85 inline-flex items-center gap-1">
                <i data-lucide="book-open" class="w-4 h-4"></i>
                <span>Courses</span>
              </div>
              <div class="mt-1 text-2xl font-semibold tracking-tight">
                <?php echo (int)$coursesCount; ?>
              </div>
              <p class="mt-0.5 text-[11px] text-white/80">Actively enrolled</p>
            </div>
            <div class="rounded-2xl bg-white/10 ring-1 ring-white/20 p-3 sm:p-4 backdrop-blur">
              <div class="text-xs text-white/85 inline-flex items-center gap-1">
                <i data-lucide="megaphone" class="w-4 h-4"></i>
                <span>Announcements</span>
              </div>
              <div class="mt-1 text-2xl font-semibold tracking-tight">
                <?php echo (int)$annCount; ?>
              </div>
              <p class="mt-0.5 text-[11px] text-white/80">Latest updates</p>
            </div>
            <div class="rounded-2xl bg-white/10 ring-1 ring-white/20 p-3 sm:p-4 backdrop-blur">
              <div class="text-xs text-white/85 inline-flex items-center gap-1">
                <i data-lucide="activity" class="w-4 h-4"></i>
                <span>Recent</span>
              </div>
              <div class="mt-1 text-[13px] font-medium leading-tight">
                <?php echo $lastActivity ? htmlspecialchars($lastActivity) : 'No activity yet'; ?>
              </div>
              <p class="mt-0.5 text-[11px] text-white/80">Last interaction</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ANNOUNCEMENTS -->
    <section class="soft-card rounded-2xl p-6 sm:p-8">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div class="flex items-center gap-2">
          <span class="inline-flex items-center justify-center rounded-2xl bg-indigo-100 text-indigo-700 p-2">
            <i data-lucide="megaphone" class="w-5 h-5"></i>
          </span>
          <div>
            <h3 class="text-xl sm:text-2xl font-semibold text-slate-800">
              Announcements
            </h3>
            <p class="text-xs sm:text-sm text-slate-500">
              Stay in sync with important updates and deadlines.
            </p>
          </div>
        </div>
      </div>

      <?php if (!empty($announcements)): ?>
        <ul class="space-y-4">
          <?php foreach ($announcements as $index => $a): ?>
            <li class="rounded-2xl bg-white/90 border border-indigo-100/80 px-4 py-4 sm:px-5 sm:py-4 shadow-sm hover-raise relative overflow-hidden">
              <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-indigo-500 to-sky-400"></div>
              <div class="pl-4 sm:pl-5 flex flex-col gap-1.5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="flex items-center gap-2">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-blue-500 ring-4 ring-blue-200/50"></span>
                    <span class="font-semibold text-slate-800 flex items-center gap-2">
                      <i data-lucide="speaker" class="w-4 h-4 text-indigo-600"></i>
                      <?= htmlspecialchars($a['title']) ?>
                    </span>
                  </div>
                  <span class="shrink-0 text-[11px] sm:text-xs text-slate-500 inline-flex items-center gap-1 bg-slate-50 border border-slate-200/80 px-2 py-1 rounded-full">
                    <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
                    <?= date('M d, Y', strtotime($a['created_at'])) ?>
                  </span>
                </div>
                <div class="text-sm text-slate-700 leading-relaxed">
                  <?= nl2br(htmlspecialchars($a['message'])) ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="flex items-center gap-3 text-slate-600 text-sm sm:text-base">
          <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
            <i data-lucide="info" class="w-5 h-5"></i>
          </span>
          <div>
            <p class="font-medium">No announcements right now.</p>
            <p class="text-xs text-slate-500">You’ll see important updates from your instructors and school here.</p>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <!-- COURSES -->
    <section id="courses" class="soft-card rounded-2xl p-6 sm:p-8">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-2">
          <span class="inline-flex items-center justify-center rounded-2xl bg-sky-100 text-sky-700 p-2">
            <i data-lucide="book-open" class="w-5 h-5"></i>
          </span>
          <div>
            <h3 class="text-xl sm:text-2xl font-semibold text-slate-800">
              Your Enrolled Courses
            </h3>
            <p class="text-xs sm:text-sm text-slate-500">
              Access all the courses you’re currently taking.
            </p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <form action="courses.php" method="get" class="relative w-full sm:w-auto">
            <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
            <input
              name="q"
              type="text"
              placeholder="Search your courses..."
              class="w-full sm:w-72 rounded-full bg-white border border-gray-200/80 px-4 py-2.5 pl-10 text-sm shadow-sm focus:ring-2 focus:ring-indigo-500/40 focus:outline-none">
          </form>
          <div class="hidden sm:flex items-center gap-1 rounded-full border border-gray-200/80 bg-white/70 px-1 shadow-sm">
            <button id="gridView" type="button"
                    class="px-2.5 py-1 text-xs rounded-full bg-indigo-600 text-white">
              Grid
            </button>
            <button id="listView" type="button"
                    class="px-2.5 py-1 text-xs rounded-full text-gray-700 hover:bg-gray-100">
              List
            </button>
          </div>
        </div>
      </div>

      <?php if (!empty($coursesArr)): ?>
        <div id="courseContainer" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($coursesArr as $course): ?>
            <?php
              $courseId = (int)$course['course_id'];
              $cover    = $course['cover_image'] ?? '';
            ?>
            <a href="course.php?course_id=<?= $courseId ?>"
               class="group relative block rounded-2xl bg-white/95 border border-indigo-100/70 p-5 shadow-sm hover-raise overflow-hidden">
              <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition bg-gradient-to-br from-indigo-500/[0.05] via-sky-500/[0.04] to-cyan-500/[0.06]"></div>
              <div class="relative z-10 flex flex-col gap-3">

                <!-- Cover image / fallback banner -->
                <?php if (!empty($cover)): ?>
                  <div class="relative mb-3 -mx-3 -mt-3 rounded-xl overflow-hidden">
                    <img
                      src="<?= htmlspecialchars($cover, ENT_QUOTES) ?>"
                      alt="Cover image for <?= htmlspecialchars($course['name'], ENT_QUOTES) ?>"
                      class="w-full h-32 sm:h-36 object-cover"
                    >
                    <div class="absolute inset-0 bg-gradient-to-t from-black/30 via-black/5 to-transparent"></div>
                  </div>
                <?php else: ?>
                  <div class="relative mb-3 -mx-3 -mt-3 h-16 rounded-xl bg-gradient-to-r from-indigo-500/15 via-sky-400/15 to-emerald-400/15"></div>
                <?php endif; ?>

                <div class="flex items-start justify-between gap-3">
                  <div class="flex items-center gap-3">
                    <span class="inline-flex items-center justify-center h-11 w-11 rounded-2xl bg-indigo-50 ring-1 ring-indigo-100">
                      <i data-lucide="bookmark" class="w-5 h-5 text-indigo-700"></i>
                    </span>
                    <div class="space-y-0.5">
                      <h4 class="text-base sm:text-lg font-semibold text-indigo-800 group-hover:text-indigo-900 group-hover:underline">
                        <?= htmlspecialchars($course['name']) ?>
                      </h4>
                      <p class="text-[11px] uppercase tracking-wide text-indigo-500 font-semibold">
                        Active course
                      </p>
                    </div>
                  </div>
                  <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 bg-emerald-50 border border-emerald-100 px-2 py-1 rounded-full">
                    <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                    <span>Enrolled</span>
                  </span>
                </div>

                <p class="text-sm text-slate-600 line-clamp-3">
                  <?= htmlspecialchars($course['description'] ?? 'No description available.') ?>
                </p>

                <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                  <span class="inline-flex items-center gap-1.5">
                    <i data-lucide="layers" class="w-3.5 h-3.5 text-indigo-500"></i>
                    <span>View lessons & resources</span>
                  </span>
                  <span class="text-indigo-600 text-sm inline-flex items-center gap-1 group-hover:translate-x-0.5 transition-transform">
                    View course
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                  </span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="flex items-center gap-3 text-slate-600 text-sm sm:text-base">
          <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
            <i data-lucide="info" class="w-5 h-5"></i>
          </span>
          <div>
            <p class="font-medium">You’re not enrolled in any courses yet.</p>
            <p class="text-xs text-slate-500 mt-0.5">
              Start learning by enrolling in your first course.
              <a href="enroll_course.php" class="text-indigo-600 font-semibold hover:underline inline-flex items-center gap-1">
                Enroll now
                <i data-lucide="arrow-right" class="w-4 h-4"></i>
              </a>
            </p>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <!-- ACTIVITY TIMELINE -->
    <section class="soft-card rounded-2xl p-6 sm:p-8">
      <div class="flex items-center gap-2 mb-6">
        <span class="inline-flex items-center justify-center rounded-2xl bg-violet-100 text-violet-700 p-2">
          <i data-lucide="activity" class="w-5 h-5"></i>
        </span>
        <div>
          <h3 class="text-xl sm:text-2xl font-semibold text-slate-800">
            Your Recent Activity
          </h3>
          <p class="text-xs sm:text-sm text-slate-500">
            A quick timeline of what you’ve been working on.
          </p>
        </div>
      </div>

      <?php if (!empty($logsArr)): ?>
        <div class="relative pl-4 sm:pl-5 timeline">
          <ul class="space-y-4">
            <?php foreach ($logsArr as $log): ?>
              <?php $when = date('F j, Y, g:i A', strtotime($log['timestamp'])); ?>
              <li class="relative flex gap-3">
                <!-- Dot -->
                <span class="absolute left-[-2px] top-2 h-3 w-3 rounded-full bg-indigo-500 ring-4 ring-indigo-200/60 shadow-sm"></span>

                <div class="ml-3 sm:ml-4 rounded-xl bg-white/95 border border-slate-100 px-3.5 py-2.5 shadow-sm w-full">
                  <div class="flex flex-wrap items-center gap-1.5 text-sm text-slate-800">
                    <span class="inline-flex items-center gap-1.5 font-semibold text-indigo-700">
                      <i data-lucide="dot" class="w-4 h-4 text-indigo-600"></i>
                      <?= htmlspecialchars($log['action']) ?>
                    </span>
                    <span class="text-slate-400 text-xs sm:text-[13px] mx-1">on</span>
                    <span class="font-medium text-slate-800">
                      <?= htmlspecialchars($log['title']) ?>
                    </span>
                  </div>
                  <div class="mt-1 text-[11px] sm:text-xs text-slate-500 italic flex items-center gap-1.5">
                    <i data-lucide="clock" class="w-3.5 h-3.5"></i>
                    <span><?= htmlspecialchars($when) ?></span>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php else: ?>
        <div class="flex items-center gap-3 text-slate-600 text-sm sm:text-base">
          <span class="inline-flex items-center justify-center h-9 w-9 rounded-2xl bg-indigo-50 text-indigo-600">
            <i data-lucide="info" class="w-5 h-5"></i>
          </span>
          <div>
            <p class="font-medium">No activity yet.</p>
            <p class="text-xs text-slate-500">
              As you explore courses, take quizzes, and interact with content, your recent activity will appear here.
            </p>
          </div>
        </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<!-- Scripts -->
<script>
  // Lucide icons
  if (window.lucide) {
    window.lucide.createIcons();
  }

  // Live Date & Time
  function updateDateTime() {
    const now = new Date();
    const opt = {
      weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
    };
    const el = document.getElementById('datetime');
    if (el) el.textContent = `🗓️ ${now.toLocaleString('en-US', opt)}`;
  }
  setInterval(updateDateTime, 1000);
  updateDateTime();

  // Grid/List toggle for courses
  const gridBtn = document.getElementById('gridView');
  const listBtn = document.getElementById('listView');
  const container = document.getElementById('courseContainer');

  gridBtn?.addEventListener('click', () => {
    if (!container) return;
    container.className = 'grid gap-6 sm:grid-cols-2 lg:grid-cols-3';
    gridBtn.classList.add('bg-indigo-600', 'text-white');
    listBtn.classList.remove('bg-indigo-600', 'text-white');
  });

  listBtn?.addEventListener('click', () => {
    if (!container) return;
    container.className = 'grid gap-4'; // stacked cards
    listBtn.classList.add('bg-indigo-600', 'text-white');
    gridBtn.classList.remove('bg-indigo-600', 'text-white');
  });
</script>

<?php include 'components/footer.php'; ?>
</body>
</html>