<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SynapZ - Welcome</title>
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Favicon -->
  <link rel="icon" type="image/png" href="./images/logo.png" />
</head>

<body class="bg-white text-gray-800 flex flex-col min-h-screen overflow-x-hidden">
  
  <!-- Navbar -->
  <?php include 'components/navbar.php'; ?>

<!-- Hero Section -->
<section class="relative bg-[url('./images/hero-bg.jpg')] bg-cover bg-center min-h-screen flex items-center justify-center text-center overflow-hidden">
  <!-- Semi-transparent overlay -->
  <div class="absolute inset-0 bg-gradient-to-br from-slate-900 via-blue-900 to-cyan-800 opacity-50"></div>

  <!-- Hero Content -->
  <div class="relative z-10 max-w-4xl px-6">
    
    <!-- Logo -->
    <div class="mb-6 flex justify-center">
      <img src="./images/logo.png" alt="SynapZ Logo" class="h-20 w-auto sm:h-24 md:h-28 drop-shadow-lg">
    </div>

    <!-- Title -->
    <h1 class="text-4xl sm:text-5xl md:text-6xl font-extrabold block bg-gradient-to-r from-yellow-400 to-yellow-500 text-transparent bg-clip-text">
      Welcome to <span class="text-white">Synap<span class="text-yellow-300">Z</span></span>
    </h1>

    <!-- Subtext -->
    <p class="text-lg sm:text-xl text-white mb-8 mt-4">
      Your complete <span class="font-semibold text-purple-100">Virtual Learning Environment (VLE)</span> for 
      <span class="font-medium text-blue-500">students</span>, and  
      <span class="font-medium text-green-500">teachers</span>,
    </p>

    <!-- Buttons -->
    <div class="flex justify-center flex-wrap gap-4 mb-8">
      <a href="login.php" class="bg-blue-600 text-white px-6 py-3 rounded-lg shadow hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
        🔐 Login
      </a>
      <a href="register.php" class="bg-green-600 text-white px-6 py-3 rounded-lg shadow hover:bg-green-700 focus:ring-4 focus:ring-green-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
        📝 Register
      </a>
    </div>


    <!-- Social Media Icons -->
    <div class="flex justify-center space-x-6 text-white text-2xl">
      <!-- Facebook -->
      <a href="https://facebook.com" target="_blank" aria-label="Facebook" class="hover:text-blue-600 transition">
        <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true">
          <path d="M22 12a10 10 0 1 0-11.5 9.8v-6.9h-2.7v-2.9h2.7v-2.2c0-2.7 1.6-4.1 4-4.1 1.2 0 2.4.2 2.4.2v2.6h-1.3c-1.3 0-1.7.8-1.7 1.6v1.9h2.9l-.5 2.9h-2.4v6.9A10 10 0 0 0 22 12z"/>
        </svg>
      </a>
      <!-- Twitter -->
      <a href="https://twitter.com" target="_blank" aria-label="Twitter" class="hover:text-blue-400 transition">
        <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true">
          <path d="M23 3a10.9 10.9 0 0 1-3.14.86A5.48 5.48 0 0 0 23 1.6a10.86 10.86 0 0 1-3.46 1.32A5.44 5.44 0 0 0 12 8.48a15.43 15.43 0 0 1-11-5.32A5.44 5.44 0 0 0 4.17 8.1 5.4 5.4 0 0 1 2.18 7.8v.07a5.44 5.44 0 0 0 4.36 5.33A5.5 5.5 0 0 1 3 13.1a5.41 5.41 0 0 1-1.03-.1 5.44 5.44 0 0 0 5.07 3.77A10.9 10.9 0 0 1 1 19.54a15.37 15.37 0 0 0 8.29 2.43c9.94 0 15.38-8.24 15.38-15.38 0-.23 0-.46-.02-.69A11 11 0 0 0 23 3z"/>
        </svg>
      </a>
      <!-- Instagram -->
      <a href="https://instagram.com" target="_blank" aria-label="Instagram" class="hover:text-pink-500 transition">
        <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true">
          <path d="M7.75 2h8.5A5.75 5.75 0 0 1 22 7.75v8.5A5.75 5.75 0 0 1 16.25 22h-8.5A5.75 5.75 0 0 1 2 16.25v-8.5A5.75 5.75 0 0 1 7.75 2zm0 2A3.75 3.75 0 0 0 4 7.75v8.5A3.75 3.75 0 0 0 7.75 20h8.5a3.75 3.75 0 0 0 3.75-3.75v-8.5A3.75 3.75 0 0 0 16.25 4h-8.5zm8.75 1.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm-4.25 2.25a4.75 4.75 0 1 1 0 9.5 4.75 4.75 0 0 1 0-9.5zm0 2a2.75 2.75 0 1 0 0 5.5 2.75 2.75 0 0 0 0-5.5z"/>
        </svg>
      </a>
      <!-- LinkedIn -->
      <a href="https://linkedin.com" target="_blank" aria-label="LinkedIn" class="hover:text-blue-700 transition">
        <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true">
          <path d="M4.98 3.5A2.48 2.48 0 0 0 2.5 5.98v12.04a2.48 2.48 0 0 0 2.48 2.48h12.04a2.48 2.48 0 0 0 2.48-2.48V5.98a2.48 2.48 0 0 0-2.48-2.48H4.98zm3.24 14.5H6.17v-7.5h1.97v7.5zM7.14 9.48a1.13 1.13 0 1 1 0-2.26 1.13 1.13 0 0 1 0 2.26zm8.44 8.52h-1.97v-4c0-1.06-.02-2.42-1.47-2.42-1.47 0-1.7 1.15-1.7 2.34v4.08h-1.97v-7.5h1.89v1.02h.03a2.08 2.08 0 0 1 1.88-1.03c2.01 0 2.38 1.32 2.38 3.04v4.47z"/>
        </svg>
      </a>
    </div>
  </div>
