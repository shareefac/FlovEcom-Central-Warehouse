<?php
// The lines' grid's "+ Add new item" answered (PoAddNew::newItemLine, docs/decisions.md KG21): not a page, a fragment for app.js, its root
// marked data-ni-fragment (app.js takes nothing else) with the next FormOnce key for the panel (data-next-key). Made: the new item as one
// choice of the product box (the order page's choice: data-label, data-si / data-sku and what fills a line), which the grid takes as a
// line. Refused: the words (data-ni-error), the items to use instead (data-ni-others, each a choice the grid takes, "Use this one") and, for
// the same name, the words of "it is a different item" (data-ni-anyway).
?>
<div class="po-new-item" data-ni-fragment data-next-key="<?= $e($nextKey) ?>">
<?php if ($choice !== null): ?>
<ul data-po-choices>
  <li data-label="<?= $e($choice['label']) ?>" data-si="<?= $e($choice['si']) ?>" data-sku="<?= $e($choice['sku']) ?>" data-name="<?= $e($choice['name']) ?>" data-code="<?= $e($choice['code']) ?>" data-pack="<?= $e($choice['pack']) ?>" data-upp="<?= $e($choice['upp']) ?>" data-their="<?= $e($choice['their']) ?>" data-price="<?= $e($choice['price']) ?>" data-stock="<?= $e($choice['stock']) ?>" data-coming="<?= $e($choice['coming']) ?>"><span class="sm-opt-name"><?= $e($choice['name']) ?></span> <span class="sm-opt-meta"><?= $e($choice['meta']) ?></span></li>
</ul>
<?php else: ?>
<p data-ni-error data-use="<?= $word('PO_ADD', 'same_use') ?>"><?= $e($error) ?></p>
<?php if ($others !== []): ?>
<ul data-ni-others>
<?php foreach ($others as $c): ?>
  <li data-label="<?= $e($c['label']) ?>" data-si="<?= $e($c['si']) ?>" data-sku="<?= $e($c['sku']) ?>" data-name="<?= $e($c['name']) ?>" data-code="<?= $e($c['code']) ?>" data-pack="<?= $e($c['pack']) ?>" data-upp="<?= $e($c['upp']) ?>" data-their="<?= $e($c['their']) ?>" data-price="<?= $e($c['price']) ?>" data-stock="<?= $e($c['stock']) ?>" data-coming="<?= $e($c['coming']) ?>"><span class="sm-opt-name"><?= $e($c['name']) ?></span> <span class="sm-opt-meta"><?= $e($c['meta']) ?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($anyway !== ''): ?>
<p data-ni-anyway><?= $e($anyway) ?></p>
<?php endif; ?>
<?php endif; ?>
</div>
