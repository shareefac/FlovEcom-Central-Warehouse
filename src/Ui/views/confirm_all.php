<?php
// "Confirm all ready": the check page (ConfirmAllCheck.dc.html; docs/decisions.md CA4). It saves nothing. The heading asks "Confirm N ERPNext
// corrections?", the line under it names the stores and warehouses; then the three lines (a coloured dot, the words, the figure), the three
// plain sentences, the note (saved on each one) and Back with "Confirm N", the page's one main button. The form carries the ids drawn here and
// the token, signed with the moment it was drawn (CA12): a correction that arrives after this page was drawn is never confirmed by its press, and
// a page older than 30 minutes is drawn again. Nothing ready: a plain line and Back.
?>
<?= $head($title, eyebrow: \CW\Ui\Words::CONFIRM_ALL['eyebrow'], meta: $from === null ? '' : $e($from), side: '') ?>
<?= $showNav() ?>
<div class="ca-page">
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<section class="card ca-lines" aria-label="<?= $word('CONFIRM_ALL', 'summary') ?>">
<?php foreach ($lines as $l): ?>
  <div class="ca-line"><span class="ca-dot <?= $e($l['tone']) ?>" aria-hidden="true"></span><span class="ca-text"><?= $e($l['text']) ?></span><span class="ca-fig"><?= $e($l['figure']) ?></span></div>
<?php endforeach; ?>
</section>
<?php if ($ready > 0): ?>
<div class="ca-says">
  <p><?= $word('CONFIRM_ALL', 'again') ?></p>
  <p><?= $word('CONFIRM_ALL', 'own_line') ?></p>
  <p><?= $word('CONFIRM_ALL', 'no_shop') ?></p>
</div>
<form class="ca-form" method="post" action="/ui/queues/corrections/confirm-all" data-unsaved="1"<?php if ($error !== null): ?> data-dirty="1"<?php endif; ?>>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="ids" value="<?= $e($ids) ?>">
  <input type="hidden" name="at" value="<?= $e($at) ?>">
  <input type="hidden" name="sig" value="<?= $e($sig) ?>">
  <label for="ca-note"><?= $word('CONFIRM_ALL', 'note') ?></label>
  <input type="text" id="ca-note" name="note" value="<?= $e($note) ?>" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required placeholder="<?= $word('CONFIRM_ALL', 'note_hint') ?>"<?php if ($error !== null): ?> aria-invalid="true"<?php endif; ?>>
  <p class="ca-actions"><a class="btn" href="<?= $u($back) ?>"><?= $word('CONFIRM_ALL', 'back') ?></a><button type="submit" class="btn primary"><?= $say('CONFIRM_ALL', 'confirm', $ready) ?></button></p>
</form>
<?php else: ?>
<p class="ca-none"><?= $word('CONFIRM_ALL', 'none_ready') ?></p>
<p class="ca-actions"><a class="btn" href="<?= $u($back) ?>"><?= $word('CONFIRM_ALL', 'back') ?></a></p>
<?php endif; ?>
</div>
