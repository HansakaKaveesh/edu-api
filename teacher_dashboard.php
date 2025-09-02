<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$result = $conn->query("SELECT teacher_id FROM teachers WHERE user_id = $user_id LIMIT 1");
$teacher_row = $result ? $result->fetch_assoc() : null;
$teacher_id = $teacher_row ? intval($teacher_row['teacher_id']) : 0;

// Handle delete course action
if (isset($_GET['action'], $_GET['course_id']) && $_GET['action'] === 'delete') {
    $course_id = intval($_GET['course_id']);
    // Remove the course from this teacher's list
    $conn->query("DELETE FROM teacher_courses WHERE teacher_id = $teacher_id AND course_id = $course_id");
    // Optionally, delete the course entirely if no other teachers are assigned
    $check = $conn->query("SELECT 1 FROM teacher_courses WHERE course_id = $course_id LIMIT 1");
    if ($check && $check->num_rows == 0) {
        $conn->query("DELETE FROM courses WHERE course_id = $course_id");
        // Optionally, delete related contents, assignments, etc.
    }
    header("Location: teacher_dashboard.php");
    exit;
}

$courses = $conn->query("
    SELECT c.course_id, c.name 
    FROM teacher_courses tc 
    JOIN courses c ON tc.course_id = c.course_id 
    WHERE tc.teacher_id = $teacher_id
");

// Quick stats
$course_count = $courses ? $courses->num_rows : 0;
$ann_count_row = $conn->query("SELECT COUNT(*) AS cnt FROM announcements WHERE audience IN ('teachers','all')");
$ann_count = $ann_count_row ? intval($ann_count_row->fetch_assoc()['cnt']) : 0;

$contents_count_row = $conn->query("
  SELECT COUNT(*) AS cnt FROM contents 
  WHERE course_id IN (SELECT course_id FROM teacher_courses WHERE teacher_id = $teacher_id)
");
$contents_count = $contents_count_row ? intval($contents_count_row->fetch_assoc()['cnt']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Teacher Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Tailwind tweak: smooth transitions -->
  <style>
    .glass {
      background: rgba(255,255,255,0.7);
      backdrop-filter: blur(10px);
    }
    .card {
      background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(255,255,255,0.8));
    }
    .bg-hero {
      background-image:
        radial-gradient(1200px 400px at 10% -20%, rgba(37,99,235,0.18), transparent 60%),
        radial-gradient(800px 300px at 90% 10%, rgba(6,182,212,0.18), transparent 60%),
        linear-gradient(135deg, #0f172a, #0b1d3a);
    }
    .fade-collapse {
      transition: max-height .35s ease, opacity .25s ease;
    }
  </style>

  <script>
    // Animated collapse
    function toggleContent(id) {
      const el = document.getElementById(id);
      if (!el) return;
      if (el.dataset.open === "1") {
        el.style.maxHeight = el.scrollHeight + "px";
        requestAnimationFrame(() => {
          el.style.maxHeight = "0px";
          el.classList.add('opacity-0');
        });
        el.dataset.open = "0";
        setTimeout(() => el.classList.add('hidden'), 350);
      } else {
        el.classList.remove('hidden');
        el.classList.remove('opacity-0');
        el.style.maxHeight = "0px";
        requestAnimationFrame(() => {
          el.style.maxHeight = el.scrollHeight + "px";
        });
        el.dataset.open = "1";
      }
    }

    // Simple course filter
    function filterCourses(inputId) {
      const q = document.getElementById(inputId).value.toLowerCase();
      const cards = document.querySelectorAll('[data-course-name]');
      let visible = 0;
      cards.forEach(card => {
        const name = card.dataset.courseName;
        const show = name.includes(q);
        card.classList.toggle('hidden', !show);
        if (show) visible++;
      });
      const emptyMsg = document.getElementById('no-filter-results');
      if (emptyMsg) emptyMsg.classList.toggle('hidden', visible !== 0);
    }
  </script>

  <!-- Icons -->
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">

  <?php include 'components/navbar.php'; ?>

<!-- Hero -->
<section class="relative overflow-hidden">
  <div class="bg-hero bg-cover bg-center bg-no-repeat" style="background-image: url('https://images.unsplash.com/photo-1529070538774-1843cb3265df?q=80&w=1600&auto=format&fit=crop');">
    <div class="max-w-7xl mx-auto px-6 md:px-10 py-14 md:py-20 text-white relative bg-black/50">
      <div class="max-w-3xl">
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 ring-1 ring-white/20 text-sm mt-12">
          <i class="ph ph-chalkboard-teacher"></i>
          Teacher Dashboard
        </span>
        <h1 class="mt-4 text-4xl md:text-5xl font-extrabold leading-tight">
          Welcome back, Teacher 👩‍🏫
        </h1>
        <p class="mt-3 text-white/90 text-lg md:text-xl">
          Empower students with engaging content, quizzes, and assignments. Shape the future today.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
          <a href="#courses" class="inline-flex items-center gap-2 bg-white text-blue-700 px-5 py-2.5 rounded-lg font-semibold shadow hover:shadow-md transition">
            <i class="ph ph-arrow-down"></i> Go to Courses
          </a>
          <a href="create_course.php" class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg font-semibold shadow hover:bg-blue-700 transition">
            <i class="ph ph-plus-circle"></i> Create Course
          </a>
        </div>
      </div>
    </div>
  </div>
</section>


  <!-- Stats -->
  <section class="max-w-7xl mx-auto px-6 md:px-10 -mt-8 md:-mt-10 relative z-10">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="card rounded-2xl shadow border border-white/60 p-5">
        <div class="flex items-center gap-4">
          <div class="h-12 w-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl">
            <i class="ph ph-books"></i>
          </div>
          <div>
            <p class="text-sm text-gray-500">Courses</p>
            <p class="text-2xl font-bold"><?= $course_count ?></p>
          </div>
        </div>
      </div>
      <div class="card rounded-2xl shadow border border-white/60 p-5">
        <div class="flex items-center gap-4">
          <div class="h-12 w-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-2xl">
            <i class="ph ph-folders"></i>
          </div>
          <div>
            <p class="text-sm text-gray-500">Total Contents</p>
            <p class="text-2xl font-bold"><?= $contents_count ?></p>
          </div>
        </div>
      </div>
      <div class="card rounded-2xl shadow border border-white/60 p-5">
        <div class="flex items-center gap-4">
          <div class="h-12 w-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-2xl">
            <i class="ph ph-megaphone"></i>
          </div>
          <div>
            <p class="text-sm text-gray-500">Announcements</p>
            <p class="text-2xl font-bold"><?= $ann_count ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Announcements -->
  <section class="max-w-7xl mx-auto px-6 md:px-10 mt-10">
    <div class="card rounded-2xl shadow border border-white/60 p-6">
      <h3 class="text-xl font-bold text-blue-700 mb-4 flex items-center gap-2">
        <i class="ph ph-megaphone-simple"></i> Announcements
      </h3>
      <?php
      $announcements = $conn->query("
          SELECT title, message, created_at
          FROM announcements
          WHERE audience = 'teachers' OR audience = 'all'
          ORDER BY created_at DESC
          LIMIT 5
      ");
      if ($announcements && $announcements->num_rows > 0): ?>
        <ol class="relative border-l border-gray-200 pl-6 space-y-6">
          <?php while ($a = $announcements->fetch_assoc()): ?>
            <li class="relative">
              <span class="absolute -left-[9px] top-1 h-4 w-4 bg-blue-600 rounded-full ring-4 ring-white"></span>
              <div class="bg-blue-50/60 border border-blue-100 rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                  <span class="font-semibold text-blue-800"><?= htmlspecialchars($a['title']) ?></span>
                  <span class="text-xs text-gray-500"><?= date('M d, Y', strtotime($a['created_at'])) ?></span>
                </div>
                <p class="mt-1 text-gray-700 text-sm"><?= nl2br(htmlspecialchars($a['message'])) ?></p>
              </div>
            </li>
          <?php endwhile; ?>
        </ol>
      <?php else: ?>
        <div class="text-gray-600 text-base">No announcements at this time.</div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Main Content -->
  <section class="max-w-7xl mx-auto px-6 md:px-10 mt-10 mb-16" id="courses">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
      <h2 class="text-3xl font-extrabold flex items-center gap-2">
        <i class="ph ph-bookmarks-simple text-blue-600"></i> Your Courses
      </h2>
      <div class="flex items-center gap-3">
        <div class="relative">
          <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
          <input
            id="course-search"
            oninput="filterCourses('course-search')"
            type="text"
            placeholder="Search courses..."
            class="pl-10 pr-3 py-2 rounded-lg border border-gray-200 bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>
        <a href="create_course.php"
           class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow transition">
          <i class="ph ph-plus-circle"></i> Create New
        </a>
        <a href="logout.php" class="inline-flex items-center gap-2 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg shadow">
          <i class="ph ph-lock"></i> Logout
        </a>
      </div>
    </div>

    <?php if ($courses && $courses->num_rows > 0): ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php while ($course = $courses->fetch_assoc()): ?>
          <?php
            $course_id = intval($course['course_id']);
            $course_name = htmlspecialchars($course['name']);
            $contents_query = $conn->query("
                SELECT content_id, title, type, position 
                FROM contents 
                WHERE course_id = $course_id 
                ORDER BY type, position
            ");
            $content_map = [];
            if ($contents_query) {
              while ($row = $contents_query->fetch_assoc()) {
                $content_map[$row['type']][] = $row;
              }
            }

            $type_icons = [
              'lesson' => 'ph-book-open',
              'video'  => 'ph-video',
              'pdf'    => 'ph-file-text',
              'quiz'   => 'ph-brain',
              'forum'  => 'ph-chats-teardrop'
            ];
            // counts
            $lessons = isset($content_map['lesson']) ? count($content_map['lesson']) : 0;
            $videos  = isset($content_map['video'])  ? count($content_map['video'])  : 0;
            $pdfs    = isset($content_map['pdf'])    ? count($content_map['pdf'])    : 0;
            $quizzes = isset($content_map['quiz'])   ? count($content_map['quiz'])   : 0;
            $forums  = isset($content_map['forum'])  ? count($content_map['forum'])  : 0;
          ?>

          <div class="rounded-2xl p-[1px] bg-gradient-to-tr from-blue-200 via-cyan-200 to-indigo-200 shadow hover:shadow-lg transition">
            <div class="bg-white rounded-2xl p-5 h-full" data-course-name="<?= strtolower($course_name) ?>">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <h4 class="text-xl font-bold text-gray-900"><?= $course_name ?></h4>
                  <p class="text-sm text-gray-500 mt-0.5">Course ID:
                    <span class="font-medium text-gray-700"><?= $course_id ?></span>
                  </p>
                </div>
                <div class="flex items-center gap-2">
                  <button onclick="toggleContent('content-<?= $course_id ?>')"
                          class="inline-flex items-center gap-1.5 text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded-lg transition text-sm">
                    <i class="ph ph-folder-open"></i> Toggle
                  </button>
                  <a href="?action=delete&course_id=<?= $course_id ?>"
                     onclick="return confirm('Are you sure you want to delete this course? This cannot be undone.');"
                     class="inline-flex items-center gap-1.5 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded-lg transition text-sm">
                    <i class="ph ph-trash"></i> Delete
                  </a>
                </div>
              </div>

              <!-- Chips -->
              <div class="mt-4 flex flex-wrap gap-2 text-xs">
                <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 px-2.5 py-1 rounded-full">
                  <i class="ph ph-book-open"></i> <?= $lessons ?> Lessons
                </span>
                <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-full">
                  <i class="ph ph-video"></i> <?= $videos ?> Videos
                </span>
                <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 px-2.5 py-1 rounded-full">
                  <i class="ph ph-file-text"></i> <?= $pdfs ?> PDFs
                </span>
                <span class="inline-flex items-center gap-1 bg-purple-50 text-purple-700 px-2.5 py-1 rounded-full">
                  <i class="ph ph-brain"></i> <?= $quizzes ?> Quizzes
                </span>
                <span class="inline-flex items-center gap-1 bg-pink-50 text-pink-700 px-2.5 py-1 rounded-full">
                  <i class="ph ph-chats-teardrop"></i> <?= $forums ?> Forums
                </span>
              </div>

              <!-- Actions -->
              <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-2 text-sm">
                <a href="add_content.php?course_id=<?= $course_id ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-700">
                  <i class="ph ph-plus-circle"></i> Add Content
                </a>
                <a href="upload_assignment.php?course_id=<?= $course_id ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-700">
                  <i class="ph ph-pencil-circle"></i> Add Assignment
                </a>
                <a href="add_quiz.php?course_id=<?= $course_id ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-700">
                  <i class="ph ph-brain"></i> Add Quiz
                </a>
                <a href="add_forum.php?course_id=<?= $course_id ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-700">
                  <i class="ph ph-chats-teardrop"></i> Add Forum
                </a>
                <a href="teacher_view_content.php?course_id=<?= $course_id ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                  <i class="ph ph-eye"></i> View Content
                </a>
              </div>

              <!-- Content List (Animated Collapsible) -->
              <div id="content-<?= $course_id ?>"
                   class="hidden opacity-0 fade-collapse overflow-hidden max-h-0 mt-4">
                <?php if (!empty($content_map)): ?>
                  <?php foreach ($content_map as $type => $items): ?>
                    <div class="mt-4">
                      <h5 class="font-semibold text-gray-700 mb-2 flex items-center gap-2">
                        <i class="ph <?= $type_icons[$type] ?? 'ph-folder' ?>"></i>
                        <?= ucfirst(htmlspecialchars($type)) ?>s
                        <span class="text-xs text-gray-500">(<?= count($items) ?>)</span>
                      </h5>
                      <ul class="space-y-1">
                        <?php foreach ($items as $item): ?>
                          <li class="flex justify-between items-center bg-gray-50 px-3 py-2 rounded-lg border border-gray-100">
                            <span class="text-sm text-gray-800">
                              <?= htmlspecialchars($item['title']) ?>
                              <span class="text-xs text-gray-400">(Pos: <?= intval($item['position']) ?>)</span>
                            </span>
                            <span class="flex items-center gap-3 text-sm">
                              <a href="edit_content.php?content_id=<?= intval($item['content_id']) ?>" class="inline-flex items-center gap-1 text-emerald-700 hover:underline">
                                <i class="ph ph-pencil-simple"></i> Edit
                              </a>
                              <a href="delete_content.php?content_id=<?= intval($item['content_id']) ?>"
                                 onclick="return confirm('Delete this content?')"
                                 class="inline-flex items-center gap-1 text-red-600 hover:underline">
                                <i class="ph ph-trash-simple"></i> Delete
                              </a>
                            </span>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="bg-white border border-gray-200 rounded-xl p-4 text-gray-600 text-sm">
                    No content yet. Start by adding lessons, videos, PDFs, or a quiz.
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        <?php endwhile; ?>
      </div>

      <!-- No results after filtering -->
      <div id="no-filter-results" class="hidden mt-8 text-center text-gray-600">
        No courses match your search.
      </div>
    <?php else: ?>
      <div class="card rounded-2xl shadow border border-white/60 p-8 text-center">
        <div class="text-5xl mb-3">📦</div>
        <h3 class="text-xl font-semibold">No courses yet</h3>
        <p class="text-gray-600 mt-1">Create your first course to get started.</p>
        <a href="create_course.php" class="inline-flex items-center gap-2 mt-5 bg-blue-600 text-white px-5 py-2.5 rounded-lg shadow hover:bg-blue-700 transition">
          <i class="ph ph-plus-circle"></i> Create Course
        </a>
      </div>
    <?php endif; ?>
  </section>

</body>
</html>