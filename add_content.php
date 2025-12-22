<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    die("Access denied");
}

$course_id = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (!$course_id) {
    http_response_code(400);
    die("Invalid course_id");
}

$message = '';
$messageType = 'success'; // 'success' or 'error'

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function uploadErrorMessage($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE: return 'The uploaded file exceeds upload_max_filesize in php.ini.';
        case UPLOAD_ERR_FORM_SIZE: return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.';
        case UPLOAD_ERR_PARTIAL: return 'The uploaded file was only partially uploaded.';
        case UPLOAD_ERR_NO_FILE: return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Missing a temporary folder on server.';
        case UPLOAD_ERR_CANT_WRITE: return 'Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION: return 'A PHP extension stopped the file upload.';
        case UPLOAD_ERR_OK: return 'No error.';
        default: return 'Unknown file upload error.';
    }
}

/* ---------- Helpers ---------- */

// Convert simple CSV (one row per line) to an HTML table
function csv_to_html_table($csvText) {
    $rows = preg_split('/\R/', trim((string)$csvText));
    if (!$rows || trim((string)$csvText) === '') return '';
    $html = '<div class="overflow-x-auto my-3"><table class="table-auto border-collapse border border-gray-300 text-sm"><tbody>';
    foreach ($rows as $i => $row) {
        $cells = array_map('trim', str_getcsv($row));
        $html .= '<tr>';
        foreach ($cells as $cell) {
            $tag = ($i === 0) ? 'th' : 'td';
            $html .= '<'.$tag.' class="border border-gray-300 px-2 py-1">'.e($cell).'</'.$tag.'>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

// Sanitize HTML from TinyMCE: keep common tags/attrs, strip scripts/events/javascript:
function sanitize_html($html) {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $allowedTags = [
        'a','p','br','hr','h1','h2','h3','h4','h5','h6','strong','em','u','s',
        'blockquote','pre','code','ul','ol','li','img','figure','figcaption',
        'table','thead','tbody','tfoot','tr','td','th','colgroup','col','span','div'
    ];
    $allowedAttrs = [
        'a'   => ['href','title','target','rel','class','style'],
        'img' => ['src','alt','title','class','style','width','height'],
        '*'   => ['class','style','colspan','rowspan','align']
    ];
    $allowedCss = [
        'color','background-color','text-align','font-weight','font-style',
        'text-decoration','font-size','max-width','width','height'
    ];
    $safeUrl = function($url) {
        if ($url === null) return '';
        $url = trim($url);
        if ($url === '') return '';
        if (stripos($url, 'javascript:') === 0) return '';
        return $url;
    };
    $cleanStyle = function($style) use ($allowedCss) {
        if (!$style) return '';
        $parts = array_filter(array_map('trim', explode(';', $style)));
        $kept = [];
        foreach ($parts as $decl) {
            if (strpos($decl, ':') === false) continue;
            list($prop, $val) = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            $valLower = strtolower($val);
            if (strpos($valLower, 'url(') !== false || strpos($valLower, 'expression') !== false || strpos($valLower, '@import') !== false) {
                continue;
            }
            if (in_array($prop, $allowedCss, true)) {
                $kept[] = $prop . ': ' . $val;
            }
        }
        return implode('; ', $kept);
    };

    $walker = function($node) use (&$walker, $allowedTags, $allowedAttrs, $safeUrl, $cleanStyle) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            if (!in_array($tag, $allowedTags, true)) {
                $parent = $node->parentNode;
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                return;
            }
            if ($node->hasAttributes()) {
                $toRemove = [];
                foreach ($node->attributes as $attr) {
                    $name = strtolower($attr->nodeName);
                    $value = $attr->nodeValue;
                    if (strpos($name, 'on') === 0) { $toRemove[] = $name; continue; }
                    $allowedForTag = $allowedAttrs[$tag] ?? [];
                    $allowedGlobal = $allowedAttrs['*'];
                    if (!in_array($name, $allowedForTag, true) && !in_array($name, $allowedGlobal, true)) {
                        $toRemove[] = $name; continue;
                    }
                    if ($name === 'href' || $name === 'src') {
                        $safe = $safeUrl($value);
                        if ($safe === '') { $toRemove[] = $name; continue; }
                        $node->setAttribute($name, $safe);
                    }
                    if ($name === 'style') {
                        $node->setAttribute('style', $cleanStyle($value));
                    }
                    if ($tag === 'a' && $name === 'target') {
                        $node->setAttribute('rel', 'noopener noreferrer');
                    }
                }
                foreach ($toRemove as $rem) {
                    $node->removeAttribute($rem);
                }
            }
        }
        if ($node->hasChildNodes()) {
            $children = [];
            foreach ($node->childNodes as $c) $children[] = $c;
            foreach ($children as $c) $walker($c);
        }
    };

    $children = [];
    foreach ($doc->childNodes as $c) $children[] = $c;
    foreach ($children as $c) $walker($c);

    $html = $doc->saveHTML();
    libxml_clear_errors();
    return $html;
}

// Persist posted values so the form can re-render with them after an error
$postedType        = $_POST['type'] ?? 'lesson';
$postedTitle       = $_POST['title'] ?? '';
$postedLessonBody  = $_POST['lesson_body'] ?? ''; // TinyMCE HTML (lesson)
$postedGenericBody = $_POST['body'] ?? '';        // Generic body (non-lesson)
$postedUrl         = $_POST['file_url'] ?? '';
$postedLessonTopic = $_POST['lesson_topic'] ?? '';
$postedSubtopics   = isset($_POST['subtopics']) && is_array($_POST['subtopics']) ? $_POST['subtopics'] : [];
$postedTables      = isset($_POST['table_csv']) && is_array($_POST['table_csv']) ? $_POST['table_csv'] : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES)) {
        $message = "❌ The request is larger than allowed. Please upload a smaller file or increase upload_max_filesize/post_max_size.";
        $messageType = 'error';
    } else {
        $title = trim($postedTitle);
        $type  = $postedType;
        $url   = trim($postedUrl);

        $errors = [];
        $allowedTypes = ['lesson', 'pdf', 'video', 'quiz', 'forum'];
        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'Invalid content type.';
        }

        if ($type === 'lesson') {
            $topic = trim($postedLessonTopic);
            if ($topic !== '') $title = $topic;
        }
        if ($title === '') {
            $errors[] = 'Title (Topic) is required.';
        }

        // File upload: optional for ALL types
        // For lessons: allowed extensions = pdf, html
        // For other types: pdf, mp4, html
        if (isset($_FILES['upload_file']) && is_array($_FILES['upload_file']) && $_FILES['upload_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $fileErr = $_FILES['upload_file']['error'];

            if ($fileErr === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['upload_file']['tmp_name'];
                $origName    = $_FILES['upload_file']['name'];
                $ext         = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if ($type === 'lesson') {
                    $allowedExtensions = ['pdf', 'html'];
                } else {
                    $allowedExtensions = ['pdf', 'mp4', 'html'];
                }

                if (!in_array($ext, $allowedExtensions, true)) {
                    if ($type === 'lesson') {
                        $errors[] = "Invalid file type. For lessons only PDF and HTML files are allowed.";
                    } else {
                        $errors[] = "Invalid file type. Only PDF, MP4, and HTML allowed.";
                    }
                } else {
                    $uploadFolder = __DIR__ . '/uploads/';
                    if (!is_dir($uploadFolder)) {
                        mkdir($uploadFolder, 0755, true);
                    }

                    $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
                    $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '_' . substr($base, 0, 50) . '.' . $ext;
                    $destFsPath = $uploadFolder . $uniqueName;
                    $destUrl    = 'uploads/' . $uniqueName;

                    if (move_uploaded_file($fileTmpPath, $destFsPath)) {
                        $url = $destUrl;
                        $postedUrl = $url;
                    } else {
                        $errors[] = "Error uploading the file.";
                    }
                }
            } else {
                $errors[] = 'File upload error: ' . uploadErrorMessage($fileErr);
            }
        }

        // LESSON: optional additional images gallery
        $lessonImagePaths = [];
        if ($type === 'lesson' && isset($_FILES['lesson_images']) && is_array($_FILES['lesson_images']['name'])) {
            $imgNames = $_FILES['lesson_images']['name'];
            $imgTmp   = $_FILES['lesson_images']['tmp_name'];
            $imgErr   = $_FILES['lesson_images']['error'];
            $imgCnt   = count($imgNames);

            $imgFolder = __DIR__ . '/uploads/lesson_images/';
            if (!is_dir($imgFolder)) mkdir($imgFolder, 0755, true);

            $allowedImg = ['jpg','jpeg','png','gif','webp'];
            for ($i=0; $i<$imgCnt; $i++) {
                if ($imgErr[$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($imgErr[$i] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Image upload error: ' . uploadErrorMessage($imgErr[$i]);
                    continue;
                }
                $ext = strtolower(pathinfo($imgNames[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedImg, true)) {
                    $errors[] = 'Invalid image type (allowed: jpg, jpeg, png, gif, webp).';
                    continue;
                }
                $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($imgNames[$i], PATHINFO_FILENAME));
                $uniqueName = time() . '_' . bin2hex(random_bytes(3)) . '_' . substr($base, 0, 50) . '.' . $ext;
                $dest = $imgFolder . $uniqueName;
                if (move_uploaded_file($imgTmp[$i], $dest)) {
                    $lessonImagePaths[] = 'uploads/lesson_images/' . $uniqueName;
                } else {
                    $errors[] = 'Failed to save image ' . e($imgNames[$i]);
                }
            }
        }

        if (!$errors) {
            if ($type === 'lesson') {
                $topic = ($postedLessonTopic !== '') ? $postedLessonTopic : $title;
                $subtopics  = array_values(array_filter(array_map('trim', $postedSubtopics ?? []), 'strlen'));
                $editorHtml = $postedLessonBody; // TinyMCE HTML from lesson_body

                $html = '<article class="prose max-w-none">';
                $html .= '<header>';
                $html .= '<h1>'.e($topic).'</h1>';
                if ($subtopics) {
                    $html .= '<ul>';
                    foreach ($subtopics as $st) {
                        $html .= '<li>'.e($st).'</li>';
                    }
                    $html .= '</ul>';
                }
                $html .= '</header>';

                if ($editorHtml !== '') {
                    $html .= '<section class="my-4">'. $editorHtml .'</section>';
                }

                if (!empty($postedTables)) {
                    foreach ($postedTables as $csv) {
                        $csv = trim((string)$csv);
                        if ($csv !== '') $html .= csv_to_html_table($csv);
                    }
                }

                if (!empty($lessonImagePaths)) {
                    $html .= '<section class="my-4 grid grid-cols-1 sm:grid-cols-2 gap-4">';
                    foreach ($lessonImagePaths as $img) {
                        $html .= '<figure class="rounded border border-gray-200 p-2 bg-white">';
                        $html .= '<img src="'.e($img).'" alt="'.e($topic).'" style="max-width:100%;height:auto">';
                        $html .= '</figure>';
                    }
                    $html .= '</section>';
                }

                $html .= '</article>';

                $body = sanitize_html($html);
                // keep $url as uploaded/manual attachment (PDF/HTML)
            } else {
                $body = trim($postedGenericBody); // for non-lessons
            }

            // Determine next position
            $position = 1;
            if ($posStmt = $conn->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM contents WHERE course_id = ?")) {
                $posStmt->bind_param("i", $course_id);
                if ($posStmt->execute()) {
                    $posStmt->bind_result($position);
                    $posStmt->fetch();
                }
                $posStmt->close();
            }

            if ($stmt = $conn->prepare("INSERT INTO contents (course_id, type, title, body, file_url, position) VALUES (?, ?, ?, ?, ?, ?)")) {
                $stmt->bind_param("issssi", $course_id, $type, $title, $body, $url, $position);
                if ($stmt->execute()) {
                    $message = "✅ Content added successfully.";
                    $messageType = 'success';
                    // Clear form values after success
                    $postedType        = 'lesson';
                    $postedTitle       = '';
                    $postedLessonBody  = '';
                    $postedGenericBody = '';
                    $postedUrl         = '';
                    $postedLessonTopic = '';
                    $postedSubtopics   = [];
                    $postedTables      = [];
                } else {
                    $message = "❌ Database error: " . $stmt->error;
                    $messageType = 'error';
                }
                $stmt->close();
            } else {
                $message = "❌ Failed to prepare database statement.";
                $messageType = 'error';
            }
        } else {
            $message = "❌ " . implode(' ', $errors);
            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Add Course Content</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="icon" type="image/png" href="./images/logo.png" />
  <!-- Tiny Cloud (use your API key) -->
  <script src="https://cdn.tiny.cloud/1/g8mt75h3w9do0k4wd7m8qjf6v08bn0jr69vnqaulhphfnv8c/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
  <style>
    .prose h1,h2,h3,h4,h5,h6{margin:0.6em 0}
    .prose p{margin:0.5em 0}
    .prose ul{list-style:disc;margin-left:1.25rem}
    .prose table th{background:#f8fafc;font-weight:600}
  </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen antialiased">
  <?php include 'components/navbar.php'; ?>

  <main class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pb-16 pt-24">
    <!-- Hero header -->
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-blue-600 via-indigo-600 to-sky-500 text-white shadow-lg mb-8">
      <div class="absolute inset-0 opacity-10 bg-[radial-gradient(circle_at_top,_#fff,_transparent_60%)]"></div>
      <div class="relative p-6 sm:p-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
          <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/30">
              <i class="ph ph-book-open text-2xl"></i>
            </div>
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-blue-100">
                Course Content Builder
              </p>
              <h1 class="mt-1 text-2xl sm:text-3xl font-extrabold tracking-tight">
                Add Content to Course
              </h1>
              <p class="mt-1 text-sm text-blue-100/90">
                Course ID: <span class="font-semibold">#<?= e($course_id) ?></span>
              </p>
              <p class="mt-2 text-xs sm:text-sm text-blue-100/90 max-w-2xl">
                Create rich lessons with text, images, tables, and attachments, or upload PDFs, videos, quizzes, and forums for your students.
              </p>
            </div>
          </div>
          <div class="flex flex-col items-start sm:items-end gap-3">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-1.5 text-[11px] font-medium uppercase tracking-[0.2em]">
              <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
              TEACHER MODE
            </span>
            <a href="teacher_dashboard.php"
               class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-blue-50 hover:bg-white/20 transition">
              <i class="ph ph-arrow-left text-sm"></i>
              Back to dashboard
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- Form card -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm">
      <div class="border-b border-slate-200 px-5 py-4 flex items-center justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-slate-900 uppercase tracking-[0.18em]">
            New Content
          </h2>
          <p class="mt-1 text-xs text-slate-500">
            Fill out the fields below. Options change depending on the selected content type.
          </p>
        </div>
        <div class="hidden sm:flex items-center gap-1 text-[11px] text-slate-400">
          <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
          <span>Autosave not enabled – remember to submit</span>
        </div>
      </div>

      <div class="px-5 py-6 sm:px-8 sm:py-8">
        <?php if ($message): ?>
          <div class="mb-5 rounded-xl border px-4 py-3 text-sm flex items-start gap-2
            <?= $messageType === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
            <div class="mt-0.5">
              <i class="ph <?= $messageType === 'success' ? 'ph-check-circle' : 'ph-warning-circle' ?> text-lg"></i>
            </div>
            <div>
              <?= e($message) ?>
            </div>
          </div>
        <?php endif; ?>

        <form method="POST"
              action="?course_id=<?= e($course_id) ?>"
              enctype="multipart/form-data"
              class="space-y-8"
              id="contentForm">

          <!-- Section 1: Basic info -->
          <section class="space-y-4">
            <div class="flex items-center justify-between gap-2">
              <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-50 text-blue-600 text-xs font-semibold">1</span>
                Basic information
              </h3>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                  Content Type
                </label>
                <select name="type"
                        id="typeSel"
                        class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"
                        required>
                  <option value="lesson" <?= $postedType === 'lesson' ? 'selected' : '' ?>>Lesson (Rich HTML)</option>
                  <option value="pdf"    <?= $postedType === 'pdf' ? 'selected' : '' ?>>PDF</option>
                  <option value="video"  <?= $postedType === 'video' ? 'selected' : '' ?>>Video</option>
                  <option value="quiz"   <?= $postedType === 'quiz' ? 'selected' : '' ?>>Quiz</option>
                  <option value="forum"  <?= $postedType === 'forum' ? 'selected' : '' ?>>Forum</option>
                </select>
              </div>

              <div id="titleRow">
                <label id="titleLabel" class="block text-sm font-medium text-slate-700 mb-1">
                  <?= ($postedType==='lesson' ? 'Topic:' : 'Title:') ?>
                </label>
                <input type="text"
                       name="title"
                       value="<?= e($postedTitle) ?>"
                       required
                       class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70" />
              </div>
            </div>
          </section>

          <!-- Section 2: Lesson Builder -->
          <section id="lessonBuilder" class="<?= $postedType==='lesson' ? '' : 'hidden' ?> space-y-6 border-t border-slate-100 pt-6">
            <div class="flex items-center justify-between gap-2">
              <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-indigo-600 text-xs font-semibold">2</span>
                Lesson builder
              </h3>
              <p class="text-xs text-slate-500">
                Design a rich lesson page with structure, text, tables, and images.
              </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                  Topic (overrides Title)
                </label>
                <input type="text"
                       name="lesson_topic"
                       value="<?= e($postedLessonTopic) ?>"
                       class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"
                       placeholder="e.g., Unit 1: Digital Devices" />
              </div>

              <div>
                <div class="flex items-center justify-between">
                  <label class="block text-sm font-medium text-slate-700 mb-1">
                    Subtopics
                  </label>
                  <button type="button"
                          id="addSubtopic"
                          class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                    <i class="ph ph-plus-circle text-sm"></i> Add subtopic
                  </button>
                </div>
                <div id="subtopicsWrap" class="mt-1 space-y-2">
                  <?php if ($postedSubtopics): ?>
                    <?php foreach ($postedSubtopics as $st): ?>
                      <div class="flex gap-2">
                        <input type="text"
                               name="subtopics[]"
                               value="<?= e($st) ?>"
                               class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70" />
                        <button type="button"
                                class="removeRow inline-flex items-center justify-center rounded-lg border border-transparent px-2 text-xs text-rose-600 hover:bg-rose-50">
                          ✕
                        </button>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="flex gap-2">
                      <input type="text"
                             name="subtopics[]"
                             class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"
                             placeholder="Subtopic 1" />
                      <button type="button"
                              class="removeRow inline-flex items-center justify-center rounded-lg border border-transparent px-2 text-xs text-rose-600 hover:bg-rose-50">
                        ✕
                      </button>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- TinyMCE Editor -->
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">
                Content (Rich HTML with TinyMCE)
              </label>
              <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-2">
                <textarea id="editor"
                          name="lesson_body"
                          rows="12"
                          class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none">
<?= e($postedLessonBody) ?></textarea>
              </div>
              <p class="mt-1 text-xs text-slate-500">
                Use headings, colors, code blocks, images, and tables. Images uploaded here are stored on the server.
              </p>
            </div>

            <!-- Optional CSV-to-table blocks -->
            <div>
              <div class="flex items-center justify-between">
                <label class="block text-sm font-medium text-slate-700 mb-1">
                  Tables (CSV paste, optional)
                </label>
                <button type="button"
                        id="addTable"
                        class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                  <i class="ph ph-plus-circle text-sm"></i> Add table
                </button>
              </div>
              <div id="tablesWrap" class="mt-2 space-y-3">
                <?php if ($postedTables): ?>
                  <?php foreach ($postedTables as $csv): ?>
                    <div class="flex gap-2 items-start">
                      <textarea name="table_csv[]"
                                rows="4"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"
                                placeholder="col1,col2
val1,val2"><?= e($csv) ?></textarea>
                      <button type="button"
                              class="removeBlock mt-1 inline-flex items-center justify-center rounded-lg border border-transparent px-2 text-xs text-rose-600 hover:bg-rose-50">
                        ✕
                      </button>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="flex gap-2 items-start">
                    <textarea name="table_csv[]"
                              rows="4"
                              class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"
                              placeholder="col1,col2
val1,val2"></textarea>
                    <button type="button"
                            class="removeBlock mt-1 inline-flex items-center justify-center rounded-lg border border-transparent px-2 text-xs text-rose-600 hover:bg-rose-50">
                      ✕
                    </button>
                  </div>
                <?php endif; ?>
              </div>
              <p class="mt-1 text-xs text-slate-500">
                Tip: you can also insert tables directly inside the editor (<span class="font-semibold">Insert → Table</span>).
              </p>
            </div>

            <!-- Optional additional images (outside editor) -->
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">
                Extra Images (optional gallery)
              </label>
              <input type="file"
                     name="lesson_images[]"
                     multiple
                     accept=".jpg,.jpeg,.png,.gif,.webp,image/*"
                     class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70" />
              <p class="mt-1 text-xs text-slate-500">
                These images will appear as a gallery below your lesson. For inline images, use the editor’s <span class="font-semibold">Insert → Image</span>.
              </p>
            </div>
          </section>

          <!-- Section 3: Body for non-lesson types -->
          <section id="genericBody"
                   class="<?= $postedType==='lesson' ? 'hidden' : '' ?> space-y-3 border-t border-slate-100 pt-6">
            <div class="flex items-center justify-between gap-2">
              <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-sky-50 text-sky-600 text-xs font-semibold">2</span>
                Description / Body
              </h3>
              <p class="text-xs text-slate-500">
                Optional description shown before the file, quiz, or forum.
              </p>
            </div>
            <textarea name="body"
                      rows="5"
                      class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"><?= e($postedGenericBody) ?></textarea>
          </section>

          <!-- Section 4: Links & files (all types) -->
          <section class="space-y-4 border-t border-slate-100 pt-6">
            <div class="flex items-center justify-between gap-2">
              <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 text-xs font-semibold">3</span>
                Links & Attachments
              </h3>
            </div>

            <!-- Manual URL -->
            <div id="manualUrl">
              <label class="block text-sm font-medium text-slate-700 mb-1">
                Manual URL (optional)
              </label>
              <input type="text"
                     name="file_url"
                     value="<?= e($postedUrl) ?>"
                     class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"
                     placeholder="https://example.com/resource" />
              <p class="mt-1 text-xs text-slate-500">
                Use this for external resources (YouTube, external PDFs, interactive pages, etc.).
              </p>
            </div>

            <!-- File upload (ALL types) -->
            <div id="fileUpload" class="pt-1">
              <label id="fileUploadLabel" class="block text-sm font-medium text-slate-700 mb-1">
                Upload File (PDF, Video, or HTML)
              </label>
              <input type="hidden" name="MAX_FILE_SIZE" value="220200960" />
              <input
                type="file"
                name="upload_file"
                id="uploadFileInput"
                accept=".pdf,.mp4,.html,text/html,application/pdf,video/mp4"
                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70"
              />
              <p id="fileUploadHelp" class="mt-1 text-xs text-slate-500">
                For lessons: PDF or HTML attachments only. For other types: PDF, MP4, or HTML files.
              </p>
            </div>
          </section>

          <!-- Submit -->
          <div class="pt-4 border-t border-slate-100 flex justify-center">
            <button type="submit"
                    class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-2 focus:ring-offset-white">
              <i class="ph ph-upload-simple text-lg"></i>
              Upload Content
            </button>
          </div>
        </form>
      </div>
    </div>
  </main>

<script>
  // Initialize TinyMCE (Tiny Cloud)
  tinymce.init({
    selector: '#editor',
    height: 480,
    menubar: 'file edit view insert format tools table',
    plugins: 'lists link image table code codesample advlist charmap hr paste wordcount autolink quickbars',
    toolbar: 'undo redo | blocks fontsize | bold italic underline forecolor backcolor | ' +
             'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | ' +
             'table link image | removeformat | code',
    toolbar_mode: 'sliding',
    quickbars_selection_toolbar: 'bold italic | quicklink h2 h3 | forecolor backcolor',
    image_caption: true,
    images_upload_url: 'upload_image.php',
    automatic_uploads: true,
    images_file_types: 'jpg,jpeg,png,gif,webp',
    paste_data_images: true,
    images_upload_credentials: true,
    content_style:
      'body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial; font-size:16px; line-height:1.65;}' +
      'img{max-width:100%; height:auto;}' +
      'table{border-collapse:collapse;}' +
      'th,td{border:1px solid #e5e7eb; padding:6px;}' +
      'th{background:#f8fafc;}'
  });

  const form            = document.getElementById('contentForm');
  const typeSel         = document.getElementById('typeSel');
  const lessonBuilder   = document.getElementById('lessonBuilder');
  const genericBody     = document.getElementById('genericBody');
  const titleLabel      = document.getElementById('titleLabel');
  const fileUploadLabel = document.getElementById('fileUploadLabel');
  const fileUploadHelp  = document.getElementById('fileUploadHelp');
  const fileInput       = document.getElementById('uploadFileInput');

  function toggleMode() {
    const isLesson = typeSel.value === 'lesson';

    lessonBuilder.classList.toggle('hidden', !isLesson);
    genericBody.classList.toggle('hidden', isLesson);

    titleLabel.textContent = isLesson ? 'Topic:' : 'Title:';

    if (isLesson) {
      fileUploadLabel.textContent = 'Upload Attachment (PDF or HTML, optional)';
      fileUploadHelp.textContent  = 'For lessons you can attach a PDF or HTML handout. For videos, choose the "Video" content type instead.';
      if (fileInput) {
        fileInput.accept = '.pdf,.html,text/html,application/pdf';
      }
    } else {
      fileUploadLabel.textContent = 'Upload File (PDF, Video, or HTML)';
      fileUploadHelp.textContent  = 'Allowed types: .pdf, .mp4, .html';
      if (fileInput) {
        fileInput.accept = '.pdf,.mp4,.html,text/html,application/pdf,video/mp4';
      }
    }
  }

  typeSel.addEventListener('change', toggleMode);
  toggleMode(); // initial

  // Ensure TinyMCE pushes content back into the textarea before submit
  form.addEventListener('submit', () => {
    if (typeSel.value === 'lesson' && window.tinymce) {
      tinymce.triggerSave(); // copies editor content into <textarea name="lesson_body">
    }
  });

  // Dynamic subtopics
  const subtopicsWrap = document.getElementById('subtopicsWrap');
  document.getElementById('addSubtopic').addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'flex gap-2 mt-2';
    row.innerHTML =
      '<input type="text" name="subtopics[]" ' +
      'class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70" ' +
      'placeholder="Another subtopic" />' +
      '<button type="button" class="removeRow inline-flex items-center justify-center rounded-lg border border-transparent px-2 text-xs text-rose-600 hover:bg-rose-50">✕</button>';
    subtopicsWrap.appendChild(row);
  });

  subtopicsWrap.addEventListener('click', (e) => {
    if (e.target.classList.contains('removeRow')) {
      e.target.closest('div').remove();
    }
  });

  // Dynamic tables (CSV)
  const tablesWrap = document.getElementById('tablesWrap');
  document.getElementById('addTable').addEventListener('click', () => {
    const block = document.createElement('div');
    block.className = 'flex gap-2 items-start';
    block.innerHTML =
      '<textarea name="table_csv[]" rows="4" ' +
      'class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200/70" ' +
      'placeholder="col1,col2\nval1,val2"></textarea>' +
      '<button type="button" class="removeBlock mt-1 inline-flex items-center justify-center rounded-lg border border-transparent px-2 text-xs text-rose-600 hover:bg-rose-50">✕</button>';
    tablesWrap.appendChild(block);
  });

  tablesWrap.addEventListener('click', (e) => {
    if (e.target.classList.contains('removeBlock')) {
      e.target.closest('div').remove();
    }
  });
</script>
</body>
</html>