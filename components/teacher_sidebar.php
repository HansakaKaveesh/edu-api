<?php
// components/teacher_sidebar.php — sticky, responsive sidebar for teachers (no dark mode, beautified) + Messages

if (!function_exists('e')) {
  function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$active        = $active        ?? 'dashboard';
$counts        = $counts        ?? [];
$teacher_name  = $teacher_name  ?? '';
$compact       = $compact       ?? false;

/* ---------- helpers ---------- */
function tsb_link_classes(string $key, string $active, bool $compact): string {
  $base = 'group relative flex items-center '.($compact ? 'gap-0 justify-center' : 'gap-3')
         .' rounded-xl px-3 py-2 transition-all duration-200 ring-1';
  return ($key === $active)
    ? "$base bg-white ring-indigo-200 text-indigo-700 shadow-sm"
    : "$base ring-transparent text-slate-700 hover:bg-slate-50 hover:text-indigo-700 hover:ring-slate-200 hover:translate-x-0.5";
}

function tsb_badge($v): string {
  if (!is_numeric($v)) return '';
  $v = (int)$v;
  return '<span class="ml-auto inline-flex items-center justify-center rounded-full 
    bg-gradient-to-r from-indigo-600/10 to-sky-500/10 border border-indigo-200 
    text-indigo-700 text-[11px] font-semibold px-2 py-0.5">'.e($v).'</span>';
}

function tsb_initials($name): string {
  $name = trim((string)$name);
  if ($name === '') return 'T';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? '', 0, 1));
  $second = strtoupper(substr($parts[1] ?? '', 0, 1));
  return $first.$second ?: 'T';
}

