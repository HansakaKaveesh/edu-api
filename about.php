<?php
session_start();

$site_name   = 'SynapZ VLE'; // change to your brand name
$org_email   = 'support@synapz.lk';
$org_phone   = '+1 (555) 555-1234';
$org_city    = 'Your City';
$org_country = 'Your Country';

// Team (replace with DB fetch if you prefer)
$team = [
  [
    'name' => 'Ava Thompson',
    'role' => 'Head of Learning Design',
    'img'  => 'https://i.pravatar.cc/160?u=ava',
    'bio'  => 'Driving pedagogy-first content with inclusive design.',
    'links'=> ['linkedin' => '#', 'twitter' => '#']
  ],
  [
    'name' => 'Mateo Rivera',
    'role' => 'Engineering Lead',
    'img'  => 'https://i.pravatar.cc/160?u=mateo',
    'bio'  => 'Building reliable, scalable learning infrastructure.',
    'links'=> ['linkedin' => '#', 'github' => '#']
  ],
  [
    'name' => 'Lina Patel',
    'role' => 'Curriculum Strategist',
    'img'  => 'https://i.pravatar.cc/160?u=lina',
    'bio'  => 'Aligning courses with outcomes and assessment.',
    'links'=> ['linkedin' => '#']
  ],
  [
    'name' => 'Ethan Chen',
    'role' => 'Community & Support',
    'img'  => 'https://i.pravatar.cc/160?u=ethan',
    'bio'  => 'Championing learner success every step of the way.',
    'links'=> ['linkedin' => '#', 'twitter' => '#']
  ],
];

$milestones = [
  ['year' => '2019', 'title' => 'Founded', 'desc' => 'Started with a mission to make learning accessible and engaging.'],
  ['year' => '2020', 'title' => 'First 10k Learners', 'desc' => 'Scaled courses and tools to support diverse classrooms.'],
  ['year' => '2022', 'title' => 'Interactive Quizzing Suite', 'desc' => 'Launched adaptive assessments and analytics.'],
  ['year' => '2024', 'title' => 'Global Classrooms', 'desc' => 'Serving schools and educators in 30+ countries.'],
];

$values = [
  ['icon' => 'ph-graduation-cap', 'title' => 'Student-first', 'desc' => 'We design for real learning outcomes and inclusion.'],
  ['icon' => 'ph-lightbulb', 'title' => 'Curiosity', 'desc' => 'We experiment, iterate, and learn by doing.'],
  ['icon' => 'ph-shield-check', 'title' => 'Trust', 'desc' => 'Privacy, accessibility, and reliability are non‑negotiable.'],
  ['icon' => 'ph-rocket-launch', 'title' => 'Impact', 'desc' => 'We focus on tools that measurably help learners grow.'],
];

$testimonials = [
  ['quote' => 'Our students are more engaged than ever. The platform makes teaching interactive and fun.', 'name' => 'Ms. Riley, High School Teacher'],
  ['quote' => 'Setup was simple, and the analytics help us tailor support for every learner.', 'name' => 'Dr. Ahmed, Principal'],
];

