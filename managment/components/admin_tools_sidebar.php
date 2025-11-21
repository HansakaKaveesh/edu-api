<?php
// ---------------------------
// Admin Tools config / defaults
// ---------------------------
if (!isset($adminTools) || !is_array($adminTools) || !count($adminTools)) {
  $adminTools = [
    ['admin_dashboard.php','fa-gauge-high','Dashboard'],
    ['admin_past_papers.php','fa-sitemap','Past Papers'],
    ['view_users.php','fa-users','All Users'],
    ['view_courses.php','fa-book','All Courses'], // fixed
    ['admin_reports.php','fa-file-invoice-dollar','Payments & Enrollments'],
    ['admin_progress_reports.php','fa-chart-line','Student Progress'],
  ];
}

$activePath = $activePath ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '');
$createAnnouncementLink = $createAnnouncementLink ?? '#create-announcement';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<!--
  Optional: Mobile header with Admin Tools trigger.
  If you already have a header, you can move this button into it.
-->
<header class="flex items-center justify-between px-4 py-3 border-b bg-white/90 backdrop-blur lg:hidden">
  <button id="toolsOpen" type="button"
          class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium
                 text-gray-700 bg-gray-100 hover:bg-gray-200">
    <span class="fa-solid fa-sliders text-blue-600"></span>
    <span>Admin Tools</span>
  </button>

  <a href="<?= h($createAnnouncementLink) ?>"
     class="inline-flex items-center gap-1 text-sm font-medium text-blue-700 hover:text-blue-800">
    <span class="fa-solid fa-bullhorn"></span>
    <span>New</span>
  </a>
</header>

<!-- Desktop Sidebar (compact) -->
<aside class="hidden lg:block lg:col-span-3" role="complementary" aria-label="Admin Tools">
  <div class="sticky top-28">
    <div class="relative rounded-xl border border-gray-200 bg-white/90 backdrop-blur p-3 shadow-sm">
      <!-- Subtle top accent -->
      <div class="absolute -top-px left-0 right-0 h-[2px] rounded-t-xl
                  bg-gradient-to-r from-blue-500 via-cyan-400 to-blue-600"></div>

      <div class="mb-3 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
          <span class="fa-solid fa-gear text-blue-600"></span>
          Admin Tools
        </h3>
        <a href="<?= h($createAnnouncementLink) ?>"
           class="inline-flex items-center gap-1 px-2 py-1 text-[12px] font-medium rounded-lg
                  text-blue-700 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 transition">
          <span class="fa-solid fa-bullhorn"></span>
          New
        </a>
      </div>

      <nav class="space-y-1.5">
        <?php foreach ($adminTools as [$href, $icon, $label]):
          $isActive = basename(parse_url($href, PHP_URL_PATH) ?: '') === $activePath;
          $base = 'group flex items-center gap-2.5 px-2.5 py-2 rounded-lg transition text-sm';
          $classes = $isActive
            ? $base . ' bg-blue-50 text-blue-800 ring-1 ring-blue-200'
            : $base . ' text-gray-700 hover:text-blue-800 hover:bg-blue-50';
        ?>
          <a href="<?= h($href) ?>"
             title="<?= h($label) ?>"
             class="<?= $classes ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="w-4 text-center fa-solid <?= h($icon) ?> text-blue-600"></span>
            <span class="truncate"><?= h($label) ?></span>
            <?php if ($isActive): ?>
              <span class="ml-auto inline-block h-2 w-2 rounded-full bg-blue-500"></span>
            <?php else: ?>
              <span class="ml-auto fa-solid fa-angle-right text-slate-300 group-hover:text-blue-500"></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>
</aside>

<!-- Mobile Drawer Overlay -->
<div id="toolsOverlay"
     class="fixed inset-0 bg-black/40 backdrop-blur-[2px] hidden opacity-0 transition-opacity duration-200 z-40 lg:hidden"></div>

