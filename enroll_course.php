<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die("Access denied.");
}

$user_id = (int)$_SESSION['user_id'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get student_id (optional)
$student_id = null;
if ($stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $student_id = ($stmt->get_result()->fetch_assoc()['student_id'] ?? null);
  $stmt->close();
}

// Flash helper
$flash = '';
if (!empty($_SESSION['flash'])) {
  $flash = $_SESSION['flash'];
  unset($_SESSION['flash']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die("Invalid request.");
    }

    $course_id = (int)($_POST['course_id'] ?? 0);
    if ($course_id <= 0) {
        $_SESSION['flash'] = 'Invalid course.';
        header('Location: enroll_course.php');
        exit;
    }

    if (isset($_POST['cancel'])) {
        $stmt = $conn->prepare("DELETE FROM enrollments WHERE user_id = ? AND course_id = ? AND status = 'pending'");
        $stmt->bind_param("ii", $user_id, $course_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = 'Pending enrollment canceled.';
        header('Location: enroll_course.php');
        exit;

    } elseif (isset($_POST['remove'])) {
        $stmt = $conn->prepare("DELETE FROM enrollments WHERE user_id = ? AND course_id = ?");
        $stmt->bind_param("ii", $user_id, $course_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = 'Enrollment removed.';
        header('Location: enroll_course.php');
        exit;

    } else {
        // Confirm course and get price
        $stmt = $conn->prepare("SELECT price FROM courses WHERE course_id = ? LIMIT 1");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$course) {
            $_SESSION['flash'] = 'Course not found.';
            header('Location: enroll_course.php');
            exit;
        }

        $price = (float)($course['price'] ?? 0);

        // Check if enrollment exists
        $stmt = $conn->prepare("SELECT enrollment_id, status FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1");
        $stmt->bind_param("ii", $user_id, $course_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            if ($existing['status'] === 'pending') {
                header("Location: make_payment.php?course_id={$course_id}&enrollment_id={$existing['enrollment_id']}");
                exit;
            } else {
                $_SESSION['flash'] = 'You are already enrolled in this course.';
                header('Location: enroll_course.php');
                exit;
            }
        }

        if ($price <= 0) {
            // Free course → activate immediately
            $status = 'active';
            $stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, status) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $user_id, $course_id, $status);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = 'Enrolled successfully. Enjoy your free course!';
            header('Location: enroll_course.php');
            exit;
        } else {
            // Paid course → pending + redirect to payment
            $status = 'pending';
            $stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, status) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $user_id, $course_id, $status);
            $stmt->execute();
            $enrollment_id = $stmt->insert_id;
            $stmt->close();

            header("Location: make_payment.php?course_id={$course_id}&enrollment_id={$enrollment_id}");
            exit;
        }
    }
}

// Fetch courses (include cover_image)
$courses = $conn->query("
    SELECT course_id, name, description, price, cover_image
    FROM courses
    ORDER BY name ASC
");
$totalCourses = $courses ? $courses->num_rows : 0;

// Fetch user's enrollments
$enrollments = [];
if ($stmt = $conn->prepare("SELECT enrollment_id, course_id, status FROM enrollments WHERE user_id = ?")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
      $enrollments[(int)$row['course_id']] = [
        'status' => $row['status'],
        'enrollment_id' => (int)$row['enrollment_id']
      ];
  }
  $stmt->close();
}

