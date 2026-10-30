<?php
// The bar at the right of a store product's head (TP_Match; docs/decisions.md W12.1): opened from a spot check, its matches as blocks (done,
// wrong, this one, still to do); opened from a list, the part answered today of what is answered today and what is left (its words for a
// screen reader, the same words as before: data-progress). Nothing when the page was opened from neither.
?>
<?php if ($strip !== null): ?>
<ol class="segments mt-blocks" aria-label="<?= $say('SPOT', 'progress', $strip['decided'], $strip['size']) ?>">
<?php foreach ($strip['blocks'] as $b): ?>
  <li class="<?= $e($b['state']) ?>" title="<?= $e($b['title']) ?>"></li>
<?php endforeach; ?>
</ol>
<?php elseif ($words !== null): ?>
<span class="mt-progress" role="img" aria-label="<?= $e($words) ?>" title="<?= $word('MATCH', 'bar') ?>" data-progress="<?= $e($words) ?>"><?php if ($fill > 0): ?><span class="mt-fill w-<?= $e($fill) ?>"></span><?php endif; ?></span>
<?php endif; ?>
