<?php
// A refusal of a box saved by itself (the live drafts plan, section 5; docs/decisions.md LS9, LS12), in a draft's fragment (po_live): app.js
// puts it under the line (data-uid) or the head box (data-field) it is about. Its words are the server's (LiveSaveWords::refusal: "Fazil just
// changed this line (14:02). Fazil's: 8 × box of 10. Yours: 6 × box of 10."), names escaped here as all text; its choices are buttons
// (data-live-choice: theirs / mine, put_back / leave, more / leave, remove / keep; data-live-safe: the one Esc takes, never losing anything
// of theirs) in a role="alertdialog" the cursor moves into. A refusal without a choice (a figure that is not one, a draft confirmed meanwhile:
// data-status, data-number, data-by) is role="alert". data-theirs: the head box's value now ("Keep theirs" puts it back).
?>
<div class="live-choice" data-live-part="conflict" data-code="<?= $e($r['code']) ?>"<?php if ($r['uid'] !== null): ?> data-uid="<?= $e($r['uid']) ?>"<?php endif; ?><?php if ($r['rev'] !== null): ?> data-rev="<?= $e($r['rev']) ?>"<?php endif; ?><?php if ($r['field'] !== null): ?> data-field="<?= $e($r['field']) ?>"<?php endif; ?><?php if ($r['at'] !== ''): ?> data-at="<?= $e($r['at']) ?>"<?php endif; ?><?php if ($r['theirs'] !== null): ?> data-theirs="<?= $e($r['theirs']) ?>"<?php endif; ?><?php if ($r['status'] !== null): ?> data-status="<?= $e($r['status']) ?>"<?php endif; ?><?php if ($r['number'] !== null): ?> data-number="<?= $e($r['number']) ?>"<?php endif; ?><?php if ($r['by'] !== null): ?> data-by="<?= $e($r['by']) ?>"<?php endif; ?> role="<?php if ($r['choices'] !== []): ?>alertdialog<?php else: ?>alert<?php endif; ?>" aria-label="<?= $e($r['words'][0] ?? '') ?>">
<?php foreach ($r['words'] as $w): ?>
  <p><?= $e($w) ?></p>
<?php endforeach; ?>
<?php if ($r['choices'] !== []): ?>
  <p class="live-choice-acts">
<?php foreach ($r['choices'] as [$choice, $label, $safe]): ?>
    <button type="button" class="btn<?php if (!$safe): ?> primary<?php endif; ?>" data-live-choice="<?= $e($choice) ?>"<?php if ($safe): ?> data-live-safe<?php endif; ?>><?= $e($label) ?></button>
<?php endforeach; ?>
  </p>
<?php endif; ?>
</div>
