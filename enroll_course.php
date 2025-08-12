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
        <h2 class="text-3xl font-extrabold text-gray-800">📚 Enroll in a Course</h2>
        <a href="student_dashboard.php" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-medium">
          ← Back to Dashboard
        </a>
    </div>

    <?php if ($flash): ?>
      <div class="max-w-3xl mx-auto bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl shadow-sm">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="max-w-3xl mx-auto">
      <div class="relative">
        <input id="searchInput" type="text" placeholder="Search courses by name or description..."
               class="w-full rounded-full bg-white/80 border border-gray-200 px-5 py-3 pl-12 shadow-sm focus:ring-2 focus:ring-indigo-500/40 focus:outline-none">
        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">🔎</span>
      </div>
    </div>

    <div class="overflow-x-auto bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-100/80">
      <table class="min-w-full table-auto text-sm">
        <thead class="bg-gradient-to-r from-blue-50 to-indigo-50 text-gray-700 sticky top-0 z-10">
          <tr>
            <th class="px-4 py-3 text-left font-semibold">Name</th>
            <th class="px-4 py-3 text-left font-semibold">Description</th>
            <th class="px-4 py-3 text-right font-semibold">Price</th>
            <th class="px-4 py-3 text-center font-semibold">Status</th>
            <th class="px-4 py-3 text-center font-semibold">Action</th>
          </tr>
        </thead>
        <tbody id="coursesBody" class="divide-y divide-gray-200">
          <?php while ($course = $courses->fetch_assoc()):
            $cid   = (int)$course['course_id'];
            $name  = $course['name'];
            $desc  = $course['description'];
            $price = isset($course['price']) ? (float)$course['price'] : 0.0;
            $en    = $enrollments[$cid] ?? null;
            $status = $en['status'] ?? null;
            $enrollmentId = $en['enrollment_id'] ?? null;
          ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($name) ?></td>
            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($desc) ?></td>
            <td class="px-4 py-3 text-right">
              <?php if ($price <= 0): ?>
                <span class="text-green-600 font-semibold">Free</span>
              <?php else: ?>
                <span class="font-semibold text-indigo-700">$<?= number_format($price, 2) ?></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-center">
              <?php if ($status === 'active'): ?>
                <span class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 border border-green-200">Active</span>
              <?php elseif ($status === 'pending'): ?>
                <span class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold bg-yellow-50 text-yellow-700 border border-yellow-200">Pending</span>
              <?php else: ?>
                <span class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-50 text-gray-600 border border-gray-200">Not enrolled</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <div class="flex flex-col sm:flex-row justify-center items-center gap-2">
                <?php if ($status === 'active'): ?>
                  <a href="course.php?course_id=<?= $cid ?>" class="w-full sm:w-auto inline-flex items-center justify-center bg-white text-indigo-700 border border-indigo-200 px-3 py-2 rounded hover:bg-indigo-50 transition">
                    View Course
                  </a>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="course_id" value="<?= $cid ?>">
                    <button type="submit" name="remove" class="w-full sm:w-auto bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600 transition">
                      Remove
                    </button>
                  </form>

                <?php elseif ($status === 'pending'): ?>
                  <?php if ($enrollmentId): ?>
                    <a href="make_payment.php?course_id=<?= $cid ?>&enrollment_id=<?= $enrollmentId ?>"
                       class="w-full sm:w-auto inline-flex items-center justify-center bg-indigo-600 text-white px-3 py-2 rounded hover:bg-indigo-700 transition">
                      Pay Now
                    </a>
                  <?php endif; ?>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="course_id" value="<?= $cid ?>">
                    <button type="submit" name="cancel" class="w-full sm:w-auto bg-yellow-500 text-white px-3 py-2 rounded hover:bg-yellow-600 transition">
                      Cancel
                    </button>
                  </form>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="course_id" value="<?= $cid ?>">
                    <button type="submit" name="remove" class="w-full sm:w-auto bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600 transition">
                      Remove
                    </button>
                  </form>

                <?php else: ?>
                  <form method="POST" class="flex justify-center">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="course_id" value="<?= $cid ?>">
                    <button type="submit"
                      class="w-full sm:w-auto bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 transition">
                      <?= $price > 0 ? 'Enroll & Pay' : 'Enroll (Free)' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

<?php include 'components/footer.php'; ?>

<script>
  // Client-side search (filters by name + description)
  const input = document.getElementById('searchInput');
  const tbody = document.getElementById('coursesBody');

  input?.addEventListener('input', () => {
    const q = input.value.toLowerCase().trim();
    for (const row of tbody.rows) {
      const name = row.cells[0]?.innerText.toLowerCase() || '';
      const desc = row.cells[1]?.innerText.toLowerCase() || '';
      row.style.display = (name.includes(q) || desc.includes(q)) ? '' : 'none';
    }
  });
</script>
</body>
</html>