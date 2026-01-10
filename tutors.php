<?php
session_start();
require_once __DIR__ . '/db_connect.php'; // contains $conn (mysqli)

function h($str) {
  return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function initials_from_name(string $first, string $last): string {
  $first = trim($first);
  $last  = trim($last);
  $i1 = $first !== '' ? (function_exists('mb_substr') ? mb_substr($first, 0, 1) : substr($first, 0, 1)) : '';
  $i2 = $last  !== '' ? (function_exists('mb_substr') ? mb_substr($last,  0, 1) : substr($last,  0, 1)) : '';
  $in = strtoupper($i1 . $i2);
  return $in !== '' ? $in : 'T';
}
function level_to_filter(string $level): string {
  $level = strtoupper(trim($level));
  if ($level === 'IGCSE') return 'igcse';
  if ($level === 'A/L')   return 'ial';   // UI calls it IAL
  return 'other';
}

/**
 * Beautified: includes course_count and keeps grouped course names/levels
 */
$sql = "
  SELECT
    t.teacher_id,
    t.first_name,
    t.last_name,
    t.email,
    COUNT(DISTINCT tc.course_id) AS course_count,
    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '|||') AS course_names,
    GROUP_CONCAT(DISTINCT ct.level ORDER BY ct.level SEPARATOR '|||') AS course_levels
  FROM teachers t
  LEFT JOIN teacher_courses tc ON tc.teacher_id = t.teacher_id
  LEFT JOIN courses c ON c.course_id = tc.course_id
  LEFT JOIN course_types ct ON ct.course_type_id = c.course_type_id
  GROUP BY t.teacher_id, t.first_name, t.last_name, t.email
  ORDER BY t.first_name, t.last_name
";

$result = $conn->query($sql);

$tutors = [];
$stats = ['total' => 0, 'igcse' => 0, 'ial' => 0, 'other' => 0];

