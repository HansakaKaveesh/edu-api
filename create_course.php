<?php session_start(); include 'db_connect.php'; ?>
<?php if ($_SESSION['role'] != 'teacher') exit("Access denied"); ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create New Course</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen flex items-center justify-center">

  <div class="w-full max-w-xl bg-white p-8 rounded shadow">
    <h2 class="text-3xl font-bold mb-6 text-center">➕ Create New Course</h2>

    <?php
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $name = $_POST['name'];
        $desc = $_POST['description'];
        $board = $_POST['board'];
        $level = $_POST['level'];
        $user_id = $_SESSION['user_id'];

        $r = $conn->query("SELECT teacher_id FROM teachers WHERE user_id = $user_id");
        $row = $r->fetch_assoc();
        $teacher_id = $row['teacher_id'];

        $stmt_type = $conn->prepare("INSERT INTO course_types (board, level) VALUES (?, ?)");
        $stmt_type->bind_param("ss", $board, $level);
        $stmt_type->execute();
        $type_id = $conn->insert_id;

        $stmt = $conn->prepare("INSERT INTO courses (name, description, course_type_id) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $name, $desc, $type_id);
        $stmt->execute();
        $course_id = $conn->insert_id;

        $conn->query("INSERT INTO teacher_courses (teacher_id, course_id) VALUES ($teacher_id, $course_id)");

        echo "<p class='text-green-600 font-semibold mb-4'>✅ Course created and assigned.</p>";
    }
    ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block font-semibold mb-1">Course Name:</label>
        <input type="text" name="name" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400" />
      </div>

      <div>
        <label class="block font-semibold mb-1">Description:</label>
        <textarea name="description" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400"></textarea>
      </div>

      <div>
        <label class="block font-semibold mb-1">Board:</label>
        <select name="board" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400">
          <option value="Cambridge">Cambridge</option>
          <option value="Edexcel">Edexcel</option>
          <option value="Local">Local</option>
          <option value="Other">Other</option>
        </select>
      </div>

      <div>
        <label class="block font-semibold mb-1">Level:</label>
        <select name="level" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-blue-400">
          <option value="O/L">O/L</option>
          <option value="A/L">A/L</option>
          <option value="IGCSE">IGCSE</option>
          <option value="Others">Others</option>
        </select>
      </div>

      <div class="text-center">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 shadow">
          ➕ Create Course
        </button>
      </div>
    </form>
  </div>

</body>
</html>
