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
  <meta name="theme-color" content="#4f46e5" />

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ["Inter", "ui-sans-serif", "system-ui"] },
          colors: {
            brand: {
              50:"#eef2ff",100:"#e0e7ff",200:"#c7d2fe",300:"#a5b4fc",400:"#818cf8",
              500:"#6366f1",600:"#4f46e5",700:"#4338ca",800:"#3730a3",900:"#312e81"
            }
          },
          boxShadow: {
            soft: "0 12px 34px rgba(2,6,23,.14)",
            soft2: "0 18px 60px rgba(2,6,23,.12)"
          }
        }
      }
    }
  </script>

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
    body {
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial;
      text-rendering: optimizeLegibility;
    }

    /* Reveal on scroll */
    .reveal { opacity: 0; transform: translateY(14px); transition: opacity .65s ease, transform .65s ease; }
    .reveal.in-view { opacity: 1; transform: translateY(0); }

    /* Typed caret */
    .typed-caret::after {
      content: '|';
      margin-left: 2px;
      color: #fff;
      opacity: .75;
      animation: blink 1s step-end infinite;
    }
    @keyframes blink { 0%, 100% { opacity: .15; } 50% { opacity: 1; } }

    /* Scroll progress */
    #scrollProgress { width: 0%; }

    /* Aurora */
    :root{
      --aurora-indigo: 99,102,241;
      --aurora-blue:   59,130,246;
      --aurora-pink:  236, 72,153;
      --aurora-cyan:   34,211,238;
      --aurora-purple:168, 85,247;
    }
    .aurora {
      position: absolute; inset: 0; overflow: hidden;
      pointer-events: none; isolation: isolate; mix-blend-mode: screen;
    }
    .aurora span {
      position: absolute; width: 52rem; height: 52rem; border-radius: 9999px;
      opacity: .9; filter: blur(70px) saturate(1.2);
      animation: drift 30s ease-in-out infinite; will-change: transform;
    }
    .aurora .a1{
      top:-18%; left:-16%;
      background:
        radial-gradient(circle at 30% 30%, rgba(var(--aurora-indigo), .55), transparent 60%),
        radial-gradient(circle at 70% 70%, rgba(var(--aurora-purple), .35), transparent 60%);
      animation-delay: 0s;
    }
    .aurora .a2{
      right:-18%; bottom:-22%;
      background:
        radial-gradient(circle at 60% 40%, rgba(var(--aurora-blue), .50), transparent 60%),
        radial-gradient(circle at 30% 70%, rgba(var(--aurora-cyan), .35), transparent 60%);
      animation-delay: 8s;
    }
    .aurora .a3{
      top:10%; right:18%;
      background:
        radial-gradient(circle at 50% 50%, rgba(var(--aurora-pink), .35), transparent 62%),
        radial-gradient(circle at 20% 80%, rgba(255,255,255,.10), transparent 60%);
      animation-delay: 16s;
    }
    @keyframes drift {
      0%,100% { transform: translate3d(0,0,0) scale(1); }
      50%     { transform: translate3d(-22px,14px,0) scale(1.06); }
    }

    /* Dots */
    .texture-dots{
      position:absolute; inset:0; pointer-events:none;
      background-image: radial-gradient(rgba(255,255,255,.06) 1px, transparent 1.5px);
      background-size: 10px 10px;
      mix-blend-mode: screen;
      opacity:.18;
    }

    /* Noise overlay (modern grain) */
    .noise{
      position:absolute; inset:0; pointer-events:none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='220'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='220' height='220' filter='url(%23n)' opacity='.35'/%3E%3C/svg%3E");
      opacity:.12;
      mix-blend-mode: overlay;
    }

    /* Bokeh */
    .bokeh { position:absolute; inset:0; pointer-events:none; overflow:hidden; mix-blend-mode: screen; opacity:.9; }
    .bokeh span{
      position:absolute; width: var(--s, 22px); height: var(--s, 22px);
      border-radius: 9999px;
      background: radial-gradient(circle at 40% 40%, rgba(255,255,255,.9), rgba(255,255,255,0) 60%);
      opacity: .06; filter: blur(1px);
      top: var(--t, 50%); left: var(--l, 50%);
      animation: float var(--d, 16s) ease-in-out infinite; will-change: transform;
    }
    @keyframes float { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-34px) } }

    /* Buttons */
    .btn-glossy{ position: relative; overflow: hidden; }
    .btn-glossy::after{
      content:""; position:absolute; inset:-150% -50% 0;
      background: linear-gradient(120deg, transparent, rgba(255,255,255,.35), transparent);
      transform: translateX(-100%); transition: transform .8s cubic-bezier(.19,1,.22,1);
    }
    .btn-glossy:hover::after{ transform: translateX(100%); }

    /* Gradient border utility */
    .grad-border{ position: relative; border-radius: 1.5rem; }
    .grad-border::before{
      content:""; position:absolute; inset:0; padding:1px; border-radius:inherit;
      background: linear-gradient(90deg, rgba(99,102,241,.55), rgba(34,211,238,.45), rgba(236,72,153,.40));
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor; mask-composite: exclude;
    }
    .grad-border > .inner{
      border-radius: inherit;
      background: rgba(255,255,255,.92);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    /* Tilt */
    .tilt { will-change: transform; transform-style: preserve-3d; transition: transform .2s ease, box-shadow .2s ease; }
    .tilt:hover { box-shadow: 0 18px 60px rgba(2,6,23,.14); }

    @media (prefers-reduced-motion: reduce) {
      .reveal { transition: none; }
      .typed-caret::after { animation: none; }
      .aurora span, .bokeh span { animation: none !important; }
      .btn-glossy::after { display: none; }
      .tilt { transition: none !important; }
    }
  </style>
</head>

<body class="bg-white text-slate-800 flex flex-col min-h-screen overflow-x-hidden">
  <!-- Scroll progress -->
  <div id="scrollProgress" class="fixed top-0 left-0 h-1 bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-400 z-[60]"></div>

  <div id="top"></div>

  <!-- Skip link -->
  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 bg-brand-600 text-white px-4 py-2 rounded-lg shadow-soft">
    Skip to content
  </a>

  <!-- Navbar -->
  <?php include 'components/navbar.php'; ?>

  <!-- HERO (more modern) -->
  <section class="relative overflow-hidden">
    <!-- Background image + overlays -->
    <img id="heroBg" src="./images/hero-bg.jpg" alt="" class="absolute inset-0 w-full h-full object-cover" fetchpriority="high" />
    <div class="absolute inset-0 bg-gradient-to-br from-slate-950/88 via-indigo-950/70 to-cyan-950/60"></div>
    <div class="aurora" aria-hidden="true">
      <span class="a1"></span><span class="a2"></span><span class="a3"></span>
    </div>
    <div class="texture-dots" aria-hidden="true"></div>
    <div class="noise" aria-hidden="true"></div>
    <div class="bokeh" aria-hidden="true">
      <span style="--s:26px;--d:17s;--t:18%;--l:22%"></span>
      <span style="--s:18px;--d:14s;--t:12%;--l:68%"></span>
      <span style="--s:22px;--d:19s;--t:40%;--l:8%"></span>
      <span style="--s:16px;--d:16s;--t:62%;--l:82%"></span>
      <span style="--s:28px;--d:21s;--t:78%;--l:34%"></span>
      <span style="--s:20px;--d:15s;--t:86%;--l:58%"></span>
    </div>

    <div class="relative z-10 mx-auto max-w-7xl px-6 pt-28 pb-16 w-full" id="main">
      <div class="grid items-center gap-10 lg:grid-cols-2">
        <!-- Left -->
        <div class="text-left">
          <div class="reveal inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm text-white/90 backdrop-blur">
            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
            SynapZ VLE — fast, clean, secure
          </div>

          <div class="reveal mt-7 flex items-center gap-3">
            <div class="relative">
              <div class="absolute inset-0 rounded-full bg-white/12 blur-xl"></div>
              <img src="./images/logo.png" alt="SynapZ Logo" class="relative h-12 w-auto drop-shadow-lg" loading="eager" decoding="async">
            </div>
            <span class="text-white/70 text-sm">SynapZ Learning</span>
          </div>

          <h1 class="reveal mt-4 text-4xl sm:text-5xl lg:text-5xl font-extrabold tracking-tight text-white">
            A modern platform to
            <span class="block bg-gradient-to-r from-white via-blue-200 to-cyan-200 bg-clip-text text-transparent">
              learn, track, and improve
            </span>
          </h1>

          <p class="reveal mt-4 text-lg text-white/80 max-w-xl leading-relaxed">
            A VLE built for
            <span class="font-semibold text-blue-200 typed-caret" id="typedWords" aria-live="polite">students</span>.
            Assignments, resources, progress and communication — in one place.
          </p>

          <div class="reveal mt-8 flex flex-wrap items-center gap-3">
            <?php if (!empty($_SESSION['user_id'])): ?>
              <?php if (($_SESSION['role'] ?? '') === 'student'): ?>
                <a href="student_dashboard.php"
                   class="btn-glossy inline-flex items-center gap-2 rounded-xl bg-brand-600 px-6 py-3 text-white shadow-soft hover:bg-brand-500 focus:outline-none focus:ring-2 focus:ring-white/30">
                  <ion-icon name="school-outline" class="text-xl"></ion-icon> Go to Dashboard
                </a>
              <?php elseif (($_SESSION['role'] ?? '') === 'teacher'): ?>
                <a href="teacher_dashboard.php"
                   class="btn-glossy inline-flex items-center gap-2 rounded-xl bg-fuchsia-600 px-6 py-3 text-white shadow-soft hover:bg-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-white/30">
                  <ion-icon name="easel-outline" class="text-xl"></ion-icon> Go to Dashboard
                </a>
              <?php else: ?>
                <a href="admin_dashboard.php"
                   class="btn-glossy inline-flex items-center gap-2 rounded-xl bg-slate-700 px-6 py-3 text-white shadow-soft hover:bg-slate-600 focus:outline-none focus:ring-2 focus:ring-white/30">
                  <ion-icon name="shield-checkmark-outline" class="text-xl"></ion-icon> Go to Dashboard
                </a>
              <?php endif; ?>

              <a href="logout.php"
                 class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-6 py-3 text-white/90 hover:bg-white/10 backdrop-blur">
                <ion-icon name="log-out-outline"></ion-icon> Logout
              </a>
            <?php else: ?>
              <a href="register.php"
                 class="btn-glossy inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-6 py-3 text-white shadow-soft hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-white/30">
                <ion-icon name="person-add-outline" class="text-xl"></ion-icon> Get started
              </a>

              <a href="login.php"
                 class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-6 py-3 text-white/90 hover:bg-white/10 backdrop-blur">
                <ion-icon name="log-in-outline" class="text-xl"></ion-icon> Login
              </a>

              <a href="#about" class="inline-flex items-center gap-2 text-white/80 hover:text-white transition">
                Learn more <ion-icon name="arrow-forward-outline"></ion-icon>
              </a>
            <?php endif; ?>
          </div>

          <div class="reveal mt-7 flex flex-wrap gap-2 text-xs text-white/85">
            <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1 backdrop-blur">Secure</span>
            <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1 backdrop-blur">Mobile-ready</span>
            <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1 backdrop-blur">Fast dashboards</span>
            <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1 backdrop-blur">24/7 access</span>
          </div>

          <div class="reveal mt-8 flex items-center gap-4 text-white/80">
            <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" aria-label="Facebook" class="hover:text-white transition">
              <ion-icon name="logo-facebook" class="text-2xl"></ion-icon>
            </a>
            <a href="https://twitter.com" target="_blank" rel="noopener noreferrer" aria-label="Twitter" class="hover:text-white transition">
              <ion-icon name="logo-twitter" class="text-2xl"></ion-icon>
            </a>
            <a href="https://instagram.com" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="hover:text-white transition">
              <ion-icon name="logo-instagram" class="text-2xl"></ion-icon>
            </a>
            <a href="https://linkedin.com" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" class="hover:text-white transition">
              <ion-icon name="logo-linkedin" class="text-2xl"></ion-icon>
            </a>
          </div>
        </div>

        <!-- Right: screenshot card (newer look) -->
        <div class="reveal">
          <div class="grad-border shadow-soft2">
            <div class="inner rounded-3xl overflow-hidden">
              <div class="bg-slate-950/90 px-5 py-4 border-b border-white/10 flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-red-400"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-yellow-400"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                <span class="ml-3 text-sm text-white/75">SynapZ Dashboard Preview</span>
              </div>

              <div class="p-4 bg-slate-950/90">
                <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/5">
                  <!-- Use your real screenshot here -->
                  <picture>
                    <!-- If you have a WEBP version -->
                    <!-- <source srcset="./images/hero2.webp" type="image/webp"> -->
                    <source srcset="./images/hero2.png" type="image/png">
                    <img
                      src="./images/hero2.png"
                      alt="SynapZ dashboard preview screenshot"
                      class="w-full h-[400px] object-cover"
                      loading="lazy"
                      decoding="async"
                      onerror="this.onerror=null;this.src='./images/hero-bg.jpg';"
                    />
                  </picture>

                  <div class="absolute inset-0 bg-gradient-to-t from-slate-950/45 via-transparent to-transparent"></div>

                  <div class="absolute bottom-3 left-3 inline-flex items-center gap-2 rounded-full bg-slate-950/55 px-3 py-1 text-xs text-white/90 border border-white/10 backdrop-blur">
                    <ion-icon name="sparkles-outline"></ion-icon>
                    Live progress & assignments
                  </div>
                </div>

                <!-- mini bento metrics -->
                <div class="mt-4 grid grid-cols-3 gap-3">
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3 text-center">
                    <div class="text-[11px] text-white/60">Progress</div>
                    <div class="mt-1 text-white font-extrabold">82%</div>
                  </div>
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3 text-center">
                    <div class="text-[11px] text-white/60">Due</div>
                    <div class="mt-1 text-white font-extrabold">7</div>
                  </div>
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3 text-center">
                    <div class="text-[11px] text-white/60">Messages</div>
                    <div class="mt-1 text-white font-extrabold">3</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-6 flex items-center justify-center gap-2 text-white/70 text-sm">
            <span>Scroll</span>
            <ion-icon name="chevron-down-outline" aria-hidden="true" class="animate-bounce"></ion-icon>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- STATS (bento) -->
  <section class="py-16 bg-gradient-to-b from-white to-indigo-50">
    <div class="max-w-7xl mx-auto px-6">
      <div class="reveal flex items-end justify-between gap-6 flex-wrap mb-8">
        <div>
          <p class="text-xs font-semibold tracking-widest text-brand-700 uppercase">Platform</p>
          <h2 class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900">Designed for momentum</h2>
          <p class="text-slate-600 mt-1">Everything you need, without clutter.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
        <div class="tilt reveal md:col-span-5 rounded-2xl border border-slate-200 bg-white shadow-soft p-6">
          <div class="flex items-center justify-between">
            <div class="w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl">
              <ion-icon name="people-outline"></ion-icon>
            </div>
            <span class="text-xs text-slate-500">Community</span>
          </div>
          <div class="mt-5 text-4xl font-extrabold text-slate-900">
            <span class="countup" data-target="5000">0</span>+
          </div>
          <div class="text-slate-600 mt-2">Active Students learning daily</div>
          <div class="mt-5 h-2 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full w-[78%] bg-gradient-to-r from-brand-600 to-cyan-400"></div>
          </div>
          <div class="mt-2 text-xs text-slate-500">Stable growth month-to-month</div>
        </div>

        <div class="tilt reveal md:col-span-7 rounded-2xl border border-slate-200 bg-white shadow-soft p-6">
          <div class="flex items-center justify-between">
            <div class="w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
              <ion-icon name="ribbon-outline"></ion-icon>
            </div>
            <span class="text-xs text-slate-500">Quality</span>
          </div>

          <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4">
              <div class="text-xs text-slate-500">Expert Tutors</div>
              <div class="mt-2 text-3xl font-extrabold text-slate-900">
                <span class="countup" data-target="200">0</span>+
              </div>
            </div>

            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4">
              <div class="text-xs text-slate-500">Courses</div>
              <div class="mt-2 text-3xl font-extrabold text-slate-900">
                <span class="countup" data-target="350">0</span>+
              </div>
            </div>

            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4">
              <div class="text-xs text-slate-500">Lessons</div>
              <div class="mt-2 text-3xl font-extrabold text-slate-900">
                <span class="countup" data-target="15000">0</span>+
              </div>
            </div>
          </div>

          <div class="mt-5 rounded-2xl border border-slate-200 bg-gradient-to-r from-indigo-50 to-cyan-50 p-4">
            <div class="flex items-center gap-2 text-slate-700">
              <ion-icon name="shield-checkmark-outline" class="text-lg"></ion-icon>
              <span class="font-semibold">Secure by default</span>
              <span class="text-slate-500 text-sm">— role-based dashboards</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ABOUT -->
  <section id="about" class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-6">
      <div class="reveal text-center mb-12">
        <p class="text-xs font-semibold tracking-widest text-brand-700 uppercase">About</p>
        <h2 class="mt-2 text-3xl sm:text-4xl font-extrabold text-slate-900">
          Built for <span class="text-brand-600">students</span>, teachers, and admins
        </h2>
        <p class="text-slate-600 text-base sm:text-lg max-w-3xl mx-auto mt-4 leading-relaxed">
          SynapZ is a modern Learning Management System connecting students, teachers, and admins
          in a seamless, collaborative environment.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <article class="tilt reveal rounded-2xl border border-slate-200 bg-white shadow-soft p-6" role="region" aria-labelledby="students-title">
          <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-2xl mb-4" aria-hidden="true">
            <ion-icon name="laptop-outline"></ion-icon>
          </div>
          <h3 id="students-title" class="text-xl font-semibold mb-2 text-slate-900">For Students</h3>
          <p class="text-slate-600">Access materials, submit assignments, and collaborate in real time.</p>
        </article>

        <article class="tilt reveal rounded-2xl border border-slate-200 bg-white shadow-soft p-6" role="region" aria-labelledby="teachers-title">
          <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-2xl mb-4" aria-hidden="true">
            <ion-icon name="easel-outline"></ion-icon>
          </div>
          <h3 id="teachers-title" class="text-xl font-semibold mb-2 text-slate-900">For Teachers</h3>
          <p class="text-slate-600">Manage classes, track progress, and provide targeted feedback.</p>
        </article>

        <article class="tilt reveal rounded-2xl border border-slate-200 bg-white shadow-soft p-6" role="region" aria-labelledby="admins-title">
          <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-2xl mb-4" aria-hidden="true">
            <ion-icon name="settings-outline"></ion-icon>
          </div>
          <h3 id="admins-title" class="text-xl font-semibold mb-2 text-slate-900">For Admins</h3>
          <p class="text-slate-600">Oversee operations, generate reports, and ensure smooth learning.</p>
        </article>
      </div>
    </div>
  </section>

  <!-- COURSES -->
  <section id="courses" class="py-24 bg-gradient-to-br from-white via-indigo-50 to-white">
    <div class="max-w-7xl mx-auto px-6">
      <div class="text-center">
        <p class="reveal text-xs font-semibold tracking-widest text-brand-700 uppercase">Courses</p>
        <h2 class="reveal mt-2 text-3xl sm:text-4xl font-extrabold text-slate-900">
          Explore <span class="text-brand-600">Courses</span>
        </h2>
        <p class="reveal text-slate-600 text-base sm:text-lg mt-4 mb-12 max-w-3xl mx-auto leading-relaxed">
          ICT and Computer Science subjects tailored for IGCSE and IAL (AS & AS2).
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- IGCSE ICT -->
        <article class="tilt reveal rounded-2xl overflow-hidden border border-slate-200 bg-white shadow-soft group">
          <div class="relative">
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRFRObkO8H_uYDj0uuGJ1vlSPl4i-qFHG92YQ&s"
                 alt="IGCSE ICT" class="w-full h-48 object-cover group-hover:scale-[1.03] transition duration-300" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/40 via-transparent to-transparent"></div>
            <div class="absolute top-3 left-3 inline-flex items-center gap-1.5 bg-white/95 text-slate-900 text-xs px-2.5 py-1 rounded-full border border-slate-200">
              <ion-icon name="layers-outline"></ion-icon> IGCSE
            </div>
          </div>
          <div class="p-5">
            <h3 class="font-bold text-xl text-slate-900">IGCSE ICT</h3>
            <p class="text-slate-600 mt-2">Practical & theory aligned with Cambridge standards.</p>
            <div class="mt-4 flex items-center justify-between">
              <span class="text-xs text-slate-500">Updated resources</span>
              <a href="#"
                 class="text-sm font-semibold text-brand-700 hover:text-brand-800">
                View
              </a>
            </div>
          </div>
        </article>

        <!-- IAL AS ICT -->
        <article class="tilt reveal rounded-2xl overflow-hidden border border-slate-200 bg-white shadow-soft group">
          <div class="relative">
            <img src="https://aotscolombiajapon.com/wp-content/uploads/2025/01/3ra-Beca-IA-Utilizing-to-overcome-DX-related-1.jpg"
                 alt="IAL AS ICT" class="w-full h-48 object-cover group-hover:scale-[1.03] transition duration-300" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/40 via-transparent to-transparent"></div>
            <div class="absolute top-3 left-3 inline-flex items-center gap-1.5 bg-white/95 text-slate-900 text-xs px-2.5 py-1 rounded-full border border-slate-200">
              <ion-icon name="layers-outline"></ion-icon> IAL AS
            </div>
          </div>
          <div class="p-5">
            <h3 class="font-bold text-xl text-slate-900">IAL AS ICT</h3>
            <p class="text-slate-600 mt-2">Core ICT systems, data handling, and fundamentals.</p>
            <div class="mt-4 flex items-center justify-between">
              <span class="text-xs text-slate-500">Structured lessons</span>
              <a href="#" class="text-sm font-semibold text-brand-700 hover:text-brand-800">View</a>
            </div>
          </div>
        </article>

        <!-- IAL AS2 ICT -->
        <article class="tilt reveal rounded-2xl overflow-hidden border border-slate-200 bg-white shadow-soft group">
          <div class="relative">
            <img src="https://www.ict.eu/sites/corporate/files/images/iStock-1322517295%20copy_3.jpg"
                 alt="IAL AS2 ICT" class="w-full h-48 object-cover group-hover:scale-[1.03] transition duration-300" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/40 via-transparent to-transparent"></div>
            <div class="absolute top-3 left-3 inline-flex items-center gap-1.5 bg-white/95 text-slate-900 text-xs px-2.5 py-1 rounded-full border border-slate-200">
              <ion-icon name="layers-outline"></ion-icon> IAL AS2
            </div>
          </div>
          <div class="p-5">
            <h3 class="font-bold text-xl text-slate-900">IAL AS2 ICT</h3>
            <p class="text-slate-600 mt-2">Advanced skills with real-world problem solving.</p>
            <div class="mt-4 flex items-center justify-between">
              <span class="text-xs text-slate-500">Exam-ready</span>
              <a href="#" class="text-sm font-semibold text-brand-700 hover:text-brand-800">View</a>
            </div>
          </div>
        </article>

        <!-- IGCSE CS -->
        <article class="tilt reveal rounded-2xl overflow-hidden border border-slate-200 bg-white shadow-soft group">
          <div class="relative">
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTiX_sE8HNgliGkDZNJaestGinmoLUp1ab5Eg&s"
                 alt="IGCSE Computer Science" class="w-full h-48 object-cover group-hover:scale-[1.03] transition duration-300" loading="lazy" decoding="async">
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/40 via-transparent to-transparent"></div>
            <div class="absolute top-3 left-3 inline-flex items-center gap-1.5 bg-white/95 text-slate-900 text-xs px-2.5 py-1 rounded-full border border-slate-200">
              <ion-icon name="layers-outline"></ion-icon> IGCSE
            </div>
          </div>
          <div class="p-5">
            <h3 class="font-bold text-xl text-slate-900">IGCSE Computer Science</h3>
            <p class="text-slate-600 mt-2">Algorithms, coding, and computer systems design.</p>
            <div class="mt-4 flex items-center justify-between">
              <span class="text-xs text-slate-500">Practice-first</span>
              <a href="#" class="text-sm font-semibold text-brand-700 hover:text-brand-800">View</a>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- TESTIMONIALS -->
  <section class="py-20 bg-white" aria-label="Success Stories">
    <div class="max-w-7xl mx-auto px-6 text-center">
      <p class="reveal text-xs font-semibold tracking-widest text-brand-700 uppercase">Results</p>
      <h2 class="reveal mt-2 text-3xl sm:text-4xl font-extrabold text-slate-900">Success Stories</h2>
      <p class="reveal text-slate-600 text-lg mt-4 mb-12 max-w-3xl mx-auto">
        Students reach their goals with <span class="font-semibold text-brand-700">SynapZ</span>.
      </p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-left">
        <article class="tilt reveal rounded-2xl border border-slate-200 bg-white shadow-soft p-6">
          <div class="flex items-start gap-4">
            <img src="./images/Men.jpg" alt="Nisansala D." class="w-12 h-12 rounded-full border border-slate-200" loading="lazy" decoding="async" />
            <div>
              <div class="flex items-center gap-1 text-amber-500 text-sm">
                <ion-icon name="star"></ion-icon><ion-icon name="star"></ion-icon><ion-icon name="star"></ion-icon><ion-icon name="star"></ion-icon><ion-icon name="star"></ion-icon>
              </div>
              <p class="text-slate-700 mt-2 leading-relaxed">
                “I passed my A/Ls with distinction thanks to the amazing support here.”
              </p>
              <p class="mt-3 font-semibold text-slate-900">– Nisansala D.</p>
            </div>
          </div>
        </article>

        <article class="tilt reveal rounded-2xl border border-slate-200 bg-white shadow-soft p-6">
          <div class="flex items-start gap-4">
            <img src="./images/Men.jpg" alt="Kaveen R." class="w-12 h-12 rounded-full border border-slate-200" loading="lazy" decoding="async" />
            <div>
              <div class="flex items-center gap-1 text-amber-500 text-sm">
                <ion-icon name="star"></ion-icon><ion-icon name="star"></ion-icon><ion-icon name="star"></ion-icon><ion-icon name="star"></ion-icon><ion-icon name="star-half"></ion-icon>
              </div>
              <p class="text-slate-700 mt-2 leading-relaxed">
                “The courses are clear and easy to follow. Learning is so flexible now.”
              </p>
              <p class="mt-3 font-semibold text-slate-900">– Kaveen R.</p>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- FINAL CTA -->
  <section class="py-16 bg-gradient-to-r from-indigo-600 to-cyan-500">
    <div class="max-w-7xl mx-auto px-6">
      <div class="reveal rounded-3xl border border-white/20 bg-white/10 backdrop-blur p-8 sm:p-10 text-white shadow-soft">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
          <div>
            <p class="text-white/80 text-sm">Ready when you are</p>
            <h3 class="mt-2 text-2xl sm:text-3xl font-extrabold">Start learning with SynapZ today</h3>
            <p class="mt-2 text-white/80 max-w-2xl">
              Create an account, join your class, and keep everything organized in one dashboard.
            </p>
          </div>
          <div class="flex flex-wrap gap-3">
            <?php if (empty($_SESSION['user_id'])): ?>
              <a href="register.php" class="btn-glossy inline-flex items-center gap-2 rounded-xl bg-white text-slate-900 px-6 py-3 font-semibold shadow-soft hover:bg-slate-50">
                <ion-icon name="person-add-outline" class="text-xl"></ion-icon> Register
              </a>
              <a href="login.php" class="inline-flex items-center gap-2 rounded-xl border border-white/30 bg-white/10 px-6 py-3 text-white hover:bg-white/15">
                <ion-icon name="log-in-outline" class="text-xl"></ion-icon> Login
              </a>
            <?php else: ?>
              <a href="<?php
                $r = $_SESSION['role'] ?? '';
                echo $r === 'student' ? 'student_dashboard.php' : ($r === 'teacher' ? 'teacher_dashboard.php' : 'admin_dashboard.php');
              ?>" class="btn-glossy inline-flex items-center gap-2 rounded-xl bg-white text-slate-900 px-6 py-3 font-semibold shadow-soft hover:bg-slate-50">
                <ion-icon name="grid-outline" class="text-xl"></ion-icon> Open Dashboard
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <?php include 'components/footer.php'; ?>

  <!-- Cookie Consent (Banner + Modal) -->
  <?php include 'components/cookie-consent.php'; ?>

  <!-- Back to top -->
  <a href="#top" id="backToTop"
     class="hidden fixed bottom-6 right-6 z-50 bg-brand-600 text-white p-3 rounded-full shadow-soft hover:bg-brand-700 transition"
     aria-label="Back to top">
     <ion-icon name="arrow-up-outline" class="text-xl"></ion-icon>
  </a>

  <!-- Scripts -->
  <script>
    // Reveal on scroll
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('in-view'); });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

    // Back to top
    const backBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 600) backBtn.classList.remove('hidden');
      else backBtn.classList.add('hidden');
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
          if (j === current.length) { deleting = true; setTimeout(tick, 1100); return; }
        } else {
          el.textContent = current.slice(0, j - 1);
          j--;
          if (j === 0) { deleting = false; i = (i + 1) % words.length; }
        }
        setTimeout(tick, deleting ? 55 : 85);
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

    // Cookie Consent (unchanged)
    (function() {
      const KEY = 'synapz_cookie_consent_v1';
      const SIX_MONTHS = 15552000 * 1000;

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

      function applyConsent(c) {}

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

    // Hero parallax + tilt
    (function() {
      const hero = document.getElementById('heroBg');
      const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      if (hero && !prefersReduced) {
        const onScroll = () => {
          const y = window.scrollY || 0;
          const offset = Math.min(70, y * 0.12);
          hero.style.transform = `translateY(${offset}px) scale(1.07)`;
          hero.style.transformOrigin = 'center';
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
      }

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