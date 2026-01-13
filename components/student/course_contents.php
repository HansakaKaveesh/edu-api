<?php
// expects: $contents, $role, $badgeMap, $typeIconName, $viewedIds, $student_id, $csrf, $course_id, $conn
?>
<div class="lg:col-span-8 xl:col-span-9">
  <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 p-4 sm:p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-2xl font-semibold text-gray-900 inline-flex items-center gap-2">
        <ion-icon name="folder-open-outline" class="text-blue-700"></ion-icon>
        Course Contents
      </h3>
      <div class="flex flex-col sm:flex-row sm:items-center gap-2">
        <button id="expandAll"
                class="text-sm rounded-md border px-2 py-1 hover:bg-gray-50 w-full sm:w-auto text-center">
          <ion-icon name="chevron-down-outline"></ion-icon> Expand all
        </button>
        <button id="collapseAll"
                class="text-sm rounded-md border px-2 py-1 hover:bg-gray-50 w-full sm:w-auto text-center">
          <ion-icon name="chevron-up-outline"></ion-icon> Collapse all
        </button>
      </div>
    </div>

    <?php if (count($contents) === 0): ?>
      <div class="text-center py-16">
        <div class="mx-auto w-14 h-14 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 mb-4">
          <ion-icon name="document-outline"></ion-icon>
        </div>
        <p class="text-gray-700 font-medium">No content added yet for this course.</p>
        <p class="text-gray-500 text-sm mt-1">Please check back later.</p>
      </div>
    <?php else: ?>
      <div id="contentList" class="space-y-5">
        <?php foreach ($contents as $c):
          $content_type = $c['type'];
          $badge = $badgeMap[$content_type] ?? 'bg-gray-100 text-gray-700';
          $title = $c['title'] ?? '';
          $isViewed = $role === 'student' && isset($viewedIds[(int)$c['content_id']]);
          $searchKey = strtolower(($title ?? '') . ' ' . ($content_type ?? ''));
        ?>

          <?php if (in_array($content_type, ['lesson','pdf'], true)): ?>
            <!-- LESSON / PDF: Modal -->
            <div id="content_<?= (int)$c['content_id'] ?>" data-type="<?= h($content_type) ?>" data-key="<?= h($searchKey) ?>"
                 x-data="{ showModal: false }"
                 class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
              <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                    <ion-icon name="<?= h($typeIconName[$content_type]) ?>"></ion-icon> <?= ucfirst($content_type) ?>
                  </span>
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
                <button @click="showModal = true; $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>));"
                        class="text-gray-500 hover:text-gray-700 transition" aria-label="Open lesson">
                  <ion-icon name="open-outline" class="w-5 h-5"></ion-icon>
                </button>
              </div>

              <!-- Modal -->
              <div x-show="showModal" x-cloak
                   class="fixed inset-0 z-50 flex items-start sm:items-center justify-center px-2 sm:px-0"
                   aria-modal="true" role="dialog">
                <div @click="showModal = false"
                     class="absolute inset-0 bg-slate-100/60 backdrop-blur-sm"></div>
                <div x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                     x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                     class="relative bg-white shadow-xl ring-1 ring-gray-200
                            w-full sm:w-[95%] sm:max-w-6xl
                            h-full sm:h-auto sm:max-h-[90vh]
                            rounded-none sm:rounded-2xl
                            p-4 sm:p-6
                            mt-4 sm:mt-0
                            overflow-y-auto">
                  <button @click="showModal = false"
                          class="absolute top-3 right-3 text-gray-400 hover:text-gray-700 text-2xl leading-none"
                          aria-label="Close">&times;</button>
                  <h2 class="text-2xl font-bold text-blue-700 mb-4 inline-flex items-center gap-2">
                    <ion-icon name="book-outline"></ion-icon> <?= h($title) ?>
                  </h2>

                  <?php if (!empty($c['body'])): ?>
                    <div class="prose max-w-none">
                      <?= $c['body'] /* sanitized on save */ ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($c['file_url'])):
                    $pathPart = parse_url($c['file_url'], PHP_URL_PATH);
                    $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
                    $isVideo = in_array($ext, ['mp4','webm','ogg'], true);
                    $isPdf   = ($ext === 'pdf');
                    $isPpt   = in_array($ext, ['ppt','pptx'], true);
                  ?>
                    <div class="mt-6">
                      <?php if ($isVideo): ?>
                        <video controls playsinline preload="metadata" controlsList="nodownload"
                               class="w-full max-h-[60vh] sm:max-h-[520px] rounded-lg ring-1 ring-gray-200"
                               oncontextmenu="return false;">
                          <source src="<?= h($c['file_url']) ?>">
                          Your browser does not support the video tag.
                        </video>
                      <?php elseif ($isPdf): ?>
                        <iframe
                          src="<?= h($c['file_url']) ?>"
                          loading="lazy"
                          class="w-full h-[60vh] sm:h-[550px] rounded-lg ring-1 ring-gray-200"
                          frameborder="0"
                          oncontextmenu="return false"></iframe>
                      <?php elseif ($isPpt): ?>
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-2 gap-2">
                          <p class="text-sm text-gray-600">
                            This lesson has a PowerPoint attachment.
                          </p>
                          <a href="<?= h($c['file_url']) ?>" target="_blank" rel="noopener"
                             class="inline-flex items-center gap-1 text-sm text-blue-700 hover:underline">
                            <ion-icon name="open-outline"></ion-icon>
                            Open / download PowerPoint
                          </a>
                        </div>
                      <?php else: ?>
                        <iframe src="<?= h($c['file_url']) ?>"
                                loading="lazy"
                                class="w-full h-[60vh] sm:h-[550px] rounded-lg ring-1 ring-gray-200"
                                frameborder="0"></iframe>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

          <?php else: ?>
            <!-- OTHER TYPES: Collapsible (quiz/video/forum) -->
            <div id="content_<?= (int)$c['content_id'] ?>" data-type="<?= h($content_type) ?>" data-key="<?= h($searchKey) ?>"
                 x-data="{ open: false }"
                 class="scroll-mt-24 border border-gray-100 p-4 rounded-xl shadow-sm bg-white hover:shadow-md transition">
              <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                    <ion-icon name="<?= h($typeIconName[$content_type] ?? 'document-text-outline') ?>"></ion-icon>
                    <?= ucfirst($content_type) ?>
                  </span>
                  <button @click="open = !open; if (open) { $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>)); }"
                          class="block text-left w-full mt-1 text-blue-700 text-lg font-semibold hover:underline truncate">
                    <?= h($title) ?>
                  </button>
                  <?php if ($isViewed): ?>
                    <span class="inline-flex items-center gap-1 text-emerald-700 text-xs mt-1">
                      <ion-icon name="checkmark-circle-outline"></ion-icon> Completed
                    </span>
                  <?php endif; ?>
                </div>
                <button @click="open = !open; if (open) { $nextTick(() => window.logView(<?= (int)$c['content_id'] ?>)); }"
                        class="text-gray-500 hover:text-gray-700 transition" aria-label="Toggle section">
                  <ion-icon :name="open ? 'chevron-up-outline' : 'chevron-down-outline'" class="transition-transform"></ion-icon>
                </button>
              </div>

              <div x-show="open" x-cloak
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-1"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   class="mt-4 space-y-4">
                <?php if (!empty($c['body'])): ?>
                  <div class="bg-slate-50 ring-1 ring-slate-100 p-4 rounded-lg text-gray-700 leading-relaxed">
                    <?= nl2br(h($c['body'])) ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($c['file_url'])):
                  $pathPart = parse_url($c['file_url'], PHP_URL_PATH);
                  $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
                  $isVideo = $content_type === 'video' || in_array($ext, ['mp4','webm','ogg'], true);
                  $isPdf   = ($ext === 'pdf');
                  $isPpt   = in_array($ext, ['ppt','pptx'], true);
                ?>
                  <div>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-2 gap-2">
                      <h4 class="font-semibold inline-flex items-center gap-2">
                        <ion-icon name="attach-outline"></ion-icon> Attached File
                      </h4>
                      <?php if (!$isPdf): ?>
                        <a href="<?= h($c['file_url']) ?>" target="_blank" rel="noopener"
                           class="text-sm text-blue-700 hover:underline inline-flex items-center gap-1">
                          <ion-icon name="open-outline"></ion-icon>
                          <?= $isPpt ? 'Open PowerPoint' : 'Open in new tab' ?>
                        </a>
                      <?php endif; ?>
                    </div>
                    <?php if ($isVideo): ?>
                      <video controls playsinline preload="metadata" controlsList="nodownload"
                             class="w-full max-h-[60vh] sm:max-h-[520px] rounded-lg ring-1 ring-gray-200"
                             oncontextmenu="return false;">
                        <source src="<?= h($c['file_url']) ?>">
                        Your browser does not support the video tag.
                      </video>
                    <?php elseif ($isPdf): ?>
                      <iframe
                        src="<?= h($c['file_url']) ?>"
                        loading="lazy"
                        class="w-full h-[60vh] sm:h-[600px] rounded-lg ring-1 ring-gray-200"
                        frameborder="0"
                        oncontextmenu="return false"></iframe>
                    <?php elseif ($isPpt): ?>
                      <p class="text-sm text-gray-600">
                        This is a PowerPoint file. Use the button above to open or download it.
                      </p>
                    <?php else: ?>
                      <iframe src="<?= h($c['file_url']) ?>"
                              loading="lazy"
                              class="w-full h-[60vh] sm:h-[600px] rounded-lg ring-1 ring-gray-200"
                              frameborder="0"></iframe>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($content_type === 'quiz'):
                  /* --------- Load assignment + questions as array --------- */
                  $assignStmt = $conn->prepare("SELECT assignment_id, passing_score FROM assignments WHERE lesson_id = ? LIMIT 1");
                  $assignStmt->bind_param("i", $c['content_id']);
                  $assignStmt->execute();
                  $assignmentRow = $assignStmt->get_result()->fetch_assoc();
                  $assignStmt->close();

                  $questionsArr = [];
                  if ($assignmentRow) {
                    $qStmt = $conn->prepare("
                      SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_option
                      FROM assignment_questions
                      WHERE assignment_id = ?
                      ORDER BY question_id ASC
                    ");
                    $qStmt->bind_param("i", $assignmentRow['assignment_id']);
                    $qStmt->execute();
                    $qRes = $qStmt->get_result();
                    $questionsArr = $qRes->fetch_all(MYSQLI_ASSOC);
                    $qStmt->close();
                    if (QUIZ_SHUFFLE_QUESTIONS) shuffle($questionsArr);
                  }

                  // Attempts and review
                  $attempts_arr = []; $latest_attempt = null; $answersMap = []; $questions_review = $questionsArr;
                  $attemptsCount = 0; $attemptsLeft = '∞';

                  if ($assignmentRow && $role === 'student' && $student_id) {
                    $resA = $conn->prepare("
                      SELECT attempt_id, attempted_at, score, passed
                      FROM student_assignment_attempts
                      WHERE student_id = ? AND assignment_id = ?
                      ORDER BY attempted_at DESC
                    ");
                    $resA->bind_param("ii", $student_id, $assignmentRow['assignment_id']);
                    $resA->execute();
                    $attRes = $resA->get_result();
                    $attempts_arr = $attRes->fetch_all(MYSQLI_ASSOC);
                    $resA->close();

                    $attemptsCount = count($attempts_arr);
                    if (QUIZ_MAX_ATTEMPTS > 0) $attemptsLeft = max(0, QUIZ_MAX_ATTEMPTS - $attemptsCount);

                    $latest_attempt = $attempts_arr[0] ?? null;

                    if ($latest_attempt) {
                      $aid = (int)$latest_attempt['attempt_id'];
                      $ansStmt = $conn->prepare("
                        SELECT question_id, selected_option, is_correct
                        FROM assignment_attempt_questions
                        WHERE attempt_id = ?
                      ");
                      $ansStmt->bind_param("i", $aid);
                      $ansStmt->execute();
                      $ansR = $ansStmt->get_result();
                      while ($rowAns = $ansR->fetch_assoc()) {
                        $answersMap[(int)$rowAns['question_id']] = $rowAns;
                      }
                      $ansStmt->close();
                    }
                  }

                  $quizNonce = quiz_generate_nonce((int)$c['content_id']);
                ?>
                  <div class="bg-emerald-50 ring-1 ring-emerald-100 p-4 rounded-lg" x-data="{ showSummary:false, showReview:false, showHistory:false }">
                    <h4 class="font-semibold mb-3 inline-flex items-center gap-2">
                      <ion-icon name="trophy-outline" class="text-emerald-700"></ion-icon> Quiz
                    </h4>

                    <?php if ($role === 'student'): ?>
                      <div class="flex flex-wrap items-center gap-2 mb-3">
                        <?php if ($latest_attempt): ?>
                          <button @click="showSummary = !showSummary" 
                                  class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-emerald-200 text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition">
                            <ion-icon name="clipboard-outline"></ion-icon> Last Attempt Summary
                          </button>
                          <button @click="showReview = !showReview" 
                                  class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-indigo-200 text-indigo-700 bg-indigo-50 hover:bg-indigo-100 transition">
                            <ion-icon name="eye-outline"></ion-icon> Review Answers
                          </button>
                        <?php endif; ?>
                        <button @click="showHistory = !showHistory" 
                                class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-gray-700 bg-white hover:bg-gray-50 transition">
                          <ion-icon name="time-outline"></ion-icon> Attempts (<?= (int)$attemptsCount ?><?= (QUIZ_MAX_ATTEMPTS>0 ? ' / '.(int)QUIZ_MAX_ATTEMPTS : '') ?>)
                        </button>
                      </div>

                      <?php if ($latest_attempt): ?>
                        <div x-show="showSummary" x-cloak
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 mb-3">
                          <?php
                            $totalQ = count($questions_review);
                            $attemptDt = $latest_attempt['attempted_at'] ? date('Y-m-d H:i:s', strtotime($latest_attempt['attempted_at'])) : '';
                            $passedTxt = $latest_attempt['passed'] ? '<span class="text-emerald-700 font-bold">Pass</span>' : '<span class="text-rose-600 font-bold">Fail</span>';
                          ?>
                          <p class="text-sm">
                            <span class="inline-flex items-center gap-1 mr-2"><ion-icon name="time-outline"></ion-icon> <?= h($attemptDt) ?></span> ·
                            Score: <span class="font-semibold text-blue-700"><?= (int)$latest_attempt['score'] ?></span> /
                            <span class="font-semibold text-blue-700"><?= (int)$totalQ ?></span> ·
                            Result: <?= $passedTxt ?>
                          </p>
                        </div>

                        <div x-show="showReview" x-cloak
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-3">
                          <?php if (!empty($questions_review)): ?>
                            <div class="space-y-3">
                              <?php foreach ($questions_review as $q):
                                $qid       = (int)$q['question_id'];
                                $selected  = $answersMap[$qid]['selected_option'] ?? null;
                                $is_correct= (int)($answersMap[$qid]['is_correct'] ?? 0);
                                $correct   = $q['correct_option'];
                              ?>
                                <div class="p-3 rounded border <?= $is_correct ? 'border-emerald-300 bg-emerald-50' : 'border-rose-300 bg-rose-50' ?>">
                                  <div class="font-medium mb-1"><?= h($q['question_text']) ?></div>
                                  <?php foreach (['A','B','C','D'] as $opt):
                                    $txt = h($q['option_'.strtolower($opt)]);
                                    $isUser = ($selected === $opt);
                                    $isAnswer = ($correct === $opt);
                                  ?>
                                    <div class="ml-4 flex items-center text-sm">
                                      <?php if ($isUser && $isAnswer): ?>
                                        <ion-icon name="checkmark-circle-outline" class="text-emerald-600 mr-2"></ion-icon>
                                      <?php elseif ($isUser && !$isAnswer): ?>
                                        <ion-icon name="close-circle-outline" class="text-rose-600 mr-2"></ion-icon>
                                      <?php else: ?>
                                        <span class="w-5 inline-block"></span>
                                      <?php endif; ?>
                                      <span class="<?= $isAnswer ? 'font-semibold underline text-emerald-700' : ($isUser ? 'text-rose-700' : '') ?>">
                                        <?= $opt ?>) <?= $txt ?>
                                      </span>
                                      <?php if ($isUser): ?><span class="ml-2 text-xs text-gray-500">(Your answer)</span><?php endif; ?>
                                      <?php if ($isAnswer): ?><span class="ml-2 text-xs text-emerald-700">(Correct answer)</span><?php endif; ?>
                                    </div>
                                  <?php endforeach; ?>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php else: ?>
                            <p class="text-gray-600">No questions to review.</p>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <div x-show="showHistory" x-cloak
                           x-transition:enter="transition ease-out duration-200"
                           x-transition:enter-start="opacity-0 -translate-y-1"
                           x-transition:enter-end="opacity-100 translate-y-0"
                           class="bg-white border border-gray-200 rounded-xl p-3 mb-3">
                        <?php if (!empty($attempts_arr)): ?>
                          <div class="text-sm text-gray-700 mb-2">
                            Attempts used: <strong><?= (int)$attemptsCount ?></strong>
                            <?php if (QUIZ_MAX_ATTEMPTS > 0): ?> · Attempts left: <strong><?= (int)$attemptsLeft ?></strong><?php endif; ?>
                          </div>
                          <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                              <thead>
                                <tr class="text-left text-gray-500">
                                  <th class="py-1 pr-4">Date</th>
                                  <th class="py-1 pr-4">Score</th>
                                  <th class="py-1 pr-4">Result</th>
                                </tr>
                              </thead>
                              <tbody>
                                <?php foreach (array_slice($attempts_arr, 0, 5) as $att): ?>
                                  <tr class="border-t">
                                    <td class="py-1 pr-4"><?= h(date('Y-m-d H:i:s', strtotime($att['attempted_at']))) ?></td>
                                    <td class="py-1 pr-4"><?= (int)$att['score'] ?></td>
                                    <td class="py-1 pr-4"><?= $att['passed'] ? 'Pass' : 'Fail' ?></td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php else: ?>
                          <p class="text-gray-600">No attempts yet.</p>
                        <?php endif; ?>
                      </div>

                      <?php
                        $canAttempt = true;
                        if (QUIZ_MAX_ATTEMPTS > 0 && $attemptsCount >= QUIZ_MAX_ATTEMPTS) $canAttempt = false;
                      ?>
                      <?php if (!empty($questionsArr) && $assignmentRow): ?>
                        <?php if ($canAttempt): ?>
                          <form method="post" class="space-y-4">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="quiz_content_id" value="<?= (int)$c['content_id'] ?>">
                            <input type="hidden" name="quiz_nonce" value="<?= h($quizNonce) ?>">
                            <?php foreach ($questionsArr as $q): ?>
                              <div class="rounded-lg border border-gray-100 p-3">
                                <p class="font-medium"><?= h($q['question_text']) ?></p>
                                <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                  <label class="block ml-4 mt-1 text-gray-700">
                                    <input class="mr-2 accent-blue-600" type="radio" name="quiz[<?= (int)$q['question_id'] ?>]" value="<?= $opt ?>" required>
                                    <?= $opt ?>) <?= h($q['option_' . strtolower($opt)]) ?>
                                  </label>
                                <?php endforeach; ?>
                              </div>
                            <?php endforeach; ?>
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                              <button type="submit" name="submit_quiz" value="<?= (int)$assignmentRow['assignment_id'] ?>"
                                      class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 shadow-sm justify-center">
                                <ion-icon name="send-outline"></ion-icon> Submit Quiz
                              </button>
                              <p class="text-sm text-gray-500">
                                Attempt <?= (int)($attemptsCount + 1) ?><?= (QUIZ_MAX_ATTEMPTS>0 ? ' of '.(int)QUIZ_MAX_ATTEMPTS : '') ?>
                              </p>
                            </div>
                          </form>
                        <?php else: ?>
                          <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-800 p-3">
                            You have reached the maximum number of attempts for this quiz.
                          </div>
                        <?php endif; ?>
                      <?php else: ?>
                        <p class="text-gray-600">No quiz questions available.</p>
                      <?php endif; ?>

                    <?php elseif ($role === 'teacher'): ?>
                      <p class="italic text-gray-500">Students can attempt this quiz. Preview questions below:</p>
                      <?php if (!empty($questionsArr)): ?>
                        <?php foreach ($questionsArr as $q): ?>
                          <div class="mb-3">
                            <p class="font-medium"><?= h($q['question_text']) ?></p>
                            <ul class="ml-6 list-disc text-gray-700">
                              <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                                <li><?= $opt ?>) <?= h($q['option_' . strtolower($opt)]) ?></li>
                              <?php endforeach; ?>
                            </ul>
                            <span class="text-green-600 text-xs">Correct: <?= h($q['correct_option']) ?></span>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <p class="text-gray-600">No quiz questions found.</p>
                      <?php endif; ?>
                      <div class="mt-2">
                        <a href="teacher_attempts.php?course_id=<?= (int)$course_id ?>&content_id=<?= (int)$c['content_id'] ?>"
                           class="inline-flex items-center gap-2 text-indigo-700 hover:underline">
                          <ion-icon name="podium-outline"></ion-icon> Review Attempts
                        </a>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($content_type === 'forum'):
                  $postsStmt = $conn->prepare("
                    SELECT p.post_id, p.body, u.username, p.posted_at 
                    FROM forum_posts p 
                    JOIN users u ON u.user_id = p.user_id 
                    WHERE p.content_id = ? AND p.parent_post_id IS NULL 
                    ORDER BY posted_at
                  ");
                  $postsStmt->bind_param("i", $c['content_id']);
                  $postsStmt->execute();
                  $posts = $postsStmt->get_result();
                ?>
                  <div class="bg-indigo-50 ring-1 ring-indigo-100 p-4 rounded-lg">
                    <h4 class="font-semibold mb-3 inline-flex items-center gap-2">
                      <ion-icon name="chatbubbles-outline" class="text-indigo-700"></ion-icon>
                      Forum Discussion
                    </h4>
                    <?php while ($post = $posts->fetch_assoc()): ?>
                      <div class="bg-white border border-gray-100 p-3 mb-2 rounded-lg">
                        <div class="flex items-center justify-between">
                          <strong class="text-gray-800 inline-flex items-center gap-1">
                            <ion-icon name="person-circle-outline" class="text-gray-600"></ion-icon>
                            <?= h($post['username']) ?>
                          </strong>
                          <small class="text-gray-500 inline-flex items-center gap-1">
                            <ion-icon name="time-outline"></ion-icon> <?= h($post['posted_at']) ?>
                          </small>
                        </div>
                        <p class="mt-1 text-gray-700"><?= nl2br(h($post['body'])) ?></p>
                      </div>
                    <?php endwhile; $postsStmt->close(); ?>

                    <?php if ($role === 'student'): ?>
                      <form method="post" class="mt-3">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="forum_content_id" value="<?= (int)$c['content_id'] ?>">
                        <textarea name="forum_body"
                                  class="w-full border border-gray-200 focus:border-blue-300 focus:ring-2 focus:ring-blue-200 outline-none p-3 rounded-lg text-gray-800"
                                  rows="2" placeholder="Type your comment..." required></textarea>
                        <button type="submit" name="post_forum"
                                class="mt-2 inline-flex items-center gap-2 bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 shadow-sm">
                          <ion-icon name="send-outline"></ion-icon> Post
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>