<?php
// components/teacher_sidebar.php — sticky, responsive sidebar for teachers (beautified, no dark mode)

if (!function_exists('e')) {
  function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$active       = $active       ?? 'dashboard';
$counts       = $counts       ?? [];
$teacher_name = $teacher_name ?? '';
$compact      = $compact      ?? false;

/* ---------- helpers ---------- */
function tsb_link_classes(string $key, string $active, bool $compact): string {
  $base = 'group relative flex items-center '.
          ($compact ? 'gap-0 justify-center' : 'gap-3 justify-start').
          ' rounded-xl px-3 py-2 text-sm font-medium transition-all duration-200';
  if ($key === $active) {
    return $base.' bg-gradient-to-r from-indigo-50 via-white to-sky-50 text-indigo-700 shadow-sm ring-1 ring-indigo-200';
  }
  return $base.' text-slate-700 hover:text-indigo-700 hover:bg-slate-50/80 hover:shadow-sm ring-1 ring-transparent hover:ring-slate-200 hover:translate-x-0.5';
}

function tsb_badge($v): string {
  if (!is_numeric($v)) return '';
  $v = (int)$v;
  return '<span class="ml-auto inline-flex items-center justify-center rounded-full 
    bg-indigo-50 border border-indigo-100 text-indigo-700 text-[11px] font-semibold px-2 py-0.5">'.e($v).'</span>';
}

function tsb_initials($name): string {
  $name = trim((string)$name);
  if ($name === '') return 'T';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? '', 0, 1));
  $second = strtoupper(substr($parts[1] ?? '', 0, 1));
  $result = $first.$second;
  return $result !== '' ? $result : 'T';
}

/* ---------- nav items ---------- */
$items = [
  ['key' => 'dashboard', 'label' => 'Dashboard',   'icon' => 'house',            'href' => 'teacher_dashboard.php',             'badge' => null],
  ['key' => 'courses',   'label' => 'My Courses',  'icon' => 'books',            'href' => 'teacher_dashboard.php#courses',     'badge' => $counts['courses']  ?? null],
  // ['key' => 'students',  'label' => 'My Students', 'icon' => 'users-three',      'href' => 'teacher_students.php',            'badge' => $counts['students'] ?? null],
  // ['key' => 'messages',  'label' => 'Messages',    'icon' => 'chat-circle-dots', 'href' => 'teacher_messages.php',              'badge' => $counts['messages'] ?? ($counts['messages_unread'] ?? null)],
  ['key' => 'settings',  'label' => 'Settings',    'icon' => 'gear-six',         'href' => 'teacher_settings.php',              'badge' => null],
];

