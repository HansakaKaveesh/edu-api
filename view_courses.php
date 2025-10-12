<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied");
}

// Optional: set your currency symbol here
$currencySymbol = '$'; // e.g. '$', 'Rs.', '€'

// CSRF token (for POST deletes)
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle delete (POST + CSRF + manual cascade where needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id']) && $_POST['action'] === 'delete') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header("Location: all_courses.php?msg=" . urlencode("Invalid session. Please refresh and try again."));
        exit;
    }

    $course_id = (int)$_POST['id'];
    if ($course_id > 0) {
        $conn->begin_transaction();
        try {
            // Remove child rows that may block deletion (adjust if your schema differs)
            if ($stmt = $conn->prepare("DELETE FROM enrollments WHERE course_id = ?")) {
                $stmt->bind_param("i", $course_id);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close();
            }
            if ($stmt = $conn->prepare("DELETE FROM teacher_courses WHERE course_id = ?")) {
                $stmt->bind_param("i", $course_id);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close();
            }

            // Finally delete the course
            if ($stmt = $conn->prepare("DELETE FROM courses WHERE course_id = ?")) {
                $stmt->bind_param("i", $course_id);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $affected = $stmt->affected_rows;
                $stmt->close();

                if ($affected === 0) {
                    // Not found or already deleted
                    $conn->rollback();
                    header("Location: all_courses.php?msg=" . urlencode("Course #$course_id not found or already deleted."));
                    exit;
                }
            }

            $conn->commit();
            header("Location: all_courses.php?msg=" . urlencode("Course #$course_id deleted successfully."));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            header("Location: all_courses.php?msg=" . urlencode("Failed to delete course #$course_id. " . $e->getMessage()));
            exit;
        }
    }

    header("Location: all_courses.php?msg=" . urlencode("Invalid course ID."));
    exit;
}

// Fetch courses
$query = $conn->query("
    SELECT 
        c.course_id,
        c.name AS course_name,
        c.description,
        c.price,
        ct.board,
        ct.level,
        CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
    FROM courses c
    JOIN course_types ct ON c.course_type_id = ct.course_type_id
    LEFT JOIN teacher_courses tc ON c.course_id = tc.course_id
    LEFT JOIN teachers t ON tc.teacher_id = t.teacher_id
    ORDER BY c.course_id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>All Courses</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome (for tools sidebar icons provided) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <!-- Lucide icons -->
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
  <?php include 'components/navbar.php'; ?>

  <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-28">
    <!-- Grid: Sidebar + Main -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <?php
        $activePath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $createAnnouncementLink = '#create-announcement';
      ?>
      <aside class="lg:col-span-3">
        <?php include 'components/admin_tools_sidebar.php'; ?>
      </aside>

      <!-- Main content -->
      <main class="lg:col-span-9">
        <div class="w-full bg-white rounded-lg shadow p-6">
          <div class="flex items-center justify-between gap-2 mb-6">
            <h2 class="text-3xl font-semibold flex items-center gap-2">
              <i data-lucide="book-open" class="w-7 h-7 text-blue-700"></i>
              All Courses
            </h2>
            <?php if (!empty($_GET['msg'])): ?>
              <div class="ml-auto rounded-lg bg-emerald-50 text-emerald-700 px-3 py-2 text-sm ring-1 ring-emerald-200">
                <i class="fa-solid fa-circle-info mr-1"></i> <?= htmlspecialchars($_GET['msg']) ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="flex items-center gap-2 mb-6">
            <a href="admin_dashboard.php" 
               class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
              <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Dashboard
            </a>
            <a href="add_course.php"
               class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition">
              <i data-lucide="plus-circle" class="w-4 h-4"></i> Add Course
            </a>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-left">
              <thead>
                <tr class="bg-gray-200 text-gray-700">
                  <th class="border border-gray-300 px-4 py-2">ID</th>
                  <th class="border border-gray-300 px-4 py-2">Name</th>
                  <th class="border border-gray-300 px-4 py-2">Board</th>
                  <th class="border border-gray-300 px-4 py-2">Level</th>
                  <th class="border border-gray-300 px-4 py-2">Price</th>
                  <th class="border border-gray-300 px-4 py-2">Description</th>
                  <th class="border border-gray-300 px-4 py-2">Teacher</th>
                  <th class="border border-gray-300 px-4 py-2">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($query && $query->num_rows > 0): ?>
                  <?php while ($row = $query->fetch_assoc()): ?>
                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                      <td class="border border-gray-300 px-4 py-2"><?= (int)$row['course_id'] ?></td>
                      <td class="border border-gray-300 px-4 py-2 font-medium"><?= htmlspecialchars($row['course_name']) ?></td>
                      <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($row['board']) ?></td>
                      <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($row['level']) ?></td>
                      <td class="border border-gray-300 px-4 py-2 font-semibold">
                        <?= $currencySymbol . number_format((float)$row['price'], 2) ?>
                      </td>
                      <td class="border border-gray-300 px-4 py-2">
                        <span class="block max-w-[360px] truncate" title="<?= htmlspecialchars($row['description']) ?>">
                          <?= htmlspecialchars($row['description']) ?>
                        </span>
                      </td>
                      <td class="border border-gray-300 px-4 py-2">
                        <?php if (!empty($row['teacher_name'])): ?>
                          <span class="inline-flex items-center gap-1">
                            <i data-lucide="user" class="w-4 h-4 text-slate-600"></i>
                            <?= htmlspecialchars($row['teacher_name']) ?>
                          </span>
                        <?php else: ?>
                          <span class="inline-flex items-center gap-1 text-slate-500">
                            <i data-lucide="minus-circle" class="w-4 h-4"></i> Not Assigned
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="border border-gray-300 px-4 py-2">
                        <div class="flex items-center gap-2">
                          <a href="edit_course.php?id=<?= (int)$row['course_id'] ?>"
                             class="inline-flex items-center gap-1 px-3 py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600 transition">
                            <i data-lucide="pencil" class="w-4 h-4"></i> Edit
                          </a>

                          <!-- Delete via POST + CSRF -->
                          <form method="post" onsubmit="return confirm('Are you sure you want to delete this course?');" class="inline-flex">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['course_id'] ?>">
                            <button type="submit"
                              class="inline-flex items-center gap-1 px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 transition">
                              <i data-lucide="trash-2" class="w-4 h-4"></i> Delete
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="8" class="border border-gray-300 px-4 py-6 text-center text-slate-500">
                      No courses found.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      if (window.lucide) { lucide.createIcons(); }
    });
  </script>
</body>
</html>