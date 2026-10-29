<?php
// One action of a saved supplier invoice or credit note (docs/decisions.md PV19, the approved design): in the document's action bar as its
// next step ($primary: the accent; a step that needs a reason opens under the bar), or as one of the others under More. $a: accept (the
// differences), credit (book its credit note), withdraw, file (attach a file: the files under the lines), reverse (cancel); the forms,
// their fields and their routes are pack C's.
?>
<?php if ($a === 'accept'): ?>
  <details class="doc-act"<?php if ($typedAccept !== ''): ?> open<?php endif; ?>>
    <summary class="btn<?php if ($primary): ?> primary<?php endif; ?>"><?= $word('INVOICES', 'accept_title') ?></summary>
    <div class="doc-act-panel">
      <p class="muted"><?= $word('INVOICES', 'accept_text') ?></p>
      <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/accept') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="match_fingerprint" value="<?= $e($match['fingerprint']) ?>">
        <label><?= $word('INVOICES', 'accept_why') ?> <input type="text" name="note" value="<?= $e($typedAccept) ?>" minlength="3" maxlength="500" required></label>
        <p class="actions"><button type="submit" class="btn primary"><?= $word('INVOICES', 'accept_button') ?></button></p>
      </form>
    </div>
  </details>
<?php endif; ?>
<?php if ($a === 'credit'): ?>
  <form class="inline" method="post" action="/ui/purchasing/credit-notes">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($creditKey) ?>">
    <input type="hidden" name="supplier_id" value="<?= $e($rec['supplier_id']) ?>">
    <input type="hidden" name="credits_id" value="<?= $e($doc->id) ?>">
    <button type="submit" class="btn<?php if ($primary): ?> primary<?php endif; ?>"><?= $word('INVOICES', 'book_credit') ?></button>
  </form>
<?php endif; ?>
<?php if ($a === 'withdraw'): ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn<?php if ($primary): ?> primary<?php endif; ?>"><?= $word('STOCK_OPS', 'withdraw_button') ?></button>
  </form>
<?php endif; ?>
<?php if ($a === 'file'): ?>
  <a class="btn<?php if ($primary): ?> primary<?php endif; ?>" href="#files"><?= $word('STOCK_OPS', 'file_button') ?></a>
<?php endif; ?>
<?php if ($a === 'reverse'): ?>
  <details class="doc-act"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
    <summary class="btn risky"><?= $word('STOCK_OPS', 'cancel_title') ?></summary>
    <div class="doc-act-panel">
      <p class="muted"><?= $e($k['cancel_text']) ?></p>
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