// Impact stats
$stats = [
  ['label' => 'Learners',         'value' => 125000],
  ['label' => 'Courses Delivered','value' => 1850],
  ['label' => 'Avg. Satisfaction','value' => 96, 'suffix' => '%'],
  ['label' => 'Countries Reached','value' => 32],
];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>About Us — <?= htmlspecialchars($site_name) ?></title>
  <meta name="description" content="Learn more about <?= htmlspecialchars($site_name) ?> — our mission, team, values, and the impact we’re making in online education." />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: {
              50:'#eef6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',
              400:'#60a5fa',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',
              800:'#1e40af',900:'#1e3a8a'
            }
          },
          boxShadow: {
            glow: '0 10px 30px rgba(59,130,246,0.18)',
          },
          keyframes: {
            float:{'0%,100%':{transform:'translateY(0)'},'50%':{transform:'translateY(-8px)'}},
            fadeInUp:{'0%':{opacity:0,transform:'translateY(12px)'},'100%':{opacity:1,transform:'translateY(0)'}}
          },
          animation: { float:'float 8s ease-in-out infinite', fadeInUp:'fadeInUp .5s ease both' }
        }
      }
    }
  </script>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    .glass { background: rgba(255,255,255,.85); border: 1px solid rgba(255,255,255,.6); backdrop-filter: blur(10px); }
    .hero-pattern:before,.hero-pattern:after{
      content:""; position:absolute; border-radius:50%; filter:blur(60px); opacity:.35; pointer-events:none;
    }
    .hero-pattern:before{ width:420px; height:420px; background:#60a5fa; left:-60px; top:-40px; animation: float 9s ease-in-out infinite; }
    .hero-pattern:after{ width:460px; height:460px; background:#22d3ee; right:-60px; bottom:-80px; animation: float 7s ease-in-out infinite; }
  </style>

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": <?= json_encode($site_name) ?>,
    "url": "https://example.com",
    "email": <?= json_encode($org_email) ?>,
    "address": {
      "@type": "PostalAddress",
      "addressLocality": <?= json_encode($org_city) ?>,
      "addressCountry": <?= json_encode($org_country) ?>
    }
  }
  </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-blue-50 text-gray-800 selection:bg-blue-100/80 selection:text-blue-900">

<?php if (file_exists(__DIR__ . '/components/navbar.php')) include __DIR__ . '/components/navbar.php'; ?>

<!-- Hero -->
<section class="relative h-[18rem] md:h-[24rem] w-full overflow-hidden">
  <div class="absolute inset-0 hero-pattern"></div>
  <div class="absolute inset-0 bg-gradient-to-tr from-primary-900/80 via-cyan-700/70 to-primary-600/70 mix-blend-multiply"></div>
  <div class="relative z-10 flex flex-col justify-center items-start h-full px-6 md:px-20 text-white">
    <p class="uppercase tracking-widest text-white/80 text-xs md:text-sm mb-2 animate-fadeInUp mt-16">About Us</p>
    <h1 class="text-3xl md:text-5xl font-extrabold mb-2 drop-shadow-sm animate-fadeInUp" style="animation-delay:.05s">
      Powering meaningful learning for everyone
    </h1>
    <p class="text-base md:text-xl max-w-2xl text-white/90 animate-fadeInUp" style="animation-delay:.1s">
      <?= htmlspecialchars($site_name) ?> is a dynamic Virtual Learning Environment (VLE) platform revolutionizing how learners and educators connect. We're committed to inclusive, engaging, and tech-driven education experiences.
    </p>
    <div class="mt-6 animate-fadeInUp" style="animation-delay:.15s">
      <a href="#mission" class="inline-flex items-center gap-2 bg-white text-primary-700 px-5 py-2.5 rounded-lg font-semibold hover:bg-blue-50 transition shadow shadow-glow">
        <i class="ph ph-compass"></i> Our Mission
      </a>
    </div>
  </div>
</section>

<main class="max-w-7xl mx-auto px-6 py-12 md:py-16 space-y-16">

  <!-- Mission / Vision -->
  <section id="mission" class="grid md:grid-cols-2 gap-8 items-stretch">
    <div class="glass rounded-2xl p-8 shadow-glow">
      <div class="flex items-center gap-3 mb-4">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-blue-100 text-blue-700">
          <i class="ph ph-target text-xl"></i>
        </span>
        <h2 class="text-2xl font-bold">Our Mission</h2>
      </div>
      <p class="text-gray-700 leading-relaxed">
        To empower educators with flexible tools and insights that nurture curiosity,
        improve outcomes, and make high‑quality learning accessible to all.
      </p>
      <ul class="mt-4 space-y-2 text-gray-700">
        <li class="flex items-start gap-2"><i class="ph ph-check-circle text-green-600 mt-1"></i> Deliver syllabus-aligned content for IGCSE & IAL learners</li>
        <li class="flex items-start gap-2"><i class="ph ph-check-circle text-green-600 mt-1"></i> Promote deep understanding through interactive digital tools</li>
        <li class="flex items-start gap-2"><i class="ph ph-check-circle text-green-600 mt-1"></i> Build an inclusive, globally connected learning community</li>
        <li class="flex items-start gap-2"><i class="ph ph-check-circle text-green-600 mt-1"></i> Support learner motivation, confidence, and success</li>
      </ul>
    </div>

    <div class="glass rounded-2xl p-8 shadow-glow">
      <div class="flex items-center gap-3 mb-4">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-purple-100 text-purple-700">
          <i class="ph ph-eye text-xl"></i>
        </span>
        <h2 class="text-2xl font-bold">Our Vision</h2>
      </div>
      <p class="text-gray-700 leading-relaxed">
        To become the most trusted and transformative online education hub for students preparing for Cambridge IGCSE and Edexcel IAL examinations across the globe.
      </p>
      <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">Personalized paths</div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">Global classrooms</div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">Accessible by design</div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">Measurable progress</div>
      </div>
    </div>
  </section>

  <!-- Core Values -->
  <section>
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-2xl font-bold">Core Values</h2>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
      <?php foreach ($values as $v): ?>
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition p-6">
          <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 mb-3">
            <i class="ph <?= htmlspecialchars($v['icon']) ?> text-2xl"></i>
          </span>
          <h3 class="font-semibold mb-1"><?= htmlspecialchars($v['title']) ?></h3>
          <p class="text-gray-600 text-sm"><?= htmlspecialchars($v['desc']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Impact Stats -->
  <section aria-labelledby="impact-title">
    <h2 id="impact-title" class="text-2xl font-bold mb-6">Our Impact</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <?php foreach ($stats as $s): ?>
        <div class="glass rounded-2xl p-6 text-center shadow-glow">
          <p class="text-3xl font-extrabold text-primary-700">
            <span class="counter" data-target="<?= (int)$s['value'] ?>">0</span><?= isset($s['suffix']) ? htmlspecialchars($s['suffix']) : '' ?>
          </p>
          <p class="text-gray-600 mt-1 text-sm"><?= htmlspecialchars($s['label']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Milestones Timeline -->
  <section>
    <h2 class="text-2xl font-bold mb-6">Our Journey</h2>
    <div class="relative pl-6">
      <span class="absolute left-3 top-0 bottom-0 w-px bg-gradient-to-b from-blue-200 via-blue-200/40 to-transparent"></span>
      <ul class="space-y-6">
        <?php foreach ($milestones as $m): ?>
          <li class="relative">
            <span class="absolute left-0 top-2 w-3 h-3 rounded-full bg-white border-4 border-blue-400"></span>
            <div class="bg-white border border-slate-100 rounded-xl p-5 shadow-sm">
              <div class="flex items-center justify-between">
                <h3 class="font-semibold"><?= htmlspecialchars($m['title']) ?></h3>
                <span class="text-xs text-gray-500"><?= htmlspecialchars($m['year']) ?></span>
              </div>
              <p class="text-gray-600 text-sm mt-1"><?= htmlspecialchars($m['desc']) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <!-- Team -->
  <section>
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-2xl font-bold">Meet the Team</h2>
      <span class="text-xs text-gray-500"><?= count($team) ?> people</span>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
      <?php foreach ($team as $t): ?>
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition p-6">
          <img src="<?= htmlspecialchars($t['img']) ?>" alt="<?= htmlspecialchars($t['name']) ?> headshot" class="w-20 h-20 rounded-2xl object-cover mb-3">
          <h3 class="font-semibold"><?= htmlspecialchars($t['name']) ?></h3>
          <p class="text-primary-700 text-sm"><?= htmlspecialchars($t['role']) ?></p>
          <p class="text-gray-600 text-sm mt-2"><?= htmlspecialchars($t['bio']) ?></p>
          <div class="mt-3 flex items-center gap-3 text-gray-500">
            <?php if (!empty($t['links']['linkedin'])): ?>
              <a href="<?= htmlspecialchars($t['links']['linkedin']) ?>" class="hover:text-primary-700" aria-label="LinkedIn"><i class="ph ph-linkedin-logo"></i></a>
            <?php endif; ?>
            <?php if (!empty($t['links']['twitter'])): ?>
              <a href="<?= htmlspecialchars($t['links']['twitter']) ?>" class="hover:text-primary-700" aria-label="Twitter"><i class="ph ph-twitter-logo"></i></a>
            <?php endif; ?>
            <?php if (!empty($t['links']['github'])): ?>
              <a href="<?= htmlspecialchars($t['links']['github']) ?>" class="hover:text-primary-700" aria-label="GitHub"><i class="ph ph-github-logo"></i></a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Testimonials -->
  <section>
    <h2 class="text-2xl font-bold mb-6">What Educators Say</h2>
    <div class="grid md:grid-cols-2 gap-6">
      <?php foreach ($testimonials as $q): ?>
        <blockquote class="glass rounded-2xl p-6 shadow-glow">
          <i class="ph ph-quotes text-3xl text-blue-600"></i>
          <p class="mt-3 text-gray-800 italic">“<?= htmlspecialchars($q['quote']) ?>”</p>
          <footer class="mt-3 text-sm text-gray-600">— <?= htmlspecialchars($q['name']) ?></footer>
        </blockquote>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- CTA -->
  <section class="relative overflow-hidden">
    <div class="rounded-3xl bg-gradient-to-r from-primary-600 to-cyan-500 text-white p-8 md:p-10 shadow-lg">
      <div class="md:flex md:items-center md:justify-between gap-6">
        <div class="md:max-w-2xl">
          <h3 class="text-2xl font-bold">Ready to elevate your classroom?</h3>
          <p class="text-white/90 mt-1">Create courses, track progress, and support every learner with <?= htmlspecialchars($site_name) ?>.</p>
        </div>
        <div class="mt-4 md:mt-0 flex gap-3">
          <a href="/create_course.php" class="inline-flex items-center gap-2 bg-white text-primary-700 px-5 py-2.5 rounded-xl font-semibold hover:bg-blue-50 transition">
            <i class="ph ph-plus-circle"></i> Create a Course
          </a>
          <a href="/contact.php" class="inline-flex items-center gap-2 border border-white/70 text-white px-5 py-2.5 rounded-xl font-semibold hover:bg-white/10 transition">
            <i class="ph ph-envelope"></i> Contact Us
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- Contact strip -->
  <section class="grid sm:grid-cols-3 gap-4">
    <div class="bg-white rounded-2xl border border-slate-100 p-5">
      <div class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="ph ph-envelope"></i></span>
        <div>
          <p class="text-sm text-gray-500">Email</p>
          <p class="font-semibold"><?= htmlspecialchars($org_email) ?></p>
        </div>
      </div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5">
      <div class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="ph ph-phone"></i></span>
        <div>
          <p class="text-sm text-gray-500">Phone</p>
          <p class="font-semibold"><?= htmlspecialchars($org_phone) ?></p>
        </div>
      </div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5">
      <div class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="ph ph-map-pin"></i></span>
        <div>
          <p class="text-sm text-gray-500">Location</p>
          <p class="font-semibold"><?= htmlspecialchars($org_city . ', ' . $org_country) ?></p>
        </div>
      </div>
    </div>
  </section>

</main>

<?php if (file_exists(__DIR__ . '/footer.php')) include __DIR__ . '/footer.php'; ?>

<script>
  // Count-up animation when stats enter the viewport
  const counters = document.querySelectorAll('.counter');
  const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

  const animateCounter = (el, target, duration = 1200) => {
    let start = null;
    const step = (ts) => {
      if (!start) start = ts;
      const p = Math.min((ts - start) / duration, 1);
      const eased = easeOutCubic(p);
      const value = Math.floor(eased * target);
      el.textContent = value.toLocaleString();
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  const io = new IntersectionObserver((entries, obs) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        const el = e.target;
        const target = parseInt(el.dataset.target || '0', 10);
        animateCounter(el, target);
        obs.unobserve(el);
      }
    });
  }, { threshold: 0.4 });

  counters.forEach(c => io.observe(c));
</script>

</body>
</html>