<?php
// The action bar of a list with ticked rows (docs/decisions.md U108; monday.com's batch-action bar): "N selected", the actions this
// person may take on this list, and × (clear). Without app.js it is a plain row of submit buttons under the list; app.js shows it
// only while rows are ticked and keeps it at the bottom of the screen. The words app.js shows come from the data- attributes.
// A page may give its own words for them (`words`: bar, none, one, many, too_many) and one choice drawn before the buttons
// (`choice`: name, label, options, selected): By Item's bar speaks of items and asks on which store (U124). `max` 0: no limit shown.
// An action with a `formaction` posts the same form to its own address (Store Products' "Create new warehouse products for these",
// which shows a preview first: NF7); without app.js too. One main action (docs/decisions.md DS5): the first action given as `primary`
// is the bar's magenta button, every other one plain.
$mainAction = null;
foreach ($actions as $a) {
    if ($a['class'] === 'primary') {
        $mainAction = $a['action'];
        break;
    }
}
?>
<div class="bulkbar" data-bulkbar data-none="<?= $e($words['none'] ?? \CW\Ui\Words::BULK['selected_none']) ?>" data-one="<?= $e($words['one'] ?? \CW\Ui\Words::BULK['selected_one']) ?>" data-many="<?= $e($words['many'] ?? \CW\Ui\Words::BULK['selected_many']) ?>" data-too-many="<?= $e($words['too_many'] ?? \CW\Ui\Words::BULK['too_many']) ?>" data-max="<?= $e($max) ?>" role="region" aria-label="<?= $e($words['bar'] ?? \CW\Ui\Words::BULK['bar']) ?>">
  <p class="bulkbar-count"><span class="bulkbar-n" data-bulk-n aria-hidden="true"></span><span data-bulk-count aria-live="polite"><?= $e($words['none'] ?? \CW\Ui\Words::BULK['selected_none']) ?></span></p>
  <div class="bulkbar-actions">
<?php if (isset($choice)): ?>
    <label class="bulkbar-choice"><span><?= $e($choice['label']) ?></span>
      <select name="<?= $e($choice['name']) ?>">
<?php foreach ($choice['options'] as $o): ?>
        <option value="<?= $e($o['value']) ?>"<?php if ($o['value'] === $choice['selected']): ?> selected<?php endif; ?>><?= $e($o['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
<?php foreach ($actions as $a): ?>
    <button type="submit" name="action" value="<?= $e($a['action']) ?>" class="btn sm<?php if ($a['action'] === $mainAction): ?> primary<?php endif; ?>"<?php if (isset($a['formaction'])): ?> formaction="<?= $u($a['formaction']) ?>"<?php endif; ?>><?= $e($a['label']) ?></button>
<?php endforeach; ?>
  </div>
  <button type="button" class="bulkbar-close" data-bulk-clear hidden><span aria-hidden="true">&times;</span><span class="visually-hidden"><?= $word('BULK', 'clear') ?></span></button>
</div>
