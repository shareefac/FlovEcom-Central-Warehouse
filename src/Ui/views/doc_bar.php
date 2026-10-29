<?php
// The bar at the bottom of a document's form (docs/decisions.md PV; the purchase order, the supplier invoice, a delivery, later the sales
// invoice): it stays on the screen while the form is. First the way out without saving (a link: nothing is sent; it says what it does, so
// app.js never asks before it: data-leave, docs/decisions.md CV14), then a short note of
// what is left (optional: a delivery's "2 lines still to check", GV2; $noteData its data- attributes for app.js), then the form's buttons,
// the main one last. $back: where the way out leads. $buttons: each the `action` it sends (or its own `name`), its words, `primary` for the
// main one.
$note = $note ?? null;
$noteData = $noteData ?? [];
?>
<div class="doc-bar">
  <a class="btn ghost" href="<?= $u($back) ?>" data-leave><?= $word('DOC', 'leave') ?></a>
<?php if ($note !== null): ?>
  <span class="doc-bar-note"<?php foreach ($noteData as $k => $v): ?> data-<?= $e($k) ?>="<?= $e($v) ?>"<?php endforeach; ?>><?= $e($note) ?></span>
<?php endif; ?>
<?php foreach ($buttons as $b): ?>
  <button type="submit" name="<?= $e($b['name'] ?? 'action') ?>" value="<?= $e($b['value']) ?>" class="btn<?php if ($b['primary'] ?? false): ?> primary<?php endif; ?>"><?= $e($b['label']) ?></button>
<?php endforeach; ?>
</div>
