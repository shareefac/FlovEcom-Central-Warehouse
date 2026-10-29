<?php
// A saved document's head (docs/decisions.md PV12, PV19; the purchase order, the supplier invoice, later the sales invoice): the other party's
// block (its name, a link to its page, its address lines) beside the document's facts (each a label and a value in words; a link when it
// names another record, or several links: `links`), and, when there is one, a big figure on the right (`big`: label, value, note; the
// invoice's balance). $party: label, name, href, lines, big. $facts: label, value, href, links.
?>
<div class="doc-meta<?php if (($party['big'] ?? null) !== null): ?> with-big<?php endif; ?>">
  <section class="doc-party" aria-label="<?= $e($party['label']) ?>">
    <p class="doc-label"><?= $e($party['label']) ?></p>
    <p class="doc-party-name"><?php if (($party['href'] ?? null) !== null): ?><a href="<?= $u($party['href']) ?>"><?= $e($party['name']) ?></a><?php else: ?><?= $e($party['name']) ?><?php endif; ?></p>
<?php foreach ($party['lines'] as $line): ?>
    <p><?= $e($line) ?></p>
<?php endforeach; ?>
  </section>
  <dl class="doc-facts">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact['label']) ?></dt><dd><?php if (($fact['links'] ?? null) !== null): ?><?php foreach ($fact['links'] as $i => $link): ?><?php if ($i > 0): ?> · <?php endif; ?><a href="<?= $u($link['href']) ?>"><?= $e($link['text']) ?></a><?php endforeach; ?><?php elseif (($fact['href'] ?? null) !== null): ?><a href="<?= $u($fact['href']) ?>"><?= $e($fact['value']) ?></a><?php else: ?><?= $e($fact['value']) ?><?php endif; ?></dd>
<?php endforeach; ?>
  </dl>
<?php if (($party['big'] ?? null) !== null): ?>
  <div class="doc-big">
    <p class="doc-label"><?= $e($party['big']['label']) ?></p>
    <p class="doc-big-value"><?= $e($party['big']['value']) ?></p>
<?php if (($party['big']['note'] ?? null) !== null): ?>
    <p class="muted"><?= $e($party['big']['note']) ?></p>
<?php endif; ?>
  </div>
<?php endif; ?>
</div>
