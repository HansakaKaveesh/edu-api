<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
  header("Location: login.php");
  exit;
}

$user_id = intval($_SESSION['user_id']);

// Get teacher_id (prepared)
$teacher_id = 0;
if ($stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ? LIMIT 1")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) $teacher_id = intval($row['teacher_id']);
  $stmt->close();
}
if (!$teacher_id) {
  http_response_code(403);
  echo "Unauthorized";
  exit;
}

// Robust content_id retrieval + fallback to course_id
$content_id = 0;
$course_id_from_query = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;

if (isset($_GET['content_id'])) {
  $content_id = intval($_GET['content_id']);
} elseif (isset($_GET['cid'])) {
  $content_id = intval($_GET['cid']);
} elseif (isset($_GET['id'])) {
  $content_id = intval($_GET['id']);
} elseif (isset($_POST['content_id'])) {
  $content_id = intval($_POST['content_id']);
} elseif ($course_id_from_query) {
  // Redirect to the first content for this course if content_id not provided
  $stmt = $conn->prepare("SELECT content_id FROM contents WHERE course_id = ? ORDER BY position, content_id LIMIT 1");
  $stmt->bind_param("i", $course_id_from_query);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    header("Location: teacher_view_content.php?content_id=" . intval($row['content_id']));
    exit;
  } else {
    // No content found for the course; show a friendly message below
    $content_id = 0;
  }
  $stmt->close();
}

if (!$content_id && !$course_id_from_query) {
  http_response_code(400);
  echo "Missing content_id";
  exit;
}

// Fetch content + course (if content_id exists)
$content = null;
if ($content_id) {
  if ($stmt = $conn->prepare("
      SELECT c.content_id, c.course_id, co.name AS course_name, COALESCE(co.description, '') AS course_description,
             c.title, c.type, c.position, COALESCE(c.body, '') AS body, COALESCE(c.file_url, '') AS file_url
      FROM contents c
      JOIN courses co ON co.course_id = c.course_id
      WHERE c.content_id = ?
      LIMIT 1
    ")) {
    $stmt->bind_param("i", $content_id);
    $stmt->execute();
    $content = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  }

  if (!$content) {
    http_response_code(404);
    echo "Content not found.";
    exit;
  }

  // Authorize teacher for this course
  $course_id = intval($content['course_id']);
  if ($stmt = $conn->prepare("SELECT 1 FROM teacher_courses WHERE teacher_id = ? AND course_id = ? LIMIT 1")) {
    $stmt->bind_param("ii", $teacher_id, $course_id);
    $stmt->execute();
    $authorized = $stmt->get_result()->num_rows > 0;
    $stmt->close();
  } else {
    $authorized = false;
  }

  if (!$authorized) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
  }
} else {
  // No content yet for this course_id path; fetch basic course info for the message
  $course_id = $course_id_from_query;
  $course = ['name' => 'Course', 'description' => ''];
  if ($stmt = $conn->prepare("SELECT name, COALESCE(description,'') AS description FROM courses WHERE course_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) $course = $row;
    $stmt->close();
  }
}

// Helpers
function is_url($s) { return !!preg_match('/^https?:\/\//i', $s); }
function ext_from_path($s) {
  $path = parse_url($s, PHP_URL_PATH);
  return $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
}
function youtube_id($url) {
  if (!$url) return null;
  $patterns = [
    '/youtu\.be\/([^\?\&]+)/',
    '/youtube\.com\/watch\?v=([^\&]+)/',
    '/youtube\.com\/embed\/([^\?\&]+)/',
    '/youtube\.com\/shorts\/([^\?\&]+)/'
  ];
  foreach ($patterns as $p) if (preg_match($p, $url, $m)) return $m[1];
  return null;
}
function vimeo_id($url) {
  if (!$url) return null;
  if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) return $m[1];
  return null;
}

