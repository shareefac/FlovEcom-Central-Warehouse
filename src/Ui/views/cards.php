<?php $group = $group ?? null; $tone = $tone ?? 'info'; $id = $id ?? 'tasks'; ?>
<div class="grp-block">
<?php if ($group !== null): ?>
  <h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="<?= $e($id) ?>"><?= $e($group) ?></button><span class="grp-count"><?php if (count($list) === 1): ?><?= $word('HOME', 'tasks_one') ?><?php else: ?><?= $say('HOME', 'tasks_many', count($list)) ?><?php endif; ?></span></h3>
<?php endif; ?>
  <div class="table-wrap" id="<?= $e($id) ?>">
  <table class="stack board tasks <?= $e($tone) ?>">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('HOME', 'col_task') ?></th>
        <th scope="col"><?= $word('HOME', 'col_where') ?></th>
        <th scope="col" class="c-status"><?= $word('HOME', 'col_status') ?></th>
        <th scope="col"><?= $word('HOME', 'col_count') ?></th>
        <th scope="col"><?= $word('HOME', 'col_next') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $c): ?>
      <tr class="task<?php if (!empty($c['hero'])): ?> hero<?php endif; ?><?php if (!empty($c['quiet'])): ?> quiet<?php endif; ?> <?= $e($c['tone'] ?? 'info') ?>"<?php if (isset($c['key'])): ?> data-card="<?= $e($c['key']) ?>"<?php endif; ?>>
        <th scope="row" class="c-head c-item">
<?php if (isset($c['job'])): ?>
          <p class="task-step"><?= $word('UI', 'job') ?> <?= $n($c['job']) ?><?php if (!empty($c['hero'])): ?> <span class="start-tag"><?= $word('HOME', 'start_here') ?></span><?php endif; ?></p>
<?php endif; ?>
          <h3><?= $e($c['title']) ?></h3>
<?php if (isset($c['text'])): ?>
          <p class="text"><?= $e($c['text']) ?></p>
<?php endif; ?>
<?php if (isset($c['lines'])): ?>
          <ul class="task-lines">
<?php foreach ($c['lines'] as $line): ?>
            <li><?= $e($line) ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
<?php if (isset($c['what'])): ?>
          <p class="task-what"><strong><?= $word('UI', 'what_happens') ?></strong> <?= $e($c['what']) ?></p>
<?php endif; ?>
        </th>
        <td class="where" data-label="<?= $word('HOME', 'col_where') ?>"><?= $e($c['where'] ?? '') ?></td>
        <td class="c-status"><?php if (isset($c['chip'])): ?><?= $chip($c['tone'] ?? 'info', $c['chip']) ?><?php endif; ?></td>
        <td data-label="<?= $word('HOME', 'col_count') ?>"><?php if (isset($c['progress'])): ?><span class="prog"><progress class="bar" value="<?= $e($c['progress'][0]) ?>" max="<?= $e(max(1, (int) $c['progress'][1])) ?>" aria-label="<?= $say('UI', 'progress_done', (int) $c['progress'][0], (int) $c['progress'][1]) ?>"><?= $say('UI', 'progress_short', (int) $c['progress'][0], (int) $c['progress'][1]) ?></progress></span><?php endif; ?><?php if (isset($c['count'])): ?><p class="count"><?= $n($c['count']) ?><?php if (isset($c['unit'])): ?> <small><?= $e($c['unit']) ?></small><?php endif; ?></p><?php endif; ?></td>
        <td class="c-next"><?php if (isset($c['href'], $c['button'])): ?><a class="btn sm <?php if (!empty($c['hero']) || !empty($c['primary'])): ?>primary<?php else: ?>secondary<?php endif; ?>" href="<?= $e($c['href']) ?>"><?= $e($c['button']) ?></a><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