</section>


<!-- Modern About Our Platform Section -->
<section id="about" class="py-24 bg-gradient-to-br from-white via-blue-50 to-white">
  <div class="max-w-6xl mx-auto px-6 text-center">
    <div class="mb-8">
      <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-800 mb-4">
        About <span class="text-blue-600">EduPortal</span>
      </h2>
      <div class="w-20 h-1 mx-auto bg-blue-600 rounded mb-4" aria-hidden="true"></div>
      <p class="text-gray-600 text-base sm:text-lg max-w-3xl mx-auto leading-relaxed">
        EduPortal is a cutting-edge Learning Management System designed for schools, institutions, and universities. 
        We bridge the gap between students, teachers, and administrators through a seamless, collaborative digital environment.
      </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8 mt-12">
      <article class="bg-white p-6 rounded-2xl shadow-md hover:shadow-lg transition duration-300" role="region" aria-labelledby="students-title">
        <div class="text-blue-600 text-5xl mb-4" aria-hidden="true">📚</div>
        <h3 id="students-title" class="text-xl font-semibold mb-2">For Students</h3>
        <p class="text-gray-600 text-sm sm:text-base">
          Access learning materials, submit assignments, and collaborate with peers in real time.
        </p>
      </article>

      <article class="bg-white p-6 rounded-2xl shadow-md hover:shadow-lg transition duration-300" role="region" aria-labelledby="teachers-title">
        <div class="text-green-500 text-5xl mb-4" aria-hidden="true">👩‍🏫</div>
        <h3 id="teachers-title" class="text-xl font-semibold mb-2">For Teachers</h3>
        <p class="text-gray-600 text-sm sm:text-base">
          Manage classes, track progress, and communicate effectively with your students.
        </p>
      </article>

      <article class="bg-white p-6 rounded-2xl shadow-md hover:shadow-lg transition duration-300" role="region" aria-labelledby="admins-title">
        <div class="text-yellow-500 text-5xl mb-4" aria-hidden="true">🛠️</div>
        <h3 id="admins-title" class="text-xl font-semibold mb-2">For Admins</h3>
        <p class="text-gray-600 text-sm sm:text-base">
          Oversee system-wide operations, generate reports, and ensure smooth educational management.
        </p>
      </article>
    </div>
  </div>
