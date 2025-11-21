<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SynapZ - Welcome</title>
  <meta name="description" content="SynapZ is a modern virtual learning environment connecting students, teachers, and admins in a seamless, collaborative platform." />
  <meta property="og:title" content="SynapZ - Learn, Grow, Succeed" />
  <meta property="og:description" content="Your complete Virtual Learning Environment for students and teachers." />
  <meta property="og:image" content="./images/logo.png" />
  <meta name="theme-color" content="#2563eb" />

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

  <!-- Ionicons -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <!-- Favicon -->
  <link rel="icon" type="image/png" href="./images/logo.png" />

  <!-- Preload hero image -->
  <link rel="preload" as="image" href="./images/hero-bg.jpg" imagesrcset="./images/hero-bg.jpg" />

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
      .typed-caret { animation: none; }
      .shine { animation: none; }
    }

    /* Typed headline caret */
    .typed-caret::after {
      content: '|';
      margin-left: 2px;
      color: #fff;
      opacity: .8;
      animation: blink 1s step-end infinite;
    }
    @keyframes blink { 0%, 100% { opacity: .1; } 50% { opacity: 1; } }

    /* Waves */
    .wave-top { transform: translateY(1px); }
    .wave-bottom { transform: translateY(-1px); }

    /* Gradient text shine */
    .shine {
      background: linear-gradient(90deg, #fff, #dbeafe, #fff);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      background-size: 200% 100%;
      animation: shine 6s linear infinite;
    }
    @keyframes shine { 0% { background-position: 0% 50%; } 100% { background-position: 200% 50%; } }

    /* Glass utility */
    .glass { background: linear-gradient(180deg, rgba(255,255,255,.16), rgba(255,255,255,.06)); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }

    /* Scroll progress bar */
    #scrollProgress { width: 0%; }

    /* ——— Beauty Upgrades ——— */

    /* Aurora gradient blobs for the hero */
    .aurora { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
    .aurora span {
      position: absolute; width: 42rem; height: 42rem;
      filter: blur(48px); opacity: .75; border-radius: 9999px;
      animation: drift 12s ease-in-out infinite;
    }
    .aurora .a1 {
      top: -12%; left: -10%;
      background: radial-gradient(circle at 30% 30%, rgba(99,102,241,.45), transparent 60%);
      animation-delay: 0s;
    }
    .aurora .a2 {
      right: -12%; bottom: -18%;
      background: radial-gradient(circle at 70% 40%, rgba(59,130,246,.45), transparent 55%);
      animation-delay: 6s;
    }
    .aurora .a3 {
      top: 18%; right: 25%;
      background: radial-gradient(circle at 50% 50%, rgba(236,72,153,.35), transparent 60%);
      animation-delay: 12s;
    }
    @keyframes drift {
      0%,100% { transform: translate3d(0,0,0) scale(1); }
      50%     { transform: translate3d(-18px,12px,0) scale(1.05); }
    }

    /* Subtle dotted texture overlay */
    .texture-dots {
      position: absolute; inset: 0; pointer-events: none;
      background-image: radial-gradient(rgba(255,255,255,.06) 1px, transparent 1.5px);
      background-size: 10px 10px;
      mix-blend-mode: screen; opacity: .25;
    }

    /* Glow ring for primary CTA buttons */
    .btn-glow { position: relative; z-index: 0; }
    .btn-glow::before {
      content: ""; position: absolute; inset: -3px; z-index: -1; border-radius: .8rem;
      background: linear-gradient(90deg, #60a5fa, #a78bfa, #60a5fa);
      filter: blur(12px); opacity: .35; transition: opacity .25s ease;
    }
    .btn-glow:hover::before { opacity: .85; }

    /* Tilt micro-interaction for cards */
    .tilt {
      will-change: transform; transform-style: preserve-3d;
      transition: transform .2s ease, box-shadow .2s ease;
    }
    .tilt:hover { box-shadow: 0 18px 45px rgba(2,6,23,.15); }

    @media (prefers-reduced-motion: reduce) {
      .aurora span { animation: none; }
      .tilt { transition: none; }
    }

    /* ——— Upgrade Pack (overrides) ——— */
    :root{
      --aurora-indigo: 99,102,241;
      --aurora-blue:   59,130,246;
      --aurora-pink:  236, 72,153;
      --aurora-cyan:   34,211,238;
      --aurora-purple:168, 85,247;
    }

    /* Aurora v2: bigger, smoother, more vibrant */
    .aurora {
      position: absolute; inset: 0; overflow: hidden;
      pointer-events: none; isolation: isolate; mix-blend-mode: screen;
    }
    .aurora span {
      position: absolute; width: 46rem; height: 46rem; border-radius: 9999px;
      opacity: .9; filter: blur(60px) saturate(1.15);
      animation: drift 28s ease-in-out infinite; will-change: transform;
    }
    .aurora .a1{
      top:-14%; left:-12%;
      background:
        radial-gradient(circle at 30% 30%, rgba(var(--aurora-indigo), .50), transparent 60%),
        radial-gradient(circle at 70% 70%, rgba(var(--aurora-purple), .35), transparent 60%);
      animation-delay: 0s;
    }
    .aurora .a2{
      right:-16%; bottom:-20%;
      background:
        radial-gradient(circle at 60% 40%, rgba(var(--aurora-blue), .45), transparent 60%),
        radial-gradient(circle at 30% 70%, rgba(var(--aurora-cyan), .35), transparent 60%);
      animation-delay: 7s;
    }
    .aurora .a3{
      top:18%; right:22%;
      background:
        radial-gradient(circle at 50% 50%, rgba(var(--aurora-pink), .35), transparent 60%),
        radial-gradient(circle at 20% 80%, rgba(255,255,255,.08), transparent 60%);
      animation-delay: 14s;
    }

    /* Soft bokeh sparkles */
    .bokeh {
      position: absolute; inset: 0; pointer-events: none; overflow: hidden;
      mix-blend-mode: screen; opacity: .9;
    }
    .bokeh span{
      position: absolute; width: var(--s, 22px); height: var(--s, 22px);
      border-radius: 9999px;
      background: radial-gradient(circle at 40% 40%, rgba(255,255,255,.9), rgba(255,255,255,0) 60%);
      opacity: .06; filter: blur(1px);
      top: var(--t, 50%); left: var(--l, 50%);
      animation: float var(--d, 16s) ease-in-out infinite; will-change: transform;
    }
    @keyframes float { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-30px) } }

    /* Title shimmer (subtler, cleaner) */
    .title-shimmer{
      background: linear-gradient(90deg, #ffffff, #dbeafe, #ffffff);
      background-size: 200% 100%;
      -webkit-background-clip: text; background-clip: text; color: transparent;
      animation: titleShine 8s linear infinite;
    }
    @keyframes titleShine { 0% {background-position: 0% 50%} 100% {background-position: 200% 50%} }

    /* Glossy swipe for CTAs */
    .btn-glossy{ position: relative; overflow: hidden; }
    .btn-glossy::after{
      content:""; position:absolute; inset:-150% -50% 0;
      background: linear-gradient(120deg, transparent, rgba(255,255,255,.35), transparent);
      transform: translateX(-100%); transition: transform .8s cubic-bezier(.19,1,.22,1);
    }
    .btn-glossy:hover::after{ transform: translateX(100%); }

    /* Optional: gradient border utility */
    .grad-border{ position: relative; border-radius: 1.25rem; }
    .grad-border::before{
      content:""; position:absolute; inset:0; padding:1px; border-radius:inherit;
      background: linear-gradient(90deg, rgba(59,130,246,.6), rgba(168,85,247,.5), rgba(34,211,238,.6));
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor; mask-composite: exclude;
    }
    .grad-border > .inner{
      border-radius: inherit; background: rgba(255,255,255,.86);
      backdrop-filter: blur(8px);
    }

    @media (prefers-reduced-motion: reduce){
      .aurora span, .bokeh span { animation: none; }
      .btn-glossy::after { display: none; }
      .title-shimmer { animation: none; }
    }
  </style>
</head>

<body class="bg-white text-gray-800 flex flex-col min-h-screen overflow-x-hidden">
  <!-- Scroll progress -->
  <div id="scrollProgress" class="fixed top-0 left-0 h-1 bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-400 z-[60]"></div>

  <div id="top"></div>

  <!-- Skip link -->
  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 bg-blue-600 text-white px-4 py-2 rounded">
    Skip to content
  </a>

  <!-- Navbar -->
  <?php include 'components/navbar.php'; ?>

  <!-- Hero -->
  <section class="relative min-h-screen flex items-center justify-center text-center overflow-hidden">
    <!-- Background image -->
    <img id="heroBg" src="./images/hero-bg.jpg" alt="" class="absolute inset-0 w-full h-full object-cover" fetchpriority="high" />
    <!-- Gradient overlay -->
    <div class="absolute inset-0 bg-gradient-to-br from-slate-900/80 via-blue-900/70 to-cyan-800/60"></div>

    <!-- Bokeh sparkles -->
    <div class="bokeh" aria-hidden="true">
      <span style="--s:26px;--d:17s;--t:18%;--l:22%"></span>
      <span style="--s:18px;--d:14s;--t:12%;--l:68%"></span>
      <span style="--s:22px;--d:19s;--t:40%;--l:8%"></span>
      <span style="--s:16px;--d:16s;--t:62%;--l:82%"></span>
      <span style="--s:28px;--d:21s;--t:78%;--l:34%"></span>
      <span style="--s:20px;--d:15s;--t:86%;--l:58%"></span>
    </div>

    <!-- New: aurora + subtle texture -->
    <div class="aurora">
      <span class="a1"></span>
      <span class="a2"></span>
      <span class="a3"></span>
    </div>
    <div class="texture-dots"></div>

    <!-- Decorative blobs -->
    <div class="pointer-events-none absolute -top-16 -left-20 w-80 h-80 bg-gradient-to-tr from-pink-400 to-purple-500 opacity-30 blur-3xl rounded-full animate-blob"></div>
    <div class="pointer-events-none absolute -bottom-20 -right-16 w-96 h-96 bg-gradient-to-tr from-cyan-400 to-blue-500 opacity-30 blur-3xl rounded-full animate-blob animation-delay-2000"></div>
    <div class="pointer-events-none absolute top-1/3 right-1/3 w-72 h-72 bg-gradient-to-tr from-yellow-400 to-orange-500 opacity-20 blur-3xl rounded-full animate-blob animation-delay-4000"></div>

    <!-- Soft radial glow behind content -->
    <div class="absolute inset-0 pointer-events-none [mask-image:radial-gradient(ellipse_60%_40%_at_50%_45%,black,transparent)]">
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[780px] h-[780px] rounded-full bg-white/6 blur-3xl"></div>
    </div>

    <!-- Content -->
    <div class="relative z-10 max-w-4xl px-6" id="main">
      <!-- Logo with glow ring -->
      <div class="mb-6 flex justify-center reveal mt-20">
        <div class="relative">
          <div class="absolute inset-0 rounded-full bg-white/10 blur-lg"></div>
          <img src="./images/logo.png" alt="SynapZ Logo" class="relative h-20 w-auto sm:h-24 md:h-28 drop-shadow-lg" loading="eager" decoding="async">
        </div>
      </div>

      <!-- Title -->
      <h1 class="reveal text-4xl sm:text-5xl md:text-6xl font-extrabold leading-tight mb-4 text-white">
        <span class="title-shimmer">Welcome to</span>
        <span class="block">Synap<span class="text-yellow-300">Z</span></span>
      </h1>

      <!-- Animated subheadline -->
      <p class="reveal text-lg sm:text-xl text-white/90 max-w-2xl mx-auto">
        Your complete Virtual Learning Environment for
        <span class="font-semibold text-blue-300 typed-caret inline-flex items-center gap-1" id="typedWords" aria-live="polite">students</span>.
      </p>

      <!-- Trust badges -->
      <div class="reveal mt-4 flex justify-center flex-wrap gap-2 text-[13px] text-white/90">
        <span class="inline-flex items-center gap-1.5 bg-white/10 border border-white/20 px-3 py-1 rounded-full">
          <ion-icon name="shield-checkmark-outline"></ion-icon> Secure
        </span>
        <span class="inline-flex items-center gap-1.5 bg-white/10 border border-white/20 px-3 py-1 rounded-full">
          <ion-icon name="phone-portrait-outline"></ion-icon> Mobile Friendly
        </span>
        <span class="inline-flex items-center gap-1.5 bg-white/10 border border-white/20 px-3 py-1 rounded-full">
          <ion-icon name="time-outline"></ion-icon> 24/7 Access
        </span>
      </div>

      <!-- CTA -->
      <div class="reveal flex justify-center flex-wrap gap-4 mt-8">
        <?php if (!empty($_SESSION['user_id'])): ?>
          <?php if (($_SESSION['role'] ?? '') === 'student'): ?>
            <a href="student_dashboard.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg shadow hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
              <ion-icon name="school-outline" class="text-xl"></ion-icon> Go to Dashboard
            </a>
          <?php elseif (($_SESSION['role'] ?? '') === 'teacher'): ?>
            <a href="teacher_dashboard.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-purple-600 text-white px-6 py-3 rounded-lg shadow hover:bg-purple-700 focus:ring-4 focus:ring-purple-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
              <ion-icon name="easel-outline" class="text-xl"></ion-icon> Go to Dashboard
            </a>
          <?php else: ?>
            <a href="admin_dashboard.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-gray-700 text-white px-6 py-3 rounded-lg shadow hover:bg-gray-800 focus:ring-4 focus:ring-gray-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
              <ion-icon name="shield-checkmark-outline" class="text-xl"></ion-icon> Go to Dashboard
            </a>
          <?php endif; ?>
          <a href="logout.php" class="inline-flex items-center gap-2 text-white/90 hover:text-white px-6 py-3 rounded-lg border border-white/30 hover:bg-white/10 transition">
            <ion-icon name="log-out-outline"></ion-icon> Logout
          </a>
        <?php else: ?>
          <a href="login.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg shadow hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
            <ion-icon name="log-in-outline" class="text-xl"></ion-icon> Login
          </a>
          <a href="register.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-green-600 text-white px-6 py-3 rounded-lg shadow hover:bg-green-700 focus:ring-4 focus:ring-green-300 transition-all duration-300 transform hover:scale-105 focus:outline-none">
            <ion-icon name="person-add-outline" class="text-xl"></ion-icon> Register
          </a>
          <a href="#about" class="inline-flex items-center gap-2 text-white/90 hover:text-white px-6 py-3 rounded-lg border border-white/30 hover:bg-white/10 transition">
            <ion-icon name="information-circle-outline"></ion-icon> Learn more
          </a>
        <?php endif; ?>
      </div>

      <!-- Socials -->
      <div class="reveal flex justify-center space-x-6 text-white text-2xl mt-10">
        <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" aria-label="Facebook" class="hover:text-blue-500 transition">
          <ion-icon name="logo-facebook" class="text-2xl"></ion-icon>
        </a>
        <a href="https://twitter.com" target="_blank" rel="noopener noreferrer" aria-label="Twitter" class="hover:text-sky-400 transition">
          <ion-icon name="logo-twitter" class="text-2xl"></ion-icon>
        </a>
        <a href="https://instagram.com" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="hover:text-pink-500 transition">
          <ion-icon name="logo-instagram" class="text-2xl"></ion-icon>
        </a>
        <a href="https://linkedin.com" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" class="hover:text-blue-500 transition">
          <ion-icon name="logo-linkedin" class="text-2xl"></ion-icon>
        </a>
      </div>

      <!-- Scroll indicator -->
      <div class="reveal mt-12 text-white/80 text-sm flex items-center justify-center gap-2">
        <span>Scroll</span>
        <ion-icon name="chevron-down-outline" aria-hidden="true" class="animate-bounce"></ion-icon>
      </div>
    </div>

  </section>

  <!-- Stats -->
  <section class="py-16 bg-gradient-to-b from-white to-blue-50">
    <div class="max-w-6xl mx-auto px-6 grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="tilt reveal rounded-2xl p-[1px] bg-gradient-to-r from-blue-100 to-indigo-100 hover:shadow-lg transition">
        <div class="bg-white/90 rounded-2xl p-5 border border-white/60 text-center">
          <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-blue-50 text-blue-600 mb-2">
            <ion-icon name="people-outline"></ion-icon>
          </div>
          <div class="text-3xl font-extrabold text-blue-700"><span class="countup" data-target="5000">0</span>+</div>
          <div class="text-gray-600">Active Students</div>
        </div>
      </div>
      <div class="tilt reveal rounded-2xl p-[1px] bg-gradient-to-r from-emerald-100 to-green-100 hover:shadow-lg transition">
        <div class="bg-white/90 rounded-2xl p-5 border border-white/60 text-center">
          <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-emerald-50 text-emerald-600 mb-2">
            <ion-icon name="ribbon-outline"></ion-icon>
          </div>
          <div class="text-3xl font-extrabold text-emerald-600"><span class="countup" data-target="200">0</span>+</div>
          <div class="text-gray-600">Expert Tutors</div>
        </div>
      </div>
      <div class="tilt reveal rounded-2xl p-[1px] bg-gradient-to-r from-purple-100 to-fuchsia-100 hover:shadow-lg transition">
        <div class="bg-white/90 rounded-2xl p-5 border border-white/60 text-center">
          <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-purple-50 text-purple-600 mb-2">
            <ion-icon name="library-outline"></ion-icon>
          </div>
          <div class="text-3xl font-extrabold text-purple-700"><span class="countup" data-target="350">0</span>+</div>
          <div class="text-gray-600">Courses</div>
        </div>
      </div>
      <div class="tilt reveal rounded-2xl p-[1px] bg-gradient-to-r from-rose-100 to-pink-100 hover:shadow-lg transition">
        <div class="bg-white/90 rounded-2xl p-5 border border-white/60 text-center">
          <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-rose-50 text-rose-600 mb-2">
            <ion-icon name="document-text-outline"></ion-icon>
          </div>
          <div class="text-3xl font-extrabold text-rose-600"><span class="countup" data-target="15000">0</span>+</div>
          <div class="text-gray-600">Lessons</div>
        </div>
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
        <article class="tilt reveal bg-white/90 p-6 rounded-2xl shadow-md hover:shadow-xl transition text-left" role="region" aria-labelledby="students-title">
          <div class="text-blue-600 text-4xl mb-4" aria-hidden="true"><ion-icon name="laptop-outline"></ion-icon></div>
          <h3 id="students-title" class="text-xl font-semibold mb-2">For Students</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Access materials, submit assignments, and collaborate in real time.
          </p>
        </article>

        <article class="tilt reveal bg-white/90 p-6 rounded-2xl shadow-md hover:shadow-xl transition text-left" role="region" aria-labelledby="teachers-title">
          <div class="text-green-500 text-4xl mb-4" aria-hidden="true"><ion-icon name="easel-outline"></ion-icon></div>
          <h3 id="teachers-title" class="text-xl font-semibold mb-2">For Teachers</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Manage classes, track progress, and provide targeted feedback.
          </p>
        </article>

        <article class="tilt reveal bg-white/90 p-6 rounded-2xl shadow-md hover:shadow-xl transition text-left" role="region" aria-labelledby="admins-title">
          <div class="text-yellow-500 text-4xl mb-4" aria-hidden="true"><ion-icon name="settings-outline"></ion-icon></div>
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
        <article class="tilt reveal bg-white/90 p-8 rounded-2xl shadow hover:shadow-xl transition text-left" role="region" aria-labelledby="live-sessions-title">
          <div class="text-blue-500 text-4xl mb-4" aria-hidden="true"><ion-icon name="videocam-outline"></ion-icon></div>
          <h3 id="live-sessions-title" class="font-semibold text-xl mb-2">Live Sessions</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Engage in interactive classes and clarify doubts in real-time.
          </p>
        </article>

        <article class="tilt reveal bg-white/90 p-8 rounded-2xl shadow hover:shadow-xl transition text-left" role="region" aria-labelledby="expert-guidance-title">
          <div class="text-green-500 text-4xl mb-4" aria-hidden="true"><ion-icon name="school-outline"></ion-icon></div>
          <h3 id="expert-guidance-title" class="font-semibold text-xl mb-2">Expert Guidance</h3>
          <p class="text-gray-600 text-sm sm:text-base">
            Learn from certified professionals with classroom experience.
          </p>
        </article>

        <article class="tilt reveal bg-white/90 p-8 rounded-2xl shadow hover:shadow-xl transition text-left" role="region" aria-labelledby="progress-tracking-title">
          <div class="text-yellow-500 text-4xl mb-4" aria-hidden="true"><ion-icon name="stats-chart-outline"></ion-icon></div>
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
        <article class="tilt reveal bg-white rounded-2xl shadow hover:shadow-2xl transition overflow-hidden text-left group" role="region" aria-labelledby="igcse-ict-title">
          <div class="relative">
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRFRObkO8H_uYDj0uuGJ1vlSPl4i-qFHG92YQ&s"
                 alt="IGCSE ICT" class="w-full h-48 object-cover group-hover:scale-[1.02] transition" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
            <div class="absolute top-3 right-3 inline-flex items-center gap-1.5 bg-white/90 text-slate-800 text-xs px-2 py-1 rounded-full">
              <ion-icon name="layers-outline"></ion-icon> IGCSE
            </div>
          </div>
          <div class="p-5">
            <h3 id="igcse-ict-title" class="font-bold text-xl mb-2">IGCSE ICT</h3>
            <p class="text-gray-600 text-sm sm:text-base">
              Practical and theoretical modules aligned with Cambridge standards.
            </p>
          </div>
        </article>

        <!-- IAL AS ICT -->
        <article class="tilt reveal bg-white rounded-2xl shadow hover:shadow-2xl transition overflow-hidden text-left group" role="region" aria-labelledby="ial-as-ict-title">
          <div class="relative">
            <img src="https://aotscolombiajapon.com/wp-content/uploads/2025/01/3ra-Beca-IA-Utilizing-to-overcome-DX-related-1.jpg"
                 alt="IAL AS ICT" class="w-full h-48 object-cover group-hover:scale-[1.02] transition" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
            <div class="absolute top-3 right-3 inline-flex items-center gap-1.5 bg-white/90 text-slate-800 text-xs px-2 py-1 rounded-full">
              <ion-icon name="layers-outline"></ion-icon> IAL AS
            </div>
          </div>
          <div class="p-5">
            <h3 id="ial-as-ict-title" class="font-bold text-xl mb-2">IAL AS ICT</h3>
            <p class="text-gray-600 text-sm sm:text-base">
              Build foundational knowledge in ICT systems and data handling.
            </p>
          </div>
        </article>

        <!-- IAL AS2 ICT -->
        <article class="tilt reveal bg-white rounded-2xl shadow hover:shadow-2xl transition overflow-hidden text-left group" role="region" aria-labelledby="ial-as2-ict-title">
          <div class="relative">
            <img src="https://www.ict.eu/sites/corporate/files/images/iStock-1322517295%20copy_3.jpg"
                 alt="IAL AS2 ICT" class="w-full h-48 object-cover group-hover:scale-[1.02] transition" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
            <div class="absolute top-3 right-3 inline-flex items-center gap-1.5 bg-white/90 text-slate-800 text-xs px-2 py-1 rounded-full">
              <ion-icon name="layers-outline"></ion-icon> IAL AS2
            </div>
          </div>
          <div class="p-5">
            <h3 id="ial-as2-ict-title" class="font-bold text-xl mb-2">IAL AS2 ICT</h3>
            <p class="text-gray-600 text-sm sm:text-base">
              Advance your ICT skills with real-world problem solving.
            </p>
          </div>
        </article>

        <!-- IGCSE Computer Science -->
        <article class="tilt reveal bg-white rounded-2xl shadow hover:shadow-2xl transition overflow-hidden text-left group" role="region" aria-labelledby="igcse-cs-title">
          <div class="relative">
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTiX_sE8HNgliGkDZNJaestGinmoLUp1ab5Eg&s"
                 alt="IGCSE Computer Science" class="w-full h-48 object-cover group-hover:scale-[1.02] transition" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
            <div class="absolute top-3 right-3 inline-flex items-center gap-1.5 bg-white/90 text-slate-800 text-xs px-2 py-1 rounded-full">
              <ion-icon name="layers-outline"></ion-icon> IGCSE
            </div>
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
        <article class="tilt reveal bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition" role="region" aria-labelledby="edu-ruwan">
          <img src="./images/tanjana-sir-image-1.png" alt="Mr. Tanjana Chamikara" class="w-24 h-24 mx-auto rounded-full border-4 border-blue-200 mb-4" loading="lazy" decoding="async" />
          <h3 id="edu-ruwan" class="font-semibold text-xl text-blue-800 flex items-center justify-center gap-2">
            <ion-icon name="person-circle-outline" class="text-blue-600"></ion-icon> Mr. Tanjana Chamikara
          </h3>
          <span class="inline-flex items-center gap-2 mt-2 px-3 py-1 bg-blue-100 text-blue-700 text-sm rounded-full">
            <ion-icon name="ribbon-outline"></ion-icon> Senior Mathematics Instructor
          </span>
          <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
            <li>Pure Mathematics</li><li>Applied Mathematics</li><li>Statistics</li>
          </ul>
        </article>

        <article class="tilt reveal bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition" role="region" aria-labelledby="edu-thilini">
          <img src="./images/madara-miss-image-600-2-1.png" alt="Ms. Madhara Wedhage" class="w-24 h-24 mx-auto rounded-full border-4 border-green-200 mb-4" loading="lazy" decoding="async" />
          <h3 id="edu-thilini" class="font-semibold text-xl text-green-800 flex items-center justify-center gap-2">
            <ion-icon name="person-circle-outline" class="text-green-600"></ion-icon> Ms. Madhara Wedhage
          </h3>
          <span class="inline-flex items-center gap-2 mt-2 px-3 py-1 bg-green-100 text-green-700 text-sm rounded-full">
            <ion-icon name="code-slash-outline"></ion-icon> Computer Science Mentor
          </span>
          <ul class="mt-3 text-sm text-gray-600 list-disc list-inside text-left">
            <li>Programming Fundamentals</li><li>Web Development</li><li>Database Systems</li>
          </ul>
        </article>

        <article class="tilt reveal bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition" role="region" aria-labelledby="edu-chamika">
          <img src="./images/udara-miss-2.png" alt="Ms. Udara Dilshani" class="w-24 h-24 mx-auto rounded-full border-4 border-yellow-200 mb-4" loading="lazy" decoding="async" />
          <h3 id="edu-chamika" class="font-semibold text-xl text-yellow-800 flex items-center justify-center gap-2">
            <ion-icon name="person-circle-outline" class="text-yellow-600"></ion-icon> Ms. Udara Dilshani
          </h3>
          <span class="inline-flex items-center gap-2 mt-2 px-3 py-1 bg-yellow-100 text-yellow-700 text-sm rounded-full">
            <ion-icon name="flask-outline"></ion-icon> Science Educator
          </span>
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
        <article class="tilt reveal bg-gradient-to-br from-blue-100 to-white p-6 rounded-xl shadow-lg text-left hover:shadow-2xl transition">
          <div class="flex items-start gap-4">
            <img src="./images/Men.jpg" alt="Nisansala D." class="w-12 h-12 rounded-full border-2 border-blue-300" loading="lazy" decoding="async" />
            <div>
              <p class="text-gray-700 italic">
                <ion-icon name="chatbubble-ellipses-outline" class="text-blue-500 mr-1 align-text-top"></ion-icon>
                “I passed my A/Ls with distinction thanks to the amazing support here.”
              </p>
              <p id="story-nisansala" class="mt-3 font-semibold text-blue-800">– Nisansala D.</p>
            </div>
          </div>
        </article>

        <article class="tilt reveal bg-gradient-to-br from-green-100 to-white p-6 rounded-xl shadow-lg text-left hover:shadow-2xl transition">
          <div class="flex items-start gap-4">
            <img src="./images/Men.jpg" alt="Kaveen R." class="w-12 h-12 rounded-full border-2 border-green-300" loading="lazy" decoding="async" />
            <div>
              <p class="text-gray-700 italic">
                <ion-icon name="chatbubble-ellipses-outline" class="text-green-500 mr-1 align-text-top"></ion-icon>
                “The courses are clear and easy to follow. Learning is so flexible now.”
              </p>
              <p id="story-kaveen" class="mt-3 font-semibold text-green-800">– Kaveen R.</p>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="py-20 bg-gradient-to-b from-white to-blue-50" aria-label="Frequently Asked Questions">
    <div class="max-w-5xl mx-auto px-6">
      <h2 class="reveal text-3xl sm:text-4xl font-extrabold text-center text-gray-800 mb-8">Frequently Asked Questions</h2>
      <div class="reveal grid grid-cols-1 md:grid-cols-2 gap-6">
        <details class="bg-white/90 border border-gray-100 rounded-xl p-5 shadow group">
          <summary class="cursor-pointer font-semibold text-gray-800 flex items-center justify-between">
            <span class="inline-flex items-center gap-2"><ion-icon name="cash-outline" class="text-blue-600"></ion-icon> Is SynapZ free for students?</span>
            <ion-icon name="chevron-down-outline" class="text-gray-400 group-open:rotate-180 transition"></ion-icon>
          </summary>
          <p class="mt-2 text-gray-600">Many courses are free; paid courses offer extra resources and live sessions.</p>
        </details>
        <details class="bg-white/90 border border-gray-100 rounded-xl p-5 shadow group">
          <summary class="cursor-pointer font-semibold text-gray-800 flex items-center justify-between">
            <span class="inline-flex items-center gap-2"><ion-icon name="phone-portrait-outline" class="text-blue-600"></ion-icon> Can I access courses on mobile?</span>
            <ion-icon name="chevron-down-outline" class="text-gray-400 group-open:rotate-180 transition"></ion-icon>
          </summary>
          <p class="mt-2 text-gray-600">Absolutely. SynapZ works great on phones, tablets, and desktops.</p>
        </details>
        <details class="bg-white/90 border border-gray-100 rounded-xl p-5 shadow group">
          <summary class="cursor-pointer font-semibold text-gray-800 flex items-center justify-between">
            <span class="inline-flex items-center gap-2"><ion-icon name="analytics-outline" class="text-blue-600"></ion-icon> Do you provide progress reports?</span>
            <ion-icon name="chevron-down-outline" class="text-gray-400 group-open:rotate-180 transition"></ion-icon>
          </summary>
          <p class="mt-2 text-gray-600">Yes! Track assignments, quiz performance, and course completion rates.</p>
        </details>
        <details class="bg-white/90 border border-gray-100 rounded-xl p-5 shadow group">
          <summary class="cursor-pointer font-semibold text-gray-800 flex items-center justify-between">
            <span class="inline-flex items-center gap-2"><ion-icon name="videocam-outline" class="text-blue-600"></ion-icon> How do I join live sessions?</span>
            <ion-icon name="chevron-down-outline" class="text-gray-400 group-open:rotate-180 transition"></ion-icon>
          </summary>
          <p class="mt-2 text-gray-600">Enrolled students receive links and reminders directly in their dashboard.</p>
        </details>
      </div>
    </div>
  </section>

  <!-- CTA band -->
  <section class="relative py-16">
    <!-- Wave top -->
    <div class="absolute -top-[1px] left-0 right-0 wave-top" aria-hidden="true">
      <svg viewBox="0 0 1440 40" class="w-full h-10 text-blue-50 fill-current">
        <path d="M0,32L80,26.7C160,21,320,11,480,8C640,5,800,11,960,16C1120,21,1280,27,1360,29.3L1440,32L1440,0L1360,0C1280,0,1120,0,960,0C800,0,640,0,480,0C320,0,160,0,80,0L0,0Z"></path>
      </svg>
    </div>
    <div class="max-w-6xl mx-auto px-6">
      <div class="reveal rounded-3xl bg-gradient-to-r from-indigo-600 via-blue-600 to-cyan-500 p-8 md:p-12 text-center text-white shadow-xl">
        <h3 class="text-2xl md:text-3xl font-extrabold">Ready to start learning with SynapZ?</h3>
        <p class="mt-2 text-white/90">Join thousands of learners and level up your skills today.</p>
        <div class="mt-6 flex justify-center gap-3 flex-wrap">
          <?php if (empty($_SESSION['user_id'])): ?>
            <a href="register.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-white text-indigo-700 font-semibold px-6 py-3 rounded-lg shadow hover:translate-y-[-1px] transition">
              <ion-icon name="person-add-outline"></ion-icon> Get Started
            </a>
            <a href="login.php" class="inline-flex items-center gap-2 bg-indigo-900/30 text-white border border-white/30 px-6 py-3 rounded-lg hover:bg-indigo-900/40 transition">
              <ion-icon name="log-in-outline"></ion-icon> I already have an account
            </a>
          <?php else: ?>
            <?php if (($_SESSION['role'] ?? '') === 'student'): ?>
              <a href="student_dashboard.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-white text-indigo-700 font-semibold px-6 py-3 rounded-lg shadow hover:translate-y-[-1px] transition">
                <ion-icon name="open-outline"></ion-icon> Open Dashboard
              </a>
            <?php elseif (($_SESSION['role'] ?? '') === 'teacher'): ?>
              <a href="teacher_dashboard.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-white text-indigo-700 font-semibold px-6 py-3 rounded-lg shadow hover:translate-y-[-1px] transition">
                <ion-icon name="open-outline"></ion-icon> Open Dashboard
              </a>
            <?php else: ?>
              <a href="admin_dashboard.php" class="btn-glow btn-glossy inline-flex items-center gap-2 bg-white text-indigo-700 font-semibold px-6 py-3 rounded-lg shadow hover:translate-y-[-1px] transition">
                <ion-icon name="open-outline"></ion-icon> Open Dashboard
              </a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <?php include 'components/footer.php'; ?>

  <!-- Cookie Consent Banner -->
  <div id="cookieBanner" class="fixed inset-x-0 bottom-0 sm:bottom-6 px-4 sm:px-6 hidden z-[60]">
    <div class="mx-auto max-w-4xl rounded-2xl border border-gray-200 bg-white/95 backdrop-blur shadow-lg p-4 sm:p-5">
      <div class="flex items-start gap-4">
        <div class="shrink-0 mt-1 text-blue-600" aria-hidden="true"><ion-icon name="cookie-outline" class="text-xl"></ion-icon></div>
        <div class="text-sm text-gray-700">
          <p class="font-medium text-gray-900">We use cookies</p>
          <p class="mt-1">
            We use essential cookies to make our site work. With your consent, we may also use analytics cookies to
            understand how you use SynapZ and improve your experience. See our
            <a href="privacy.php" class="text-blue-600 underline hover:text-blue-700">Privacy Policy</a>.
          </p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button id="cc-accept" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-white text-sm font-medium hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-300">
              <ion-icon name="checkmark-circle-outline"></ion-icon> Accept all
            </button>
            <button id="cc-reject" class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-gray-800 text-sm font-medium hover:bg-gray-200 focus:outline-none focus:ring-4 focus:ring-gray-200">
              <ion-icon name="close-circle-outline"></ion-icon> Reject non‑essential
            </button>
            <button id="cc-settings" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 focus:outline-none">
              <ion-icon name="options-outline"></ion-icon> Manage settings
            </button>
          </div>
        </div>
        <button id="cc-close" aria-label="Close" class="ml-auto text-gray-500 hover:text-gray-700">
          <ion-icon name="close-outline"></ion-icon>
        </button>
      </div>
    </div>
  </div>

  <!-- Cookie Settings Modal -->
  <div id="cookieModal" class="fixed inset-0 hidden items-end sm:items-center justify-center p-4 z-[70]">
    <div class="absolute inset-0 bg-black/40" aria-hidden="true"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="cookieModalTitle"
         class="relative w-full max-w-lg rounded-2xl bg-white shadow-xl border border-gray-200 p-6">
      <h3 id="cookieModalTitle" class="text-lg font-semibold text-gray-900">Cookie preferences</h3>
      <p class="mt-1 text-sm text-gray-600">
        Control which cookies we use. Necessary cookies are always on—they’re required for the site to function.
      </p>

      <div class="mt-4 space-y-3">
        <label class="flex items-start gap-3">
          <input type="checkbox" checked disabled class="mt-0.5 h-4 w-4 text-blue-600 border-gray-300 rounded">
          <span>
            <span class="block font-medium text-gray-900">Necessary</span>
            <span class="block text-sm text-gray-600">Required for core features like login and security.</span>
          </span>
        </label>

        <label class="flex items-start gap-3">
          <input id="cc-analytics" type="checkbox" class="mt-0.5 h-4 w-4 text-blue-600 border-gray-300 rounded">
          <span>
            <span class="block font-medium text-gray-900">Analytics</span>
            <span class="block text-sm text-gray-600">Helps us understand usage to improve SynapZ.</span>
          </span>
        </label>

        <label class="flex items-start gap-3">
          <input id="cc-marketing" type="checkbox" class="mt-0.5 h-4 w-4 text-blue-600 border-gray-300 rounded">
          <span>
            <span class="block font-medium text-gray-900">Marketing (optional)</span>
            <span class="block text-sm text-gray-600">Used to personalize content across services.</span>
          </span>
        </label>
      </div>

      <div class="mt-6 flex items-center justify-end gap-2">
        <button id="cc-cancel" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-gray-900">
          <ion-icon name="close-outline"></ion-icon> Cancel
        </button>
        <button id="cc-save" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700">
          <ion-icon name="save-outline"></ion-icon> Save preferences
        </button>
      </div>
    </div>
  </div>

  <!-- Back to top button -->
  <a href="#top" id="backToTop"
     class="hidden fixed bottom-6 right-6 z-50 bg-blue-600 text-white p-3 rounded-full shadow-lg hover:bg-blue-700 transition"
     aria-label="Back to top">
     <ion-icon name="arrow-up-outline" class="text-xl"></ion-icon>
  </a>

  <!-- Scripts: reveal on scroll + back-to-top + typed + countUp + cookie consent + progress bar + beauty effects -->
  <script>
    // Reveal on scroll
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('in-view'); });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

    // Back to top
    const backBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 600) backBtn.classList.remove('hidden'); else backBtn.classList.add('hidden');
    });

    // Scroll progress bar
    (function(){
      const bar = document.getElementById('scrollProgress');
      if (!bar) return;
      const onScroll = () => {
        const h = document.documentElement;
        const sc = h.scrollTop || document.body.scrollTop;
        const sh = h.scrollHeight - h.clientHeight;
        const pct = Math.max(0, Math.min(100, (sc / sh) * 100));
        bar.style.width = pct + '%';
      };
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('resize', onScroll);
    })();

    // Typed rotating words
    (function() {
      const el = document.getElementById('typedWords');
      if (!el) return;
      const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const words = ['students', 'teachers', 'schools'];
      let i = 0, j = 0, deleting = false;

      function tick() {
        if (prefersReduced) { el.textContent = words[0]; return; }
        const current = words[i];
        if (!deleting) {
          el.textContent = current.slice(0, j + 1);
          j++;
          if (j === current.length) { deleting = true; setTimeout(tick, 1200); return; }
        } else {
          el.textContent = current.slice(0, j - 1);
          j--;
          if (j === 0) { deleting = false; i = (i + 1) % words.length; }
        }
        setTimeout(tick, deleting ? 50 : 80);
      }
      tick();
    })();

    // Count-up stats
    (function() {
      const els = document.querySelectorAll('.countup');
      if (!els.length) return;
      const ro = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          const el = entry.target;
          const target = parseInt(el.dataset.target, 10) || 0;
          const duration = 1200;
          const start = performance.now();
          const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          const animate = (t) => {
            const p = Math.min(1, (t - start) / duration);
            const val = prefersReduced ? target : Math.floor(p * target);
            el.textContent = val.toLocaleString();
            if (p < 1) requestAnimationFrame(animate);
          };
          requestAnimationFrame(animate);
          obs.unobserve(el);
        });
      }, { threshold: 0.6 });
      els.forEach(el => ro.observe(el));
    })();

    // Cookie Consent
    (function() {
      const KEY = 'synapz_cookie_consent_v1';
      const SIX_MONTHS = 15552000 * 1000; // ms

      const banner = document.getElementById('cookieBanner');
      const modal  = document.getElementById('cookieModal');
      const btnAccept  = document.getElementById('cc-accept');
      const btnReject  = document.getElementById('cc-reject');
      const btnClose   = document.getElementById('cc-close');
      const btnSettings= document.getElementById('cc-settings');
      const btnSave    = document.getElementById('cc-save');
      const btnCancel  = document.getElementById('cc-cancel');
      const chkAnalytics = document.getElementById('cc-analytics');
      const chkMarketing = document.getElementById('cc-marketing');

      function readConsent() {
        try {
          const raw = localStorage.getItem(KEY);
          if (!raw) return null;
          const data = JSON.parse(raw);
          if (!data || !data.updatedAt) return null;
          if (Date.now() - data.updatedAt > SIX_MONTHS) return null;
          return data;
        } catch (_) { return null; }
      }

      function saveConsent(consent) {
        const payload = {
          necessary: true,
          analytics: !!consent.analytics,
          marketing: !!consent.marketing,
          updatedAt: Date.now()
        };
        localStorage.setItem(KEY, JSON.stringify(payload));
        document.cookie = "cookie_consent=1; Max-Age=15552000; Path=/; SameSite=Lax";
        applyConsent(payload);
      }

      function showBanner() { banner && banner.classList.remove('hidden'); }
      function hideBanner() { banner && banner.classList.add('hidden'); }
      function openModal() {
        if (!modal) return;
        const current = readConsent() || { analytics: false, marketing: false };
        if (chkAnalytics) chkAnalytics.checked = !!current.analytics;
        if (chkMarketing) chkMarketing.checked = !!current.marketing;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
      }
      function closeModal() {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      }

      function applyConsent(c) {
        // Hook for analytics/marketing scripts
      }

      btnAccept && btnAccept.addEventListener('click', () => { saveConsent({ analytics: true, marketing: true }); hideBanner(); });
      btnReject && btnReject.addEventListener('click', () => { saveConsent({ analytics: false, marketing: false }); hideBanner(); });
      btnClose && btnClose.addEventListener('click', hideBanner);
      btnSettings && btnSettings.addEventListener('click', openModal);
      btnCancel && btnCancel.addEventListener('click', closeModal);
      btnSave && btnSave.addEventListener('click', () => {
        saveConsent({ analytics: chkAnalytics?.checked, marketing: chkMarketing?.checked });
        closeModal(); hideBanner();
      });

      const existing = readConsent();
      if (existing) applyConsent(existing); else showBanner();

      modal && modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
    })();

    // Beauty effects: hero parallax and tilt
    (function() {
      // Hero parallax (gentle)
      const hero = document.getElementById('heroBg');
      const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (hero && !prefersReduced) {
        const onScroll = () => {
          const y = window.scrollY || 0;
          const offset = Math.min(60, y * 0.12);
          hero.style.transform = `translateY(${offset}px) scale(1.06)`;
          hero.style.transformOrigin = 'center';
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
      }

      // Tilt cards on pointer
      const fine = window.matchMedia('(pointer: fine)').matches;
      if (fine && !prefersReduced) {
        const SENS = 10;
        document.querySelectorAll('.tilt').forEach((card) => {
          let raf = null;
          const leave = () => { card.style.transform = ''; };
          const move = (e) => {
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => {
              const r = card.getBoundingClientRect();
              const px = (e.clientX - r.left) / r.width;
              const py = (e.clientY - r.top) / r.height;
              const rx = (0.5 - py) * SENS;
              const ry = (px - 0.5) * SENS;
              card.style.transform = `rotateX(${rx}deg) rotateY(${ry}deg) translateZ(0)`;
            });
          };
          card.addEventListener('mousemove', move);
          card.addEventListener('mouseleave', leave);
        });
      }
    })();
  </script>
</body>
</html>