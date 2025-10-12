<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

// Helper
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- Student Progress Data for Chart ---
$student_progress_chart = $conn->query("
    SELECT s.first_name, s.last_name, sp.chapters_completed, sp.course_id
    FROM student_progress sp
    JOIN students s ON sp.student_id = s.student_id
");

$student_names = [];
$student_percents = [];
while ($row = $student_progress_chart->fetch_assoc()) {
    $course_id = (int)$row['course_id'];
    $totalRow = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id = $course_id");
    $total_chapters = $totalRow ? (int)$totalRow->fetch_assoc()['total'] : 0;
    $completed = (int)$row['chapters_completed'];
    $percent = ($total_chapters > 0) ? round(($completed / $total_chapters) * 100, 1) : 0;
    $student_names[] = $row['first_name'] . ' ' . $row['last_name'];
    $student_percents[] = $percent;
}

// --- Teacher Contributions Data for Chart ---
$teacher_chart = $conn->query("SELECT t.teacher_id, t.first_name, t.last_name FROM teachers t");
$teacher_names = [];
$teacher_contents = [];
while ($teacher = $teacher_chart->fetch_assoc()) {
    $tid = (int)$teacher['teacher_id'];
    $coursesRes = $conn->query("
        SELECT c.course_id 
        FROM teacher_courses tc 
        JOIN courses c ON tc.course_id = c.course_id 
        WHERE tc.teacher_id = $tid
    ");
    $course_ids = [];
    if ($coursesRes) {
        while ($c = $coursesRes->fetch_assoc()) {
            $course_ids[] = (int)$c['course_id'];
        }
    }
    $content_count = 0;
    if (!empty($course_ids)) {
        $ids = implode(",", $course_ids);
        $totalC = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id IN ($ids)");
        $content_count = $totalC ? (int)$totalC->fetch_assoc()['total'] : 0;
    }
    $teacher_names[] = $teacher['first_name'] . ' ' . $teacher['last_name'];
    $teacher_contents[] = $content_count;
}

// --- Stats ---
$studentCount = count($student_names);
$avgProgress  = $studentCount ? round(array_sum($student_percents) / $studentCount, 1) : 0;
$topStudent   = $studentCount ? $student_names[array_keys($student_percents, max($student_percents))[0]] : '—';
$topPercent   = $studentCount ? max($student_percents) : 0;

$teacherCount = count($teacher_names);
$totalContent = array_sum($teacher_contents);
$topTeacher   = $teacherCount ? $teacher_names[array_keys($teacher_contents, max($teacher_contents))[0]] : '—';
$topContent   = $teacherCount ? max($teacher_contents) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>📊 Progress Reports - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- html2pdf -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    <style>
      html { scroll-behavior: smooth; }
      .card { @apply bg-white p-6 rounded-xl shadow ring-1 ring-gray-200; }
      .badge { @apply inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 text-gray-800 font-sans min-h-screen">
  <div class="max-w-7xl mx-auto px-6 py-8">
    <!-- Header / Hero -->
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-indigo-600 to-sky-500 text-white p-6 shadow">
      <div class="relative z-10 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h2 class="text-2xl md:text-3xl font-extrabold flex items-center gap-3">
            <i class="fa-solid fa-chart-column"></i> Progress Overview
          </h2>
          <p class="text-white/90">Students & Teachers insights at a glance.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <a href="admin_dashboard.php" class="inline-flex items-center gap-2 bg-white/10 ring-1 ring-white/20 px-4 py-2 rounded-lg hover:bg-white/20">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
          </a>
          <button onclick="downloadPDF()" class="inline-flex items-center gap-2 bg-white text-indigo-700 px-4 py-2 rounded-lg shadow hover:bg-indigo-50">
            <i class="fa-solid fa-file-pdf"></i> Download Report (PDF)
          </button>
        </div>
      </div>

      <!-- Stats -->
      <div class="relative z-10 grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
        <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
          <div class="text-xs text-white/80 flex items-center gap-2"><i class="fa-regular fa-user"></i> Students Tracked</div>
          <div class="mt-1 text-2xl font-bold"><?= (int)$studentCount ?></div>
        </div>
        <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
          <div class="text-xs text-white/80 flex items-center gap-2"><i class="fa-solid fa-percent"></i> Avg. Progress</div>
          <div class="mt-1 text-2xl font-bold"><?= e($avgProgress) ?>%</div>
        </div>
        <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
          <div class="text-xs text-white/80 flex items-center gap-2"><i class="fa-solid fa-trophy"></i> Top Student</div>
          <div class="mt-1 text-sm font-semibold leading-tight"><?= e($topStudent) ?> <span class="text-white/80">· <?= e($topPercent) ?>%</span></div>
        </div>
        <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
          <div class="text-xs text-white/80 flex items-center gap-2"><i class="fa-solid fa-chalkboard-user"></i> Teachers · Contents</div>
          <div class="mt-1 text-sm font-semibold leading-tight"><?= (int)$teacherCount ?> <span class="text-white/80">· <?= (int)$totalContent ?></span></div>
          <div class="mt-0.5 text-[11px] text-white/80"><i class="fa-solid fa-star"></i> <?= e($topTeacher) ?> (<?= (int)$topContent ?>)</div>
        </div>
      </div>

      <!-- Blobs -->
      <div aria-hidden="true" class="pointer-events-none absolute -top-16 -right-20 w-72 h-72 bg-white/10 rounded-full blur-3xl"></div>
      <div aria-hidden="true" class="pointer-events-none absolute -bottom-24 -left-24 w-96 h-96 bg-white/10 rounded-full blur-3xl"></div>
    </div>

    <div id="report-content" class="mt-6 space-y-8">
      <!-- Student Progress -->
      <section class="card">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-2xl font-semibold flex items-center gap-3"><i class="fa-solid fa-user-graduate text-indigo-600"></i> Student Progress</h3>
          <a href="#students-table" class="text-indigo-600 hover:text-indigo-700 text-sm inline-flex items-center gap-1">
            Jump to table <i class="fa-solid fa-angles-down"></i>
          </a>
        </div>

        <!-- Chart -->
        <div class="mb-6">
          <div class="max-w-2xl mx-auto">
            <canvas id="studentProgressChart" height="280"></canvas>
          </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto" id="students-table">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-2 text-left font-medium text-gray-600">Student</th>
                <th class="px-4 py-2 text-left font-medium text-gray-600">Course</th>
                <th class="px-4 py-2 text-center font-medium text-gray-600">Completed</th>
                <th class="px-4 py-2 text-center font-medium text-gray-600">Total Lessons</th>
                <th class="px-4 py-2 text-center font-medium text-gray-600">Progress</th>
                <th class="px-4 py-2 text-left font-medium text-gray-600">Last Updated</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
              <?php
              $student_progress = $conn->query("
                  SELECT sp.student_id, sp.course_id, sp.chapters_completed, sp.last_updated,
                         s.first_name, s.last_name, c.name as course_name
                  FROM student_progress sp
                  JOIN students s ON sp.student_id = s.student_id
                  JOIN courses c ON sp.course_id = c.course_id
              ");
              while ($row = $student_progress->fetch_assoc()):
                $course_id = (int)$row['course_id'];
                $totRes = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id = $course_id");
                $total = $totRes ? (int)$totRes->fetch_assoc()['total'] : 0;
                $completed = (int)$row['chapters_completed'];
                $percent = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;
                $pClass = $percent >= 80 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : ($percent >= 50 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-rose-50 text-rose-700 ring-rose-200');
                $pIcon  = $percent >= 80 ? 'fa-circle-check' : ($percent >= 50 ? 'fa-hourglass-half' : 'fa-triangle-exclamation');
              ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                  <span class="inline-flex items-center gap-2">
                    <i class="fa-regular fa-user text-gray-500"></i>
                    <?= e($row['first_name'].' '.$row['last_name']) ?>
                  </span>
                </td>
                <td class="px-4 py-2">
                  <span class="inline-flex items-center gap-2">
                    <i class="fa-solid fa-book text-gray-500"></i>
                    <?= e($row['course_name']) ?>
                  </span>
                </td>
                <td class="px-4 py-2 text-center"><?= (int)$completed ?></td>
                <td class="px-4 py-2 text-center"><?= (int)$total ?></td>
                <td class="px-4 py-2 text-center">
                  <span class="badge ring-1 <?= $pClass ?>">
                    <i class="fa-solid <?= $pIcon ?>"></i> <?= e($percent) ?>%
                  </span>
                </td>
                <td class="px-4 py-2">
                  <span class="inline-flex items-center gap-2">
                    <i class="fa-regular fa-clock text-gray-500"></i> <?= e($row['last_updated']) ?>
                  </span>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Teacher Contributions -->
      <section class="card">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-2xl font-semibold flex items-center gap-3"><i class="fa-solid fa-chalkboard-user text-indigo-600"></i> Teacher Contributions</h3>
          <a href="#teachers-table" class="text-indigo-600 hover:text-indigo-700 text-sm inline-flex items-center gap-1">
            Jump to table <i class="fa-solid fa-angles-down"></i>
          </a>
        </div>

        <!-- Chart -->
        <div class="mb-6">
          <div class="max-w-3xl mx-auto">
            <canvas id="teacherContributionsChart" height="320"></canvas>
          </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto" id="teachers-table">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-2 text-left font-medium text-gray-600">Teacher</th>
                <th class="px-4 py-2 text-center font-medium text-gray-600">Total Courses</th>
                <th class="px-4 py-2 text-center font-medium text-gray-600">Total Contents</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
              <?php
              $teachersRes = $conn->query("SELECT t.teacher_id, t.first_name, t.last_name FROM teachers t");
              while ($teacher = $teachersRes->fetch_assoc()):
                $tid = (int)$teacher['teacher_id'];

                // Courses
                $cRes = $conn->query("
                    SELECT c.course_id 
                    FROM teacher_courses tc 
                    JOIN courses c ON tc.course_id = c.course_id 
                    WHERE tc.teacher_id = $tid
                ");
                $course_ids = [];
                $course_count = 0;
                if ($cRes) {
                    $course_count = $cRes->num_rows;
                    while ($c = $cRes->fetch_assoc()) $course_ids[] = (int)$c['course_id'];
                }

                // Contents
                $content_count = 0;
                if (!empty($course_ids)) {
                    $ids = implode(",", $course_ids);
                    $ct = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id IN ($ids)");
                    $content_count = $ct ? (int)$ct->fetch_assoc()['total'] : 0;
                }
              ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                  <span class="inline-flex items-center gap-2">
                    <i class="fa-regular fa-user text-gray-500"></i>
                    <?= e($teacher['first_name'].' '.$teacher['last_name']) ?>
                  </span>
                </td>
                <td class="px-4 py-2 text-center"><?= (int)$course_count ?></td>
                <td class="px-4 py-2 text-center">
                  <span class="badge ring-1 ring-indigo-200 bg-indigo-50 text-indigo-700">
                    <i class="fa-solid fa-layer-group"></i> <?= (int)$content_count ?>
                  </span>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>

  <!-- JS: Charts + Export -->
  <script>
    // Student Progress Doughnut Chart
    const studentNames = <?php echo json_encode($student_names); ?>;
    const studentPercents = <?php echo json_encode($student_percents); ?>;

    const spCtx = document.getElementById('studentProgressChart').getContext('2d');
    new Chart(spCtx, {
      type: 'doughnut',
      data: {
        labels: studentNames,
        datasets: [{
          label: 'Progress %',
          data: studentPercents,
          backgroundColor: [
            '#3b82f6','#f59e0b','#10b981','#fbbf24','#6366f1','#ef4444','#a21caf','#14b8a6','#22c55e','#eab308'
          ],
          borderWidth: 0
        }]
      },
      options: {
        cutout: '55%',
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: (ctx) => `${ctx.label}: ${ctx.parsed}%`
            }
          },
          title: { display: true, text: 'Student Progress (%)' }
        }
      }
    });

    // Teacher Contributions Bar Chart
    const teacherNames = <?php echo json_encode($teacher_names); ?>;
    const teacherContents = <?php echo json_encode($teacher_contents); ?>;
    const tcCtx = document.getElementById('teacherContributionsChart').getContext('2d');
    new Chart(tcCtx, {
      type: 'bar',
      data: {
        labels: teacherNames,
        datasets: [{
          label: 'Total Contents',
          data: teacherContents,
          backgroundColor: '#6366f1',
          borderRadius: 6
        }]
      },
      options: {
        plugins: {
          legend: { display: false },
          title: { display: true, text: 'Teacher Contributions' },
          tooltip: {
            callbacks: { label: (ctx) => `Contents: ${ctx.parsed.y}` }
          }
        },
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
      }
    });

    // Download PDF (wait a beat to ensure charts rendered)
    function downloadPDF() {
      setTimeout(() => {
        const element = document.getElementById('report-content');
        const opt = {
          margin: 0.2,
          filename: 'progress_report.pdf',
          image: { type: 'jpeg', quality: 0.98 },
          html2canvas: { scale: 2 },
          jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
      }, 300);
    }
  </script>
</body>
</html>