</section>



<!-- Unlock Learning with Expert Tutors -->
<section id="tutors" class="py-24 bg-gradient-to-br from-blue-50 to-white">
  <div class="max-w-6xl mx-auto px-6 text-center">
    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-800 mb-4">
      Unlock Learning with <span class="text-blue-600">Expert Tutors</span>
    </h2>
    <p class="text-gray-600 text-base sm:text-lg mb-12 max-w-3xl mx-auto leading-relaxed">
      Our experienced educators provide personalized support and innovative teaching to help you excel.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8">
      <!-- Card 1 -->
      <article class="bg-white p-8 rounded-2xl shadow hover:shadow-lg transition duration-300 text-left" role="region" aria-labelledby="live-sessions-title">
        <div class="text-blue-500 text-5xl mb-4" aria-hidden="true">🎥</div>
        <h3 id="live-sessions-title" class="font-semibold text-xl mb-2">Live Sessions</h3>
        <p class="text-gray-600 text-sm sm:text-base">
          Interactive classes to ask questions and clear doubts in real-time.
        </p>
      </article>

      <!-- Card 2 -->
      <article class="bg-white p-8 rounded-2xl shadow hover:shadow-lg transition duration-300 text-left" role="region" aria-labelledby="expert-guidance-title">
        <div class="text-green-500 text-5xl mb-4" aria-hidden="true">🎓</div>
        <h3 id="expert-guidance-title" class="font-semibold text-xl mb-2">Expert Guidance</h3>
        <p class="text-gray-600 text-sm sm:text-base">
          Learn from certified professionals with real teaching experience.
        </p>
      </article>

      <!-- Card 3 -->
      <article class="bg-white p-8 rounded-2xl shadow hover:shadow-lg transition duration-300 text-left" role="region" aria-labelledby="progress-tracking-title">
        <div class="text-yellow-500 text-5xl mb-4" aria-hidden="true">📊</div>
        <h3 id="progress-tracking-title" class="font-semibold text-xl mb-2">Progress Tracking</h3>
        <p class="text-gray-600 text-sm sm:text-base">
          Stay on top of your learning goals with our built-in performance monitoring tools.
        </p>
      </article>
    </div>
  </div>
</section>


<!-- Explore Our Courses with Images -->
<section id="courses" class="py-24 bg-gradient-to-br from-white via-blue-50 to-white">
  <div class="max-w-6xl mx-auto px-6 text-center">
    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-800 mb-4">
      Explore Our <span class="text-blue-600">Courses</span>
    </h2>
    <p class="text-gray-600 text-base sm:text-lg mb-10 max-w-3xl mx-auto leading-relaxed">
      Choose from a variety of ICT and Computer Science subjects tailored for IGCSE and IAL (AS & AS2) levels.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-8">
      <!-- IGCSE ICT -->
      <article class="bg-white rounded-2xl shadow hover:shadow-lg transition duration-300 overflow-hidden text-left" role="region" aria-labelledby="igcse-ict-title">
        <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRFRObkO8H_uYDj0uuGJ1vlSPl4i-qFHG92YQ&s" alt="IGCSE ICT" class="w-full h-48 object-cover">
        <div class="p-5">
          <h3 id="igcse-ict-title" class="font-bold text-xl mb-2">IGCSE ICT</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Practical and theoretical modules aligned with Cambridge standards.
          </p>
        </div>
      </article>

      <!-- IAL AS ICT -->
      <article class="bg-white rounded-2xl shadow hover:shadow-lg transition duration-300 overflow-hidden text-left" role="region" aria-labelledby="ial-as-ict-title">
        <img src="https://aotscolombiajapon.com/wp-content/uploads/2025/01/3ra-Beca-IA-Utilizing-to-overcome-DX-related-1.jpg" alt="IAL AS ICT" class="w-full h-48 object-cover">
        <div class="p-5">
          <h3 id="ial-as-ict-title" class="font-bold text-xl mb-2">IAL AS ICT</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Build foundational knowledge in ICT systems and data handling.
          </p>
        </div>
      </article>

      <!-- IAL AS2 ICT -->
      <article class="bg-white rounded-2xl shadow hover:shadow-lg transition duration-300 overflow-hidden text-left" role="region" aria-labelledby="ial-as2-ict-title">
        <img src="https://www.ict.eu/sites/corporate/files/images/iStock-1322517295%20copy_3.jpg" alt="IAL AS2 ICT" class="w-full h-48 object-cover">
        <div class="p-5">
          <h3 id="ial-as2-ict-title" class="font-bold text-xl mb-2">IAL AS2 ICT</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Advance your ICT skills with real-world problem solving and analysis.
          </p>
        </div>
      </article>

      <!-- IGCSE Computer Science -->
      <article class="bg-white rounded-2xl shadow hover:shadow-lg transition duration-300 overflow-hidden text-left" role="region" aria-labelledby="igcse-cs-title">
        <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTiX_sE8HNgliGkDZNJaestGinmoLUp1ab5Eg&s" alt="IGCSE Computer Science" class="w-full h-48 object-cover">
        <div class="p-5">
          <h3 id="igcse-cs-title" class="font-bold text-xl mb-2">IGCSE Computer Science</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Dive into algorithms, coding, and computer systems design.
          </p>
        </div>
      </article>
    </div>
  </div>
