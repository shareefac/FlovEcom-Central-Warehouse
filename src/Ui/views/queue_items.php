<?php
// Lines to place: the item box's choices (StoreQueuesController::suspenseItems, docs/decisions.md LQ5), which app.js draws in the box's own
// list (the order page's dropdown). Not a page: no statement, no layout. Its root says what it is (data-lq-fragment: app.js takes nothing
// else). Each choice carries the box's words for it (data-label: the name and the CW code) and what the row's form sends (data-code), and
// shows two lines: its name, then its CW code, brand and stock. The line under the box says when nothing is found. data-exact: the words were a
// usable barcode or a CW number (the one item app.js may choose by Enter, LQ9).
?>
<div class="po-options" data-lq-fragment<?php if ($exact): ?> data-exact="1"<?php endif; ?>>
<ul class="po-choices" data-lq-choices>
<?php foreach ($choices as $c): ?>
  <li data-label="<?= $e($c['label']) ?>" data-code="<?= $e($c['code']) ?>"><span class="sm-opt-name"><?= $e($c['name']) ?></span> <span class="sm-opt-meta"><?= $e($c['meta']) ?></span></li>
<?php endforeach; ?>
</ul>
<p class="po-says" data-lq-says><?= $e($says) ?></p>
</div>
