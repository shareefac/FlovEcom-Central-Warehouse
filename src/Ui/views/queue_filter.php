<?php
// Approvals › From the stores (pack F): the status filter and the count line every queue page starts its list with.
?>
<form class="filters" method="get" action="<?= $u($action) ?>">
  <label><?= $word('QUEUES', 'show') ?>
    <select name="status">
<?php foreach ($states as $code): ?>
      <option value="<?= $e($code) ?>"<?php if ($state === $code): ?> selected<?php endif; ?>><?php if ($code === 'all'): ?><?= $word('QUEUES', 'all') ?><?php else: ?><?= $word('QUEUE_STATE', $code) ?><?php endif; ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit"><?= $word('QUEUES', 'filter') ?></button>
</form>
<?php if ($count > 0): ?>
<p class="muted"><?php if ($more): ?><?= $say('QUEUES', 'more', $pageSize) ?><?php elseif ($count === 1): ?><?= $word('QUEUES', 'total_one') ?><?php else: ?><?= $say('QUEUES', 'total_many', $count) ?><?php endif; ?></p>
<?php endif; ?>
