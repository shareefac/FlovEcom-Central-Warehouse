<?php
// The item page's actions (the approved redesign of 30 Oct 2026, artboard Item; docs/decisions.md RD-A4, IV2 of before): in the head at its
// right, More and then the ONE main action (Order more, else Count it, else Move stock). More holds the others this person may use: Count it
// (a count sheet of this item, through the count board's own form and route), Move stock (a transfer's new form with this item), the other
// stock records with this item, its reorder settings.
?>
<?php $main = $actions['main']; ?>
<?php if ($actions['count'] !== null || $actions['move'] !== null || $actions['order'] !== null || $actions['more'] !== []): ?>
<div class="doc-actions item-bar" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if (($actions['count'] !== null && $main !== 'count') || ($actions['move'] !== null && $main !== 'move') || $actions['more'] !== []): ?>
  <details class="more">
    <summary class="btn"><?= $word('ITEM_PAGE', 'more') ?></summary>
    <div class="more-menu">
<?php if ($actions['count'] !== null && $main !== 'count'): ?>
      <form method="post" action="/ui/stock/counts" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="form_key" value="<?= $e($actions['count']['key']) ?>">
        <input type="hidden" name="warehouse" value="<?= $e($actions['count']['warehouse']) ?>">
        <input type="hidden" name="mode" value="ticked">
        <input type="hidden" name="pick_<?= $e($skuId) ?>" value="1">
        <button type="submit" class="btn"><?= $word('COUNTS', 'item_count_it') ?></button>
      </form>
<?php endif; ?>
<?php if ($actions['move'] !== null && $main !== 'move'): ?>
      <a class="menu-item" href="<?= $e($actions['move']) ?>"><?= $word('ITEM', 'move_stock') ?></a>
<?php endif; ?>
<?php foreach ($actions['more'] as $m): ?>
      <a class="menu-item" href="<?= $e($m['href']) ?>"><?= $e($m['label']) ?></a>
<?php endforeach; ?>
    </div>
  </details>
<?php endif; ?>
<?php if ($main === 'count'): ?>
  <form class="inline" method="post" action="/ui/stock/counts" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($actions['count']['key']) ?>">
    <input type="hidden" name="warehouse" value="<?= $e($actions['count']['warehouse']) ?>">
    <input type="hidden" name="mode" value="ticked">
    <input type="hidden" name="pick_<?= $e($skuId) ?>" value="1">
    <button type="submit" class="btn primary"><?= $word('COUNTS', 'item_count_it') ?></button>
  </form>
<?php elseif ($main === 'move'): ?>
  <a class="btn primary" href="<?= $e($actions['move']) ?>"><?= $word('ITEM', 'move_stock') ?></a>
<?php elseif ($main === 'order'): ?>
  <a class="btn primary" href="<?= $e($actions['order']) ?>"><?= $word('ITEM', 'order_more') ?></a>
<?php endif; ?>
</div>
<?php endif; ?>
