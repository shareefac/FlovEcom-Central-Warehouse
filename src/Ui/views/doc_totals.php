<?php
// A document's totals block (docs/decisions.md PV; the supplier invoice, the purchase order's page, later the sales invoice): the rows on
// the right under the lines. $label: the block's name for a screen reader. $rows: each a label and a value already in words (money through
// Html::money), `grand` for the last total, or `input` (name, value, id) for a figure the person types as the paper says it.
?>
<section class="doc-totals" aria-label="<?= $e($label) ?>">
  <dl>
<?php foreach ($rows as $row): ?>
<?php if (isset($row['input'])): ?>
    <dt><label for="<?= $e($row['input']['id']) ?>"><?= $e($row['label']) ?></label></dt>
    <dd><input id="<?= $e($row['input']['id']) ?>" type="text" name="<?= $e($row['input']['name']) ?>" value="<?= $e($row['input']['value']) ?>" inputmode="decimal" maxlength="16"></dd>
<?php else: ?>
    <dt<?php if ($row['grand'] ?? false): ?> class="grand"<?php endif; ?>><?= $e($row['label']) ?></dt>
    <dd<?php if ($row['grand'] ?? false): ?> class="grand"<?php endif; ?>><?= $e($row['value']) ?></dd>
<?php endif; ?>
<?php endforeach; ?>
  </dl>
</section>
