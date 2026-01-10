<?php
session_start();
require_once __DIR__ . '/db_connect.php'; // mysqli $conn

function h($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function level_to_filter(string $level): string {
  $level = strtoupper(trim($level));
  if ($level === 'IGCSE') return 'igcse';
  if ($level === 'A/L')   return 'ial';
  return 'other';
}
function shorten(string $text, int $max = 150): string {
  $text = trim(preg_replace('/\s+/', ' ', $text));
  if ($text === '') return '';
  if (function_exists('mb_strlen')) {
    return (mb_strlen($text) > $max) ? mb_substr($text, 0, $max - 1) . '…' : $text;
  }
  return (strlen($text) > $max) ? substr($text, 0, $max - 1) . '…' : $text;
}

$sql = "
  SELECT
    c.course_id,
    c.name,
    c.description,
    c.cover_image,
    ct.board,
    ct.level,
    ct.description AS type_description
  FROM courses c
  INNER JOIN course_types ct ON ct.course_type_id = c.course_type_id
  ORDER BY
    FIELD(ct.level,'IGCSE','A/L','O/L','Others'),
    c.name
";
$result = $conn->query($sql);

$courses = [];
$stats = [
  'total' => 0,
  'igcse' => 0,
  'ial'   => 0,
  'other' => 0
];

if ($result) {
  while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
    $stats['total']++;
    $k = level_to_filter($row['level'] ?? 'Others');
    if (!isset($stats[$k])) $stats[$k] = 0;
    $stats[$k]++;
  }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SynapZ Courses</title>
  <meta name="description" content="Explore ICT and Computer Science courses tailored for IGCSE and IAL students on SynapZ." />
  <meta name="theme-color" content="#2563eb" />

  <script src="https://cdn.tailwindcss.com"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <link rel="icon" type="image/png" href="./images/logo.png" />

  <style>
    body {
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      color: #0f172a;
      background-color: #ffffff;
    }

    /* Reveal on scroll */
    .reveal { opacity: 0; transform: translateY(14px); transition: opacity .6s ease, transform .6s ease; }
    .reveal.in-view { opacity: 1; transform: translateY(0); }

    /* Hero background */
    .hero-courses {
      position: relative;
      overflow: hidden;
      background:
        radial-gradient(circle at 10% 10%, rgba(191,219,254,.65), transparent 55%),
        radial-gradient(circle at 90% 30%, rgba(99,102,241,.45), transparent 55%),
        radial-gradient(circle at 70% 90%, rgba(34,211,238,.35), transparent 55%),
        linear-gradient(to bottom right, #0b1220, #1d4ed8);
    }
    .hero-grid {
      background-image:
        linear-gradient(to right, rgba(255,255,255,.06) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255,255,255,.06) 1px, transparent 1px);
      background-size: 46px 46px;
      mask-image: radial-gradient(circle at 30% 30%, black 35%, transparent 70%);
      opacity: .55;
      position: absolute;
      inset: 0;
      pointer-events: none;
    }

    /* Glass panels */
    .glass {
      background: rgba(255,255,255,.08);
      border: 1px solid rgba(255,255,255,.14);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    /* Card */
    .card-soft {
      background: rgba(255,255,255,.98);
      border-radius: 1.25rem;
      border: 1px solid rgba(226,232,240,.95);
      box-shadow:
        0 18px 50px rgba(15,23,42,.06),
        0 1px 0 rgba(148,163,184,.25);
    }

    /* Image shine */
    .img-sheen::after{
      content:"";
      position:absolute;
      inset:-40%;
      background: linear-gradient(115deg, transparent 35%, rgba(255,255,255,.18) 45%, transparent 55%);
      transform: translateX(-60%) rotate(10deg);
      transition: transform .7s ease;
      pointer-events:none;
    }
    .group:hover .img-sheen::after{
      transform: translateX(50%) rotate(10deg);
    }

    /* Tilt */
    .tilt { will-change: transform; transform-style: preserve-3d; transition: transform .18s ease, box-shadow .18s ease; }
    .tilt:hover { box-shadow: 0 24px 65px rgba(15,23,42,.16); }

    /* Filter chips (no @apply with Tailwind CDN) */
    .filter-chip{
      display:inline-flex; align-items:center; gap:.5rem;
      padding:.55rem 1rem; border-radius:9999px;
      font-size:.875rem; font-weight:700; cursor:pointer;
      transition: all .2s ease;
      user-select:none;
      white-space:nowrap;
    }
    .filter-chip-active{
      background: linear-gradient(90deg, #2563eb, #4f46e5);
      color:#fff;
      box-shadow: 0 14px 24px rgba(37,99,235,.25);
    }
    .filter-chip-inactive{
      background:#fff;
      color:#334155;
      border:1px solid #e2e8f0;
    }
    .filter-chip-inactive:hover{ background:#f8fafc; }

    /* Back to top */
    #backToTop { box-shadow: 0 16px 30px rgba(37,99,235,.35); }
    #backToTop:hover { box-shadow: 0 20px 40px rgba(37,99,235,.45); }

    @media (prefers-reduced-motion: reduce) {
      .reveal, .tilt { transition: none; }
      .img-sheen::after { display:none; }
    }
  </style>
</head>

<body class="bg-white text-gray-800 flex flex-col min-h-screen overflow-x-hidden">
  <div id="top"></div>

  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 bg-blue-600 text-white px-4 py-2 rounded">
    Skip to content
  </a>

  <?php include 'components/navbar.php'; ?>

  <!-- HERO -->
  <section class="hero-courses text-white">
    <div class="hero-grid"></div>

    <div class="max-w-6xl mx-auto px-6 pt-16 pb-12 sm:pt-20 sm:pb-16">
      <div id="main" class="reveal">
        <div class="inline-flex items-center gap-2 text-xs font-bold tracking-[0.22em] uppercase text-blue-100/90">
          <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl glass">
            <ion-icon name="layers-outline" class="text-lg"></ion-icon>
          </span>
          SynapZ Courses
        </div>

        <h1 class="mt-4 text-3xl sm:text-4xl md:text-5xl font-extrabold leading-tight">
          Learn smarter with <span class="text-transparent bg-clip-text bg-gradient-to-r from-yellow-300 via-white to-cyan-200">exam-focused</span> courses
        </h1>

        <p class="mt-4 max-w-2xl text-blue-100/90 text-base sm:text-lg">
          Browse ICT and Computer Science courses by level, search instantly, and open full details when you’re ready.
        </p>

        <!-- Stats -->
        <div class="mt-7 grid grid-cols-2 sm:grid-cols-4 gap-3 max-w-2xl">
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Total Courses</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['total'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">IGCSE</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['igcse'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">IAL (A/L)</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['ial'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Other</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['other'] ?></p>
          </div>
        </div>

        <!-- CTA -->
        <div class="mt-7 flex flex-wrap items-center gap-3">
          <?php if (empty($_SESSION['user_id'])): ?>
            <a href="register.php"
               class="inline-flex items-center gap-2 bg-white text-blue-700 px-5 py-2.5 rounded-xl text-sm font-extrabold shadow hover:bg-blue-50 transition">
              <ion-icon name="person-add-outline"></ion-icon> Create free account
            </a>
            <a href="login.php"
               class="inline-flex items-center gap-2 glass px-5 py-2.5 rounded-xl text-sm font-semibold hover:bg-white/10 transition">
              <ion-icon name="log-in-outline"></ion-icon> Login
            </a>
          <?php else: ?>
            <?php if (($_SESSION['role'] ?? '') === 'student'): ?>
              <a href="student_dashboard.php"
                 class="inline-flex items-center gap-2 bg-white text-blue-700 px-5 py-2.5 rounded-xl text-sm font-extrabold shadow hover:bg-blue-50 transition">
                <ion-icon name="school-outline"></ion-icon> Go to Dashboard
              </a>
            <?php elseif (($_SESSION['role'] ?? '') === 'teacher'): ?>
              <a href="teacher_dashboard.php"
                 class="inline-flex items-center gap-2 bg-white text-blue-700 px-5 py-2.5 rounded-xl text-sm font-extrabold shadow hover:bg-blue-50 transition">
                <ion-icon name="easel-outline"></ion-icon> Go to Dashboard
              </a>
            <?php else: ?>
              <a href="admin_dashboard.php"
                 class="inline-flex items-center gap-2 bg-white text-blue-700 px-5 py-2.5 rounded-xl text-sm font-extrabold shadow hover:bg-blue-50 transition">
                <ion-icon name="shield-checkmark-outline"></ion-icon> Go to Dashboard
              </a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- CONTENT -->
  <main class="flex-1 bg-gradient-to-b from-white via-blue-50/50 to-white py-12">
    <div class="max-w-6xl mx-auto px-6">

      <!-- Toolbar -->
      <div class="reveal card-soft p-4 sm:p-5 mb-8">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900">Available Courses</h2>
            <p class="text-sm text-slate-600 mt-1">Filter by level and search by name/keywords.</p>
          </div>

          <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
            <!-- Search -->
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                <ion-icon name="search-outline"></ion-icon>
              </span>
              <input id="courseSearch" type="text"
                     placeholder="Search courses..."
                     class="w-full sm:w-72 pl-10 pr-10 py-2.5 rounded-xl border border-slate-200 bg-white focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-300 text-sm">
              <button id="clearSearch" type="button"
                      class="hidden absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-2">
                <ion-icon name="close-circle"></ion-icon>
              </button>
            </div>

            <!-- Filter chips -->
            <div class="flex flex-wrap gap-2" id="courseFilters">
              <button data-filter="all" class="filter-chip filter-chip-active" type="button">
                <ion-icon name="grid-outline"></ion-icon> All
              </button>
              <button data-filter="igcse" class="filter-chip filter-chip-inactive" type="button">
                <ion-icon name="school-outline"></ion-icon> IGCSE
              </button>
              <button data-filter="ial" class="filter-chip filter-chip-inactive" type="button">
                <ion-icon name="layers-outline"></ion-icon> IAL
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Grid -->
      <section aria-label="Course list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
        <?php if (empty($courses)): ?>
          <div class="col-span-full card-soft p-6 text-slate-700">
            No courses found in the database.
          </div>
        <?php else: ?>
          <?php foreach ($courses as $course): ?>
            <?php
              $filterLevel = level_to_filter($course['level'] ?? 'Others');

              $title = $course['name'] ?? 'Untitled course';
              $desc  = $course['description'] ?? '';
              $typeDesc = $course['type_description'] ?? '';

              $img = !empty($course['cover_image']) ? $course['cover_image'] : 'images/default-course.jpg';
              $badgeBoard = $course['board'] ?? '';
              $badgeLevel = $course['level'] ?? 'Others';

              $detailsUrl = 'course_details.php?course_id=' . (int)$course['course_id'];
              $link = empty($_SESSION['user_id'])
                ? 'login.php?redirect=' . urlencode($detailsUrl)
                : $detailsUrl;

              // For search filtering in JS
              $searchText = strtolower(($title . ' ' . $desc . ' ' . $typeDesc . ' ' . $badgeBoard . ' ' . $badgeLevel));
            ?>

            <article
              class="tilt reveal card-soft overflow-hidden group flex flex-col"
              data-level="<?= h($filterLevel) ?>"
              data-search="<?= h($searchText) ?>"
              aria-labelledby="course-title-<?= (int)$course['course_id'] ?>"
            >
              <div class="relative">
                <div class="absolute inset-0 img-sheen"></div>

                <img
                  src="<?= h($img) ?>"
                  alt="<?= h($title) ?>"
                  class="w-full h-48 object-cover group-hover:scale-[1.04] transition-transform duration-500"
                  loading="lazy" decoding="async"
                />

                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/15 to-transparent"></div>

                <!-- Badges -->
                <div class="absolute top-3 left-3 flex flex-wrap gap-2">
                  <?php if ($badgeBoard !== ''): ?>
                    <span class="inline-flex items-center gap-1.5 bg-white/95 text-slate-900 text-xs font-extrabold px-2.5 py-1 rounded-full">
                      <ion-icon name="shield-outline"></ion-icon> <?= h($badgeBoard) ?>
                    </span>
                  <?php endif; ?>
                  <span class="inline-flex items-center gap-1.5 bg-blue-600 text-white text-xs font-extrabold px-2.5 py-1 rounded-full">
                    <ion-icon name="school-outline"></ion-icon> <?= h($badgeLevel) ?>
                  </span>
                </div>

                <!-- Title overlay (nice look) -->
                <div class="absolute bottom-3 left-3 right-3">
                  <p class="text-white font-extrabold text-lg leading-snug drop-shadow">
                    <?= h(shorten($title, 52)) ?>
                  </p>
                </div>
              </div>

              <div class="p-5 flex flex-col flex-1">
                <h3 id="course-title-<?= (int)$course['course_id'] ?>" class="sr-only">
                  <?= h($title) ?>
                </h3>

                <p class="text-sm text-slate-600 leading-relaxed flex-1">
                  <?= h(shorten($desc !== '' ? $desc : $typeDesc, 160)) ?>
                </p>

                <div class="mt-4 flex items-center justify-between gap-3 pt-4 border-t border-slate-100">
                  <span class="inline-flex items-center gap-2 text-xs font-bold text-slate-600">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    Updated, exam-focused
                  </span>

                  <a href="<?= h($link) ?>"
                     class="inline-flex items-center gap-2 bg-slate-900 text-white px-3.5 py-2 rounded-xl text-xs font-extrabold hover:bg-slate-800 transition">
                    View details <ion-icon name="arrow-forward-outline"></ion-icon>
                  </a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <!-- Empty state for search -->
      <div id="noResults" class="hidden mt-10 card-soft p-6 text-slate-700 text-center">
        <p class="font-extrabold text-slate-900">No results</p>
        <p class="text-sm text-slate-600 mt-1">Try a different keyword or switch the level filter.</p>
      </div>
    </div>
  </main>

  <!-- Back to top -->
  <a href="#top" id="backToTop"
     class="hidden fixed bottom-6 right-6 z-40 bg-blue-600 text-white p-3 rounded-full hover:bg-blue-700 transition"
     aria-label="Back to top">
    <ion-icon name="arrow-up-outline" class="text-xl"></ion-icon>
  </a>

  <?php include 'components/footer.php'; ?>

  <script>
    // Reveal on scroll
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('in-view'); });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

    // Back to top visibility
    const backBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 400) backBtn.classList.remove('hidden');
      else backBtn.classList.add('hidden');
    });

    // Filter + Search (combined)
    (function() {
      const buttons = document.querySelectorAll('#courseFilters button');
      const cards   = document.querySelectorAll('article[data-level]');
      const search  = document.getElementById('courseSearch');
      const clear   = document.getElementById('clearSearch');
      const noRes   = document.getElementById('noResults');

      if (!buttons.length || !cards.length) return;

      let activeFilter = 'all';
      let query = '';

      function apply() {
        let shown = 0;

        cards.forEach(card => {
          const level = card.dataset.level || 'other';
          const hay   = (card.dataset.search || '').toLowerCase();

          const okFilter = (activeFilter === 'all' || activeFilter === level);
          const okSearch = (!query || hay.includes(query));

          if (okFilter && okSearch) {
            card.classList.remove('hidden');
            shown++;
          } else {
            card.classList.add('hidden');
          }
        });

        if (noRes) {
          noRes.classList.toggle('hidden', shown !== 0);
        }
      }

      // Filter click
      buttons.forEach(btn => {
        btn.addEventListener('click', () => {
          activeFilter = btn.dataset.filter || 'all';

          buttons.forEach(b => {
            b.classList.remove('filter-chip-active', 'filter-chip-inactive');
            b.classList.add(b === btn ? 'filter-chip-active' : 'filter-chip-inactive');
          });

          apply();
        });
      });

      // Search
      if (search) {
        const onSearch = () => {
          query = (search.value || '').trim().toLowerCase();
          if (clear) clear.classList.toggle('hidden', !query);
          apply();
        };
        search.addEventListener('input', onSearch);

        if (clear) {
          clear.addEventListener('click', () => {
            search.value = '';
            search.focus();
            query = '';
            clear.classList.add('hidden');
            apply();
          });
        }
      }

      apply();
    })();

    // Tilt cards (subtle)
    (function() {
      const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const fine = window.matchMedia('(pointer: fine)').matches;
      if (!fine || prefersReduced) return;

      const SENS = 9;
      document.querySelectorAll('.tilt').forEach(card => {
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
    })();
  </script>
</body>
</html>