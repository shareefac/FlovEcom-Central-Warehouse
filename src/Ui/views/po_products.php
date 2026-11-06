<?php
// The order's product box: its choices (PurchaseOrdersController::products, docs/decisions.md PX8), which app.js draws in the box's own list
// (SM11's dropdown). Not a page: no statement, no layout. Its root says what it is (data-po-fragment: app.js takes nothing else). Each choice
// carries the box's words for it (data-label) and what Add sends for it (data-si, its supplier item; else data-sku, the item), and shows two
// lines: its name, then its CW number, brand and how this supplier sells it. The line under the box says when nothing is found. What the
// lines' grid fills a line with in place (docs/decisions.md KG2): data-name, data-code, data-pack, data-upp, data-their, data-price.
?>
<div class="po-options" data-po-fragment>
<ul class="po-choices" data-po-choices>
<?php foreach ($choices as $c): ?>
  <li data-label="<?= $e($c['label']) ?>" data-si="<?= $e($c['si']) ?>" data-sku="<?= $e($c['sku']) ?>" data-name="<?= $e($c['name']) ?>" data-code="<?= $e($c['code']) ?>" data-pack="<?= $e($c['pack']) ?>" data-upp="<?= $e($c['upp']) ?>" data-their="<?= $e($c['their']) ?>" data-price="<?= $e($c['price']) ?>"><span class="sm-opt-name"><?= $e($c['name']) ?></span> <span class="sm-opt-meta"><?= $e($c['meta']) ?></span></li>
<?php endforeach; ?>
</ul>
<p class="po-says" data-po-says><?= $e($says) ?></p>
</div>
