<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

/* verify that this teacher owns this course */
$check = $conn->prepare("
  SELECT c.name, t.teacher_id 
  FROM teacher_courses tc
  JOIN courses c ON tc.course_id = c.course_id
  JOIN teachers t ON tc.teacher_id = t.teacher_id
  WHERE t.user_id = ? AND c.course_id = ? LIMIT 1
");
$check->bind_param("ii", $user_id, $course_id);
$check->execute();
$res = $check->get_result();
if ($res->num_rows === 0) { die("You don't have access to this course."); }

$course       = $res->fetch_assoc();
$course_name  = htmlspecialchars($course['name']);

/* grouped contents */
$contents_query = $conn->prepare("
  SELECT content_id, title, type, position
  FROM contents 
  WHERE course_id = ? 
  ORDER BY type, position
");
$contents_query->bind_param("i", $course_id);
$contents_query->execute();
$query_result = $contents_query->get_result();

$content_map = [];
while ($row = $query_result->fetch_assoc()) {
  $content_map[$row['type']][] = $row;
}

/* icons & styles per type */
$type_icons = [
  'lesson' => 'ph-book-open',
  'video'  => 'ph-video',
  'pdf'    => 'ph-file-text',
  'quiz'   => 'ph-brain',
  'forum'  => 'ph-chats-teardrop'
];

$type_styles = [
  'lesson' => 'border-l-4 border-l-sky-500',
  'video'  => 'border-l-4 border-l-rose-500',
  'pdf'    => 'border-l-4 border-l-amber-500',
  'quiz'   => 'border-l-4 border-l-emerald-500',
  'forum'  => 'border-l-4 border-l-purple-500'
];

/* simple stats */
$total_contents = 0;
$type_counts    = [];

foreach ($content_map as $t => $items) {
  $count = count($items);
  $type_counts[$t] = $count;
  $total_contents += $count;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Manage Course - <?= $course_name ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen antialiased">
<?php include 'components/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-6 py-10" x-data="{ show:false, iframeSrc:'' }">

  <!-- Top bar -->
  <div class="flex items-center justify-between gap-4 mb-6">
    <a href="teacher_dashboard.php"
       class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900 hover:underline">
      <i class="ph ph-arrow-left text-lg"></i>
      Back to Dashboard
    </a>
  </div>

  <!-- Hero / Course header -->
  <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-blue-600 via-indigo-600 to-sky-500 text-white shadow-lg mb-8">
    <div class="absolute inset-0 opacity-10 bg-[radial-gradient(circle_at_top,_#fff,_transparent_60%)]"></div>
    <div class="relative p-6 sm:p-8">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
        <div class="flex items-start gap-4">
          <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/30">
            <i class="ph ph-chalkboard text-2xl"></i>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-100">
              Course Management
            </p>
            <h1 class="mt-1 text-2xl sm:text-3xl font-extrabold tracking-tight">
              <?= $course_name ?>
            </h1>
            <p class="mt-1 text-sm text-blue-100/90 max-w-xl">
              Organize lessons, videos, PDFs, quizzes, and forums for your students in one place.
            </p>
          </div>
        </div>
        <div class="flex flex-col items-start sm:items-end gap-3">
          <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-1.5 text-xs font-medium uppercase tracking-[0.2em]">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
            TEACHER VIEW
          </span>
          <p class="text-sm text-blue-100/80">
            Total items: <span class="font-semibold"><?= $total_contents ?></span>
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick stats -->
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
    <div class="rounded-xl bg-white shadow-sm border border-slate-200 px-4 py-3">
      <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Total content</p>
      <p class="mt-1 text-2xl font-semibold text-slate-900"><?= $total_contents ?></p>
      <p class="mt-1 text-xs text-slate-500">All content types combined</p>
    </div>

    <div class="rounded-xl bg-white shadow-sm border border-sky-100 px-4 py-3">
      <p class="text-xs font-medium text-sky-600 uppercase tracking-wide">Lessons</p>
      <p class="mt-1 text-xl font-semibold text-slate-900"><?= $type_counts['lesson'] ?? 0 ?></p>
      <p class="mt-1 text-xs text-slate-500">Text lessons and modules</p>
    </div>

    <div class="rounded-xl bg-white shadow-sm border border-rose-100 px-4 py-3">
      <p class="text-xs font-medium text-rose-600 uppercase tracking-wide">Videos</p>
      <p class="mt-1 text-xl font-semibold text-slate-900"><?= $type_counts['video'] ?? 0 ?></p>
      <p class="mt-1 text-xs text-slate-500">Recorded & embedded videos</p>
    </div>

    <div class="rounded-xl bg-white shadow-sm border border-emerald-100 px-4 py-3">
      <p class="text-xs font-medium text-emerald-600 uppercase tracking-wide">Quizzes</p>
      <p class="mt-1 text-xl font-semibold text-slate-900"><?= $type_counts['quiz'] ?? 0 ?></p>
      <p class="mt-1 text-xs text-slate-500">Assessments & practice</p>
    </div>
  </div>

  <!-- Add content buttons -->
  <div class="mb-10 rounded-2xl bg-white shadow-sm border border-slate-200 p-4 sm:p-5">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
      <div>
        <h2 class="text-sm font-semibold text-slate-900 uppercase tracking-[0.2em]">
          Add New
        </h2>
        <p class="mt-1 text-xs text-slate-500">
          Quickly create new materials and activities for this course.
        </p>
      </div>
    </div>

    <div class="flex flex-wrap gap-3">
      <a href="add_content.php?course_id=<?= $course_id ?>"
         class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:border-slate-300 transition">
         <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-900/5">
           <i class="ph ph-plus-circle text-base"></i>
         </span>
         <span>Lesson / Video / PDF</span>
      </a>

      <a href="upload_assignment.php?course_id=<?= $course_id ?>"
         class="inline-flex items-center gap-2 rounded-xl border border-amber-100 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100 hover:border-amber-200 transition">
         <span class="flex h-7 w-7 items-center justify-center rounded-full bg-amber-100/70">
           <i class="ph ph-pencil-circle text-base"></i>
         </span>
         <span>Assignment</span>
      </a>

      <a href="add_quiz.php?course_id=<?= $course_id ?>"
         class="inline-flex items-center gap-2 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100 hover:border-emerald-200 transition">
         <span class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-100/70">
           <i class="ph ph-brain text-base"></i>
         </span>
         <span>Quiz</span>
      </a>

      <a href="add_forum.php?course_id=<?= $course_id ?>"
         class="inline-flex items-center gap-2 rounded-xl border border-purple-100 bg-purple-50 px-4 py-2 text-sm font-medium text-purple-800 hover:bg-purple-100 hover:border-purple-200 transition">
         <span class="flex h-7 w-7 items-center justify-center rounded-full bg-purple-100/70">
           <i class="ph ph-chats-teardrop text-base"></i>
         </span>
         <span>Forum</span>
      </a>
    </div>
  </div>

  <!-- Contents grouped -->
  <?php if (!empty($content_map)): ?>
    <?php foreach ($content_map as $type => $items): ?>
      <section class="mb-8 overflow-hidden rounded-2xl bg-white shadow-sm border border-slate-200 <?= $type_styles[$type] ?? '' ?>">
        <!-- Section header -->
        <div class="flex items-center justify-between gap-4 px-5 py-4 bg-slate-50/80">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900/5 text-slate-700">
              <i class="ph <?= $type_icons[$type] ?? 'ph-folder' ?> text-xl"></i>
            </div>
            <div>
              <h2 class="text-base sm:text-lg font-semibold text-slate-900 flex items-center gap-2">
                <?= ucfirst($type) ?>s
                <span class="inline-flex items-center rounded-full bg-slate-900/5 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                  <?= $type_counts[$type] ?? count($items) ?> item<?= (count($items) !== 1 ? 's' : '') ?>
                </span>
              </h2>
              <p class="text-xs text-slate-500">
                Manage all <?= htmlspecialchars($type) ?>-type content for this course.
              </p>
            </div>
          </div>
        </div>

        <!-- Items -->
        <div class="divide-y divide-slate-100">
          <?php foreach ($items as $row): ?>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50 transition-colors">
              <div class="flex-1">
                <p class="font-medium text-slate-900">
                  <?= htmlspecialchars($row['title']) ?>
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                  <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-[11px] uppercase tracking-wide">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    Position <?= (int)$row['position'] ?>
                  </span>
                  <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] uppercase tracking-wide">
                    ID #<?= (int)$row['content_id'] ?>
                  </span>
                </div>
              </div>

              <div class="flex shrink-0 items-center gap-2 sm:gap-3 text-sm">
                <!-- View Content (opens modal) -->
                <button
                  @click.prevent="iframeSrc='view_content.php?id=<?= (int)$row['content_id'] ?>'; show=true;"
                  class="inline-flex items-center gap-1 rounded-full border border-blue-100 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100 hover:border-blue-200 transition">
                  <i class="ph ph-eye text-sm"></i>
                  View
                </button>

                <a href="edit_content.php?content_id=<?= (int)$row['content_id'] ?>"
                   class="inline-flex items-center gap-1 rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 hover:border-emerald-200 transition">
                  <i class="ph ph-pencil-simple text-sm"></i>
                  Edit
                </a>

                <a href="delete_content.php?content_id=<?= (int)$row['content_id'] ?>"
                   onclick="return confirm('Delete this content?')"
                   class="inline-flex items-center gap-1 rounded-full border border-rose-100 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-100 hover:border-rose-200 transition">
                  <i class="ph ph-trash-simple text-sm"></i>
                  Delete
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <!-- Empty state -->
    <div class="rounded-2xl bg-white shadow-sm border border-dashed border-slate-300 px-8 py-10 text-center">
      <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-50 text-slate-500">
        <i class="ph ph-folders text-2xl"></i>
      </div>
      <h2 class="text-lg font-semibold text-slate-900 mb-1">
        No content yet
      </h2>
      <p class="text-sm text-slate-500 max-w-md mx-auto mb-5">
        This course doesn’t have any lessons, videos, PDFs, quizzes, or forums yet.
        Use the buttons above to start building your learning experience.
      </p>
      <a href="add_content.php?course_id=<?= $course_id ?>"
         class="inline-flex items-center gap-2 rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow hover:bg-slate-800 transition">
        <i class="ph ph-plus-circle text-base"></i>
        Add your first content
      </a>
    </div>
  <?php endif; ?>

  <!-- Preview Modal -->
  <template x-if="show">
    <div class="fixed inset-0 z-50 flex items-center justify-center">
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="show=false"></div>
      <div class="relative w-[95%] max-w-6xl rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/10 overflow-hidden">
        <div class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-slate-200 bg-slate-50">
          <div class="flex items-center gap-2">
            <div class="h-8 w-8 flex items-center justify-center rounded-lg bg-slate-900/5 text-slate-700">
              <i class="ph ph-eye text-lg"></i>
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-900">
                Content preview
              </p>
              <p class="text-xs text-slate-500">
                This is how students will see this item.
              </p>
            </div>
          </div>
          <button @click="show=false"
                  class="inline-flex items-center justify-center rounded-full bg-white text-slate-500 hover:text-slate-900 hover:bg-slate-100 border border-slate-200 h-8 w-8 transition">
            <span class="sr-only">Close</span>
            &times;
          </button>
        </div>
        <div class="p-3 sm:p-4">
          <iframe :src="iframeSrc" class="w-full h-[75vh] rounded-lg border border-slate-200" frameborder="0"></iframe>
        </div>
      </div>
    </div>
  </template>
</div>

</body>
</html>