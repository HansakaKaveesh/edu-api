<?php include 'db_connect.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register - SynapZ</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4"
      style="background-image: url('https://glassoflearning.com/wp-content/uploads/2022/03/virtual-learning-environment-scaled.jpg'); background-size: cover; background-position: center;">
  
  <?php include 'components/navbar.php'; ?>

  <div class="bg-white/90 backdrop-blur-md rounded-lg shadow-xl p-8 w-full max-w-lg mt-16">
    <h2 class="text-3xl font-bold text-center mb-6 text-blue-800">📝 Register to SynapZ</h2>

    <form method="POST" class="space-y-5">

      <!-- Role -->
      <div>
        <label for="role" class="block mb-1 font-medium text-gray-700">Select Role:</label>
        <select name="role" id="role" required class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
          <option value="">-- Select --</option>
          <option value="student">Student</option>
          <option value="teacher">Teacher</option>
        </select>
      </div>

      <!-- Username -->
      <div>
        <label for="username" class="block mb-1 font-medium text-gray-700">Username:</label>
        <input type="text" id="username" name="username" required
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
      </div>

      <!-- Password -->
      <div>
        <label for="password" class="block mb-1 font-medium text-gray-700">Password:</label>
        <input type="password" id="password" name="password" required
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
      </div>

      <!-- First Name -->
      <div>
        <label for="first_name" class="block mb-1 font-medium text-gray-700">First Name:</label>
        <input type="text" id="first_name" name="first_name" required
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
      </div>

      <!-- Last Name -->
      <div>
        <label for="last_name" class="block mb-1 font-medium text-gray-700">Last Name:</label>
        <input type="text" id="last_name" name="last_name" required
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
      </div>

      <!-- Email -->
      <div>
        <label for="email" class="block mb-1 font-medium text-gray-700">Email:</label>
        <input type="email" id="email" name="email" required
          class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
      </div>

      <!-- Student Only Fields -->
      <div id="studentFields" class="hidden space-y-4">
        <div>
          <label for="dob" class="block mb-1 font-medium text-gray-700">Date of Birth:</label>
          <input type="date" id="dob" name="dob"
            class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
        </div>
        <div>
          <label for="contact_number" class="block mb-1 font-medium text-gray-700">Contact Number:</label>
          <input type="text" id="contact_number" name="contact_number"
            class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400" />
        </div>
      </div>

      <!-- Submit Button -->
      <div>
        <button type="submit" name="register"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded shadow transition">
          ✅ Register
        </button>
      </div>

      <!-- Login link -->
      <div class="text-center mt-4">
        <a href="login.php" class="text-blue-600 hover:underline">🔐 Already have an account? Login</a>
      </div>
    </form>

    <!-- PHP Registration Logic -->
    <?php
    if (isset($_POST['register'])) {
        $role = $_POST['role'];
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $first = $_POST['first_name'];
        $last = $_POST['last_name'];
        $email = $_POST['email'];

        $stmtUser = $conn->prepare("INSERT INTO users (username, role) VALUES (?, ?)");
        $stmtUser->bind_param("ss", $username, $role);

        if ($stmtUser->execute()) {
            $user_id = $conn->insert_id;

            $stmtPwd = $conn->prepare("INSERT INTO passwords (user_id, password_hash, is_current) VALUES (?, ?, 1)");
            $stmtPwd->bind_param("is", $user_id, $password);
            $stmtPwd->execute();

            if ($role === 'student') {
                $dob = !empty($_POST['dob']) ? $_POST['dob'] : NULL;
                $contact = !empty($_POST['contact_number']) ? $_POST['contact_number'] : NULL;

                $stmtStudent = $conn->prepare("INSERT INTO students (user_id, first_name, last_name, dob, email, contact_number)
                                               VALUES (?, ?, ?, ?, ?, ?)");
                $stmtStudent->bind_param("isssss", $user_id, $first, $last, $dob, $email, $contact);
                $stmtStudent->execute();

                echo "<p class='mt-4 text-green-600 font-semibold text-center'>🎉 Student registered successfully.</p>";

            } elseif ($role === 'teacher') {
                $stmtTeacher = $conn->prepare("INSERT INTO teachers (user_id, first_name, last_name, email)
                                               VALUES (?, ?, ?, ?)");
                $stmtTeacher->bind_param("isss", $user_id, $first, $last, $email);
                $stmtTeacher->execute();

                echo "<p class='mt-4 text-green-600 font-semibold text-center'>🎉 Teacher registered successfully.</p>";
            }
        } else {
            echo "<p class='mt-4 text-red-600 font-semibold text-center'>⛔ Username already exists or an error occurred!</p>";
        }
    }
    ?>
  </div>

  <!-- Script to toggle student-only fields -->
  <script>
    const roleSelect = document.getElementById('role');
    const studentFields = document.getElementById('studentFields');

    function toggleFields() {
      studentFields.classList.toggle('hidden', roleSelect.value !== 'student');
    }

    roleSelect.addEventListener('change', toggleFields);
    toggleFields();
  </script>
</body>
</html>
