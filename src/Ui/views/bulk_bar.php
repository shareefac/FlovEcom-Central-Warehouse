<?php
// The action bar of a list with ticked rows (docs/decisions.md U108; monday.com's batch-action bar): "N selected", the actions this
// person may take on this list, and × (clear). Without app.js it is a plain row of submit buttons under the list; app.js shows it
// only while rows are ticked and keeps it at the bottom of the screen. The words app.js shows come from the data- attributes.
?>
<div class="bulkbar" data-bulkbar data-none="<?= $word('BULK', 'selected_none') ?>" data-one="<?= $word('BULK', 'selected_one') ?>" data-many="<?= $word('BULK', 'selected_many') ?>" data-too-many="<?= $word('BULK', 'too_many') ?>" data-max="<?= $e($max) ?>" role="region" aria-label="<?= $word('BULK', 'bar') ?>">
  <p class="bulkbar-count"><span class="bulkbar-n" data-bulk-n aria-hidden="true"></span><span data-bulk-count aria-live="polite"><?= $word('BULK', 'selected_none') ?></span></p>
  <div class="bulkbar-actions">
<?php foreach ($actions as $a): ?>
    <button type="submit" name="action" value="<?= $e($a['action']) ?>" class="btn sm <?= $e($a['class']) ?>"><?= $e($a['label']) ?></button>
<?php endforeach; ?>
  </div>
  <button type="button" class="bulkbar-close" data-bulk-clear hidden><span aria-hidden="true">&times;</span><span class="visually-hidden"><?= $word('BULK', 'clear') ?></span></button>
</div>
