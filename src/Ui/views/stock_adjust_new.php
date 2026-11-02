<?php
// A new adjustment: the quick screen before anything is saved (docs/decisions.md AG1). Its first save makes the draft and its lines together.
?>
<p class="crumbs"><a href="<?= $e($base) ?>"><?= $e($listName) ?></a></p>
<?= $head(\CW\Ui\Words::ADJ_GRID['title']) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('stock_adjust_grid', $grid) ?>
