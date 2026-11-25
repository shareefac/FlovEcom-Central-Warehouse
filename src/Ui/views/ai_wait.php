<?php
// Claude reads the upload (CW-1182; docs/decisions.md AI3): the page that waits while Claude reads a document sent from Profit > Delivery bills or
// Profit > Expenses. It looks again by itself every few seconds (the controller's Refresh line: no script); "Look again now" does the
// same by hand, "Back without waiting" leaves it (the reading goes on; this page's address shows it within the hour).
?>
<section class="narrow ai-wait">
  <?= $head(\CW\Ui\Words::AIREAD['wait_title']) ?>
  <p class="notice" role="status" aria-live="polite"><?= $say('AIREAD', 'wait_text', $what) ?></p>
<?php if ($queued): ?>
  <p class="hint"><?= $word('AIREAD', 'wait_queue') ?></p>
<?php endif; ?>
  <p class="muted"><?= $say('AIREAD', 'wait_since', (int) $since) ?></p>
  <p class="actions"><a class="btn primary" href="<?= $u($again) ?>"><?= $word('AIREAD', 'wait_again') ?></a> <a class="btn" href="<?= $u($back) ?>"><?= $word('AIREAD', 'wait_back') ?></a></p>
</section>