</section>



<!-- Meet Our Educators -->
<section class="py-20 bg-gradient-to-b from-blue-50 to-white" aria-label="Meet Our Educators">
  <div class="max-w-6xl mx-auto px-4 text-center">
    <h2 class="text-4xl font-bold mb-4 text-blue-900">Meet Our Educators</h2>
    <p class="text-gray-600 text-lg mb-10 max-w-3xl mx-auto">
      Learn from qualified and passionate professionals committed to your success.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8">
      <!-- Educator Card -->
      <article class="bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition duration-300" role="region" aria-labelledby="edu-ruwan">
        <img src="./images/tanjana-sir-image-1.png" alt="Mr. Tanjana Chamikara" class="w-24 h-24 mx-auto rounded-full border-4 border-blue-200 mb-4" />
        <h3 id="edu-ruwan" class="font-semibold text-xl text-blue-800">Mr. Tanjana Chamikara</h3>
        <span class="inline-block mt-2 px-3 py-1 bg-blue-100 text-blue-700 text-sm rounded-full">
          Senior Mathematics Instructor
        </span>
        <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
          <li>Pure Mathematics</li>
          <li>Applied Mathematics</li>
          <li>Statistics</li>
        </ul>
      </article>

      <!-- Educator Card -->
      <article class="bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition duration-300" role="region" aria-labelledby="edu-thilini">
        <img src="./images/madara-miss-image-600-2-1.png" alt="Ms. Madhara Wedhage" class="w-24 h-24 mx-auto rounded-full border-4 border-green-200 mb-4" />
        <h3 id="edu-thilini" class="font-semibold text-xl text-green-800">Ms. Madhara Wedhage</h3>
        <span class="inline-block mt-2 px-3 py-1 bg-green-100 text-green-700 text-sm rounded-full">
          Computer Science Mentor
        </span>
        <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
          <li>Programming Fundamentals</li>
          <li>Web Development</li>
          <li>Database Systems</li>
        </ul>
      </article>

      <!-- Educator Card -->
      <article class="bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition duration-300" role="region" aria-labelledby="edu-chamika">
        <img src="./images/udara-miss-2.png" alt="Ms. Udara Dilshani" class="w-24 h-24 mx-auto rounded-full border-4 border-yellow-200 mb-4" />
        <h3 id="edu-chamika" class="font-semibold text-xl text-yellow-800">Ms. Udara Dilshani</h3>
        <span class="inline-block mt-2 px-3 py-1 bg-yellow-100 text-yellow-700 text-sm rounded-full">
          Science Educator
        </span>
        <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
          <li>Biology</li>
          <li>Chemistry</li>
          <li>Physics</li>
        </ul>
      </article>
    </div>
  </div>
</section>



