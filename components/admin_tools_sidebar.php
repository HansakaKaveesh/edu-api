<?php
if (!isset($adminTools) || !is_array($adminTools) || !count($adminTools)) {
  $adminTools = [
    ['admin_dashboard.php','fa-tachometer-alt','Dashboard'],
    ['admin_register.php','fa-user-shield','Register Admin'],
    ['view_users.php','fa-users','All Users'],
    ['view_courses.php','fa-book','All Courses'],
    ['admin_reports.php','fa-file-invoice-dollar','Payments & Enrollments'],
    ['admin_progress_reports.php','fa-chart-line','Student Progress'],
  ];
}
$activePath = $activePath ?? basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$createAnnouncementLink = $createAnnouncementLink ?? '#create-announcement';
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
?>

<!-- ===== Desktop Sidebar ===== -->
<aside class="hidden lg:block lg:col-span-3" role="complementary" aria-label="Admin Tools">
  <div class="sticky top-28">
    <div class="relative rounded-2xl border border-gray-200/80 bg-white/80 backdrop-blur-md shadow-lg p-5">
      <!-- Decorative gradient bar with soft glow -->
      <div class="absolute -top-px left-0 right-0 h-[3px]
                  bg-gradient-to-r from-blue-500 via-cyan-400 to-blue-600
                  rounded-t-2xl shadow-[0_2px_8px_rgba(59,130,246,0.25)]"></div>

      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
          <span class="fa-solid fa-gear text-blue-600"></span> Admin Tools
        </h3>
        <a href="<?= h($createAnnouncementLink) ?>"
           class="inline-flex items-center gap-1 text-sm font-medium text-blue-700 hover:text-blue-800">
          <span class="fa-solid fa-bullhorn"></span> New
        </a>
      </div>

      <nav class="space-y-1">
        <?php foreach($adminTools as [$href,$icon,$label]):
          $isActive = basename(parse_url($href,PHP_URL_PATH)) === $activePath;
          $classes = $isActive
            ? 'bg-gradient-to-r from-blue-50 to-cyan-50 text-blue-800 ring-1 ring-blue-200 shadow-inner'
            : 'text-gray-800 hover:text-blue-800 hover:bg-blue-50 focus-visible:ring-2 focus-visible:ring-blue-500';
        ?>
        <a href="<?= h($href) ?>"
           title="<?= h($label) ?>"
           class="group flex items-center gap-3 px-3 py-2 rounded-xl transition duration-150 <?= $classes ?>">
          <span class="w-5 text-center fa-solid <?= h($icon) ?>
                        text-blue-600 group-hover:scale-110 transition-transform"></span>
          <span class="font-medium truncate"><?= h($label) ?></span>
          <?php if($isActive): ?>
            <span class="ml-auto text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Current</span>
          <?php else: ?>
            <span class="ml-auto fa-solid fa-angle-right text-slate-400 group-hover:text-blue-500"></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>
</aside>

<!-- ===== Mobile Drawer ===== -->
<div id="toolsOverlay"
     class="fixed inset-0 bg-black/50 backdrop-blur-[2px] hidden lg:hidden z-40 transition-opacity"></div>

<aside id="toolsDrawer"
       class="fixed top-0 left-0 h-full w-5/6 max-w-[320px] bg-white rounded-r-2xl shadow-2xl z-50
              transform -translate-x-full opacity-0 transition-[transform,opacity] duration-250 ease-out lg:hidden">
  <div class="flex items-center justify-between mb-4 p-4 border-b border-gray-100">
    <h3 class="text-lg font-bold flex items-center gap-2 text-gray-900">
      <span class="fa-solid fa-sliders text-blue-600"></span> Admin Tools
    </h3>
    <button id="toolsClose"
            class="p-2 rounded-lg hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-blue-500"
            aria-label="Close menu">
      <span class="fa-solid fa-xmark"></span>
    </button>
  </div>

  <nav class="px-3 space-y-1 overflow-y-auto h-[calc(100%-3rem)]">
    <?php foreach($adminTools as [$href,$icon,$label]):
      $isActive = basename(parse_url($href,PHP_URL_PATH)) === $activePath;
      $cls = $isActive
        ? 'bg-blue-50 text-blue-800 ring-1 ring-blue-200'
        : 'text-gray-800 hover:bg-gray-50 hover:text-blue-800 focus-visible:ring-2 focus-visible:ring-blue-500';
    ?>
      <a href="<?= h($href) ?>"
         title="<?= h($label) ?>"
         class="flex items-center gap-3 px-3 py-2 rounded-xl transition-colors <?= $cls ?>">
        <span class="w-5 text-center fa-solid <?= h($icon) ?> text-blue-600"></span>
        <span class="font-medium truncate"><?= h($label) ?></span>
      </a>
    <?php endforeach; ?>

    <a href="<?= h($createAnnouncementLink) ?>"
       class="flex items-center gap-3 px-3 py-2 rounded-xl text-gray-800 hover:bg-gray-50 hover:text-blue-800 transition focus-visible:ring-2 focus-visible:ring-blue-500">
      <span class="w-5 text-center fa-solid fa-bullhorn text-blue-600"></span>
      <span class="font-medium truncate">Create Announcement</span>
    </a>

    <!-- bottom soft shadow indicator -->
    <div class="sticky bottom-0 h-6 bg-gradient-to-t from-gray-50 to-transparent pointer-events-none"></div>
  </nav>
</aside>

<!-- === Drawer JS === -->
<script>
const overlay=document.getElementById('toolsOverlay');
const drawer=document.getElementById('toolsDrawer');
document.querySelectorAll('[data-open-tools]').forEach(btn=>{
  btn.addEventListener('click',openDrawer);
});
function openDrawer(){
  overlay.classList.remove('hidden');
  requestAnimationFrame(()=>{
    overlay.classList.add('opacity-100');
    drawer.style.transform='translateX(0)';
    drawer.style.opacity='1';
  });
}
function closeDrawer(){
  overlay.classList.remove('opacity-100');
  drawer.style.transform='translateX(-100%)';
  drawer.style.opacity='0';
  setTimeout(()=>overlay.classList.add('hidden'),250);
}
overlay?.addEventListener('click',closeDrawer);
document.getElementById('toolsClose')?.addEventListener('click',closeDrawer);
window.addEventListener('keydown',e=>{if(e.key==='Escape')closeDrawer();});
</script>