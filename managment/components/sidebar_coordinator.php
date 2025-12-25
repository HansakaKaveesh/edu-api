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
  <div class="sticky top-24 space-y-4">
    <div class="rounded-2xl bg-white/90 border border-slate-200 shadow-sm p-4">
      <div class="flex items-center gap-3 mb-3">
        <div class="h-9 w-9 rounded-xl bg-indigo-600 text-white grid place-items-center text-sm font-semibold">
          CC
        </div>
        <div>
          <p class="text-xs uppercase tracking-wide text-slate-500">Role</p>
          <p class="text-sm font-semibold text-slate-800">Course Coordinator</p>
        </div>
      </div>
      <p class="text-xs text-slate-500">
        Manage courses, track content coverage, and support teachers.
      </p>
    </div>

    <nav class="rounded-2xl bg-white/95 border border-slate-200 shadow-sm p-3 text-sm">
      <p class="px-2 pb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
        Navigation
      </p>
      <ul class="space-y-1">
        <li>
          <a href="coordinator_dashboard.php"
             class="flex items-center gap-2 px-3 py-2 rounded-xl border <?= coordActive('coordinator_dashboard.php') ?>">
            <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
            <span>Dashboard overview</span>
          </a>
        </li>
        <li>
          <a href="manage_courses.php"
             class="flex items-center gap-2 px-3 py-2 rounded-xl border <?= coordActive('manage_courses.php') ?>">
            <i data-lucide="layers" class="w-4 h-4"></i>
            <span>Manage courses</span>
          </a>
        </li>
        <li>
          <a href="manage_contents.php"
             class="flex items-center gap-2 px-3 py-2 rounded-xl border <?= coordActive('manage_contents.php') ?>">
            <i data-lucide="file-text" class="w-4 h-4"></i>
            <span>Manage contents</span>
          </a>
        </li>
        <li>
          <a href="course_types.php"
             class="flex items-center gap-2 px-3 py-2 rounded-xl border <?= coordActive('course_types.php') ?>">
            <i data-lucide="grid" class="w-4 h-4"></i>
            <span>Course types</span>
          </a>
        </li>
      </ul>

      <p class="px-2 pt-4 pb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
        Quick links
      </p>
      <ul class="space-y-1">
        <li>
          <a href="teacher_list.php"
             class="flex items-center gap-2 px-3 py-2 rounded-xl border <?= coordActive('teacher_list.php') ?>">
            <i data-lucide="users" class="w-4 h-4"></i>
            <span>Teachers</span>
          </a>
        </li>
        <li>
          <a href="reports_courses.php"
             class="flex items-center gap-2 px-3 py-2 rounded-xl border <?= coordActive('reports_courses.php') ?>">
            <i data-lucide="bar-chart-3" class="w-4 h-4"></i>
            <span>Course reports</span>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>