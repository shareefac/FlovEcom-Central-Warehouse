<?php
// The bar at the bottom of a document's form (docs/decisions.md PV; the purchase order, the supplier invoice, later the sales invoice): it
// stays on the screen while the form is. First the way out without saving (a link: nothing is sent), then the form's buttons, the main
// one last. $back: where the way out leads. $buttons: each the `action` it sends, its words, and `primary` for the main one.
?>
<div class="doc-bar">
  <a class="btn ghost" href="<?= $u($back) ?>"><?= $word('DOC', 'leave') ?></a>
<?php foreach ($buttons as $b): ?>
  <button type="submit" name="action" value="<?= $e($b['value']) ?>" class="btn<?php if ($b['primary'] ?? false): ?> primary<?php endif; ?>"><?= $e($b['label']) ?></button>
<?php endforeach; ?>
</div>
