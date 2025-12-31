<?php
// components/sidebar_coordinator.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

function coordActive(string $file): string {
    global $currentPath;
    return $currentPath === $file
        ? 'bg-indigo-600/10 text-indigo-700 border-indigo-500/60'
        : 'text-slate-600 hover:bg-slate-50 hover:text-indigo-700 border-transparent';
}
?>
<aside class="w-full lg:w-64 xl:w-72 flex-shrink-0">
  <!-- On mobile: normal flow; on lg+: sticky -->
  <div class="space-y-4 lg:sticky lg:top-24">
    <!-- Role card -->
    <div class="rounded-2xl bg-white/90 border border-slate-200 shadow-sm p-3 sm:p-4">
      <div class="flex items-center gap-3 mb-2.5 sm:mb-3">
        <div class="h-9 w-9 rounded-xl bg-indigo-600 text-white grid place-items-center text-sm font-semibold">
          CC
        </div>
        <div>
          <p class="text-[11px] sm:text-xs uppercase tracking-wide text-slate-500">Role</p>
          <p class="text-sm font-semibold text-slate-800">Course Coordinator</p>
        </div>
      </div>
      <p class="text-xs sm:text-[13px] text-slate-500 leading-snug">
        Manage courses, track content coverage, and support teachers.
      </p>
    </div>

    <!-- Nav -->
    <nav class="rounded-2xl bg-white/95 border border-slate-200 shadow-sm p-3 text-sm">
      <p class="px-2 pb-1 text-[11px] sm:text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
        Navigation
      </p>
      <ul class="space-y-1">
        <li>
          <a href="coordinator_dashboard.php"
             class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= coordActive('coordinator_dashboard.php') ?>">
            <i data-lucide="layout-dashboard" class="w-4 h-4 flex-shrink-0"></i>
            <span class="truncate">Dashboard overview</span>
          </a>
        </li>
        <li>
          <a href="manage_courses.php"
             class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= coordActive('manage_courses.php') ?>">
            <i data-lucide="layers" class="w-4 h-4 flex-shrink-0"></i>
            <span class="truncate">Manage courses</span>
          </a>
        </li>
        <li>
          <a href="manage_contents.php"
             class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= coordActive('manage_contents.php') ?>">
            <i data-lucide="file-text" class="w-4 h-4 flex-shrink-0"></i>
            <span class="truncate">Manage contents</span>
          </a>
        </li>
        <li>
          <a href="course_types.php"
             class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= coordActive('course_types.php') ?>">
            <i data-lucide="grid" class="w-4 h-4 flex-shrink-0"></i>
            <span class="truncate">Course types</span>
          </a>
        </li>
      </ul>

      <p class="px-2 pt-4 pb-1 text-[11px] sm:text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
        Quick links
      </p>
      <ul class="space-y-1">
        <li>
          <a href="teacher_list.php"
             class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= coordActive('teacher_list.php') ?>">
            <i data-lucide="users" class="w-4 h-4 flex-shrink-0"></i>
            <span class="truncate">Teachers</span>
          </a>
        </li>
        <li>
          <a href="reports_courses.php"
             class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= coordActive('reports_courses.php') ?>">
            <i data-lucide="bar-chart-3" class="w-4 h-4 flex-shrink-0"></i>
            <span class="truncate">Course reports</span>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>