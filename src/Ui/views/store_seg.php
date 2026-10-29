<?php
// The store selector of the matching pages (docs/decisions.md U106): "All stores" (where the page has it) and each store from the
// channel table, each with its count; the choice travels in the query (`channel`) through every Mapping segment and list. A row of the
// one tabs component (docs/decisions.md DS2).
?>
<nav class="tabs store-seg" aria-label="<?= $word('BULK', 'stores') ?>">
<?php foreach ($items as $it): ?>
  <a class="tab"<?php if ($it['current']): ?> aria-current="page"<?php else: ?> href="<?= $u($it['href'], $it['query']) ?>"<?php endif; ?>><span><?= $e($it['label']) ?></span><span class="count" title="<?= $n($it['count']) ?> <?= $e($countWords) ?>"><?= $n($it['count']) ?><span class="visually-hidden"> <?= $e($countWords) ?></span></span></a>
<?php endforeach; ?>
</nav>