// If we have content, prep fields
$title        = $content_id ? ($content['title'] ?? 'Content') : '';
$type         = $content_id ? strtolower($content['type'] ?? 'lesson') : '';
$body         = $content_id ? ($content['body'] ?? '') : '';
$file_url     = $content_id ? ($content['file_url'] ?? '') : '';
$course_name  = $content_id ? ($content['course_name'] ?? 'Course') : ($course['name'] ?? 'Course');
$course_desc  = $content_id ? ($content['course_description'] ?? '') : ($course['description'] ?? '');

$icon_map = [
  'lesson' => 'ph-book-open',
  'video'  => 'ph-video',
  'pdf'    => 'ph-file-text',
  'quiz'   => 'ph-brain',
  'forum'  => 'ph-chats-teardrop',
];
$icon = $content_id ? ($icon_map[$type] ?? 'ph-file') : 'ph-file';

// File type checks
$file_ext  = $file_url ? ext_from_path($file_url) : '';
$is_video  = $file_url && (in_array($file_ext, ['mp4','webm','ogg']) || youtube_id($file_url) || vimeo_id($file_url));
$is_pdf    = $file_url && ($file_ext === 'pdf');
$is_doc    = $file_url && in_array($file_ext, ['doc','docx','ppt','pptx','xls','xlsx']); // Google viewer-friendly
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title><?= htmlspecialchars($content_id ? "$title • Teacher View" : "$course_name • Teacher View") ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
   <link rel="icon" type="image/png" href="./images/logo.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
  <?php include 'components/navbar.php'; ?>

  <main class="max-w-6xl mx-auto px-6 md:px-10 py-8">
    <div class="flex items-center justify-between">
      <div>
        <a href="course.php?course_id=<?= intval($course_id) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700">
          <i class="ph ph-arrow-left mr-1"></i> Back to <?= htmlspecialchars($course_name) ?>
        </a>
        <?php if ($content_id): ?>
          <h1 class="text-3xl md:text-4xl font-extrabold mt-2 flex items-center gap-3">
            <i class="ph <?= $icon ?> text-blue-600"></i>
            <?= htmlspecialchars($title) ?>
          </h1>
          <p class="text-gray-600 mt-1">Type: <span class="font-medium uppercase"><?= htmlspecialchars($type) ?></span></p>
        <?php else: ?>
          <h1 class="text-3xl md:text-4xl font-extrabold mt-2"><?= htmlspecialchars($course_name) ?></h1>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-2">
        <?php if ($content_id): ?>
          <a href="edit_content.php?content_id=<?= intval($content_id) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-800">
            <i class="ph ph-pencil-simple"></i> Edit
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$content_id): ?>
      <div class="mt-6 bg-white border border-gray-200 rounded-2xl shadow p-6">
        <p class="text-gray-700">This course has no content yet.</p>
        <div class="mt-3 flex gap-2">
          <a href="add_content.php?course_id=<?= intval($course_id) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
            <i class="ph ph-plus-circle"></i> Add Content
          </a>
          <a href="course.php?course_id=<?= intval($course_id) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200">
            <i class="ph ph-list-bullets"></i> View Course
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="mt-6 grid grid-cols-1 md:grid-cols-12 gap-6">
        <aside class="md:col-span-4 lg:col-span-3">
          <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <h3 class="font-semibold text-gray-900">Course</h3>
            <p class="text-sm text-gray-700 mt-1"><?= htmlspecialchars($course_name) ?></p>
            <?php if (!empty($course_desc)): ?>
              <p class="text-xs text-gray-500 mt-2"><?= nl2br(htmlspecialchars($course_desc)) ?></p>
            <?php endif; ?>
            <div class="mt-4 space-y-2">
              <a href="course.php?course_id=<?= intval($course_id) ?>" class="inline-flex items-center gap-2 text-blue-700 hover:underline text-sm">
                <i class="ph ph-list-bullets"></i> All contents
              </a>
              <a href="add_content.php?course_id=<?= intval($course_id) ?>" class="inline-flex items-center gap-2 text-sm text-gray-700 hover:underline">
                <i class="ph ph-plus-circle"></i> Add content
              </a>
            </div>
          </div>
        </aside>

        <section class="md:col-span-8 lg:col-span-9">
          <div class="bg-white border border-gray-200 rounded-2xl shadow p-5">
            <?php if ($type === 'quiz'): ?>
              <div class="bg-blue-50 ring-1 ring-blue-100 p-4 rounded-lg">
                <h2 class="font-semibold mb-2">Quiz</h2>
                <p class="text-gray-700 text-sm">Manage or preview this quiz:</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <a href="view_quiz.php?content_id=<?= intval($content_id) ?>" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                    <i class="ph ph-eye"></i> Preview Quiz
                  </a>
                  <a href="add_quiz.php?course_id=<?= intval($course_id) ?>" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200">
                    <i class="ph ph-pencil-circle"></i> Manage Quizzes
                  </a>
                </div>
              </div>

            <?php elseif ($type === 'forum'): ?>
              <div class="bg-purple-50 ring-1 ring-purple-100 p-4 rounded-lg">
                <h2 class="font-semibold mb-2">Forum</h2>
                <p class="text-gray-700 text-sm">Open the forum thread for this content:</p>
                <a href="forum.php?course_id=<?= intval($course_id) ?>" class="inline-flex items-center gap-2 px-3 py-1.5 mt-2 rounded-lg bg-purple-600 text-white hover:bg-purple-700">
                  <i class="ph ph-chats-teardrop"></i> Open Forum
                </a>
              </div>

            <?php else: ?>
              <?php if (!empty($body)): ?>
                <article class="prose max-w-none">
                  <?= nl2br(htmlspecialchars($body)) ?>
                </article>
                <hr class="my-5">
              <?php endif; ?>

              <?php if (!empty($file_url)): ?>
                <?php
                  $yt = youtube_id($file_url);
                  $vm = vimeo_id($file_url);
                ?>
                <?php if ($yt): ?>
                  <div class="aspect-video rounded-xl overflow-hidden">
                    <iframe class="w-full h-full" src="https://www.youtube.com/embed/<?= htmlspecialchars($yt) ?>" allowfullscreen loading="lazy"></iframe>
                  </div>
                <?php elseif ($vm): ?>
                  <div class="aspect-video rounded-xl overflow-hidden">
                    <iframe class="w-full h-full" src="https://player.vimeo.com/video/<?= htmlspecialchars($vm) ?>" allowfullscreen loading="lazy"></iframe>
                  </div>
                <?php elseif ($is_video): ?>
                  <video controls playsinline preload="metadata" class="w-full rounded-xl ring-1 ring-gray-200">
                    <source src="<?= htmlspecialchars($file_url) ?>" type="video/<?= htmlspecialchars($file_ext ?: 'mp4') ?>">
                    Your browser does not support the video tag.
                  </video>
                <?php elseif ($is_pdf): ?>
                  <div class="h-[80vh] rounded-xl overflow-hidden ring-1 ring-gray-200">
                    <iframe src="<?= htmlspecialchars($file_url) ?>#toolbar=1&navpanes=0" class="w-full h-full"></iframe>
                  </div>
                <?php elseif ($is_doc && is_url($file_url)): ?>
                  <div class="h-[80vh] rounded-xl overflow-hidden ring-1 ring-gray-200">
                    <iframe src="https://docs.google.com/gview?embedded=1&url=<?= urlencode($file_url) ?>" class="w-full h-full"></iframe>
                  </div>
                <?php else: ?>
                  <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-4 rounded-lg">
                    Could not embed this file. <a href="<?= htmlspecialchars($file_url) ?>" target="_blank" rel="noopener" class="underline">Open in a new tab</a>.
                  </div>
                <?php endif; ?>

                <div class="mt-3">
                  <a href="<?= htmlspecialchars($file_url) ?>" target="_blank" rel="noopener"
                     class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200">
                    <i class="ph ph-download-simple"></i> Open/Download
                  </a>
                </div>
              <?php else: ?>
                <p class="text-gray-600">No attachments for this content.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </section>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>