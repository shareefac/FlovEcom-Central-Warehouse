<?php
// Approvals › From the stores (pack F): the Show choice every queue page starts its list with, in the board toolbar of LP_Queue (the muted
// "Show", the choice, its Show button: no script needed; docs/decisions.md DC18). The groups' headings count the rows; only a cut list says so.
?>
<form class="toolbar" method="get" action="<?= $u($action) ?>" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="q-status"><?= $word('QUEUES', 'show') ?></label>
    <select id="q-status" name="status">
<?php foreach ($states as $code): ?>
      <option value="<?= $e($code) ?>"<?php if ($state === $code): ?> selected<?php endif; ?>><?php if ($code === 'all'): ?><?= $word('QUEUES', 'all') ?><?php else: ?><?= $word('QUEUE_STATE', $code) ?><?php endif; ?></option>
<?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>
<?php if ($count > 0 && $more): ?>
<p class="muted"><?= $say('QUEUES', 'more', $pageSize) ?></p>
<?php endif; ?>
