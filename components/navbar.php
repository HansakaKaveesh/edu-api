<nav class="bg-blue-600/80 backdrop-blur-md fixed top-4 left-4 right-4 mx-auto rounded-xl z-50 text-white px-6 py-4 shadow-lg transition-colors duration-300 dark:bg-gray-900/80" role="navigation" aria-label="Primary Navigation">
  <div class="relative flex items-center justify-between max-w-7xl mx-auto">
    <!-- Logo -->
    <a href="index.php" class="text-2xl md:text-3xl font-bold tracking-tight hover:scale-105 transition-transform duration-200">
      Synap<span class="text-yellow-300">Z</span>
    </a>

    <!-- Hamburger (mobile) -->
    <div class="md:hidden flex items-center">
      <button id="mobile-toggle" class="text-white focus:outline-none" aria-label="Toggle Menu" aria-expanded="false">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path id="mobile-icon" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>
    </div>

    <!-- Desktop Nav -->
    <ul class="hidden md:flex items-center space-x-6 font-medium text-md">
      <li><a href="index.php" class="flex items-center gap-1 hover:text-yellow-300 transition">Home</a></li>
      <li><a href="#about" class="flex items-center gap-1 hover:text-yellow-300 transition">About Us</a></li>
      <li><a href="#courses" class="flex items-center gap-1 hover:text-yellow-300 transition">Courses</a></li>
      <li><a href="#tutors" class="flex items-center gap-1 hover:text-yellow-300 transition">Tutors</a></li>
      <li><a href="#contact" class="flex items-center gap-1 hover:text-yellow-300 transition">Contact</a></li>
    </ul>

    <!-- Right Section -->
    <div class="hidden md:flex items-center space-x-3">
      <?php if (isset($_SESSION['user_id'])): ?>
        <?php if ($_SESSION['role'] == 'student'): ?>
          <a href="student_dashboard.php"
             class="px-5 py-2 bg-indigo-600 text-white font-semibold rounded-full shadow-md hover:bg-indigo-500 transition">
          Student
        <?php elseif ($_SESSION['role'] == 'teacher'): ?>
          <a href="teacher_dashboard.php"
             class="px-5 py-2 bg-purple-600 text-white font-semibold rounded-full shadow-md hover:bg-purple-500 transition">
          Teacher
          </a>
        <?php elseif ($_SESSION['role'] == 'admin'): ?>
          <a href="admin_dashboard.php"
             class="px-5 py-2 bg-blue-700 text-white font-semibold rounded-full shadow-md hover:bg-blue-600 transition">
            Admin
          </a>
        <?php endif; ?>

        <a href="logout.php"
           class="px-5 py-2 bg-red-600 text-white font-semibold rounded-full shadow-md hover:bg-red-500 transition">
          Logout
        </a>
      <?php else: ?>
        <a href="login.php"
           class="px-5 py-2 bg-yellow-400 text-black font-semibold rounded-full shadow-md hover:bg-yellow-300 transition">
          Login
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Mobile Menu -->
  <div id="mobile-menu" class="hidden md:hidden mt-4 bg-white text-blue-800 rounded-lg shadow-lg p-4 space-y-2">
    <a href="index.php" class="block px-2 py-2 hover:text-yellow-500">Home</a>
    <a href="#about" class="block px-2 py-2 hover:text-yellow-500">About Us</a>
    <a href="#courses" class="block px-2 py-2 hover:text-yellow-500">Courses</a>
    <a href="#tutors" class="block px-2 py-2 hover:text-yellow-500">Tutors</a>
    <a href="#contact" class="block px-2 py-2 hover:text-yellow-500">Contact</a>

    <hr class="my-2" />

    <?php if (isset($_SESSION['user_id'])): ?>
      <?php if ($_SESSION['role'] == 'student'): ?>
        <a href="student_dashboard.php" class="block px-2 py-2 hover:text-yellow-500">🎒 Dashboard</a>
      <?php elseif ($_SESSION['role'] == 'teacher'): ?>
        <a href="teacher_dashboard.php" class="block px-2 py-2 hover:text-yellow-500">📚 Dashboard</a>
      <?php elseif ($_SESSION['role'] == 'admin'): ?>
        <a href="admin_dashboard.php" class="block px-2 py-2 hover:text-yellow-500">🛠️ Dashboard</a>
      <?php endif; ?>
      <a href="logout.php" class="block px-2 py-2 hover:text-yellow-500">🚪 Logout</a>
    <?php else: ?>
      <a href="login.php" class="block px-2 py-2 hover:text-yellow-500">Sign In</a>
      <a href="register.php" class="block px-2 py-2 hover:text-yellow-500">Register</a>
    <?php endif; ?>
  </div>

  <!-- Scripts -->
  <script>
    const mobileToggle = document.getElementById("mobile-toggle");
    const mobileMenu = document.getElementById("mobile-menu");
    const mobileIcon = document.getElementById("mobile-icon");

    mobileToggle.setAttribute("aria-expanded", "false");

    mobileToggle.addEventListener("click", () => {
      mobileMenu.classList.toggle("hidden");
      const expanded = mobileMenu.classList.contains("hidden") ? "false" : "true";
      mobileToggle.setAttribute("aria-expanded", expanded);

      if (mobileMenu.classList.contains("hidden")) {
        mobileIcon.setAttribute("d", "M4 6h16M4 12h16M4 18h16");
      } else {
        mobileIcon.setAttribute("d", "M6 18L18 6M6 6l12 12");
      }
    });
  </script>
</nav>
