<div class="pending-actions">
<?php if ($can_approve): ?>
<form class="inline" method="post" action="/ui/review/decision/<?= $e($id) ?>/approve" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <input type="text" name="reason" placeholder="<?= $word('PENDING', 'note') ?>" maxlength="500" aria-label="<?= $word('PENDING', 'note_label') ?>">
  <button type="submit" class="btn primary"><?= $word('PENDING', 'approve') ?></button>
</form>
<?php elseif ($say_wait ?? true): ?>
<p class="muted"><?= $word('PENDING', 'wait') ?></p>
<?php endif; ?>
<?php if ($can_withdraw): ?>
<form class="inline" method="post" action="/ui/review/decision/<?= $e($id) ?>/withdraw" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <button class="btn" type="submit"><?php if (!$own && $from === 'pending' && ($decider ?? null) !== null): ?><?= $say('PENDING', 'cancel_named', (string) $decider) ?><?php else: ?><?= $word('PENDING', 'cancel') ?><?php endif; ?></button>
</form>
<p class="muted small"><?= $word('PENDING', 'cancel_does') ?></p>
<?php endif; ?>
</div>
