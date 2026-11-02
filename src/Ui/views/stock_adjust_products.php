<?php
// A line's product box on the quick adjustment screen: its choices (StockOpsController::gridProducts, docs/decisions.md AG4), which app.js draws in the line's
// own list (SM11's dropdown). Not a page: no statement, no layout. Its root says what it is (data-aj-fragment: app.js takes nothing else). Each choice carries
// the box's words for it (data-label), the product (data-sku), its CW number, its stock in the warehouse and what its barcode stands for (data-units), and
// shows two lines: its name, then its CW number, brand and what is free.
?>
<div class="aj-options" data-aj-fragment>
<ul data-aj-choices>
<?php foreach ($choices as $c): ?>
  <li data-sku="<?= $e($c['sku']) ?>" data-label="<?= $e($c['label']) ?>" data-code="<?= $e($c['code']) ?>" data-hand="<?= $e($c['hand']) ?>" data-units="<?= $e($c['units']) ?>"><span class="sm-opt-name"><?= $e($c['label']) ?></span> <span class="sm-opt-meta"><?= $e($c['meta']) ?></span></li>
<?php endforeach; ?>
</ul>
<p data-aj-says><?= $e($says) ?></p>
</div>
