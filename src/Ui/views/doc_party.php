<?php
// A saved document's head (docs/decisions.md PV; the purchase order, the supplier invoice, later the sales invoice): the other party's
// block (its name, a link to its page, its address lines) beside the document's facts (each a label and a value in words, a link when it
// names another record). $party: label, name, href, lines. $facts: label, value, href (optional).
?>
<div class="doc-meta">
  <section class="doc-party" aria-label="<?= $e($party['label']) ?>">
    <p class="doc-label"><?= $e($party['label']) ?></p>
    <p class="doc-party-name"><?php if (($party['href'] ?? null) !== null): ?><a href="<?= $u($party['href']) ?>"><?= $e($party['name']) ?></a><?php else: ?><?= $e($party['name']) ?><?php endif; ?></p>
<?php foreach ($party['lines'] as $line): ?>
    <p><?= $e($line) ?></p>
<?php endforeach; ?>
  </section>
  <dl class="doc-facts">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact['label']) ?></dt><dd><?php if (($fact['href'] ?? null) !== null): ?><a href="<?= $u($fact['href']) ?>"><?= $e($fact['value']) ?></a><?php else: ?><?= $e($fact['value']) ?><?php endif; ?></dd>
<?php endforeach; ?>
  </dl>
</div>
