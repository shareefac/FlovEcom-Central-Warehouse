<?php
// A delivery's scan box: its choices (ReceivingController::choices, docs/decisions.md GW3), which app.js draws in the box's own list (the
// site's one dropdown). Not a page: no statement, no layout. Its root says what it is (data-gi-fragment: app.js takes nothing else). Each
// choice is what the delivery's Add would add: its words (data-label), its supplier item or item (data-si, else data-sku and data-upp), its
// name, CW code, pack and their code, the packs one scan is (data-packs), the line of this delivery it adds to (data-line: Add's same-line
// rule) and a case's words (data-note); two lines: its name, then its CW code, brand and how this supplier sells it. No price (the save sets
// it, as Add does). The line under the box says when nothing is found or what to choose.
?>
<div class="po-options" data-gi-fragment>
<ul class="po-choices" data-po-choices>
<?php foreach ($choices as $c): ?>
  <li data-label="<?= $e($c['label']) ?>" data-si="<?= $e($c['si']) ?>" data-sku="<?= $e($c['sku']) ?>" data-name="<?= $e($c['name']) ?>" data-code="<?= $e($c['code']) ?>" data-pack="<?= $e($c['pack']) ?>" data-upp="<?= $e($c['upp']) ?>" data-their="<?= $e($c['their']) ?>" data-packs="<?= $e($c['packs']) ?>" data-line="<?= $e($c['line']) ?>" data-note="<?= $e($c['note']) ?>"><span class="sm-opt-name"><?= $e($c['name']) ?></span> <span class="sm-opt-meta"><?= $e($c['meta']) ?></span></li>
<?php endforeach; ?>
</ul>
<p class="po-says" data-po-says><?= $e($says) ?></p>
</div>
