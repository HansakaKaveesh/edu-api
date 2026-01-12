<?php
// components/sidebar_ceo.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Keep current query string (optional). If you already set $qs before include, it will be used.
$qs = $qs ?? (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '');

// Optional: you can pass this in before include, e.g. $pendingDelBadge = 3;
$pendingDelBadge = $pendingDelBadge ?? '';
$pendingDelBadgeHtml = '';
if ($pendingDelBadge !== '' && $pendingDelBadge !== null) {
    $pendingDelBadgeHtml = (string)$pendingDelBadge;
}

function ceoActive(string $href): string {
    global $currentPath;
    $hrefPath = basename(parse_url($href, PHP_URL_PATH) ?? '');
    return $currentPath === $hrefPath
        ? 'bg-indigo-600/10 text-indigo-700 border-indigo-500/60'
        : 'text-slate-600 hover:bg-slate-50 hover:text-indigo-700 border-transparent';
}

$navItems = [
    ['label' => 'Dashboard', 'href' => 'ceo_dashboard.php' . $qs, 'icon' => 'grid-outline'],
    ['label' => 'Payments',  'href' => 'ceo_payments.php'  . $qs, 'icon' => 'card-outline'],
    ['label' => 'Teachers',  'href' => 'ceo_teachers.php'  . $qs, 'icon' => 'easel-outline'],
    ['label' => 'Students',  'href' => 'ceo_students.php'  . $qs, 'icon' => 'school-outline'],
    [
        'label'      => 'Deletion Requests',
        'href'       => 'ceo_user_deletion_requests.php' . $qs,
        'icon'       => 'trash-outline',
        'badge'      => $pendingDelBadgeHtml,
        'badgeClass' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
    ],
    ['label' => 'Top Courses', 'href' => 'ceo_courses.php' . $qs, 'icon' => 'trophy-outline'],
];

$quickItems = [
    ['label' => 'Settings', 'href' => 'ceo_settings.php', 'icon' => 'settings-outline'],
    ['label' => 'Logout',   'href' => 'logout.php',       'icon' => 'log-out-outline'],
];
?>

<aside class="w-full lg:w-64 xl:w-72 flex-shrink-0">
  <!-- On mobile: normal flow; on lg+: sticky -->
  <div class="space-y-4 lg:sticky lg:top-24">
    <!-- Role card -->
    <div class="rounded-2xl bg-white/90 border border-slate-200 shadow-sm p-3 sm:p-4">
      <div class="flex items-center gap-3 mb-2.5 sm:mb-3">
        <div class="h-9 w-9 rounded-xl bg-indigo-600 text-white grid place-items-center text-sm font-semibold">
          CEO
        </div>
        <div>
          <p class="text-[11px] sm:text-xs uppercase tracking-wide text-slate-500">Role</p>
          <p class="text-sm font-semibold text-slate-800">Chief Executive Officer</p>
        </div>
      </div>
      <p class="text-xs sm:text-[13px] text-slate-500 leading-snug">
        Monitor performance, payments, and platform activity.
      </p>
    </div>

    <!-- Nav -->
    <nav class="rounded-2xl bg-white/95 border border-slate-200 shadow-sm p-3 text-sm">
      <p class="px-2 pb-1 text-[11px] sm:text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
        Navigation
      </p>

      <ul class="space-y-1">
        <?php foreach ($navItems as $item): ?>
          <li>
            <a href="<?= htmlspecialchars($item['href']) ?>"
               class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= ceoActive($item['href']) ?>">
              <!-- Ionicons -->
              <ion-icon name="<?= htmlspecialchars($item['icon']) ?>" class="text-[18px] flex-shrink-0"></ion-icon>

              <span class="truncate flex-1"><?= htmlspecialchars($item['label']) ?></span>

              <?php if (!empty($item['badge'])): ?>
                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= htmlspecialchars($item['badgeClass'] ?? 'bg-slate-100 text-slate-700 ring-1 ring-slate-200') ?>">
                  <?= htmlspecialchars((string)$item['badge']) ?>
                </span>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <p class="px-2 pt-4 pb-1 text-[11px] sm:text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
        Quick links
      </p>

      <ul class="space-y-1">
        <?php foreach ($quickItems as $item): ?>
          <li>
            <a href="<?= htmlspecialchars($item['href']) ?>"
               class="flex items-center gap-2 px-3 py-2.5 rounded-xl border <?= ceoActive($item['href']) ?>">
              <ion-icon name="<?= htmlspecialchars($item['icon']) ?>" class="text-[18px] flex-shrink-0"></ion-icon>
              <span class="truncate"><?= htmlspecialchars($item['label']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
  </div>
</aside>