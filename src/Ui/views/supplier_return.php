<?php
// A return to a supplier (docs/decisions.md W9.6; the owner's approved designs of 29 Oct 2026). Its number with its status as a solid block
// and the action bar at the head's right (return_bar), the decide box when a check waits, the figures strip (units going back, lines, the
// claim, the credit). Its creator's draft is the one-page form (PI_New): the details in two columns, the lines board, the add row, the files
// on the left and the claim on the right, the bar at the bottom ("Leave without saving", "Save the lines", "Make the return final"). Any
// other return is the record page (PO_View): the paper card (the supplier's block beside the facts, the lines, the files and the claim),
// its credit notes, its history. Every form posts what and where it did.
?>
<p class="crumbs"><a href="<?= $e($base) ?>"><?= $word('MENU', 'returns') ?></a></p>
<?php $bar = $partial('return_bar', ['base' => $base, 'doc' => $doc, 'version' => $version, 'editable' => $editable, 'canWithdraw' => $canWithdraw, 'reverse' => $reverse,
    'canCredit' => $canCredit, 'creditKey' => $creditKey, 'typedCancel' => $typedCancel, 'typedStop' => $typedStop]); ?>
<div class="doc-head">
<?= $head((string) $title, intro: $introKey, look: $lookOnly, side: $bar, tag: $claimOnly ? \CW\Ui\Words::RETURNS['claim_tag'] : '', meta: ($doc->isReversal() ? $chip('off', \CW\Ui\Words::INVOICES['reversal']) : $stateChip('DOC_STATUS', $doc->status)) . ($rec['credit_state'] !== null && !$doc->isReversal() ? ' <span class="tag ' . $e(\CW\Ui\Words::tone('CREDIT_STATE', (string) $rec['credit_state'])) . '">' . $word('CREDIT_STATE', (string) $rec['credit_state']) . '</span>' : '')) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($decide !== null): ?>
<?= $partial('decide_box', ['decide' => $decide]) ?>
<?php elseif ($waiting): ?>
<p class="muted"><?= $word('RECORD', 'waiting') ?></p>
<?php endif; ?>
<?php if ($notCreator): ?>
<p class="note read-only"><?= $word('STOCK_OPS', 'not_creator') ?></p>
<?php endif; ?>
<?php if ($problems !== []): ?>
<ul class="warnings problems">
<?php foreach ($problems as $p): ?>
  <li><?= $e($p) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('RETURNS', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('RETURNS', 'fig_units') ?></p><p class="doc-step-big"><?= $n($figures['units']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('RETURNS', 'fig_lines') ?></p><p class="doc-step-big"><?= $n($figures['lines']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('RETURNS', 'fig_claim') ?></p><p class="doc-step-big"><?= $e($figures['claim']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('RETURNS', 'fig_credit') ?></p><p class="doc-step-big"><?= $e($figures['credit']) ?></p></div>
</section>

<?php if ($editable): ?>
<form class="doc-details" method="post" action="<?= $u($base . '/' . $doc->id . '/details') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <fieldset class="doc-grid">
    <legend><?= $word('RETURNS', 'details') ?></legend>
    <label><?= $word('RETURNS', 'from') ?>
      <select name="warehouse_id">
<?php foreach ($warehouses as $w): ?>
        <option value="<?= $e($w['id']) ?>"<?php if ($form['warehouse_id'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RETURNS', 'reason') ?>
      <select name="reason_code">
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"<?php if ($form['reason_code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RETURNS', 'ref') ?> <input type="text" name="return_ref" value="<?= $e($form['return_ref']) ?>" maxlength="100"></label>
    <label><?= $word('RETURNS', 'date') ?> <input type="date" name="return_date" value="<?= $e($form['return_date']) ?>"></label>
    <label><?= $word('RETURNS', 'delivery') ?>
      <select name="grn_id">
        <option value=""><?= $word('RETURNS', 'no_delivery') ?></option>
<?php foreach ($deliveryChoices as $d): ?>
        <option value="<?= $e($d['id']) ?>"<?php if ($form['grn_id'] === (string) $d['id']): ?> selected<?php endif; ?>><?= $e($d['number']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RETURNS', 'invoice') ?>
      <select name="invoice_id">
        <option value=""><?= $word('RETURNS', 'no_invoice') ?></option>
<?php foreach ($invoiceChoices as $ic): ?>
        <option value="<?= $e($ic['id']) ?>"<?php if ($form['invoice_id'] === (string) $ic['id']): ?> selected<?php endif; ?>><?= $e($ic['number']) ?><?php if ($ic['external_ref'] !== null): ?> (<?= $e($ic['external_ref']) ?>)<?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label class="doc-wide"><?= $word('STOCK_OPS', 'note') ?> <input type="text" name="note" value="<?= $e($form['note']) ?>" maxlength="1000"></label>
    <p class="actions doc-wide"><button class="btn" type="submit"><?= $word('STOCK_OPS', 'save_details') ?></button></p>
  </fieldset>
</form>

<section class="doc-view" aria-labelledby="lines-h">
  <h2 id="lines-h" class="doc-label"><?= $word('RETURNS', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('RETURNS', 'no_lines') ?></p>
<?php else: ?>
  <form id="ret-lines" method="post" action="<?= $u($base . '/' . $doc->id . '/lines') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="line_count" value="<?= $e(count($lines)) ?>">
    <div class="table-wrap">
    <table class="stack list board doc-lines lines">
      <thead>
        <tr>
          <th scope="col"><?= $word('INVOICES', 'line') ?></th>
          <th scope="col" class="num"><?= $word('STOCK_OPS', 'units') ?></th>
          <th scope="col"><?= $word('RETURNS', 'from') ?></th>
          <th scope="col" class="num"><?= $word('RETURNS', 'price') ?></th>
          <th scope="col" class="num"><?= $word('RETURNS', 'duty') ?></th>
          <th scope="col" class="num"><?= $word('INVOICES', 'amount') ?></th>
          <th scope="col"><?= $word('RETURNS', 'reason') ?></th>
          <th scope="col"><?= $word('STOCK_OPS', 'line_remove') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $l): ?>
        <tr>
<?php if ($l['sku_id'] !== null): ?>
          <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name'] ?? '') ?></a><span class="o-sub"><?= $e($l['code'] ?? '') ?><?php if ($l['grn_number'] !== null): ?> · <?= $say('INVOICES', 'for_delivery', (string) $l['grn_number'], (int) $l['grn_line_no']) ?><?php endif; ?></span></th>
          <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"><input type="text" name="u_<?= $e($l['line_no']) ?>" value="<?= $e($l['qty']) ?>" inputmode="numeric" maxlength="8" aria-label="<?= $word('STOCK_OPS', 'units') ?>"></td>
          <td data-label="<?= $word('RETURNS', 'from') ?>">
            <select name="w_<?= $e($l['line_no']) ?>" aria-label="<?= $word('RETURNS', 'from') ?>">
<?php foreach ($warehouses as $w): ?>
              <option value="<?= $e($w['id']) ?>"<?php if ($l['warehouse_id'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
            </select>
          </td>
          <td class="num" data-label="<?= $word('RETURNS', 'price') ?>"><input type="text" name="c_<?= $e($l['line_no']) ?>" value="<?= $dec($l['unit_cost']) ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('RETURNS', 'price') ?>"></td>
          <td class="num" data-label="<?= $word('RETURNS', 'duty') ?>"><input type="text" name="t_<?= $e($l['line_no']) ?>" value="<?= $dec($l['duty_amount']) ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('RETURNS', 'duty') ?>"></td>
<?php else: ?>
          <th scope="row" class="c-head"><input type="text" class="line-what" name="d_<?= $e($l['line_no']) ?>" value="<?= $e($l['description'] ?? '') ?>" maxlength="255" aria-label="<?= $word('INVOICES', 'description') ?>"></th>
          <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"></td>
          <td data-label="<?= $word('RETURNS', 'from') ?>"></td>
          <td class="num" data-label="<?= $word('RETURNS', 'price') ?>"></td>
          <td class="num" data-label="<?= $word('RETURNS', 'duty') ?>"></td>
<?php endif; ?>
          <td class="num" data-label="<?= $word('INVOICES', 'amount') ?>"><?php if ($l['sku_id'] === null): ?><input type="text" name="a_<?= $e($l['line_no']) ?>" value="<?= $dec($l['amount']) ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('INVOICES', 'amount') ?>"><?php else: ?><?= $money($l['amount']) ?><?php endif; ?></td>
          <td data-label="<?= $word('RETURNS', 'reason') ?>">
            <select name="r_<?= $e($l['line_no']) ?>" aria-label="<?= $word('RETURNS', 'reason') ?>">
              <option value=""><?= $word('RETURNS', 'reason_of_return') ?></option>
<?php foreach ($reasons as $r): ?>
              <option value="<?= $e($r['code']) ?>"<?php if ($l['reason_code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
            </select>
          </td>
          <td data-label="<?= $word('STOCK_OPS', 'line_remove') ?>"><label class="choice"><input type="checkbox" name="x_<?= $e($l['line_no']) ?>" value="1"> <?= $word('STOCK_OPS', 'line_remove') ?></label></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </form>
<?php endif; ?>
  <section aria-labelledby="add-h">
    <h3 id="add-h" class="doc-label"><?= $word('INVOICES', 'add_title') ?></h3>
    <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-item') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($version) ?>">
      <label><?= $word('STOCK_OPS', 'add_q') ?> <input type="search" name="q" value="<?= $e($typedAdd['q']) ?>" maxlength="100" autocomplete="off"></label>
      <label><?= $word('STOCK_OPS', 'units') ?> <input type="text" name="qty" value="<?= $e($typedAdd['qty']) ?>" inputmode="numeric" maxlength="8" required></label>
      <label><?= $word('RETURNS', 'from') ?>
        <select name="warehouse_id">
          <option value=""><?= $word('RETURNS', 'return_warehouse') ?></option>
<?php foreach ($warehouses as $w): ?>
          <option value="<?= $e($w['id']) ?>"<?php if ($typedAdd['warehouse'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('RETURNS', 'price') ?> <input type="text" name="price" value="<?= $e($typedAdd['price']) ?>" inputmode="decimal" maxlength="16"></label>
      <button class="btn" type="submit"><?= $word('STOCK_OPS', 'add_button') ?></button>
    </form>
<?php if ($choices !== null): ?>
    <div class="card choices">
      <p><?= $say('STOCK_OPS', 'choices', (string) $typedAdd['q']) ?></p>
      <div class="table-wrap">
      <table class="stack list">
        <tbody>
<?php foreach ($choices as $c): ?>
          <tr>
            <th scope="row" class="c-head"><?= $e($c['name']) ?><span class="o-sub"><?= $e($c['code']) ?></span></th>
            <td class="c-next">
              <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-item') ?>" data-leaves="1">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="version" value="<?= $e($version) ?>">
                <input type="hidden" name="sku_id" value="<?= $e($c['sku_id']) ?>">
                <input type="hidden" name="qty" value="<?= $e($typedAdd['qty']) ?>">
                <input type="hidden" name="warehouse_id" value="<?= $e($typedAdd['warehouse']) ?>">
                <input type="hidden" name="price" value="<?= $e($typedAdd['price']) ?>">
                <button class="btn" type="submit"><?= $word('STOCK_OPS', 'choose_this') ?></button>
              </form>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
<?php endif; ?>
    <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-claim') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($version) ?>">
      <label><?= $word('RETURNS', 'claim_amount') ?> <input type="text" name="amount" value="<?= $e($typedClaim['amount']) ?>" inputmode="decimal" maxlength="16" required></label>
      <label><?= $word('RETURNS', 'claim_for') ?> <input type="text" name="description" value="<?= $e($typedClaim['description']) ?>" maxlength="255" required></label>
      <label><?= $word('RETURNS', 'reason') ?>
        <select name="reason_code">
          <option value=""><?= $word('RETURNS', 'reason_of_return') ?></option>
<?php foreach ($reasons as $r): ?>
          <option value="<?= $e($r['code']) ?>"<?php if ($typedClaim['reason'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit"><?= $word('RETURNS', 'claim_button') ?></button>
    </form>
  </section>
  <div class="doc-foot">
    <div class="doc-notes" id="files">
<?= $partial('return_files', ['base' => $base, 'doc' => $doc, 'files' => $files, 'canFile' => $canFile, 'fileRoles' => $fileRoles, 'maxMb' => $maxMb]) ?>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => [['label' => \CW\Ui\Words::RETURNS['claim'], 'value' => \CW\Ui\Html::money(\CW\Invoices\InvoiceMath::fromE2($total)), 'grand' => true]]]) ?>
  </div>
</section>
<form id="ret-post" method="post" action="<?= $u($base . '/' . $doc->id . '/post') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
</form>
<div class="doc-bar">
  <a class="btn ghost" href="<?= $e($base) ?>" data-leave><?= $word('DOC', 'leave') ?></a>
  <span class="doc-bar-note"><?= $word('RETURNS', $claimOnly ? 'post_does_claim' : 'post_does') ?><?php if ($ruleWaits): ?> <?= $word('STOCK_OPS', 'final_waits') ?><?php endif; ?></span>
<?php if ($lines !== []): ?>
  <button class="btn" type="submit" form="ret-lines"><?= $word('STOCK_OPS', 'save_lines') ?></button>
<?php endif; ?>
  <button type="submit" class="btn primary" form="ret-post"><?= $word('RETURNS', 'post_button') ?></button>
</div>
<?php else: ?>
<article class="doc-view" aria-labelledby="lines-h">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
  <h2 id="lines-h" class="doc-label"><?= $word('RETURNS', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('RETURNS', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list board doc-lines lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('INVOICES', 'line') ?></th>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'units') ?></th>
        <th scope="col"><?= $word('RETURNS', 'from') ?></th>
        <th scope="col" class="num"><?= $word('RETURNS', 'price') ?></th>
        <th scope="col" class="num"><?= $word('RETURNS', 'duty') ?></th>
        <th scope="col" class="num"><?= $word('INVOICES', 'amount') ?></th>
        <th scope="col"><?= $word('RETURNS', 'reason') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <th scope="row" class="c-head"><?php if ($l['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name'] ?? '') ?></a><span class="o-sub"><?= $e($l['code'] ?? '') ?><?php if ($l['grn_number'] !== null): ?> · <?= $say('INVOICES', 'for_delivery', (string) $l['grn_number'], (int) $l['grn_line_no']) ?><?php endif; ?></span><?php else: ?><?= $e($l['description'] ?? '') ?><?php endif; ?></th>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"><?php if ($l['qty'] !== null): ?><?= $n($l['qty']) ?><?php endif; ?></td>
        <td data-label="<?= $word('RETURNS', 'from') ?>"><?= $e($l['warehouse_name'] ?? '') ?></td>
        <td class="num" data-label="<?= $word('RETURNS', 'price') ?>"><?php if ($l['unit_cost'] !== null): ?><?= $money($l['unit_cost']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('RETURNS', 'duty') ?>"><?php if ($l['duty_amount'] !== null && (float) $l['duty_amount'] !== 0.0): ?><?= $money($l['duty_amount']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('INVOICES', 'amount') ?>"><?= $money($l['amount']) ?></td>
        <td data-label="<?= $word('RETURNS', 'reason') ?>"><?= $e($l['reason_label'] ?? '') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <div class="doc-foot">
    <div class="doc-notes" id="files">
      <p class="muted"><?= $word('RETURNS', 'is_debit_note') ?></p>
<?= $partial('return_files', ['base' => $base, 'doc' => $doc, 'files' => $files, 'canFile' => $canFile, 'fileRoles' => $fileRoles, 'maxMb' => $maxMb]) ?>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => [['label' => \CW\Ui\Words::RETURNS['claim'], 'value' => \CW\Ui\Html::money(\CW\Invoices\InvoiceMath::fromE2($total)), 'grand' => true]]]) ?>
  </div>
</article>
<?php endif; ?>

<?php if (!$doc->isReversal() && $doc->status === 'posted' || $creditNotes !== []): ?>
<section class="doc-related" aria-labelledby="credits-h">
  <div class="board-head"><h2 id="credits-h" class="board-sub"><?= $word('INVOICES', 'credit_notes') ?></h2></div>
<?php if ($creditNotes === []): ?>
  <p class="muted"><?= $word('RETURNS', 'no_credit_notes') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board done">
  <table class="stack list board doc-list">
    <thead>
      <tr><th scope="col" class="c-item"><?= $word('INVOICES', 'record') ?></th><th scope="col" class="c-status"><?= $word('STOCK_OPS', 'status') ?></th><th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th></tr>
    </thead>
    <tbody>
<?php foreach ($creditNotes as $c): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/purchasing/credit-notes/' . $c['id']) ?>"><?= $e($c['number'] ?? \CW\Ui\Words::STOCK_OPS['no_number']) ?></a></th>
        <td class="c-status doc-st <?= $e(\CW\Ui\Words::tone('DOC_STATUS', (string) $c['status'])) ?>"><?= $word('DOC_STATUS', (string) $c['status']) ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($c['net_total'] !== null): ?><?= $money($c['net_total']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($history !== []): ?>
<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('RECORD', 'history') ?></h2>
  <ol class="versions checks-history">
<?php foreach ($history as $h): ?>
    <li>
      <p><?= $e($h['asked']) ?> <span class="muted"><?= $e($h['reason']) ?></span></p>
      <p><?= $chip($h['tone'], $h['outcome']) ?><?php if ($h['note'] !== null): ?> <?= $e($h['note']) ?><?php endif; ?></p>
    </li>
<?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
