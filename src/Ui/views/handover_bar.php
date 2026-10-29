<?php
// A hand-over's actions (docs/decisions.md W8; the approved record page PO_View): in the head, at its right. The one main action is the
// invoice (PDF) of a final hand-over, once it prints (the company details confirmed, PH8); the others under More: the invoice of a draft,
// copy it into a new draft, cancel a final one, stop a draft. Each is its own form, posted where it was before; a step that needs a reason
// opens under the bar, and a refused one opens More again with what was typed. Nothing is drawn when the person has nothing to do here.
$main = $pdf !== null && $pdfBlocked === null && $doc->status === 'posted';
$hasMore = ($pdf !== null && $pdfBlocked === null && !$main) || $canCopy || $reverse !== null || $editable;
?>
<?php if ($main || $hasMore): ?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if ($hasMore): ?>
  <details class="more"<?php if ($typedCancel['code'] !== '' || $typedStop !== ''): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php if ($pdf !== null && $pdfBlocked === null && !$main): ?>
      <a class="menu-item" href="<?= $u($pdf) ?>"><?= $word('HANDOVER', 'pdf') ?></a>
<?php endif; ?>
<?php if ($canCopy): ?>
      <form class="inline" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/copy') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button class="btn" type="submit"><?= $word('HANDOVER', 'copy_button') ?></button>
      </form>
<?php endif; ?>
<?php if ($reverse !== null): ?>
      <details class="doc-act"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
        <summary class="btn risky"><?= $word('HANDOVER', 'cancel_title') ?></summary>
        <div class="doc-act-panel">
          <p class="muted"><?= $word('HANDOVER', 'cancel_text') ?></p>
          <form class="record" method="post" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/reverse') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <label><?= $word('HANDOVER', 'cancel_why') ?>
              <select name="reason_code" required>
                <option value=""><?= $word('HANDOVER', 'choose') ?></option>
<?php foreach ($reverse['reasons'] as $r): ?>
                <option value="<?= $e($r['code']) ?>"<?php if ($typedCancel['code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
              </select>
            </label>
            <label><?= $word('HANDOVER', 'cancel_note') ?> <input type="text" name="note" value="<?= $e($typedCancel['note']) ?>" maxlength="1000"></label>
            <p class="actions"><button type="submit" class="btn risky"><?= $word('HANDOVER', 'cancel_button') ?></button></p>
          </form>
        </div>
      </details>
<?php endif; ?>
<?php if ($editable): ?>
      <details class="doc-act"<?php if ($typedStop !== ''): ?> open<?php endif; ?>>
        <summary class="btn risky"><?= $word('HANDOVER', 'stop_title') ?></summary>
        <div class="doc-act-panel">
          <p class="muted"><?= $word('HANDOVER', 'stop_text') ?></p>
          <form class="record" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/cancel') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($version) ?>">
            <label><?= $word('HANDOVER', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
            <p class="actions"><button type="submit" class="btn risky"><?= $word('HANDOVER', 'stop_button') ?></button></p>
          </form>
        </div>
      </details>
<?php endif; ?>
    </div>
  </details>
<?php endif; ?>
<?php if ($main): ?>
  <a class="btn primary" href="<?= $u($pdf) ?>"><?= $word('HANDOVER', 'pdf') ?></a>
<?php endif; ?>
</div>
<?php endif; ?>
