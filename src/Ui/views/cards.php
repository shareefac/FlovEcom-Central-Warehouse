<ol class="cards">
<?php foreach ($list as $c): ?>
  <li class="card task<?php if (!empty($c['hero'])): ?> hero<?php endif; ?><?php if (!empty($c['quiet'])): ?> quiet<?php endif; ?> <?= $e($c['tone'] ?? 'info') ?>"<?php if (isset($c['key'])): ?> data-card="<?= $e($c['key']) ?>"<?php endif; ?>>
    <div class="task-head">
      <div>
<?php if (isset($c['job'])): ?>
        <p class="task-step"><?= $word('UI', 'job') ?> <?= $n($c['job']) ?><?php if (!empty($c['hero'])): ?> <span class="start-tag"><?= $word('HOME', 'start_here') ?></span><?php endif; ?></p>
<?php endif; ?>
        <h3><?= $e($c['title']) ?></h3>
      </div>
<?php if (isset($c['chip'])): ?>
      <?= $chip($c['tone'] ?? 'info', $c['chip']) ?>
<?php endif; ?>
    </div>
<?php if (isset($c['count'])): ?>
    <p class="count"><?= $n($c['count']) ?><?php if (isset($c['unit'])): ?> <small><?= $e($c['unit']) ?></small><?php endif; ?></p>
<?php endif; ?>
<?php if (isset($c['progress'])): ?>
    <progress class="bar" value="<?= $e($c['progress'][0]) ?>" max="<?= $e(max(1, (int) $c['progress'][1])) ?>" aria-label="<?= $say('UI', 'progress_done', (int) $c['progress'][0], (int) $c['progress'][1]) ?>"><?= $say('UI', 'progress_short', (int) $c['progress'][0], (int) $c['progress'][1]) ?></progress>
<?php endif; ?>
<?php if (!empty($c['hero']) && isset($c['href'], $c['button'])): ?>
    <p class="task-actions"><a class="btn primary" href="<?= $e($c['href']) ?>"><?= $e($c['button']) ?></a></p>
<?php endif; ?>
<?php if (isset($c['text'])): ?>
    <p class="text"><?= $e($c['text']) ?></p>
<?php endif; ?>
<?php if (isset($c['what'])): ?>
    <p class="task-what"><strong><?= $word('UI', 'what_happens') ?></strong> <?= $e($c['what']) ?></p>
<?php endif; ?>
<?php if (empty($c['hero']) && isset($c['href'], $c['button'])): ?>
    <p class="task-actions"><a class="btn <?php if (!empty($c['primary'])): ?>primary<?php else: ?>secondary<?php endif; ?>" href="<?= $e($c['href']) ?>"><?= $e($c['button']) ?></a></p>
<?php endif; ?>
  </li>
<?php endforeach; ?>
</ol>
