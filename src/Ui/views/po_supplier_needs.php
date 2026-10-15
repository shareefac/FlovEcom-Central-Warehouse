<?php /* Pack PO (docs/decisions.md PO3): what a supplier that is not approved yet still needs before the order can be confirmed. */ ?>
<section class="card note supplier-needs" aria-labelledby="supplier-needs-h">
  <h2 id="supplier-needs-h"><?= $e($needs['title']) ?></h2>
  <p><?= $e($needs['lead']) ?></p>
<?php if ($needs['steps'] !== []): ?>
  <ol>
<?php foreach ($needs['steps'] as $step): ?>
    <li><?= $e($step) ?></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
  <p><a href="<?= $u($needs['link']) ?>"><?= $word('PO_ADD', 'needs_link') ?></a></p>
</section>
