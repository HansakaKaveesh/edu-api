<?php
// expects: $contents, $role, $viewedIds, $typeIconName
?>
<aside class="lg:col-span-4 xl:col-span-3">
  <div class="sticky top-24 space-y-4">
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
      <h3 class="text-lg font-semibold text-gray-900 mb-3 inline-flex items-center gap-2">
        <ion-icon name="list-outline" class="text-blue-700"></ion-icon> Contents
      </h3>
      <div class="relative mb-3">
        <input id="tocSearch" type="text" placeholder="Search content..."
               class="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300">
        <ion-icon name="search-outline" class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></ion-icon>
      </div>
      <div class="flex flex-wrap gap-2 mb-3">
        <?php foreach (['lesson','video','pdf','quiz','forum'] as $t): ?>
          <label class="cursor-pointer">
            <input type="checkbox" class="sr-only toc-filter" value="<?= $t ?>" checked>
            <span class="pill inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs ring-1 ring-gray-200 bg-gray-100 text-gray-700"
                  data-type-pill="<?= $t ?>">
              <ion-icon name="<?= h($typeIconName[$t]) ?>"></ion-icon> <?= ucfirst($t) ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <ul id="tocList" class="space-y-1 max-h-[60vh] overflow-y-auto pr-1">
        <?php if (count($contents) === 0): ?>
          <li class="text-gray-500 text-sm">No content.</li>
        <?php else: ?>
          <?php foreach ($contents as $c):
            $t = $c['type'];
            $title = $c['title'] ?? '';
            $searchKey = strtolower(($title ?? '') . ' ' . ($t ?? ''));
            $isViewed = $role === 'student' && isset($viewedIds[(int)$c['content_id']]);
          ?>
            <li data-type="<?= h($t) ?>" data-key="<?= h($searchKey) ?>">
              <a href="#content_<?= (int)$c['content_id'] ?>"
                 class="flex items-center gap-2 px-3 py-2 rounded-lg border border-transparent hover:bg-blue-50 hover:border-blue-100 transition">
                <ion-icon name="<?= h($typeIconName[$t] ?? 'document-text-outline') ?>" class="text-blue-700"></ion-icon>
                <span class="truncate flex-1"><?= h($title) ?></span>
                <?php if ($isViewed): ?>
                  <span class="inline-flex items-center gap-1 text-emerald-700 text-xs">
                    <ion-icon name="checkmark-circle-outline"></ion-icon> Done
                  </span>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
    <a href="#top" class="block text-center text-blue-700 hover:underline inline-flex items-center gap-1">
      <ion-icon name="arrow-up-outline"></ion-icon> Back to top
    </a>
  </div>
</aside>