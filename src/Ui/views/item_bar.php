<?php
// The item page's actions (docs/decisions.md IV2; the approved design IT_Item, IT_Phone): in the head at its right, at the bottom of the screen
// on a phone. Count it (a count sheet of this item, through the count board's own form and route), Move stock (a transfer's new form with
// this item), Order more (a new purchase order with its main supplier's box), More (the other stock records with this item, its reorder
// settings). Only what the person may use; ONE main action (Order more, else Count it, else Move stock).
?>
<?php if ($actions['count'] !== null || $actions['move'] !== null || $actions['order'] !== null || $actions['more'] !== []): ?>
<div class="doc-actions item-bar" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if ($actions['count'] !== null): ?>
  <form class="inline" method="post" action="/ui/stock/counts">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($actions['count']['key']) ?>">
    <input type="hidden" name="warehouse" value="<?= $e($actions['count']['warehouse']) ?>">
    <input type="hidden" name="mode" value="ticked">
    <input type="hidden" name="pick_<?= $e($skuId) ?>" value="1">
    <button type="submit" class="btn<?php if ($actions['main'] === 'count'): ?> primary<?php endif; ?>"><?= $word('COUNTS', 'item_count_it') ?></button>
  </form>
<?php endif; ?>
<?php if ($actions['move'] !== null): ?>
  <a class="btn<?php if ($actions['main'] === 'move'): ?> primary<?php endif; ?>" href="<?= $e($actions['move']) ?>"><?= $word('ITEM', 'move_stock') ?></a>
<?php endif; ?>
<?php if ($actions['order'] !== null): ?>
  <a class="btn primary" href="<?= $e($actions['order']) ?>"><?= $word('ITEM', 'order_more') ?></a>
<?php endif; ?>
<?php if ($actions['more'] !== []): ?>
  <details class="more">
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php foreach ($actions['more'] as $m): ?>
      <a class="menu-item" href="<?= $e($m['href']) ?>"><?= $e($m['label']) ?></a>
<?php endforeach; ?>
    </div>
  </details>
<?php endif; ?>
</div>
<?php endif; ?>