<!-- Success Stories -->
<section class="py-20 bg-white" aria-label="Success Stories">
  <div class="max-w-6xl mx-auto px-4 text-center">
    <h2 class="text-4xl font-bold mb-6 text-blue-900">Success Stories</h2>
    <p class="text-gray-600 text-lg mb-12 max-w-3xl mx-auto">
      Hear from students who achieved their goals with <span class="font-semibold text-blue-700">EduPortal</span>.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
      <!-- Story 1 -->
      <article class="bg-gradient-to-br from-blue-100 to-white p-6 rounded-xl shadow-lg text-left hover:shadow-2xl transition duration-300">
        <div class="flex items-start gap-4">
          <img src="./images/Men.jpg" alt="Nisansala D." class="w-12 h-12 rounded-full border-2 border-blue-300" />
          <div>
            <p class="text-gray-700 italic">
              <svg class="inline w-5 h-5 text-blue-500 mr-1" fill="currentColor" viewBox="0 0 24 24"><path d="M7.17 6.1A5 5 0 0 0 2 11v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1H5.92A3.01 3.01 0 0 1 9 9a1 1 0 0 0-1-1H7.17ZM20 6a5 5 0 0 0-5 5v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-3.08A3.01 3.01 0 0 1 22 9a1 1 0 0 0-1-1h-1Z"/></svg>
              “I passed my A/Ls with distinction thanks to the online support I received here.”
            </p>
            <p id="story-nisansala" class="mt-3 font-semibold text-blue-800">– Nisansala D.</p>
          </div>
        </div>
      </article>

      <!-- Story 2 -->
      <article class="bg-gradient-to-br from-green-100 to-white p-6 rounded-xl shadow-lg text-left hover:shadow-2xl transition duration-300">
        <div class="flex items-start gap-4">
          <img src="./images/Men.jpg" alt="Kaveen R." class="w-12 h-12 rounded-full border-2 border-green-300" />
          <div>
            <p class="text-gray-700 italic">
              <svg class="inline w-5 h-5 text-green-500 mr-1" fill="currentColor" viewBox="0 0 24 24"><path d="M7.17 6.1A5 5 0 0 0 2 11v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1H5.92A3.01 3.01 0 0 1 9 9a1 1 0 0 0-1-1H7.17ZM20 6a5 5 0 0 0-5 5v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-3.08A3.01 3.01 0 0 1 22 9a1 1 0 0 0-1-1h-1Z"/></svg>
              “The courses are clear and easy to follow. I love how flexible learning is now.”
            </p>
            <p id="story-kaveen" class="mt-3 font-semibold text-green-800">– Kaveen R.</p>
          </div>
        </div>
      </article>
    </div>
  </div>
</section>


<!-- If logged in, show Dashboard section 
<?php if (isset($_SESSION['user_id'])): ?>
<section class="bg-gray-50 py-16" aria-label="User Dashboard Navigation">
  <div class="max-w-4xl mx-auto px-4 text-center">
    <h2 class="text-2xl font-semibold text-gray-700 mb-6">Go to your Dashboard</h2>
    <nav class="space-x-4" aria-label="Dashboard and logout links">
      <?php if ($_SESSION['role'] == 'student'): ?>
        <a href="student_dashboard.php" class="inline-block bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700 transition" role="button">
          🎒 Student Dashboard
        </a>
      <?php elseif ($_SESSION['role'] == 'teacher'): ?>
        <a href="teacher_dashboard.php" class="inline-block bg-purple-600 text-white px-6 py-2 rounded hover:bg-purple-700 transition" role="button">
          📚 Teacher Dashboard
        </a>
      <?php elseif ($_SESSION['role'] == 'admin'): ?>
        <a href="admin_dashboard.php" class="inline-block bg-gray-700 text-white px-6 py-2 rounded hover:bg-gray-800 transition" role="button">
          🛠️ Admin Dashboard
        </a>
      <?php endif; ?>
      <a href="logout.php" class="inline-block bg-red-600 text-white px-6 py-2 rounded hover:bg-red-700 transition" role="button">
        🚪 Logout
      </a>
    </nav>
  </div>
</section>
<?php endif; ?> -->

<!-- Footer -->
<?php include 'components/footer.php'; ?>
</body>
</html>
