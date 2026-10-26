<?php
// Simple matching's choices for one cell (SimpleMatchController::options, docs/decisions.md SM4, SM11): the products of ONE store that
// still wait for a match, for ONE warehouse product, which app.js draws in the cell's own list under the box. Not a page: no statement,
// no layout. Its root says what it is (data-sm-fragment: app.js takes nothing else). Each choice carries the box's words for it
// (data-label: "ID 4711 · …", what Apply compares) and what Apply sends for it (its data attributes), and shows two lines: its full
// name on the store, then its ID, its status on the store, its strength and its mark. The line under the box says when nothing is found.
?>
<div class="sm-options" data-sm-fragment data-sku-id="<?= $e($sku) ?>" data-channel="<?= $e($store) ?>">
<ul class="sm-choices" data-sm-choices>
<?php foreach ($choices as $c): ?>
  <li data-label="<?= $e($c['label']) ?>" data-listing-id="<?= $e($c['listing_id']) ?>" data-map-version="<?= $e($c['map_version']) ?>" data-proposal-id="<?= $e($c['proposal_id'] ?? '') ?>" data-option="<?= $e($c['option']) ?>"><span class="sm-opt-name"><?= $e($c['name']) ?></span> <span class="sm-opt-meta"><?= $e($c['meta']) ?></span></li>
<?php endforeach; ?>
</ul>
<p class="sm-says" data-sm-says><?= $e($says) ?></p>
</div>
