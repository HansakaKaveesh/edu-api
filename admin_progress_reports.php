<?php
session_start();
include 'db_connect.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

// --- Student Progress Data for Chart ---
$student_progress_chart = $conn->query("
    SELECT s.first_name, s.last_name, sp.chapters_completed, sp.course_id
    FROM student_progress sp
    JOIN students s ON sp.student_id = s.student_id
");
$student_names = [];
$student_percents = [];
while ($row = $student_progress_chart->fetch_assoc()) {
    $course_id = $row['course_id'];
    $total_chapters = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id = $course_id")->fetch_assoc()['total'];
    $completed = $row['chapters_completed'];
    $percent = ($total_chapters > 0) ? round(($completed / $total_chapters) * 100, 1) : 0;
    $student_names[] = $row['first_name'] . ' ' . $row['last_name'];
    $student_percents[] = $percent;
}

// --- Teacher Contributions Data for Chart ---
$teacher_chart = $conn->query("SELECT t.teacher_id, t.first_name, t.last_name FROM teachers t");
$teacher_names = [];
$teacher_contents = [];
while ($teacher = $teacher_chart->fetch_assoc()) {
    $tid = $teacher['teacher_id'];
    $courses = $conn->query("SELECT c.course_id FROM teacher_courses tc JOIN courses c ON tc.course_id = c.course_id WHERE tc.teacher_id = $tid");
    $course_ids = [];
    while ($c = $courses->fetch_assoc()) {
        $course_ids[] = $c['course_id'];
    }
    $content_count = 0;
    if (!empty($course_ids)) {
        $ids = implode(",", $course_ids);
        $content_count = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id IN ($ids)")->fetch_assoc()['total'];
    }
    $teacher_names[] = $teacher['first_name'] . ' ' . $teacher['last_name'];
    $teacher_contents[] = $content_count;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>📊 Progress Reports - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="bg-gray-100 text-gray-800 font-sans">

    <div class="max-w-7xl mx-auto p-6">
        <h2 class="text-3xl font-bold mb-4">📊 Progress Overview (Students & Teachers)</h2>
        <a href="admin_dashboard.php" class="inline-block mb-6 text-blue-600 hover:underline">⬅ Back to Admin Dashboard</a>
        <button
          onclick="downloadPDF()"
          class="mb-6 ml-4 px-5 py-2 bg-green-600 text-white font-semibold rounded-full shadow-md hover:bg-green-500 transition"
        >
          ⬇️ Download Report as PDF
        </button>

        <div id="report-content">
            <div class="bg-white p-6 rounded-lg shadow mb-10">
                <h3 class="text-2xl font-semibold mb-4">🎓 Student Progress</h3>
                
                <!-- Student Progress Chart (smaller) -->
                <div class="mb-8 flex justify-center max-w-xs mx-auto">
                    <canvas id="studentProgressChart" width="200" height="120"></canvas>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Student</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Course</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-600">Completed</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-600">Total Lessons</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-600">Progress %</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Last Updated</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        $student_progress = $conn->query("
                            SELECT sp.student_id, sp.course_id, sp.chapters_completed, sp.last_updated,
                                   s.first_name, s.last_name, c.name as course_name
                            FROM student_progress sp
                            JOIN students s ON sp.student_id = s.student_id
                            JOIN courses c ON sp.course_id = c.course_id
                        ");

                        while ($row = $student_progress->fetch_assoc()) {
                            $course_id = $row['course_id'];
                            $total_chapters = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id = $course_id")->fetch_assoc()['total'];
                            $completed = $row['chapters_completed'];
                            $percent = ($total_chapters > 0) ? round(($completed / $total_chapters) * 100, 1) : 0;

                            echo "<tr>
                                <td class='px-4 py-2'>{$row['first_name']} {$row['last_name']}</td>
                                <td class='px-4 py-2'>{$row['course_name']}</td>
                                <td class='px-4 py-2 text-center'>$completed</td>
                                <td class='px-4 py-2 text-center'>$total_chapters</td>
                                <td class='px-4 py-2 text-center font-semibold text-blue-600'>$percent%</td>
                                <td class='px-4 py-2'>{$row['last_updated']}</td>
                            </tr>";
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow">
                <h3 class="text-2xl font-semibold mb-4">👩‍🏫 Teacher Contributions</h3>
                
                <!-- Teacher Contributions Chart (smaller) -->
                <div class="mb-8 flex justify-center max-w-xs mx-auto">
                    <canvas id="teacherContributionsChart" width="200" height="120"></canvas>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Teacher</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-600">Total Courses</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-600">Total Contents</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        $teachers = $conn->query("SELECT t.teacher_id, t.first_name, t.last_name FROM teachers t");

                        while ($teacher = $teachers->fetch_assoc()) {
                            $tid = $teacher['teacher_id'];

                            // Courses by teacher
                            $courses = $conn->query("SELECT c.course_id FROM teacher_courses tc JOIN courses c ON tc.course_id = c.course_id WHERE tc.teacher_id = $tid");
                            $course_count = $courses->num_rows;

                            // Count contents in teacher's courses
                            $course_ids = [];
                            while ($c = $courses->fetch_assoc()) {
                                $course_ids[] = $c['course_id'];
                            }

                            $content_count = 0;
                            if (!empty($course_ids)) {
                                $ids = implode(",", $course_ids);
                                $content_count = $conn->query("SELECT COUNT(*) as total FROM contents WHERE course_id IN ($ids)")->fetch_assoc()['total'];
                            }

                            echo "<tr>
                                <td class='px-4 py-2'>{$teacher['first_name']} {$teacher['last_name']}</td>
                                <td class='px-4 py-2 text-center'>$course_count</td>
                                <td class='px-4 py-2 text-center'>$content_count</td>
                            </tr>";
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js Scripts -->
    <script>
      // Student Progress Chart Data
      const studentNames = <?php echo json_encode($student_names); ?>;
      const studentPercents = <?php echo json_encode($student_percents); ?>;

      // Teacher Contributions Chart Data
      const teacherNames = <?php echo json_encode($teacher_names); ?>;
      const teacherContents = <?php echo json_encode($teacher_contents); ?>;

      // Student Progress Doughnut Chart
      new Chart(document.getElementById('studentProgressChart'), {
        type: 'doughnut',
        data: {
          labels: studentNames,
          datasets: [{
            label: 'Progress %',
            data: studentPercents,
            backgroundColor: [
              '#3b82f6', '#f59e42', '#10b981', '#fbbf24', '#6366f1', '#ef4444', '#a21caf', '#14b8a6'
            ],
            borderWidth: 1
          }]
        },
        options: {
          plugins: {
            title: {
              display: true,
              text: 'Student Progress (%)'
            },
            legend: {
              position: 'bottom'
            }
          }
        }
      });

      // Teacher Contributions Bar Chart
      new Chart(document.getElementById('teacherContributionsChart'), {
        type: 'bar',
        data: {
          labels: teacherNames,
          datasets: [{
            label: 'Total Contents',
            data: teacherContents,
            backgroundColor: '#6366f1'
          }]
        },
        options: {
          plugins: {
            title: {
              display: true,
              text: 'Teacher Contributions'
            },
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { stepSize: 1 }
            }
          }
        }
      });

      // Download PDF function
      function downloadPDF() {
        // Wait for charts to finish rendering
        setTimeout(function() {
          const element = document.getElementById('report-content');
          const opt = {
            margin:       0.2,
            filename:     'progress_report.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2 },
            jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
          };
          html2pdf().set(opt).from(element).save();
        }, 500); // 0.5s delay to ensure charts are rendered
      }
    </script>
</body>
</html>