// Simple metrics
$activeCount  = 0;
$pendingCount = 0;
foreach ($enrollments as $en) {
    if ($en['status'] === 'active')  $activeCount++;
    if ($en['status'] === 'pending') $pendingCount++;
}
$totalEnrolled = count($enrollments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enroll in Course</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="./images/logo.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Ionicons -->
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
      html, body {
        font-family: "Inter", ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      }

      :root { color-scheme: light; }

      @keyframes fadeUp {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
      }
      .animate-fadeUp { animation: fadeUp .45s ease-out both; }

      .bg-bubbles::before, .bg-bubbles::after {
        content: "";
        position: absolute;
        border-radius: 9999px;
        filter: blur(42px);
        opacity: .35;
        z-index: 0;
        pointer-events: none;
      }
      .bg-bubbles::before {
        width: 420px;
        height: 420px;
        background: radial-gradient(circle at 20% 20%, rgba(56,189,248,0.9), transparent 70%);
        top: -80px;
        left: -80px;
      }
      .bg-bubbles::after {
        width: 520px;
        height: 520px;
        background: radial-gradient(circle at 80% 80%, rgba(129,140,248,0.95), transparent 70%);
        bottom: -140px;
        right: -120px;
      }

      ::-webkit-scrollbar { width: 10px; height: 10px; }
      ::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg,#60a5fa,#a78bfa);
        border-radius: 9999px;
      }
      ::-webkit-scrollbar-track { background: transparent; }

      /* Pretty chips */
      .chip {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .25rem .6rem;
        border-radius: 9999px;
        font-size: .7rem;
        font-weight: 600;
        border-width: 1px;
      }
      .chip-gray  { background:#f8fafc; color:#334155; border-color:#e2e8f0; }
      .chip-green { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
      .chip-amber { background:#fffbeb; color:#92400e; border-color:#fde68a; }
      .chip-indigo{ background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }

      .glass-card {
        background: linear-gradient(to bottom right, rgba(255,255,255,0.94), rgba(243,244,246,0.96));
        border: 1px solid rgba(226,232,240,0.9);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        box-shadow: 0 18px 40px rgba(15,23,42,0.10);
      }

      .soft-card {
        background: linear-gradient(to bottom right, rgba(248,250,252,0.96), rgba(239,246,255,0.96));
        border: 1px solid rgba(222,231,255,0.9);
        box-shadow: 0 14px 30px rgba(15,23,42,0.09);
      }

      .card {
        transition: box-shadow .2s ease, transform .2s ease, border-color .2s ease, background .2s ease;
      }
      .card:hover {
        box-shadow: 0 16px 34px rgba(15,23,42,.16);
        transform: translateY(-2px);
        border-color: rgba(129,140,248,0.4);
        background: rgba(255,255,255,0.98);
      }

      .text-gradient {
        background: linear-gradient(120deg,#4f46e5,#2563eb,#06b6d4);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
      }

      .line-clamp-2,
      .line-clamp-3 {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }
      .line-clamp-2 { -webkit-line-clamp: 2; }
      .line-clamp-3 { -webkit-line-clamp: 3; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 text-gray-800 antialiased">
<?php include 'components/navbar.php'; ?>

<!-- Decorative background -->
<div class="fixed inset-0 bg-bubbles -z-10"></div>

<div class="flex flex-col lg:flex-row max-w-8xl mx-auto px-4 sm:px-6 lg:px-10 py-24 lg:py-28 gap-8">
  <!-- Sidebar -->
  <?php include 'components/sidebar_student.php'; ?>

  <!-- Main Content -->
  <main class="w-full space-y-8 animate-fadeUp">

    <!-- Hero / Header -->
    <section class="glass-card rounded-3xl px-5 sm:px-7 lg:px-9 py-6 sm:py-7 lg:py-8 relative overflow-hidden">
      <div class="absolute inset-y-0 right-[-40px] w-64 bg-gradient-to-b from-indigo-200/50 via-sky-100/30 to-transparent rounded-l-full pointer-events-none"></div>

      <div class="relative flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
        <div class="space-y-3">
          <div class="inline-flex items-center gap-2 text-xs font-medium text-slate-500 uppercase tracking-wide">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-600">
              <ion-icon name="library-outline" class="text-xl"></ion-icon>
            </span>
            <span>Course Enrollment</span>
          </div>

          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-slate-900 leading-tight">
            <span class="block text-gradient">Enroll in a Course</span>
          </h1>

          <p class="text-sm sm:text-base text-slate-600 max-w-xl">
            Discover available courses, enroll in new subjects, and manage your existing enrollments—from free
            courses to paid programs.
          </p>

          <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm">
            <a href="student_dashboard.php"
               class="inline-flex items-center gap-1.5 text-indigo-600 hover:text-indigo-800 font-medium">
              <ion-icon name="arrow-back-outline" class="text-base"></ion-icon>
              Back to Dashboard
            </a>
          </div>
        </div>

        <!-- Quick stats -->
        <div class="grid grid-cols-3 gap-2 sm:gap-3 max-w-xs ml-auto">
          <div class="rounded-2xl bg-white/80 border border-slate-100 px-3 py-3 text-center shadow-sm">
            <p class="text-[11px] text-slate-500 uppercase tracking-wide font-semibold">Total Courses</p>
            <p class="mt-1 text-xl font-semibold text-slate-900"><?= (int)$totalCourses ?></p>
          </div>
          <div class="rounded-2xl bg-emerald-50/80 border border-emerald-100 px-3 py-3 text-center shadow-sm">
            <p class="text-[11px] text-emerald-700 uppercase tracking-wide font-semibold">Active</p>
            <p class="mt-1 text-xl font-semibold text-emerald-700"><?= (int)$activeCount ?></p>
          </div>
          <div class="rounded-2xl bg-amber-50/80 border border-amber-100 px-3 py-3 text-center shadow-sm">
            <p class="text-[11px] text-amber-700 uppercase tracking-wide font-semibold">Pending</p>
            <p class="mt-1 text-xl font-semibold text-amber-700"><?= (int)$pendingCount ?></p>
          </div>
        </div>
      </div>
    </section>

    <!-- Flash message -->
    <?php if ($flash): ?>
      <div class="max-w-3xl mx-auto">
        <div class="flex items-start gap-3 bg-emerald-50/95 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-2xl shadow-sm">
          <div class="mt-0.5">
            <ion-icon name="checkmark-circle-outline" class="text-xl"></ion-icon>
          </div>
          <div class="text-sm">
            <p class="font-semibold">Success</p>
            <p class="text-emerald-700"><?= htmlspecialchars($flash) ?></p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Search card -->
    <section class="soft-card rounded-3xl px-5 sm:px-7 py-5 max-w-3xl mx-auto">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 flex items-center gap-1.5">
            <ion-icon name="search-outline" class="text-base text-indigo-500"></ion-icon>
            Search Courses
          </p>
          <p class="text-sm text-slate-600 mt-1">
            Filter courses by name or description to quickly find what interests you.
          </p>
        </div>
        <div class="w-full sm:w-auto sm:min-w[260px]">
          <div class="relative">
            <input
              id="searchInput"
              type="text"
              placeholder="Search courses..."
              class="w-full rounded-full bg-white border border-gray-200 px-5 py-2.5 pl-11 shadow-sm focus:ring-2 focus:ring-indigo-500/40 focus:outline-none text-sm"
              aria-label="Search courses">
            <ion-icon name="search-outline"
                      class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xl"></ion-icon>
          </div>
        </div>
      </div>
    </section>

    <!-- Cards Grid -->
    <section class="max-w-6xl mx-auto w-full">
      <?php if ($totalCourses === 0): ?>
        <div class="flex items-center justify-center gap-3 text-gray-600 bg-white/80 border border-gray-100 rounded-3xl p-8 shadow-sm">
          <ion-icon name="information-circle-outline" class="text-2xl text-slate-500"></ion-icon>
          <div class="text-sm">
            <p class="font-semibold">No courses available right now.</p>
            <p class="text-xs text-slate-500 mt-0.5">Please check back later as new courses are added.</p>
          </div>
        </div>
      <?php else: ?>
        <div id="noResults" class="hidden text-center text-gray-600 bg-white/80 border border-gray-100 rounded-2xl p-6 shadow-sm mb-4">
          <div class="inline-flex items-center gap-2 text-sm">
            <ion-icon name="search-circle-outline" class="text-2xl text-slate-500"></ion-icon>
            <span>No courses match your search.</span>
          </div>
        </div>

        <div id="coursesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <?php while ($course = $courses->fetch_assoc()):
            $cid   = (int)$course['course_id'];
            $name  = $course['name'] ?? '';
            $desc  = $course['description'] ?? '';
            $price = isset($course['price']) ? (float)$course['price'] : 0.0;
            $cover = $course['cover_image'] ?? '';
            $en    = $enrollments[$cid] ?? null;
            $status = $en['status'] ?? null;
            $enrollmentId = $en['enrollment_id'] ?? null;

            $searchText = strtolower(($name ?? '') . ' ' . ($desc ?? ''));
          ?>
            <article
              class="course-card card group relative flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white/85 backdrop-blur-sm shadow-sm"
              data-card
              data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">

              <!-- Top accent / cover image -->
              <?php if (!empty($cover)): ?>
                <div class="h-32 w-full overflow-hidden">
                  <img
                    src="<?= htmlspecialchars($cover, ENT_QUOTES) ?>"
                    alt="Cover image for <?= htmlspecialchars($name, ENT_QUOTES) ?>"
                    class="w-full h-full object-cover"
                  >
                </div>
              <?php else: ?>
                <div class="h-20 bg-gradient-to-r from-indigo-500/10 via-sky-400/15 to-cyan-400/10 relative overflow-hidden">
                  <div class="absolute inset-0 opacity-60 bg-[radial-gradient(circle_at_10%_20%,rgba(129,140,248,0.5),transparent_55%),radial-gradient(circle_at_80%_0,rgba(45,212,191,0.4),transparent_55%)]"></div>
                </div>
              <?php endif; ?>

              <!-- Status badge -->
              <div class="absolute top-4 right-4">
                <?php if ($status === 'active'): ?>
                  <span class="chip chip-green">
                    <ion-icon name="checkmark-circle-outline" class="text-base"></ion-icon> Active
                  </span>
                <?php elseif ($status === 'pending'): ?>
                  <span class="chip chip-amber">
                    <ion-icon name="time-outline" class="text-base"></ion-icon> Pending
                  </span>
                <?php else: ?>
                  <span class="chip chip-gray">
                    <ion-icon name="ellipse-outline" class="text-base"></ion-icon> Not enrolled
                  </span>
                <?php endif; ?>
              </div>

              <!-- Body -->
              <div class="p-5 space-y-3 flex-1">
                <h3 class="text-lg font-semibold text-gray-900 inline-flex items-center gap-2">
                  <span class="inline-flex h-9 w-9 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                    <ion-icon name="book-outline" class="text-lg"></ion-icon>
                  </span>
                  <span class="line-clamp-2"><?= htmlspecialchars($name) ?></span>
                </h3>
                <p class="text-sm text-gray-700 leading-relaxed line-clamp-3">
                  <?= htmlspecialchars($desc ?: 'No description provided for this course.') ?>
                </p>
              </div>

              <!-- Footer / actions -->
              <div class="px-5 pb-5 pt-4 border-t border-gray-100 bg-slate-50/70">
                <div class="flex items-center justify-between gap-3 mb-3">
                  <div class="text-sm inline-flex items-center gap-1 flex-wrap">
                    <?php if ($price <= 0): ?>
                      <span class="chip chip-green">
                        <ion-icon name="pricetag-outline" class="text-base"></ion-icon> Free
                      </span>
                    <?php else: ?>
                      <span class="chip chip-indigo">
                        <ion-icon name="cash-outline" class="text-base"></ion-icon> $<?= number_format($price, 2) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="flex flex-wrap gap-2">
                  <?php if ($status === 'active'): ?>
                    <a href="course.php?course_id=<?= $cid ?>"
                       class="inline-flex items-center justify-center bg-white text-indigo-700 border border-indigo-200 px-3 py-2 rounded-lg hover:bg-indigo-50 transition text-sm">
                      <ion-icon name="open-outline" class="text-base mr-1"></ion-icon>
                      View Course
                    </a>
                    <form method="POST" class="inline" onsubmit="return confirm('Remove this enrollment?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit" name="remove"
                              class="inline-flex items-center bg-red-500 text-white px-3 py-2 rounded-lg hover:bg-red-600 transition text-sm">
                        <ion-icon name="trash-outline" class="text-base mr-1"></ion-icon>
                        Remove
                      </button>
                    </form>

                  <?php elseif ($status === 'pending'): ?>
                    <?php if ($enrollmentId): ?>
                      <a href="make_payment.php?course_id=<?= $cid ?>&enrollment_id=<?= $enrollmentId ?>"
                         class="inline-flex items-center justify-center bg-indigo-600 text-white px-3 py-2 rounded-lg hover:bg-indigo-700 transition text-sm">
                        <ion-icon name="card-outline" class="text-base mr-1"></ion-icon>
                        Pay Now
                      </a>
                    <?php endif; ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Cancel pending enrollment?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit" name="cancel"
                              class="inline-flex items-center bg-amber-500 text-white px-3 py-2 rounded-lg hover:bg-amber-600 transition text-sm">
                        <ion-icon name="close-circle-outline" class="text-base mr-1"></ion-icon>
                        Cancel
                      </button>
                    </form>
                    <form method="POST" class="inline" onsubmit="return confirm('Remove this enrollment record?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit" name="remove"
                              class="inline-flex items-center bg-red-500 text-white px-3 py-2 rounded-lg hover:bg-red-600 transition text-sm">
                        <ion-icon name="trash-outline" class="text-base mr-1"></ion-icon>
                        Remove
                      </button>
                    </form>

                  <?php else: ?>
                    <form method="POST" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit"
                              class="inline-flex items-center bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition text-sm">
                        <ion-icon name="add-circle-outline" class="text-base mr-1"></ion-icon>
                        <?= $price > 0 ? 'Enroll & Pay' : 'Enroll (Free)' ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<?php include 'components/footer.php'; ?>

<script>
  // Client-side search (filters cards by name + description)
  const input = document.getElementById('searchInput');
  const grid  = document.getElementById('coursesGrid');
  const noRes = document.getElementById('noResults');

  function applyFilter() {
    if (!grid) return;
    const q = (input?.value || '').toLowerCase().trim();
    let visible = 0;
    grid.querySelectorAll('[data-card]').forEach(card => {
      const hay = (card.dataset.search || card.innerText.toLowerCase());
      const show = hay.includes(q);
      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (noRes) noRes.classList.toggle('hidden', visible !== 0);
  }

  input?.addEventListener('input', applyFilter);
  applyFilter();
</script>
</body>
</html>