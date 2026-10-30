<?php
// Home's two buttons beside the greeting (the approved Main artboard of 30 Oct 2026; docs/decisions.md RD-C15): Receive a delivery (Goods received's
// one way to start a delivery) and + New purchase order (the page's main button), each only for whoever may do it.
?>
<?php if ($receive !== null || $newOrder !== null): ?>
<div class="hm-actions">
<?php if ($receive !== null): ?>
  <a class="btn" href="<?= $e($receive) ?>"><?= $word('RD_HOME', 'receive') ?></a>
<?php endif; ?>
<?php if ($newOrder !== null): ?>
  <a class="btn primary" href="<?= $e($newOrder) ?>"><span class="ico ico-plus" aria-hidden="true"></span><span><?= $word('RD_HOME', 'new_order') ?></span></a>
<?php endif; ?>
</div>
<?php endif; ?>
