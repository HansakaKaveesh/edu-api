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

$type_icons = [
  'lesson' => 'ph-book-open',
  'video'  => 'ph-video',
  'pdf'    => 'ph-file-text',
  'quiz'   => 'ph-brain',
  'forum'  => 'ph-chats-teardrop'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Manage Course - <?= $course_name ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
<?php include 'components/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-6 py-24" 
     x-data="{ show:false, iframeSrc:'' }">

  <a href="teacher_dashboard.php"
     class="inline-flex items-center gap-2 text-blue-600 hover:underline mb-6">
    <i class="ph ph-arrow-left"></i> Back to Dashboard
  </a>

  <h1 class="text-3xl font-extrabold text-gray-900 flex items-center gap-2 mb-6">
    <i class="ph ph-chalkboard"></i> <?= $course_name ?>
  </h1>

  <!-- Add buttons -->
  <div class="flex flex-wrap gap-3 mb-8">
    <a href="add_content.php?course_id=<?= $course_id ?>"
       class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg inline-flex items-center gap-2">
       <i class="ph ph-plus-circle"></i> Add Lesson / Video / PDF
    </a>
    <a href="upload_assignment.php?course_id=<?= $course_id ?>"
       class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg inline-flex items-center gap-2">
       <i class="ph ph-pencil-circle"></i> Add Assignment
    </a>
    <a href="add_quiz.php?course_id=<?= $course_id ?>"
       class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg inline-flex items-center gap-2">
       <i class="ph ph-brain"></i> Add Quiz
    </a>
    <a href="add_forum.php?course_id=<?= $course_id ?>"
       class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg inline-flex items-center gap-2">
       <i class="ph ph-chats-teardrop"></i> Add Forum
    </a>
  </div>

  <!-- Contents grouped -->
  <?php if (!empty($content_map)): ?>
    <?php foreach ($content_map as $type => $items): ?>
      <div class="mb-8 bg-white border border-gray-200 rounded-xl shadow-sm">
        <div class="flex items-center justify-between px-5 py-4 border-b">
          <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
            <i class="ph <?= $type_icons[$type] ?? 'ph-folder' ?>"></i>
            <?= ucfirst($type) ?>s
          </h2>
        </div>

        <div class="divide-y">
          <?php foreach ($items as $row): ?>
            <div class="flex justify-between items-center px-5 py-3 hover:bg-gray-50 transition">
              <div>
                <p class="font-medium text-gray-900">
                  <?= htmlspecialchars($row['title']) ?>
                </p>
                <p class="text-xs text-gray-500">
                  Position: <?= (int)$row['position'] ?> | ID: <?= (int)$row['content_id'] ?>
                </p>
              </div>

              <div class="flex gap-4 text-sm">
                <!-- 👁️ View Content (opens modal) -->
                <a href="#"
                   @click.prevent="iframeSrc='view_content.php?id=<?= (int)$row['content_id'] ?>'; show=true;"
                   class="text-blue-700 hover:underline flex items-center gap-1">
                  <i class="ph ph-eye"></i> View
                </a>

                <a href="edit_content.php?content_id=<?= (int)$row['content_id'] ?>"
                   class="text-emerald-700 hover:underline flex items-center gap-1">
                  <i class="ph ph-pencil-simple"></i> Edit
                </a>

                <a href="delete_content.php?content_id=<?= (int)$row['content_id'] ?>"
                   onclick="return confirm('Delete this content?')"
                   class="text-red-600 hover:underline flex items-center gap-1">
                  <i class="ph ph-trash-simple"></i> Delete
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="bg-white border border-gray-200 rounded-xl p-8 text-center text-gray-600 shadow">
      No contents yet for this course.
      Add new lessons or resources using the buttons above.
    </div>
  <?php endif; ?>

  <!-- 🪟 Preview Modal -->
  <template x-if="show">
    <div class="fixed inset-0 z-50 flex items-center justify-center">
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="show=false"></div>
      <div class="relative bg-white w-[95%] max-w-6xl rounded-xl p-4 shadow-xl ring-1 ring-gray-200">
        <button @click="show=false"
                class="absolute top-2 right-2 text-gray-600 hover:text-red-600 text-xl">
          &times;
        </button>
        <iframe :src="iframeSrc" class="w-full h-[80vh] rounded" frameborder="0"></iframe>
      </div>
    </div>
  </template>
</div>

</body>
</html>