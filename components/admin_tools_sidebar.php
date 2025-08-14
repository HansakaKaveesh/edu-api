<?php
// components/admin_tools_sidebar.php
// Reusable Admin Tools sidebar + mobile drawer.
// Accepts optional variables from parent:
// - $adminTools: array of [href, fa-icon-class (no leading dot), label]
// - $activePath: current page filename (defaults to current request path)
// - $createAnnouncementLink: anchor/url for announcement creator (default '#create-announcement')

if (!isset($adminTools) || !is_array($adminTools) || !count($adminTools)) {
  $adminTools = [
    ['admin_dashboard.php',      'fa-tachometer-alt',      'Dashboard'],
    ['admin_register.php',        'fa-user-shield',         'Register Admin'],
    ['view_users.php',            'fa-users',               'View All Users'],
    ['view_courses.php',          'fa-book',                'View All Courses'],
    ['admin_reports.php',         'fa-file-invoice-dollar', 'Payment & Enrollment Reports'],
    ['admin_progress_reports.php','fa-chart-line',          'View Progress'],
  ];
}

$activePath = $activePath
  ?? ($currentPath ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
$createAnnouncementLink = $createAnnouncementLink ?? '#create-announcement';
?>

<!-- Desktop Sidebar -->
<aside class="hidden lg:block lg:col-span-3" role="complementary" aria-label="Admin Tools">
  <div class="sticky top-28">
    <div class="rounded-2xl bg-white ring-1 ring-gray-200 p-4 shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-lg font-bold text-gray-900">⚙️ Admin Tools</h3>
        <a href="<?= htmlspecialchars($createAnnouncementLink) ?>" class="text-xs text-blue-700 hover:underline">
          Create announcement
        </a>
      </div>
      <nav class="space-y-2">
        <?php foreach ($adminTools as $tool):
          $href = $tool[0]; $icon = $tool[1]; $label = $tool[2];
          $isActive = (basename(parse_url($href, PHP_URL_PATH)) === $activePath);
          $classes = 'group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition';
          if ($isActive) $classes .= ' bg-blue-50 ring-blue-300';
        ?>
          <a href="<?= htmlspecialchars($href) ?>"
             class="<?= $classes ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="fa-solid <?= htmlspecialchars($icon) ?> text-blue-600 w-5 text-center"></span>
            <span class="font-medium text-gray-800 group-hover:text-blue-800"><?= htmlspecialchars($label) ?></span>
            <?php if ($isActive): ?>
              <span class="ml-auto text-xs text-blue-700">Active</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>
</aside>

<!-- Mobile Drawer -->
<div id="toolsOverlay" class="fixed inset-0 bg-black/40 hidden lg:hidden z-40"></div>
<aside id="toolsDrawer"
       class="fixed top-0 left-0 h-full w-5/6 max-w-[320px] bg-white p-4 shadow-2xl z-50 transform -translate-x-full transition-transform duration-200 ease-in-out lg:hidden"
       role="dialog" aria-modal="true" aria-labelledby="toolsDrawerTitle" aria-hidden="true">
  <div class="flex items-center justify-between mb-3">
    <h3 id="toolsDrawerTitle" class="text-lg font-bold text-gray-900">⚙️ Admin Tools</h3>
    <button id="toolsClose" class="p-2 rounded-lg hover:bg-gray-100" aria-label="Close admin tools">
      <span class="fa-solid fa-xmark"></span>
    </button>
  </div>
  <nav class="space-y-2">
    <?php foreach ($adminTools as $tool):
      $href = $tool[0]; $icon = $tool[1]; $label = $tool[2];
      $isActive = (basename(parse_url($href, PHP_URL_PATH)) === $activePath);
      $classes = 'group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition';
      if ($isActive) $classes .= ' bg-blue-50 ring-blue-300';
    ?>
      <a href="<?= htmlspecialchars($href) ?>"
         class="<?= $classes ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>>
        <span class="fa-solid <?= htmlspecialchars($icon) ?> text-blue-600 w-5 text-center"></span>
        <span class="font-medium text-gray-800 group-hover:text-blue-800"><?= htmlspecialchars($label) ?></span>
      </a>
    <?php endforeach; ?>
    <a href="<?= htmlspecialchars($createAnnouncementLink) ?>"
       class="group flex items-center gap-3 px-3 py-2 rounded-xl ring-1 ring-gray-200 hover:bg-blue-50 hover:ring-blue-200 transition">
      <span class="fa-solid fa-bullhorn text-blue-600 w-5 text-center"></span>
      <span class="font-medium text-gray-800 group-hover:text-blue-800">Create Announcement</span>
    </a>
  </nav>
</aside>