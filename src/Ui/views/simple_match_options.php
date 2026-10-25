<?php
// Simple matching's choices for one cell (SimpleMatchController::options, docs/decisions.md SM4): the products of ONE store that still
// wait for a match, for ONE warehouse product, which app.js copies into the cell's <datalist>. Not a page: no statement, no layout. Its
// root says what it is (data-sm-fragment: app.js takes nothing else). Each choice's value is what the box shows ("name · ID 4711"); its
// data attributes are what Apply sends for it. The line under the box says when nothing is found.
?>
<div class="sm-options" data-sm-fragment data-sku-id="<?= $e($sku) ?>" data-channel="<?= $e($store) ?>">
<datalist>
<?php foreach ($choices as $c): ?>
  <option value="<?= $e($c['label']) ?>" data-listing-id="<?= $e($c['listing_id']) ?>" data-map-version="<?= $e($c['map_version']) ?>" data-proposal-id="<?= $e($c['proposal_id'] ?? '') ?>" data-option="<?= $e($c['option']) ?>"></option>
<?php endforeach; ?>
</datalist>
<p class="sm-says" data-sm-says><?= $e($says) ?></p>
</div>
