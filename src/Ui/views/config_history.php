<?php if ($rows === []): ?>
<p class="muted"><?= $word('CONFIG', 'history_none') ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="stack history config-history">
  <thead>
    <tr>
      <th scope="col"><?= $word('CONFIG', 'when') ?></th>
      <th scope="col"><?= $word('CONFIG', 'who') ?></th>
      <th scope="col"><?= $word('CONFIG', 'what') ?></th>
      <th scope="col"><?= $word('CONFIG', 'why') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $h): ?>
    <tr>
      <th scope="row" class="c-head"><?= $when($h['when']) ?></th>
      <td data-label="<?= $word('CONFIG', 'who') ?>"><?= $e($h['who']) ?></td>
      <td data-label="<?= $word('CONFIG', 'what') ?>"><strong><?= $e($h['action']) ?></strong> <?= $e($h['what']) ?></td>
      <td data-label="<?= $word('CONFIG', 'why') ?>"><?= $e($h['why']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
