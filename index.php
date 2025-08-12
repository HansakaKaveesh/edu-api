<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SynapZ - Welcome</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

  <!-- Favicon -->
  <link rel="icon" type="image/png" href="./images/logo.png" />

  <style>
    body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial; }
    /* Reveal on scroll */
    .reveal { opacity: 0; transform: translateY(14px); transition: opacity .6s ease, transform .6s ease; }
    .reveal.in-view { opacity: 1; transform: translateY(0); }
    /* Pretty blobs */
    @keyframes blob {
      0%, 100% { transform: translate(0,0) scale(1); }
      33% { transform: translate(14px,-18px) scale(1.06); }
      66% { transform: translate(-18px,10px) scale(0.98); }
    }
    .animate-blob { animation: blob 12s infinite; }
    .animation-delay-2000 { animation-delay: 2s; }
    .animation-delay-4000 { animation-delay: 4s; }
    /* Reduce motion */
    @media (prefers-reduced-motion: reduce) {
      .reveal { transition: none; }
      .animate-blob { animation: none; }
    }
  </style>
</head>

<body class="bg-white text-gray-800 flex flex-col min-h-screen overflow-x-hidden">

  <!-- Skip link -->
  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 bg-blue-600 text-white px-4 py-2 rounded">
    Skip to content
  </a>

  <!-- Navbar -->
  <?php include 'components/navbar.php'; ?>

  <!-- Hero -->
  <section class="relative min-h-screen flex items-center justify-center text-center overflow-hidden">
    <!-- Background image -->
    <img src="./images/hero-bg.jpg" alt="" class="absolute inset-0 w-full h-full object-cover" />
    <!-- Gradient overlay -->
    <div class="absolute inset-0 bg-gradient-to-br from-slate-900/70 via-blue-900/60 to-cyan-800/60"></div>

    <!-- Decorative blobs -->
    <div class="pointer-events-none absolute -top-16 -left-20 w-80 h-80 bg-gradient-to-tr from-pink-400 to-purple-500 opacity-30 blur-3xl rounded-full animate-blob"></div>
    <div class="pointer-events-none absolute -bottom-20 -right-16 w-96 h-96 bg-gradient-to-tr from-cyan-400 to-blue-500 opacity-30 blur-3xl rounded-full animate-blob animation-delay-2000"></div>
    <div class="pointer-events-none absolute top-1/3 right-1/3 w-72 h-72 bg-gradient-to-tr from-yellow-400 to-orange-500 opacity-20 blur-3xl rounded-full animate-blob animation-delay-4000"></div>

    <!-- Content -->
    <div class="relative z-10 max-w-4xl px-6" id="main">
      <!-- Logo -->
      <div class="mb-6 flex justify-center reveal">
        <img src="./images/logo.png" alt="SynapZ Logo" class="h-20 w-auto sm:h-24 md:h-28 drop-shadow-lg">
      </div>

      <!-- Title -->
      <h1 class="reveal text-4xl sm:text-5xl md:text-6xl font-extrabold leading-tight mb-4">
        <span class="bg-gradient-to-r from-yellow-300 via-yellow-400 to-amber-500 text-transparent bg-clip-text">Welcome to</span>
        <span class="block text-white">Synap<span class="text-yellow-300">Z</span></span>
      </h1>

      <!-- Subtext -->
      <p class="reveal text-lg sm:text-xl text-white/90 max-w-2xl mx-auto">
        Your complete Virtual Learning Environment for
        <span class="font-semibold text-blue-300">students</span> and
        <span class="font-semibold text-green-300">teachers</span>.
      </p>

      <!-- CTA -->
      <div class="reveal flex justify-center flex-wrap gap-4 mt-8">
        <a href="login.php" class="bg-blue-600 text-white px-6 py-3 rounded-lg shadow hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
          🔐 Login
        </a>
        <a href="register.php" class="bg-green-600 text-white px-6 py-3 rounded-lg shadow hover:bg-green-700 focus:ring-4 focus:ring-green-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
          📝 Register
        </a>
        <a href="#about" class="text-white/90 hover:text-white px-6 py-3 rounded-lg border border-white/30 hover:bg-white/10 transition">
          Learn more
        </a>
      </div>

      <!-- Socials -->
      <div class="reveal flex justify-center space-x-6 text-white text-2xl mt-10">
        <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" aria-label="Facebook" class="hover:text-blue-500 transition">
          <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.5 9.8v-6.9h-2.7v-2.9h2.7v-2.2c0-2.7 1.6-4.1 4-4.1 1.2 0 2.4.2 2.4.2v2.6h-1.3c-1.3 0-1.7.8-1.7 1.6v1.9h2.9l-.5 2.9h-2.4v6.9A10 10 0 0 0 22 12z"/></svg>
        </a>
        <a href="https://twitter.com" target="_blank" rel="noopener noreferrer" aria-label="Twitter" class="hover:text-sky-400 transition">
          <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true"><path d="M23 3a10.9 10.9 0 0 1-3.14.86A5.48 5.48 0 0 0 23 1.6a10.86 10.86 0 0 1-3.46 1.32A5.44 5.44 0 0 0 12 8.48a15.43 15.43 0 0 1-11-5.32A5.44 5.44 0 0 0 4.17 8.1 5.4 5.4 0 0 1 2.18 7.8v.07a5.44 5.44 0 0 0 4.36 5.33A5.5 5.5 0 0 1 3 13.1a5.41 5.41 0 0 1-1.03-.1 5.44 5.44 0 0 0 5.07 3.77A10.9 10.9 0 0 1 1 19.54a15.37 15.37 0 0 0 8.29 2.43c9.94 0 15.38-8.24 15.38-15.38 0-.23 0-.46-.02-.69A11 11 0 0 0 23 3z"/></svg>
        </a>
        <a href="https://instagram.com" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="hover:text-pink-500 transition">
          <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true"><path d="M7.75 2h8.5A5.75 5.75 0 0 1 22 7.75v8.5A5.75 5.75 0 0 1 16.25 22h-8.5A5.75 5.75 0 0 1 2 16.25v-8.5A5.75 5.75 0 0 1 7.75 2zm0 2A3.75 3.75 0 0 0 4 7.75v8.5A3.75 3.75 0 0 0 7.75 20h8.5a3.75 3.75 0 0 0 3.75-3.75v-8.5A3.75 3.75 0 0 0 16.25 4h-8.5zm8.75 1.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm-4.25 2.25a4.75 4.75 0 1 1 0 9.5 4.75 4.75 0 0 1 0-9.5zm0 2a2.75 2.75 0 1 0 0 5.5 2.75 2.75 0 0 0 0-5.5z"/></svg>
        </a>
        <a href="https://linkedin.com" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" class="hover:text-blue-500 transition">
          <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" role="img" aria-hidden="true"><path d="M4.98 3.5A2.48 2.48 0 0 0 2.5 5.98v12.04a2.48 2.48 0 0 0 2.48 2.48h12.04a2.48 2.48 0 0 0 2.48-2.48V5.98a2.48 2.48 0 0 0-2.48-2.48H4.98zm3.24 14.5H6.17v-7.5h1.97v7.5zM7.14 9.48a1.13 1.13 0 1 1 0-2.26 1.13 1.13 0 0 1 0 2.26zm8.44 8.52h-1.97v-4c0-1.06-.02-2.42-1.47-2.42-1.47 0-1.7 1.15-1.7 2.34v4.08h-1.97v-7.5h1.89v1.02h.03a2.08 2.08 0 0 1 1.88-1.03c2.01 0 2.38 1.32 2.38 3.04v4.47z"/></svg>
        </a>
      </div>

      <!-- Scroll indicator -->
      <div class="reveal mt-12 text-white/80 text-sm flex items-center justify-center gap-2">
        <span>Scroll</span>
        <span aria-hidden="true">↓</span>
      </div>
    </div>
  </section>

  <!-- Stats -->
  <section class="py-12 bg-gradient-to-b from-white to-blue-50">
    <div class="max-w-6xl mx-auto px-6 grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="reveal bg-white rounded-2xl p-5 shadow border border-gray-100">
        <div class="text-3xl font-extrabold text-blue-700">5k+</div>
        <div class="text-gray-600">Active Students</div>
      </div>
      <div class="reveal bg-white rounded-2xl p-5 shadow border border-gray-100">
        <div class="text-3xl font-extrabold text-emerald-600">200+</div>
        <div class="text-gray-600">Expert Tutors</div>
      </div>
      <div class="reveal bg-white rounded-2xl p-5 shadow border border-gray-100">
        <div class="text-3xl font-extrabold text-purple-700">350+</div>
        <div class="text-gray-600">Courses</div>
      </div>
      <div class="reveal bg-white rounded-2xl p-5 shadow border border-gray-100">
        <div class="text-3xl font-extrabold text-rose-600">15k+</div>
        <div class="text-gray-600">Lessons</div>
      </div>
    </div>
  </section>

  <!-- About -->
  <section id="about" class="py-24 bg-gradient-to-br from-white via-blue-50 to-white">
    <div class="max-w-6xl mx-auto px-6 text-center">
      <div class="reveal mb-8">
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-800 mb-4">
          About <span class="text-blue-600">SynapZ</span>
        </h2>
        <div class="w-20 h-1 mx-auto bg-blue-600 rounded mb-4" aria-hidden="true"></div>
        <p class="text-gray-600 text-base sm:text-lg max-w-3xl mx-auto leading-relaxed">
          SynapZ is a modern Learning Management System connecting students, teachers, and admins
          in a seamless, collaborative environment.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8 mt-12">
        <article class="reveal bg-white p-6 rounded-2xl shadow-md hover:shadow-lg transition text-left" role="region" aria-labelledby="students-title">
          <div class="text-blue-600 text-5xl mb-4" aria-hidden="true">📚</div>
          <h3 id="students-title" class="text-xl font-semibold mb-2">For Students</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Access materials, submit assignments, and collaborate in real time.
          </p>
        </article>

        <article class="reveal bg-white p-6 rounded-2xl shadow-md hover:shadow-lg transition text-left" role="region" aria-labelledby="teachers-title">
          <div class="text-green-500 text-5xl mb-4" aria-hidden="true">👩‍🏫</div>
          <h3 id="teachers-title" class="text-xl font-semibold mb-2">For Teachers</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Manage classes, track progress, and provide targeted feedback.
          </p>
        </article>

        <article class="reveal bg-white p-6 rounded-2xl shadow-md hover:shadow-lg transition text-left" role="region" aria-labelledby="admins-title">
          <div class="text-yellow-500 text-5xl mb-4" aria-hidden="true">🛠️</div>
          <h3 id="admins-title" class="text-xl font-semibold mb-2">For Admins</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Oversee operations, generate reports, and ensure smooth learning.
          </p>
        </article>
      </div>
    </div>
  </section>

  <!-- Tutors/Features -->
  <section id="tutors" class="py-24 bg-gradient-to-br from-blue-50 to-white">
    <div class="max-w-6xl mx-auto px-6 text-center">
      <h2 class="reveal text-3xl sm:text-4xl font-extrabold text-gray-800 mb-4">
        Unlock Learning with <span class="text-blue-600">Expert Tutors</span>
      </h2>
      <p class="reveal text-gray-600 text-base sm:text-lg mb-12 max-w-3xl mx-auto leading-relaxed">
        Personalized support and innovative teaching to help you excel.
      </p>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8">
        <article class="reveal bg-white p-8 rounded-2xl shadow hover:shadow-lg transition text-left" role="region" aria-labelledby="live-sessions-title">
          <div class="text-blue-500 text-5xl mb-4" aria-hidden="true">🎥</div>
          <h3 id="live-sessions-title" class="font-semibold text-xl mb-2">Live Sessions</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Engage in interactive classes and clarify doubts in real-time.
          </p>
        </article>

        <article class="reveal bg-white p-8 rounded-2xl shadow hover:shadow-lg transition text-left" role="region" aria-labelledby="expert-guidance-title">
          <div class="text-green-500 text-5xl mb-4" aria-hidden="true">🎓</div>
          <h3 id="expert-guidance-title" class="font-semibold text-xl mb-2">Expert Guidance</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Learn from certified professionals with classroom experience.
          </p>
        </article>

        <article class="reveal bg-white p-8 rounded-2xl shadow hover:shadow-lg transition text-left" role="region" aria-labelledby="progress-tracking-title">
          <div class="text-yellow-500 text-5xl mb-4" aria-hidden="true">📊</div>
          <h3 id="progress-tracking-title" class="font-semibold text-xl mb-2">Progress Tracking</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Stay on top of your goals with built-in performance monitoring.
          </p>
        </article>
      </div>
    </div>
  </section>

  <!-- Courses -->
  <section id="courses" class="py-24 bg-gradient-to-br from-white via-blue-50 to-white">
    <div class="max-w-6xl mx-auto px-6 text-center">
      <h2 class="reveal text-3xl sm:text-4xl font-extrabold text-gray-800 mb-4">
        Explore Our <span class="text-blue-600">Courses</span>
      </h2>
      <p class="reveal text-gray-600 text-base sm:text-lg mb-10 max-w-3xl mx-auto leading-relaxed">
        Choose from ICT and Computer Science subjects tailored for IGCSE and IAL (AS & AS2).
      </p>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-8">
        <!-- IGCSE ICT -->
        <article class="reveal bg-white rounded-2xl shadow hover:shadow-xl transition overflow-hidden text-left group" role="region" aria-labelledby="igcse-ict-title">
          <div class="relative">
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRFRObkO8H_uYDj0uuGJ1vlSPl4i-qFHG92YQ&s" alt="IGCSE ICT" class="w-full h-48 object-cover group-hover:scale-[1.02] transition">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
          </div>
          <div class="p-5">
            <h3 id="igcse-ict-title" class="font-bold text-xl mb-2">IGCSE ICT</h3>
            <p class="text-gray-600 text-sm sm:text-base">
              Practical and theoretical modules aligned with Cambridge standards.
            </p>
          </div>
        </article>

        <!-- IAL AS ICT -->
        <article class="reveal bg-white rounded-2xl shadow hover:shadow-xl transition overflow-hidden text-left group" role="region" aria-labelledby="ial-as-ict-title">
          <div class="relative">
            <img src="https://aotscolombiajapon.com/wp-content/uploads/2025/01/3ra-Beca-IA-Utilizing-to-overcome-DX-related-1.jpg" alt="IAL AS ICT" class="w-full h-48 object-cover group-hover:scale-[1.02] transition">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
          </div>
          <div class="p-5">
            <h3 id="ial-as-ict-title" class="font-bold text-xl mb-2">IAL AS ICT</h3>
            <p class="text-gray-600 text-sm sm:text-base">
              Build foundational knowledge in ICT systems and data handling.
            </p>
          </div>
        </article>

        <!-- IAL AS2 ICT -->
        <article class="reveal bg-white rounded-2xl shadow hover:shadow-xl transition overflow-hidden text-left group" role="region" aria-labelledby="ial-as2-ict-title">
          <div class="relative">
            <img src="https://www.ict.eu/sites/corporate/files/images/iStock-1322517295%20copy_3.jpg" alt="IAL AS2 ICT" class="w-full h-48 object-cover group-hover:scale-[1.02] transition">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
          </div>
          <div class="p-5">
            <h3 id="ial-as2-ict-title" class="font-bold text-xl mb-2">IAL AS2 ICT</h3>
            <p class="text-gray-600 text-sm sm:text-base">
              Advance your ICT skills with real-world problem solving.
            </p>
          </div>
        </article>

        <!-- IGCSE Computer Science -->
        <article class="reveal bg-white rounded-2xl shadow hover:shadow-xl transition overflow-hidden text-left group" role="region" aria-labelledby="igcse-cs-title">
          <div class="relative">
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTiX_sE8HNgliGkDZNJaestGinmoLUp1ab5Eg&s" alt="IGCSE Computer Science" class="w-full h-48 object-cover group-hover:scale-[1.02] transition">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
          </div>
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

  <!-- Educators -->
  <section class="py-20 bg-gradient-to-b from-blue-50 to-white" aria-label="Meet Our Educators">
    <div class="max-w-6xl mx-auto px-6 text-center">
      <h2 class="reveal text-4xl font-bold mb-4 text-blue-900">Meet Our Educators</h2>
      <p class="reveal text-gray-600 text-lg mb-10 max-w-3xl mx-auto">
        Learn from qualified professionals committed to your success.
      </p>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8">
        <article class="reveal bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition" role="region" aria-labelledby="edu-ruwan">
          <img src="./images/tanjana-sir-image-1.png" alt="Mr. Tanjana Chamikara" class="w-24 h-24 mx-auto rounded-full border-4 border-blue-200 mb-4" />
          <h3 id="edu-ruwan" class="font-semibold text-xl text-blue-800">Mr. Tanjana Chamikara</h3>
          <span class="inline-block mt-2 px-3 py-1 bg-blue-100 text-blue-700 text-sm rounded-full">Senior Mathematics Instructor</span>
          <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
            <li>Pure Mathematics</li><li>Applied Mathematics</li><li>Statistics</li>
          </ul>
        </article>

        <article class="reveal bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition" role="region" aria-labelledby="edu-thilini">
          <img src="./images/madara-miss-image-600-2-1.png" alt="Ms. Madhara Wedhage" class="w-24 h-24 mx-auto rounded-full border-4 border-green-200 mb-4" />
          <h3 id="edu-thilini" class="font-semibold text-xl text-green-800">Ms. Madhara Wedhage</h3>
          <span class="inline-block mt-2 px-3 py-1 bg-green-100 text-green-700 text-sm rounded-full">Computer Science Mentor</span>
          <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
            <li>Programming Fundamentals</li><li>Web Development</li><li>Database Systems</li>
          </ul>
        </article>

        <article class="reveal bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition" role="region" aria-labelledby="edu-chamika">
          <img src="./images/udara-miss-2.png" alt="Ms. Udara Dilshani" class="w-24 h-24 mx-auto rounded-full border-4 border-yellow-200 mb-4" />
          <h3 id="edu-chamika" class="font-semibold text-xl text-yellow-800">Ms. Udara Dilshani</h3>
          <span class="inline-block mt-2 px-3 py-1 bg-yellow-100 text-yellow-700 text-sm rounded-full">Science Educator</span>
          <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
            <li>Biology</li><li>Chemistry</li><li>Physics</li>
          </ul>
        </article>
      </div>
    </div>
  </section>

  <!-- Success Stories -->
  <section class="py-20 bg-white" aria-label="Success Stories">
    <div class="max-w-6xl mx-auto px-6 text-center">
      <h2 class="reveal text-4xl font-bold mb-6 text-blue-900">Success Stories</h2>
      <p class="reveal text-gray-600 text-lg mb-12 max-w-3xl mx-auto">
        Hear from students who achieved their goals with <span class="font-semibold text-blue-700">SynapZ</span>.
      </p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <article class="reveal bg-gradient-to-br from-blue-100 to-white p-6 rounded-xl shadow-lg text-left hover:shadow-2xl transition">
          <div class="flex items-start gap-4">
            <img src="./images/Men.jpg" alt="Nisansala D." class="w-12 h-12 rounded-full border-2 border-blue-300" />
            <div>
              <p class="text-gray-700 italic">
                <svg class="inline w-5 h-5 text-blue-500 mr-1" fill="currentColor" viewBox="0 0 24 24"><path d="M7.17 6.1A5 5 0 0 0 2 11v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1H5.92A3.01 3.01 0 0 1 9 9a1 1 0 0 0-1-1H7.17ZM20 6a5 5 0 0 0-5 5v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-3.08A3.01 3.01 0 0 1 22 9a1 1 0 0 0-1-1h-1Z"/></svg>
                “I passed my A/Ls with distinction thanks to the amazing support here.”
              </p>
              <p id="story-nisansala" class="mt-3 font-semibold text-blue-800">– Nisansala D.</p>
            </div>
          </div>
        </article>

        <article class="reveal bg-gradient-to-br from-green-100 to-white p-6 rounded-xl shadow-lg text-left hover:shadow-2xl transition">
          <div class="flex items-start gap-4">
            <img src="./images/Men.jpg" alt="Kaveen R." class="w-12 h-12 rounded-full border-2 border-green-300" />
            <div>
              <p class="text-gray-700 italic">
                <svg class="inline w-5 h-5 text-green-500 mr-1" fill="currentColor" viewBox="0 0 24 24"><path d="M7.17 6.1A5 5 0 0 0 2 11v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1H5.92A3.01 3.01 0 0 1 9 9a1 1 0 0 0-1-1H7.17ZM20 6a5 5 0 0 0-5 5v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-3.08A3.01 3.01 0 0 1 22 9a1 1 0 0 0-1-1h-1Z"/></svg>
                “The courses are clear and easy to follow. Learning is so flexible now.”
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
    <div class="max-w-4xl mx-auto px-6 text-center">
      <h2 class="text-2xl font-semibold text-gray-700 mb-6">Go to your Dashboard</h2>
      <nav class="space-x-4" aria-label="Dashboard and logout links">
        <?php if ($_SESSION['role'] == 'student'): ?>
          <a href="student_dashboard.php" class="inline-block bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700 transition" role="button">🎒 Student Dashboard</a>
        <?php elseif ($_SESSION['role'] == 'teacher'): ?>
          <a href="teacher_dashboard.php" class="inline-block bg-purple-600 text-white px-6 py-2 rounded hover:bg-purple-700 transition" role="button">📚 Teacher Dashboard</a>
        <?php elseif ($_SESSION['role'] == 'admin'): ?>
          <a href="admin_dashboard.php" class="inline-block bg-gray-700 text-white px-6 py-2 rounded hover:bg-gray-800 transition" role="button">🛠️ Admin Dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="inline-block bg-red-600 text-white px-6 py-2 rounded hover:bg-red-700 transition" role="button">🚪 Logout</a>
      </nav>
    </div>
  </section>
  <?php endif; ?> -->

  <!-- Footer -->
  <?php include 'components/footer.php'; ?>

  <!-- Back to top button -->
  <a href="#top" id="backToTop"
     class="hidden fixed bottom-6 right-6 z-50 bg-blue-600 text-white p-3 rounded-full shadow-lg hover:bg-blue-700 transition"
     aria-label="Back to top">
     ↑
  </a>

  <!-- Scripts: reveal on scroll + back-to-top -->
  <script>
    // Reveal on scroll
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('in-view'); });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

    // Back to top
    const backBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 600) {
        backBtn.classList.remove('hidden');
      } else {
        backBtn.classList.add('hidden');
      }
    });
  </script>
</body>
</html>