<?php
// A stock record's actions (docs/decisions.md W8; the approved record page PO_View): in the head, at its right. The one main action is the
// transfer note or the release invoice (PDF) of a final record; the others under More: the PDF of a record not final, take it back while
// it waits for an OK, cancel a final record, stop a draft. Each is its own form, posted where it was before; a step that needs a reason
// opens under the bar, and a refused one opens More again with what was typed. Nothing is drawn when the person has nothing to do here.
$main = $pdf !== null && $doc->status === 'posted';
$hasMore = ($pdf !== null && !$main) || $canWithdraw || $reverse !== null || $editable;
?>
<?php if ($main || $hasMore): ?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if ($hasMore): ?>
  <details class="more"<?php if ($typedCancel['code'] !== '' || $typedStop !== ''): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php if ($pdf !== null && !$main): ?>
      <a class="menu-item" href="<?= $u($pdf) ?>"><?= $e($k['pdf']) ?></a>
<?php endif; ?>
<?php if ($canWithdraw): ?>
      <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/withdraw') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button class="btn" type="submit"><?= $word('STOCK_OPS', 'withdraw_button') ?></button>
      </form>
<?php endif; ?>
<?php if ($reverse !== null): ?>
      <details class="doc-act"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
        <summary class="btn risky"><?= $word('STOCK_OPS', 'cancel_title') ?></summary>
        <div class="doc-act-panel">
          <p class="muted"><?= $word('STOCK_OPS', 'cancel_text') ?></p>
          <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/reverse') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <label><?= $word('STOCK_OPS', 'cancel_why') ?>
              <select name="reason_code" required>
                <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reverse['reasons'] as $r): ?>
                <option value="<?= $e($r['code']) ?>"<?php if ($typedCancel['code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
              </select>
            </label>
            <label><?= $word('STOCK_OPS', 'cancel_note') ?> <input type="text" name="note" value="<?= $e($typedCancel['note']) ?>" maxlength="1000"></label>
            <p class="actions"><button type="submit" class="btn risky"><?= $word('STOCK_OPS', 'cancel_button') ?></button></p>
          </form>
        </div>
      </details>
<?php endif; ?>
<?php if ($editable): ?>
      <details class="doc-act"<?php if ($typedStop !== ''): ?> open<?php endif; ?>>
        <summary class="btn risky"><?= $word('STOCK_OPS', 'stop_title') ?></summary>
        <div class="doc-act-panel">
          <p class="muted"><?= $word('STOCK_OPS', 'stop_text') ?></p>
          <form class="record" method="post" data-leaves="1" action="<?= $u($base . '/' . $doc->id . '/cancel') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($version) ?>">
            <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
            <p class="actions"><button type="submit" class="btn risky"><?= $word('STOCK_OPS', 'stop_button') ?></button></p>
          </form>
        </div>
      </details>
<?php endif; ?>
    </div>
  </details>
<?php endif; ?>
<?php if ($main): ?>
  <a class="btn primary" href="<?= $u($pdf) ?>"><?= $e($k['pdf']) ?></a>
<?php endif; ?>
</div>
<?php endif; ?>
