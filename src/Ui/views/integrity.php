<?php
// Settings › the safety checks page, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.19):
// read only. The heading with what the check is under its "?"; a card of the last check (its result, when it finished and how long it took,
// the warning when it is late, what it found folded open when it found something); then a card of the earlier checks, a row each.
?>
<?= $head(\CW\Ui\Words::MENU['integrity'], intro: 'integrity', aside: $explain('safety_check', \CW\Ui\Words::MENU['integrity'])) ?>
<?php if ($latest === null): ?>
<?= $empty(\CW\Ui\Words::INTEGRITY['none']) ?>
<?php else: ?>
<div class="set-list">
<section class="set-card latest-check<?php if (!$latest['ok']): ?> waiting<?php endif; ?>" aria-labelledby="last-h">
  <h2 id="last-h"><?= $word('INTEGRITY', 'last') ?></h2>
  <div class="set-row">
    <div class="set-k">
      <p class="set-kt"><?= $say('INTEGRITY', 'took', \CW\Ui\Html::when($latest['finished_at']), $latest['seconds']) ?></p>
<?php if ($stale): ?>
      <p class="note" role="alert"><?= $say('INTEGRITY', 'stale', \CW\Ui\Html::when($latest['finished_at'])) ?></p>
<?php endif; ?>
<?php if (!$latest['ok']): ?>
      <p class="error" role="alert"><?= $word('INTEGRITY', 'tell') ?></p>
<?php endif; ?>
    </div>
    <div class="set-v"><?php if ($latest['ok']): ?><span class="tag done"><?= $word('INTEGRITY', 'result_ok') ?></span><?php elseif ($latest['problems'] === 1): ?><span class="tag blocked"><?= $word('INTEGRITY', 'result_one') ?></span><?php else: ?><span class="tag blocked"><?= $say('INTEGRITY', 'result_bad', $latest['problems']) ?></span><?php endif; ?></div>
<?php if (!$latest['ok']): ?>
    <details class="set-fold fold tech" open>
      <summary><?= $word('INTEGRITY', 'details') ?></summary>
      <div class="set-fold-body">
        <ul class="problems">
<?php foreach ($latest['details'] as $line): ?>
          <li><code><?= $e($line) ?></code></li>
<?php endforeach; ?>
        </ul>
<?php if ($latest['cut'] !== null): ?>
        <p class="muted"><?= $say('INTEGRITY', 'more', $latest['cut']) ?></p>
<?php endif; ?>
      </div>
    </details>
<?php endif; ?>
  </div>
</section>
<?php if ($earlier !== []): ?>
<section class="set-card checks" aria-labelledby="earlier-h">
  <h2 id="earlier-h"><?= $word('INTEGRITY', 'earlier') ?></h2>
<?php foreach ($earlier as $r): ?>
  <div class="set-row">
    <div class="set-k"><p class="set-kt"><?= $when($r['finished_at']) ?></p></div>
    <div class="set-v"><?php if ($r['ok']): ?><span class="tag done"><?= $word('INTEGRITY', 'result_ok') ?></span><?php elseif ($r['problems'] === 1): ?><span class="tag blocked"><?= $word('INTEGRITY', 'result_one') ?></span><?php else: ?><span class="tag blocked"><?= $say('INTEGRITY', 'result_bad', $r['problems']) ?></span><?php endif; ?></div>
  </div>
<?php endforeach; ?>
</section>
<?php endif; ?>
</div>
<?php endif; ?>
