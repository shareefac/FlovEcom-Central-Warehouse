<?php if ($can_approve): ?>
<form class="inline" method="post" action="/ui/review/decision/<?= $e($id) ?>/approve">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <input type="text" name="reason" placeholder="Note (optional)" maxlength="500" aria-label="Note for the approval">
  <button type="submit" class="primary">Approve</button>
</form>
<?php elseif ($own): ?>
<span class="muted">Waiting for another mapping lead.</span>
<?php else: ?>
<span class="muted">Waiting for a mapping lead.</span>
<?php endif; ?>
<?php if ($can_withdraw): ?>
<form class="inline" method="post" action="/ui/review/decision/<?= $e($id) ?>/withdraw">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <button type="submit">Withdraw</button>
</form>
<?php endif; ?>
