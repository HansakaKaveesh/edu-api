<?php
// 1. Fix: Safe function declaration (prevents "Cannot redeclare" errors)
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// 2. Initialize variables to prevent undefined variable warnings
$contents = $contents ?? [];
$role = $role ?? '';
$viewedIds = $viewedIds ?? [];
$course_id = $course_id ?? 0;

// 3. UI Helpers
$typeIconName = [
    'lesson' => 'document-text-outline',
    'video'  => 'videocam-outline',
    'pdf'    => 'document-attach-outline',
    'quiz'   => 'trophy-outline',
    'forum'  => 'chatbubbles-outline'
];
$badgeMap = [
    'lesson' => 'bg-blue-100 text-blue-700',
    'video'  => 'bg-rose-100 text-rose-700',
    'pdf'    => 'bg-amber-100 text-amber-700',
    'quiz'   => 'bg-emerald-100 text-emerald-700',
    'forum'  => 'bg-indigo-100 text-indigo-700'
];
?>

<div class="lg:col-span-8 xl:col-span-9">
  <!-- Main Content Card -->
  <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-4 sm:p-6">
    
    <!-- Card Header -->
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-2xl font-semibold text-gray-900 inline-flex items-center gap-2">
        <ion-icon name="folder-open-outline" class="text-blue-700"></ion-icon>
        Course Contents
      </h3>
      <!-- Expand/Collapse Buttons -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2">
        <button id="expandAll" class="text-sm rounded-md border px-2 py-1 hover:bg-gray-50 w-full sm:w-auto text-center">
          <ion-icon name="chevron-down-outline"></ion-icon> Expand all
        </button>
        <button id="collapseAll" class="text-sm rounded-md border px-2 py-1 hover:bg-gray-50 w-full sm:w-auto text-center">
          <ion-icon name="chevron-up-outline"></ion-icon> Collapse all
        </button>
      </div>
    </div>

    <!-- Empty State -->
    <?php if (count($contents) === 0): ?>
      <div class="text-center py-16">
        <div class="mx-auto w-14 h-14 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 mb-4">
          <ion-icon name="document-outline"></ion-icon>
        </div>
        <p class="text-gray-700 font-medium">No content added yet for this course.</p>
      </div>
    <?php else: ?>
      
      <!-- Content List -->
      <div id="contentList" class="space-y-5">
        <?php foreach ($contents as $c):
          $content_type = $c['type'];
          $badge = $badgeMap[$content_type] ?? 'bg-gray-100 text-gray-700';
          $title = $c['title'] ?? '';
          $isViewed = $role === 'student' && isset($viewedIds[(int)$c['content_id']]);
          $searchKey = strtolower(($title ?? '') . ' ' . ($content_type ?? ''));
        ?>

          <!-- =========================================================
               CASE 1: QUIZ -> Link to separate view_quiz.php page
               ========================================================= -->
          <?php if ($content_type === 'quiz'): ?>
            <div id="content_<?= (int)$c['content_id'] ?>" data-type="quiz" data-key="<?= h($searchKey) ?>"
                 class="scroll-mt-24 border border-emerald-100 bg-emerald-50/50 p-4 rounded-xl shadow-sm hover:shadow-md transition">
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="min-w-0">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                    <ion-icon name="<?= h($typeIconName[$content_type]) ?>"></ion-icon> Quiz
                  </span>
                  <h4 class="text-lg font-semibold text-gray-900 mt-1"><?= h($title) ?></h4>
                  
                  <?php if ($isViewed): ?>
                    <span class="inline-flex items-center gap-1 text-emerald-700 text-xs mt-1">
                      <ion-icon name="checkmark-circle-outline"></ion-icon> Attempted
                    </span>
                  <?php endif; ?>
                </div>

                <div>
                  <a href="view_quiz.php?content_id=<?= (int)$c['content_id'] ?>&course_id=<?= (int)$course_id ?>"
                     class="inline-flex items-center justify-center gap-2 w-full sm:w-auto px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition shadow-sm shadow-emerald-200">
                    <ion-icon name="play-outline"></ion-icon>
                    <?= ($role === 'teacher') ? 'Preview Quiz' : 'Open Quiz' ?>
                  </a>
                </div>
              </div>
            </div>

          <!-- =========================================================
               CASE 2: LESSON / PDF -> Open in Modal
               ========================================================= -->
          <?php elseif (in_array($content_type, ['lesson', 'pdf'], true)): ?>
            <div id="content_<?= (int)$c['content_id'] ?>" data-type="<?= h($content_type) ?>" data-key="<?= h($searchKey) ?>"
                 x-data="{ showModal: false }"
                 class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
              <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                    <ion-icon name="<?= h($typeIconName[$content_type] ?? 'document') ?>"></ion-icon> <?= ucfirst($content_type) ?>
                  </span>
                  <!-- Click title triggers modal -->
                  <button @click="showModal = true; $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>));"
                          class="block text-left w-full mt-1 text-blue-700 text-lg font-semibold hover:underline truncate">
                    <?= h($title) ?>
                  </button>
                  <?php if ($isViewed): ?>
                    <span class="inline-flex items-center gap-1 text-emerald-700 text-xs mt-1">
                      <ion-icon name="checkmark-circle-outline"></ion-icon> Completed
                    </span>
                  <?php endif; ?>
                </div>
                <!-- Icon Trigger -->
                <button @click="showModal = true; $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>));"
                        class="text-gray-400 hover:text-blue-600 transition text-2xl">
                  <ion-icon name="open-outline"></ion-icon>
                </button>
              </div>

              <!-- MODAL STRUCTURE -->
              <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="showModal = false"></div>
                <div class="relative bg-white w-full max-w-5xl h-[85vh] rounded-2xl shadow-2xl flex flex-col overflow-hidden">
                    <div class="p-4 border-b flex justify-between items-center bg-gray-50">
                        <h3 class="font-bold text-gray-800"><?= h($title) ?></h3>
                        <button @click="showModal = false" class="text-gray-500 hover:text-red-500 text-2xl">&times;</button>
                    </div>
                    <div class="flex-1 overflow-y-auto p-6">
                        <?php if (!empty($c['body'])): ?>
                           <div class="prose max-w-none mb-6"><?= $c['body'] ?></div>
                        <?php endif; ?>
                        <?php if (!empty($c['file_url'])): ?>
                            <iframe src="<?= h($c['file_url']) ?>" class="w-full h-[600px] rounded border border-gray-200"></iframe>
                        <?php endif; ?>
                    </div>
                </div>
              </div>
            </div>

          <!-- =========================================================
               CASE 3: VIDEO / FORUM -> Accordion Expand/Collapse
               ========================================================= -->
          <?php else: ?>
            <div id="content_<?= (int)$c['content_id'] ?>" data-type="<?= h($content_type) ?>" data-key="<?= h($searchKey) ?>"
                 x-data="{ open: false }"
                 class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
              <div class="flex items-center justify-between gap-4 cursor-pointer" 
                   @click="open = !open; if(open) $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>));">
                <div class="min-w-0">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                    <ion-icon name="<?= h($typeIconName[$content_type] ?? 'document') ?>"></ion-icon> <?= ucfirst($content_type) ?>
                  </span>
                  <h4 class="mt-1 text-lg font-semibold text-gray-800 truncate"><?= h($title) ?></h4>
                  <?php if ($isViewed): ?>
                    <span class="inline-flex items-center gap-1 text-emerald-700 text-xs mt-1">
                      <ion-icon name="checkmark-circle-outline"></ion-icon> Completed
                    </span>
                  <?php endif; ?>
                </div>
                <div class="text-gray-400">
                    <ion-icon :name="open ? 'chevron-up' : 'chevron-down'" class="text-xl transition-transform"></ion-icon>
                </div>
              </div>

              <!-- Accordion Body -->
              <div x-show="open" x-cloak class="mt-4 pt-4 border-t border-gray-100">
                <?php if (!empty($c['body'])): ?>
                  <div class="text-gray-600 mb-4"><?= nl2br(h($c['body'])) ?></div>
                <?php endif; ?>
                
                <?php if (!empty($c['file_url'])): ?>
                    <?php if ($content_type === 'video'): ?>
                        <video controls class="w-full rounded-xl shadow-sm bg-black"><source src="<?= h($c['file_url']) ?>"></video>
                    <?php else: ?>
                        <a href="<?= h($c['file_url']) ?>" target="_blank" class="text-blue-600 hover:underline flex items-center gap-2">
                            <ion-icon name="cloud-download-outline"></ion-icon> Download Attachment
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($content_type === 'forum'): ?>
                    <div class="mt-4 bg-indigo-50 p-4 rounded-lg text-center">
                        <p class="text-indigo-800 font-medium mb-2">Discussion Board</p>
                        <a href="view_content.php?content_id=<?= $c['content_id'] ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                            Open Discussion <ion-icon name="open-outline"></ion-icon>
                        </a>
                    </div>
                <?php endif; ?>
              </div>
            </div>

          <?php endif; ?> <!-- End Type Checks -->

        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>