/* ---------- nav items ---------- */
$items = [
  ['key' => 'dashboard', 'label' => 'Dashboard',  'icon' => 'house',             'href' => 'teacher_dashboard.php#dashboard', 'badge' => null],
  ['key' => 'courses',   'label' => 'My Courses', 'icon' => 'books',             'href' => 'teacher_dashboard.php#courses',   'badge' => $counts['courses']  ?? null],
  ['key' => 'students',  'label' => 'My Students','icon' => 'users-three',       'href' => 'teacher_students.php',            'badge' => $counts['students'] ?? null],
  // New: Messages (badge reads counts['messages'] or counts['messages_unread'])
  ['key' => 'messages',  'label' => 'Messages',   'icon' => 'chat-circle-dots',  'href' => 'teacher_messages.php',            'badge' => $counts['messages'] ?? ($counts['messages_unread'] ?? null)],
  ['key' => 'settings',  'label' => 'Settings',   'icon' => 'gear-six',          'href' => 'teacher_settings.php',            'badge' => null],
];
?>
<!-- Sticky wrapper -->
<aside class="sticky top-28 self-start w-full md:w-full max-h-[calc(100vh-2rem)] overflow-y-auto 
              scrollbar-thin scrollbar-thumb-indigo-300 scrollbar-track-transparent">
  <nav class="relative overflow-hidden rounded-2xl bg-white/80 ring-1 ring-slate-200 backdrop-blur p-4 shadow-sm">
    <!-- Subtle decorative gradient -->
    <div aria-hidden="true" class="pointer-events-none absolute -top-16 -right-16 h-40 w-40 rounded-full
                                  bg-gradient-to-tr from-indigo-100 to-sky-100"></div>

    <?php if (!$compact): ?>
      <!-- Profile header -->
      <div class="mb-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-full bg-gradient-to-br from-indigo-500 to-sky-500 p-[2px] shadow-sm">
            <div class="h-full w-full rounded-full bg-white ring-1 ring-slate-200 grid place-items-center">
              <span class="text-sm font-semibold text-indigo-700">
                <?= e(tsb_initials($teacher_name)) ?>
              </span>
            </div>
          </div>
          <div class="leading-tight">
            <div class="text-sm font-semibold text-slate-900 truncate">
              <?= e($teacher_name ?: 'Teacher') ?>
            </div>
            <div class="text-[11px] text-slate-500">Teacher</div>
          </div>
        </div>
        <a href="create_course.php"
           class="inline-flex items-center gap-1.5 rounded-md bg-gradient-to-r from-indigo-600 to-sky-500 
                  text-white px-3 py-1.5 text-xs font-medium shadow hover:shadow-md hover:from-indigo-700 transition"
           title="Create New Course">
          <i class="ph ph-plus-circle ph-bold"></i> New
        </a>
      </div>
    <?php endif; ?>

    <h4 class="px-2 pb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-500">
      <?= $compact ? 'Menu' : 'Navigation' ?>
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
             
            <?php if ($isActive): ?>
              <span class="absolute left-0 top-1/2 -translate-y-1/2 h-6 w-1.5 
                            rounded-full bg-gradient-to-b from-indigo-500 to-sky-500"></span>
            <?php endif; ?>
            
            <i class="ph ph-<?= e($it['icon']) ?> text-lg 
                     <?= $isActive 
                        ? 'text-indigo-600' 
                        : 'text-slate-500 group-hover:text-indigo-700' ?>"></i>
            
            <?php if (!$compact): ?>
              <span class="font-medium"><?= e($it['label']) ?></span>
              <?= tsb_badge($it['badge']) ?>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>

      <!-- Logout -->
      <li class="<?= $compact ? 'mt-2' : 'pt-1' ?>">
        <a href="logout.php"
           class="group flex items-center <?= $compact ? 'justify-center' : 'gap-3' ?> 
                  rounded-xl px-3 py-2 text-rose-700 bg-rose-50 hover:bg-rose-100 
                  border border-rose-100 transition-shadow shadow-sm hover:shadow"
           title="Logout">
          <i class="ph ph-sign-out text-lg"></i>
          <?php if (!$compact): ?><span class="font-medium">Logout</span><?php endif; ?>
        </a>
      </li>
    </ul>

    <?php if (!$compact): ?>
      <!-- Quick Actions -->
      <div class="mt-6 border-t border-slate-200 pt-3">
        <h5 class="text-[11px] font-semibold uppercase tracking-widest text-slate-500 mb-2">Quick Actions</h5>
        <div class="grid grid-cols-2 gap-2">
          <a href="add_content.php"
             class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-semibold
                    text-indigo-700 bg-gradient-to-br from-indigo-50 to-white ring-1 ring-indigo-100
                    hover:from-indigo-100 hover:to-white transition-shadow shadow-sm hover:shadow">
            <i class="ph ph-file-plus text-sm"></i> Content
          </a>

          <a href="upload_assignment.php"
             class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-semibold
                    text-emerald-700 bg-gradient-to-br from-emerald-50 to-white ring-1 ring-emerald-100
                    hover:from-emerald-100 hover:to-white transition-shadow shadow-sm hover:shadow">
            <i class="ph ph-pencil-circle text-sm"></i> Assignment
          </a>

          <a href="add_quiz.php"
             class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-semibold
                    text-orange-700 bg-gradient-to-br from-orange-50 to-white ring-1 ring-orange-100
                    hover:from-orange-100 hover:to-white transition-shadow shadow-sm hover:shadow">
            <i class="ph ph-brain text-sm"></i> Quiz
          </a>

          <a href="teacher_announcements.php"
             class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-semibold
                    text-sky-700 bg-gradient-to-br from-sky-50 to-white ring-1 ring-sky-100
                    hover:from-sky-100 hover:to-white transition-shadow shadow-sm hover:shadow">
            <i class="ph ph-megaphone text-sm"></i> Announce
          </a>

          <!-- New: Messages quick action -->
          <a href="teacher_messages.php"
             class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-semibold
                    text-fuchsia-700 bg-gradient-to-br from-fuchsia-50 to-white ring-1 ring-fuchsia-100
                    hover:from-fuchsia-100 hover:to-white transition-shadow shadow-sm hover:shadow">
            <i class="ph ph-chat-circle-dots text-sm"></i> Message
          </a>
        </div>
      </div>
    <?php endif; ?>
  </nav>
</aside>