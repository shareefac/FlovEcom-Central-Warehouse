<?php
// A return's action bar (docs/decisions.md W9.6; the approved record page, as po_bar): in the page's head at its right. The next step in the
// accent: "Book the supplier's credit note" on a final return; a draft's main step ("Make the return final") is in the bar at the bottom of
// its page. The rest under More: stop a draft, take back a request for an OK, cancel a final return (a fold with its reason under the bar).
// The forms, their fields and their routes are as before.
$more = $editable || $canWithdraw || $reverse !== null;
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if ($more): ?>
  <details class="more"<?php if ($typedCancel['code'] !== '' || $typedStop !== ''): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php if ($canWithdraw): ?>
      <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/withdraw') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button class="btn" type="submit"><?= $word('STOCK_OPS', 'withdraw_button') ?></button>
      </form>
<?php endif; ?>
<?php if ($editable): ?>
      <details class="doc-act"<?php if ($typedStop !== ''): ?> open<?php endif; ?>>
        <summary class="btn risky"><?= $word('STOCK_OPS', 'stop_title') ?></summary>
        <div class="doc-act-panel">
          <p class="muted"><?= $word('STOCK_OPS', 'stop_text') ?></p>
          <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/cancel') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($version) ?>">
            <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
            <p class="actions"><button type="submit" class="btn risky"><?= $word('STOCK_OPS', 'stop_button') ?></button></p>
          </form>
        </div>
      </details>
<?php endif; ?>
<?php if ($reverse !== null): ?>
      <details class="doc-act"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
        <summary class="btn risky"><?= $word('STOCK_OPS', 'cancel_title') ?></summary>
        <div class="doc-act-panel">
          <p class="muted"><?= $word('RETURNS', 'cancel_text') ?></p>
          <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/reverse') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
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
    </div>
  </details>
<?php endif; ?>
<?php if ($canCredit): ?>
  <form class="inline" method="post" action="/ui/purchasing/credit-notes/from-return" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($creditKey) ?>">
    <input type="hidden" name="dn_id" value="<?= $e($doc->id) ?>">
    <button class="btn primary" type="submit"><?= $word('RETURNS', 'book_credit') ?></button>
  </form>
<?php endif; ?>
</div>