<!-- Mobile Drawer (compact) -->
<aside id="toolsDrawer"
       tabindex="-1"
       class="fixed top-0 left-0 h-full w-72 max-w-[80vw] bg-white rounded-r-2xl shadow-2xl z-50
              -translate-x-full opacity-0 transition-[transform,opacity] duration-200 ease-out lg:hidden"
       role="dialog" aria-modal="true" aria-label="Admin Tools" aria-hidden="true">
  <div class="p-3 border-b border-gray-100 flex items-center justify-between">
    <h3 class="text-sm font-semibold flex items-center gap-2 text-gray-900">
      <span class="fa-solid fa-sliders text-blue-600"></span>
      Admin Tools
    </h3>
    <button id="toolsClose"
            type="button"
            class="p-2 rounded-lg hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-blue-500"
            aria-label="Close menu">
      <span class="fa-solid fa-xmark"></span>
    </button>
  </div>

  <nav class="p-3 space-y-1.5 overflow-y-auto h-[calc(100%-56px)]">
    <?php foreach ($adminTools as [$href, $icon, $label]):
      $isActive = basename(parse_url($href, PHP_URL_PATH) ?: '') === $activePath;
      $cls = $isActive
        ? 'bg-blue-50 text-blue-800 ring-1 ring-blue-200'
        : 'text-gray-700 hover:bg-gray-50 hover:text-blue-800';
    ?>
      <a href="<?= h($href) ?>"
         title="<?= h($label) ?>"
         class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition <?= $cls ?>">
        <span class="w-4 text-center fa-solid <?= h($icon) ?> text-blue-600"></span>
        <span class="font-medium truncate"><?= h($label) ?></span>
      </a>
    <?php endforeach; ?>

    <a href="<?= h($createAnnouncementLink) ?>"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-50 hover:text-blue-800 transition">
      <span class="w-4 text-center fa-solid fa-bullhorn text-blue-600"></span>
      <span class="font-medium truncate">Create Announcement</span>
    </a>
  </nav>
</aside>

<!-- Minimal Drawer JS (mobile + accessibility) -->
<script>
(function(){
  const overlay  = document.getElementById('toolsOverlay');
  const drawer   = document.getElementById('toolsDrawer');
  const closeBtn = document.getElementById('toolsClose');
  const openBtn  = document.getElementById('toolsOpen') || document.querySelector('[data-open-tools]');

  if (!overlay || !drawer) return;

  let prevFocus = null;
  let isOpen = false;

  const open = (e) => {
    if (e) e.preventDefault();
    if (isOpen) return;
    isOpen = true;

    prevFocus = document.activeElement;

    overlay.classList.remove('hidden', 'opacity-0');
    overlay.classList.add('opacity-100');

    // Slide drawer in
    drawer.style.transform = 'translateX(0)';
    drawer.style.opacity = '1';
    drawer.setAttribute('aria-hidden','false');

    // Lock body scroll
    document.body.style.overflow = 'hidden';

    // Focus drawer for accessibility
    drawer.focus();
  };

  const close = () => {
    if (!isOpen) return;
    isOpen = false;

    overlay.classList.remove('opacity-100');
    overlay.classList.add('opacity-0');

    // Slide drawer out
    drawer.style.transform = 'translateX(-100%)';
    drawer.style.opacity = '0';
    drawer.setAttribute('aria-hidden','true');

    // Restore body scroll
    document.body.style.overflow = '';

    // After transition, hide overlay
    setTimeout(() => {
      if (!isOpen) overlay.classList.add('hidden');
    }, 200);

    // Restore previous focus
    if (prevFocus && typeof prevFocus.focus === 'function') {
      prevFocus.focus();
    }
  };

  if (openBtn)   openBtn.addEventListener('click', open);
  if (overlay)   overlay.addEventListener('click', close);
  if (closeBtn)  closeBtn.addEventListener('click', close);

  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });
})();
</script>