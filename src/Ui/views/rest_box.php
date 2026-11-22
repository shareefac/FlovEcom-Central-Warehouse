<?php
// CW-1155 "Receive the rest" (docs/decisions.md RR13): what an invoice is still waiting for after its first receipt, on the invoice page and on
// the first receipt's page: the units (and the money, for someone who reads it), how many days (red once late), each owed line, the receipts
// of the rest so far, and "Receive the rest" (Goods In's right; a CSRF-protected POST) or the receipt of the rest being booked in.
?>
<div class="rest-box" id="rest">
  <h3><?= $word('ARRIVALS', 'rest_title') ?></h3>
<?php if ($rest['text'] !== null): ?>
  <p><strong><?= $e($rest['text']) ?></strong> <?= $chip($rest['late'] ? 'needs' : 'waiting', (string) $rest['days']) ?></p>
  <p class="muted"><?= $word('ARRIVALS', 'rest_text') ?></p>
  <ul>
<?php foreach ($rest['lines'] as $l): ?>
    <li><?php if ($rest['prices']): ?><?= $say('ARRIVALS', 'missing_line', $l['units'], $l['code'], $l['name'], $l['line_no'], $l['amount_text']) ?><?php else: ?><?= $say('ARRIVALS', 'missing_line_items', $l['units'], $l['code'], $l['name'], $l['line_no']) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($rest['receipts'] !== []): ?>
  <ul>
<?php foreach ($rest['receipts'] as $g): ?>
    <li><?= $say('ARRIVALS', 'rest_received_on', $g['number'], $g['units']) ?> <a href="<?= $u($g['href']) ?>"><?= $e($g['number']) ?></a></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($rest['live'] !== null): ?>
  <p><a class="btn" href="<?= $u($rest['live']['href']) ?>"><?= $word('ARRIVALS', 'rest_open') ?></a></p>
<?php elseif ($rest['canReceive']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/receiving/arriving/' . $rest['invoiceId'] . '/receive-rest') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($fromGrn !== null): ?>
    <input type="hidden" name="from_grn" value="<?= $e($fromGrn) ?>">
<?php endif; ?>
    <button type="submit" class="btn primary"><?= $word('ARRIVALS', 'rest_receive') ?></button>
  </form>
<?php endif; ?>
<?php if ($fromGrn === null && $rest['text'] !== null && $rest['first'] !== null): ?>
  <p class="muted"><a href="<?= $u($rest['first']['href'] . '#arrival') ?>"><?= $say('ARRIVALS', 'rest_claim', $rest['first']['number']) ?></a></p>
<?php endif; ?>
</div>
