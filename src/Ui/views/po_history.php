<?php
// The order's History card (docs/decisions.md LP10: the order's ONE history), under its paper (po_cards) and in a draft's own fragment for
// the page that saves box by box (po_live, docs/decisions.md LS7, LS10): what happened to it, newest first ($events: the started, asked,
// confirmed, sent, closed and cancelled lines, each person who changed it while a draft, and the line-by-line changes of a draft saved box by
// box, one line per person per minute with each change in its fold), then its checks and OKs ($history). $eventsMore: the older changes left out.
?>
<section class="card po-history" aria-labelledby="history-h">
  <div class="head-help">
    <h2 id="history-h"><?= $word('ORDER', 'history_title') ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
<?php if ($events === [] && $history === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_history') ?></p>
<?php endif; ?>
<?php if ($events !== []): ?>
  <ol class="plain po-events">
<?php foreach ($events as $ev): ?>
    <li><strong><?= $e($ev['when']) ?></strong> · <?= $e($ev['text']) ?><?php if (($ev['fold'] ?? []) !== []): ?>
      <details class="live-fold"><summary><?= $word('LIVE_SAVE', 'fold') ?></summary>
        <ul class="plain">
<?php foreach ($ev['fold'] as $f): ?>
          <li><?= $e($f) ?></li>
<?php endforeach; ?>
        </ul>
      </details><?php endif; ?></li>
<?php endforeach; ?>
  </ol>
<?php if (($eventsMore ?? null) !== null): ?>
  <p class="muted"><?= $e($eventsMore) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($history !== []): ?>
  <h3 class="doc-label"><?= $word('ORDER', 'history') ?></h3>
  <ol class="plain checks-history">
<?php foreach ($history as $h): ?>
    <li>
      <p><?= $e($h['asked']) ?> <?= $e($h['why']) ?><?php if ($h['about'] !== null): ?> <?= $e($h['about']) ?><?php endif; ?></p>
      <p class="muted"><?= $e($h['outcome']) ?><?php if ($h['note'] !== null): ?> "<?= $e($h['note']) ?>"<?php endif; ?></p>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