// small header stats (optional)
$cCourses = isset($counts['courses']) ? (int)$counts['courses'] : null;
$cMsgs    = isset($counts['messages_unread']) ? (int)$counts['messages_unread'] : null;
?>
<!-- Sticky wrapper -->
<aside class="sticky top-28 self-start w-full md:w-full max-h-[calc(100vh-2rem)] overflow-y-auto 
              scrollbar-thin scrollbar-thumb-indigo-300 scrollbar-track-transparent">

  <nav class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-50 via-white to-sky-50/60 
              ring-1 ring-slate-200/80 backdrop-blur p-4 shadow-sm">

    <!-- Decorative glow -->
    <div aria-hidden="true" class="pointer-events-none absolute -top-20 -right-16 h-40 w-40 rounded-full
                                  bg-gradient-to-tr from-indigo-100/60 via-sky-100/40 to-transparent blur-2xl opacity-80"></div>
    <div aria-hidden="true" class="pointer-events-none absolute -bottom-24 -left-20 h-44 w-44 rounded-full
                                  bg-gradient-to-tr from-sky-100/60 via-indigo-100/40 to-transparent blur-3xl opacity-70"></div>

    <?php if (!$compact): ?>
      <!-- Profile header -->
      <div class="relative mb-4 flex items-center justify-start">
        <div class="flex items-center gap-3">
          <div class="h-11 w-11 rounded-full bg-gradient-to-br from-indigo-500 to-sky-500 p-[2px] shadow-sm">
            <div class="h-full w-full rounded-full bg-white ring-1 ring-slate-200 grid place-items-center">
              <span class="text-sm font-semibold text-indigo-700">
                <?= e(tsb_initials($teacher_name)) ?>
              </span>
            </div>
          </div>

          <div class="leading-tight">
            <div class="flex items-center gap-1">
              <span class="text-sm font-semibold text-slate-900 truncate max-w-[11rem]">
                <?= e($teacher_name ?: 'Teacher') ?>
              </span>
              <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-[1px] text-[10px] font-semibold text-emerald-700 border border-emerald-100">
                • Active
              </span>
            </div>
            <div class="mt-0.5 text-[11px] text-slate-500">Educator · SynapZ</div>
          </div>
        </div>
      </div>

      <!-- Small stats row -->
      <?php if ($cCourses !== null || $cMsgs !== null): ?>
        <div class="relative mb-4 grid grid-cols-2 gap-2 text-[11px]">
          <?php if ($cCourses !== null): ?>
            <div class="flex items-center justify-between rounded-xl bg-white/80 px-3 py-2 ring-1 ring-slate-100 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
              <div class="flex items-center gap-2">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-indigo-600">
                  <i class="ph ph-books text-sm"></i>
                </span>
                <div class="leading-tight">
                  <div class="font-semibold text-slate-800"><?= e($cCourses) ?></div>
                  <div class="text-[10px] text-slate-500 uppercase tracking-wide">Courses</div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($cMsgs !== null): ?>
            <div class="flex items-center justify-between rounded-xl bg-white/80 px-3 py-2 ring-1 ring-slate-100 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
              <div class="flex items-center gap-2">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-fuchsia-50 text-fuchsia-600">
                  <i class="ph ph-chat-circle-dots text-sm"></i>
                </span>
                <div class="leading-tight">
                  <div class="font-semibold text-slate-800"><?= e($cMsgs) ?></div>
                  <div class="text-[10px] text-slate-500 uppercase tracking-wide">Unread</div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Navigation -->
    <div class="relative">
      <h4 class="px-1 pb-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
        <?= $compact ? 'Menu' : 'Main Navigation' ?>
      </h4>

      <ul class="space-y-1">
        <?php foreach ($items as $it): 
          $isActive = ($it['key'] === $active);
          $cls = tsb_link_classes($it['key'], $active, $compact);
        ?>
          <li>
            <a href="<?= e($it['href']) ?>" 
               class="<?= $cls ?>" 
               <?= $isActive ? 'aria-current="page"' : '' ?> 
               title="<?= e($it['label']) ?>">

              <?php if ($isActive && !$compact): ?>
                <span class="absolute inset-y-1 left-1 w-[3px] rounded-full bg-gradient-to-b from-indigo-500 to-sky-500"></span>
              <?php endif; ?>

              <!-- Icon -->
              <span class="relative inline-flex h-8 w-8 items-center justify-center rounded-xl
                           <?= $isActive
                                ? 'bg-gradient-to-br from-indigo-500 to-sky-500 text-white shadow-sm'
                                : 'bg-slate-50 text-slate-500 group-hover:bg-indigo-50 group-hover:text-indigo-600' ?>">
                <i class="ph ph-<?= e($it['icon']) ?> text-lg"></i>
              </span>

              <?php if (!$compact): ?>
                <!-- Label + badge -->
                <span class="ml-1 flex-1 truncate"><?= e($it['label']) ?></span>
                <?= tsb_badge($it['badge']) ?>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>

        <!-- Separator -->
        <?php if (!$compact): ?>
          <li class="pt-2">
            <div class="flex items-center gap-2 px-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              <span class="inline-block h-px w-4 bg-slate-200"></span>
              <span>Account</span>
              <span class="inline-block h-px flex-1 bg-slate-200"></span>
            </div>
          </li>
        <?php endif; ?>

        <!-- Logout -->
        <li class="<?= $compact ? 'mt-2' : 'pt-1' ?>">
          <a href="logout.php"
             class="group flex items-center <?= $compact ? 'justify-center' : 'gap-3' ?> 
                    rounded-xl px-3 py-2 text-sm font-medium text-rose-700 bg-rose-50/90 
                    hover:bg-rose-100 hover:text-rose-800 border border-rose-100
                    transition-all shadow-[0_1px_2px_rgba(248,113,113,0.25)] hover:shadow">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-white/80 text-rose-500 group-hover:bg-rose-50">
              <i class="ph ph-sign-out text-lg"></i>
            </span>
            <?php if (!$compact): ?>
              <span>Logout</span>
            <?php endif; ?>
          </a>
        </li>
      </ul>
    </div>

  </nav>
</aside>