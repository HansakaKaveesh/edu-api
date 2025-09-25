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

// Fetch courses
$courses = $conn->query("SELECT course_id, name, description, price FROM courses ORDER BY name ASC");
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
      html, body { font-family: "Inter", ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; }
      @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      .animate-fadeUp { animation: fadeUp .45s ease-out both; }
      .bg-bubbles::before, .bg-bubbles::after {
        content: ""; position: absolute; border-radius: 9999px; filter: blur(40px); opacity: .25; z-index: 0; pointer-events: none;
      }
      .bg-bubbles::before { width: 420px; height: 420px; background: radial-gradient(closest-side, #60a5fa, transparent 70%); top: -80px; left: -80px; }
      .bg-bubbles::after { width: 500px; height: 500px; background: radial-gradient(closest-side, #a78bfa, transparent 70%); bottom: -120px; right: -120px; }
      ::-webkit-scrollbar { width: 10px; height: 10px; }
      ::-webkit-scrollbar-thumb { background: linear-gradient(180deg,#60a5fa,#a78bfa); border-radius: 9999px; }
      ::-webkit-scrollbar-track { background: transparent; }

      /* Pretty chips */
      .chip { display: inline-flex; align-items: center; gap: .4rem; padding: .25rem .6rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; border-width: 1px; }
      .chip-gray { background:#f8fafc; color:#334155; border-color:#e2e8f0; }
      .chip-green { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
      .chip-amber { background:#fffbeb; color:#92400e; border-color:#fde68a; }
      .chip-indigo { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }

      /* Card hover */
      .card { transition: box-shadow .2s ease, transform .2s ease; }
      .card:hover { box-shadow: 0 12px 24px rgba(15,23,42,.08); transform: translateY(-1px); }
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
  <main class="w-full space-y-8 animate-fadeUp">

    <div class="text-center space-y-2">
        <h2 class="text-3xl font-extrabold text-gray-800 inline-flex items-center gap-2">
          <ion-icon name="library-outline" class="text-indigo-600 text-2xl"></ion-icon>
          Enroll in a Course
        </h2>
        <a href="student_dashboard.php" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-medium">
          <ion-icon name="arrow-back-outline" class="text-base"></ion-icon>
          Back to Dashboard
        </a>
    </div>

    <?php if ($flash): ?>
      <div class="max-w-3xl mx-auto bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl shadow-sm inline-flex items-center gap-2">
        <ion-icon name="checkmark-circle-outline" class="text-lg"></ion-icon>
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="max-w-3xl mx-auto">
      <div class="relative">
        <input id="searchInput" type="text" placeholder="Search courses by name or description..."
               class="w-full rounded-full bg-white/80 border border-gray-200 px-5 py-3 pl-12 shadow-sm focus:ring-2 focus:ring-indigo-500/40 focus:outline-none" aria-label="Search courses">
        <ion-icon name="search-outline" class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xl"></ion-icon>
      </div>
    </div>

    <!-- Cards Grid -->
    <section class="max-w-6xl mx-auto w-full">
      <?php if ($totalCourses === 0): ?>
        <div class="flex items-center justify-center gap-2 text-gray-600 bg-white/70 border border-gray-100 rounded-2xl p-8 shadow-sm">
          <ion-icon name="information-circle-outline" class="text-2xl text-slate-500"></ion-icon>
          No courses available right now. Please check back later.
        </div>
      <?php else: ?>
        <div id="noResults" class="hidden text-center text-gray-600 bg-white/70 border border-gray-100 rounded-2xl p-6 shadow-sm mb-4">
          <div class="inline-flex items-center gap-2">
            <ion-icon name="search-circle-outline" class="text-2xl text-slate-500"></ion-icon>
            No courses match your search.
          </div>
        </div>

        <div id="coursesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <?php while ($course = $courses->fetch_assoc()):
            $cid   = (int)$course['course_id'];
            $name  = $course['name'] ?? '';
            $desc  = $course['description'] ?? '';
            $price = isset($course['price']) ? (float)$course['price'] : 0.0;
            $en    = $enrollments[$cid] ?? null;
            $status = $en['status'] ?? null;
            $enrollmentId = $en['enrollment_id'] ?? null;

            $searchText = strtolower(($name ?? '') . ' ' . ($desc ?? ''));
          ?>
            <article
              class="course-card card group relative flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white/80 backdrop-blur-sm shadow-sm"
              data-card
              data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">

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
                  <ion-icon name="book-outline" class="text-indigo-600"></ion-icon>
                  <?= htmlspecialchars($name) ?>
                </h3>
                <p class="text-sm text-gray-700 leading-relaxed">
                  <?= htmlspecialchars($desc) ?>
                </p>
              </div>

              <!-- Footer / actions -->
              <div class="px-5 pb-5 pt-4 border-t border-gray-100">
                <div class="flex items-center justify-between gap-3 mb-3">
                  <div class="text-sm inline-flex items-center gap-1">
                    <?php if ($price <= 0): ?>
                      <span class="chip chip-green"><ion-icon name="pricetag-outline" class="text-base"></ion-icon> Free</span>
                    <?php else: ?>
                      <span class="chip chip-indigo"><ion-icon name="cash-outline" class="text-base"></ion-icon> $<?= number_format($price, 2) ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="flex flex-wrap gap-2">
                  <?php if ($status === 'active'): ?>
                    <a href="course.php?course_id=<?= $cid ?>"
                       class="inline-flex items-center justify-center bg-white text-indigo-700 border border-indigo-200 px-3 py-2 rounded hover:bg-indigo-50 transition">
                      <ion-icon name="open-outline" class="text-base mr-1"></ion-icon>
                      View Course
                    </a>
                    <form method="POST" class="inline" onsubmit="return confirm('Remove this enrollment?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit" name="remove"
                              class="inline-flex items-center bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600 transition">
                        <ion-icon name="trash-outline" class="text-base mr-1"></ion-icon>
                        Remove
                      </button>
                    </form>

                  <?php elseif ($status === 'pending'): ?>
                    <?php if ($enrollmentId): ?>
                      <a href="make_payment.php?course_id=<?= $cid ?>&enrollment_id=<?= $enrollmentId ?>"
                         class="inline-flex items-center justify-center bg-indigo-600 text-white px-3 py-2 rounded hover:bg-indigo-700 transition">
                        <ion-icon name="card-outline" class="text-base mr-1"></ion-icon>
                        Pay Now
                      </a>
                    <?php endif; ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Cancel pending enrollment?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit" name="cancel"
                              class="inline-flex items-center bg-yellow-500 text-white px-3 py-2 rounded hover:bg-yellow-600 transition">
                        <ion-icon name="close-circle-outline" class="text-base mr-1"></ion-icon>
                        Cancel
                      </button>
                    </form>
                    <form method="POST" class="inline" onsubmit="return confirm('Remove this enrollment record?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit" name="remove"
                              class="inline-flex items-center bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600 transition">
                        <ion-icon name="trash-outline" class="text-base mr-1"></ion-icon>
                        Remove
                      </button>
                    </form>

                  <?php else: ?>
                    <form method="POST" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="course_id" value="<?= $cid ?>">
                      <button type="submit"
                              class="inline-flex items-center bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 transition">
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
  // Initial (in case of prefilled search by browser)
  applyFilter();
</script>
</body>
</html>