if ($result) {
  while ($row = $result->fetch_assoc()) {
    $tutors[] = $row;
    $stats['total']++;

    // Count tutor in each level bucket based on their course_levels
    $levels = [];
    if (!empty($row['course_levels'])) {
      $parts = array_filter(array_map('trim', explode('|||', $row['course_levels'])));
      foreach ($parts as $lv) $levels[level_to_filter($lv)] = true;
    } else {
      $levels['other'] = true;
    }
    foreach (array_keys($levels) as $k) {
      if (isset($stats[$k])) $stats[$k]++;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SynapZ Tutors</title>
  <meta name="description" content="Meet our tutors and explore the courses they teach on SynapZ." />
  <meta name="theme-color" content="#2563eb" />

  <script src="https://cdn.tailwindcss.com"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <link rel="icon" type="image/png" href="./images/logo.png" />

  <style>
    body{
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      color:#0f172a; background:#fff;
    }

    /* Reveal */
    .reveal{ opacity:0; transform:translateY(14px); transition:opacity .6s ease, transform .6s ease; }
    .reveal.in-view{ opacity:1; transform:translateY(0); }

    /* Hero */
    .hero-tutors{
      position:relative; overflow:hidden;
      background:
        radial-gradient(circle at 12% 10%, rgba(191,219,254,.65), transparent 55%),
        radial-gradient(circle at 80% 20%, rgba(99,102,241,.45), transparent 55%),
        radial-gradient(circle at 70% 90%, rgba(34,211,238,.35), transparent 55%),
        linear-gradient(to bottom right, #0b1220, #1d4ed8);
    }
    .hero-grid{
      background-image:
        linear-gradient(to right, rgba(255,255,255,.06) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255,255,255,.06) 1px, transparent 1px);
      background-size: 46px 46px;
      mask-image: radial-gradient(circle at 30% 30%, black 35%, transparent 70%);
      opacity:.55;
      position:absolute; inset:0; pointer-events:none;
    }
    .glass{
      background: rgba(255,255,255,.08);
      border: 1px solid rgba(255,255,255,.14);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    /* Cards */
    .card-soft{
      background: rgba(255,255,255,.98);
      border-radius: 1.25rem;
      border: 1px solid rgba(226,232,240,.95);
      box-shadow: 0 18px 50px rgba(15,23,42,.06), 0 1px 0 rgba(148,163,184,.25);
    }
    .tilt{ will-change: transform; transform-style: preserve-3d; transition: transform .18s ease, box-shadow .18s ease; }
    .tilt:hover{ box-shadow: 0 24px 65px rgba(15,23,42,.16); }

    /* Filter chips (no @apply with Tailwind CDN) */
    .filter-chip{
      display:inline-flex; align-items:center; gap:.5rem;
      padding:.55rem 1rem; border-radius:9999px;
      font-size:.875rem; font-weight:800; cursor:pointer;
      transition: all .2s ease; user-select:none; white-space:nowrap;
    }
    .filter-chip-active{
      background: linear-gradient(90deg, #2563eb, #4f46e5);
      color:#fff;
      box-shadow: 0 14px 24px rgba(37,99,235,.25);
    }
    .filter-chip-inactive{
      background:#fff; color:#334155; border:1px solid #e2e8f0;
    }
    .filter-chip-inactive:hover{ background:#f8fafc; }

    /* Back-to-top */
    #backToTop{ box-shadow: 0 16px 30px rgba(37,99,235,.35); }
    #backToTop:hover{ box-shadow: 0 20px 40px rgba(37,99,235,.45); }

    @media (prefers-reduced-motion: reduce){
      .reveal, .tilt{ transition:none; }
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
  <section class="hero-tutors text-white">
    <div class="hero-grid"></div>

    <div class="max-w-6xl mx-auto px-6 pt-16 pb-12 sm:pt-20 sm:pb-16">
      <div id="main" class="reveal">
        <div class="inline-flex items-center gap-2 text-xs font-extrabold tracking-[0.22em] uppercase text-blue-100/90">
          <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl glass">
            <ion-icon name="people-outline" class="text-lg"></ion-icon>
          </span>
          SynapZ Tutors
        </div>

        <h1 class="mt-4 text-3xl sm:text-4xl md:text-5xl font-extrabold leading-tight">
          Learn with <span class="text-transparent bg-clip-text bg-gradient-to-r from-yellow-300 via-white to-cyan-200">expert</span> tutors
        </h1>

        <p class="mt-4 max-w-2xl text-blue-100/90 text-base sm:text-lg">
          Browse tutors, search by name/course, and filter by the level they teach.
        </p>

        <!-- Stats -->
        <div class="mt-7 grid grid-cols-2 sm:grid-cols-4 gap-3 max-w-2xl">
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Total Tutors</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['total'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">IGCSE Tutors</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['igcse'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">IAL (A/L) Tutors</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['ial'] ?></p>
          </div>
          <div class="glass rounded-2xl px-4 py-3">
            <p class="text-xs text-blue-100/80 font-semibold">Other</p>
            <p class="text-xl font-extrabold mt-1"><?= (int)$stats['other'] ?></p>
          </div>
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
            <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900">Available Tutors</h2>
            <p class="text-sm text-slate-600 mt-1">Search and filter to find the right tutor quickly.</p>
          </div>

          <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
            <!-- Search -->
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                <ion-icon name="search-outline"></ion-icon>
              </span>
              <input id="tutorSearch" type="text"
                     placeholder="Search tutor / email / course..."
                     class="w-full sm:w-80 pl-10 pr-10 py-2.5 rounded-xl border border-slate-200 bg-white focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-300 text-sm">
              <button id="clearSearch" type="button"
                      class="hidden absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-2">
                <ion-icon name="close-circle"></ion-icon>
              </button>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-2" id="tutorFilters">
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

      <?php if (empty($tutors)): ?>
        <div class="card-soft p-6 text-slate-700">
          No tutors found.
        </div>
      <?php else: ?>
        <section aria-label="Tutor list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
          <?php foreach ($tutors as $t): ?>
            <?php
              $first = trim($t['first_name'] ?? '');
              $last  = trim($t['last_name'] ?? '');
              $full  = trim($first . ' ' . $last);
              if ($full === '') $full = 'Tutor';

              $initials = initials_from_name($first, $last);
              $email = trim($t['email'] ?? '');
              $courseCount = (int)($t['course_count'] ?? 0);

              $courseNames = [];
              if (!empty($t['course_names'])) {
                $courseNames = array_filter(array_map('trim', explode('|||', $t['course_names'])));
              }

              $levelsRaw = [];
              $levelsNorm = [];
              if (!empty($t['course_levels'])) {
                $levelsRaw = array_filter(array_map('trim', explode('|||', $t['course_levels'])));
                foreach ($levelsRaw as $lv) $levelsNorm[level_to_filter($lv)] = true;
              } else {
                $levelsNorm['other'] = true;
              }

              $levelBadges = [];
              foreach (array_keys($levelsNorm) as $k) $levelBadges[] = $k;

              // For search
              $searchText = strtolower($full . ' ' . $email . ' ' . implode(' ', $courseNames) . ' ' . implode(' ', $levelsRaw));
              $levelsData = implode(',', $levelBadges);
            ?>

            <article
              class="tilt reveal card-soft overflow-hidden flex flex-col"
              data-levels="<?= h($levelsData) ?>"
              data-search="<?= h($searchText) ?>"
              aria-label="<?= h($full) ?>"
            >
              <!-- Top band -->
              <div class="p-5 bg-gradient-to-br from-blue-600 to-indigo-700 text-white">
                <div class="flex items-start gap-4">
                  <div class="shrink-0 w-12 h-12 rounded-2xl bg-white/15 border border-white/20 flex items-center justify-center font-extrabold">
                    <?= h($initials) ?>
                  </div>

                  <div class="min-w-0 flex-1">
                    <h3 class="font-extrabold text-lg leading-tight truncate">
                      <?= h($full) ?>
                    </h3>

                    <div class="mt-1 flex flex-wrap items-center gap-2">
                      <span class="inline-flex items-center gap-1.5 text-xs font-bold bg-white/15 border border-white/15 px-2.5 py-1 rounded-full">
                        <ion-icon name="book-outline"></ion-icon>
                        <?= (int)$courseCount ?> course<?= $courseCount === 1 ? '' : 's' ?>
                      </span>

                      <?php if (!empty($levelsNorm['igcse'])): ?>
                        <span class="inline-flex items-center gap-1.5 text-xs font-extrabold bg-yellow-300 text-slate-900 px-2.5 py-1 rounded-full">
                          <ion-icon name="school-outline"></ion-icon> IGCSE
                        </span>
                      <?php endif; ?>

                      <?php if (!empty($levelsNorm['ial'])): ?>
                        <span class="inline-flex items-center gap-1.5 text-xs font-extrabold bg-cyan-200 text-slate-900 px-2.5 py-1 rounded-full">
                          <ion-icon name="layers-outline"></ion-icon> IAL
                        </span>
                      <?php endif; ?>
                    </div>

                    <?php if ($email !== ''): ?>
                      <a href="mailto:<?= h($email) ?>" class="mt-2 inline-flex items-center gap-2 text-xs font-semibold text-white/90 hover:text-white truncate">
                        <ion-icon name="mail-outline"></ion-icon>
                        <?= h($email) ?>
                      </a>
                    <?php else: ?>
                      <p class="mt-2 text-xs text-white/80">Email not provided</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Body -->
              <div class="p-5 flex-1 flex flex-col">
                <p class="text-xs font-extrabold text-slate-700 mb-2 flex items-center gap-2">
                  <ion-icon name="bookmark-outline"></ion-icon> Teaches
                </p>

                <?php if (empty($courseNames)): ?>
                  <div class="mt-1 text-sm text-slate-500">
                    No courses assigned yet.
                  </div>
                <?php else: ?>
                  <div class="flex flex-wrap gap-2">
                    <?php foreach ($courseNames as $cn): ?>
                      <span class="text-xs font-semibold bg-white border border-slate-200 text-slate-700 px-2.5 py-1 rounded-full">
                        <?= h($cn) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <div class="mt-auto pt-4 border-t border-slate-100 flex items-center justify-between gap-3">
                  <span class="inline-flex items-center gap-2 text-xs font-bold text-slate-600">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    Verified tutor
                  </span>

                  <?php if ($email !== ''): ?>
                    <a href="mailto:<?= h($email) ?>"
                       class="inline-flex items-center gap-2 bg-slate-900 text-white px-3.5 py-2 rounded-xl text-xs font-extrabold hover:bg-slate-800 transition">
                      Contact <ion-icon name="send-outline"></ion-icon>
                    </a>
                  <?php else: ?>
                    <span class="text-xs font-bold text-slate-400">Contact unavailable</span>
                  <?php endif; ?>
                </div>
              </div>
            </article>

          <?php endforeach; ?>
        </section>

        <div id="noResults" class="hidden mt-10 card-soft p-6 text-slate-700 text-center">
          <p class="font-extrabold text-slate-900">No results</p>
          <p class="text-sm text-slate-600 mt-1">Try a different keyword or switch the level filter.</p>
        </div>
      <?php endif; ?>
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

    // Filter + Search
    (function () {
      const buttons = document.querySelectorAll('#tutorFilters button');
      const cards   = document.querySelectorAll('article[data-levels]');
      const search  = document.getElementById('tutorSearch');
      const clear   = document.getElementById('clearSearch');
      const noRes   = document.getElementById('noResults');

      if (!buttons.length || !cards.length) return;

      let activeFilter = 'all';
      let query = '';

      function apply() {
        let shown = 0;

        cards.forEach(card => {
          const levels = (card.dataset.levels || '');
          const hay    = (card.dataset.search || '').toLowerCase();

          const okFilter = (activeFilter === 'all' || levels.split(',').includes(activeFilter));
          const okSearch = (!query || hay.includes(query));

          if (okFilter && okSearch) {
            card.classList.remove('hidden');
            shown++;
          } else {
            card.classList.add('hidden');
          }
        });

        if (noRes) noRes.classList.toggle('hidden', shown !== 0);
      }

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

    // Tilt (subtle)
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