<?php include 'db_connect.php'; ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Registration</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[url('https://images.unsplash.com/photo-1542744173-8e7e53415bb0?auto=format&fit=crop&w=1470&q=80')] bg-cover bg-center min-h-screen flex items-center justify-center text-gray-800">

  <div class="bg-white bg-opacity-90 shadow-xl rounded-lg p-8 w-full max-w-md backdrop-blur">
    <h2 class="text-2xl font-bold mb-6 text-center">Register an Administrator</h2>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Username</label>
        <input type="text" name="username" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Password</label>
        <input type="password" name="password" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">First Name</label>
        <input type="text" name="first_name" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Last Name</label>
        <input type="text" name="last_name" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email" required class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Contact Number</label>
        <input type="text" name="contact_number" class="w-full border-gray-300 rounded-md shadow-sm px-3 py-2 focus:ring focus:ring-blue-200">
      </div>
      <div>
        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md shadow">
          Register Admin
        </button>
      </div>
    </form>

    <?php
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $first = $_POST['first_name'];
        $last = $_POST['last_name'];
        $email = $_POST['email'];
        $contact = $_POST['contact_number'];

        $stmt = $conn->prepare("INSERT INTO users (username, role) VALUES (?, 'admin')");
        $stmt->bind_param("s", $username);

        if ($stmt->execute()) {
            $user_id = $conn->insert_id;

            $stmt_pwd = $conn->prepare("INSERT INTO passwords (user_id, password_hash) VALUES (?, ?)");
            $stmt_pwd->bind_param("is", $user_id, $password);
            $stmt_pwd->execute();

            $stmt_admin = $conn->prepare("INSERT INTO admins (user_id, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
            $stmt_admin->bind_param("issss", $user_id, $first, $last, $email, $contact);
            $stmt_admin->execute();

            echo "<p class='mt-4 text-green-700 text-center font-medium'>✅ Admin registered successfully!</p>";
        } else {
            echo "<p class='mt-4 text-red-600 text-center font-medium'>⚠️ Error: " . $stmt->error . "</p>";
        }
    }
    ?>
  </div>

</body>
</html>
