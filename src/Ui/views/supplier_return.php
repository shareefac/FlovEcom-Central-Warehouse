<p class="crumbs"><a href="<?= $e($base) ?>"><?= $word('MENU', 'returns') ?></a></p>
<?= $head((string) $title, intro: $doc->status === 'posted' ? 'supplier_return_final' : ($claimOnly ? 'supplier_return_claim' : 'supplier_return'), look: $lookOnly, chips: ($doc->isReversal() ? $chip('off', \CW\Ui\Words::INVOICES['reversal']) : $stateChip('DOC_STATUS', $doc->status)) . ($rec['credit_state'] !== null && !$doc->isReversal() ? ' ' . $stateChip('CREDIT_STATE', $rec['credit_state']) : '')) ?>
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

<section aria-labelledby="facts-h">
  <h2 id="facts-h" class="visually-hidden"><?= $word('STOCK_OPS', 'facts') ?></h2>
  <dl class="wide">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact['label']) ?></dt><dd><?= $e($fact['value']) ?></dd>
<?php endforeach; ?>
<?php if ($doc->isReversal()): ?>
    <dt><?= $word('RECORD', 'cancels') ?></dt><dd><a href="<?= $u($base . '/' . $doc->reversesId) ?>"><?= $e($rec['reverses_number']) ?></a></dd>
<?php endif; ?>
<?php if ($reversedBy !== null): ?>
    <dt><?= $word('STOCK_OPS', 'cancelled_by') ?></dt><dd><a href="<?= $u($base . '/' . $reversedBy['id']) ?>"><?php if ($reversedBy['number'] === null): ?><?= $word('STOCK_OPS', 'cancel_waiting') ?><?php else: ?><?= $e($reversedBy['number']) ?><?php endif; ?></a></dd>
<?php endif; ?>
  </dl>
  <p class="muted"><?= $word('RETURNS', 'is_debit_note') ?></p>
</section>

<?php if ($problems !== []): ?>
<section class="card" aria-labelledby="problems-h">
  <h2 id="problems-h"><?= $word('INVOICES', 'problems') ?></h2>
  <ul class="problems">
<?php foreach ($problems as $p): ?>
    <li><?= $e($p) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('RETURNS', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('RETURNS', 'no_lines') ?></p>
<?php elseif ($editable): ?>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/lines') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="line_count" value="<?= $e(count($lines)) ?>">
    <div class="table-wrap">
    <table class="stack lines">
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
          <th scope="row" class="c-head"><input type="text" name="d_<?= $e($l['line_no']) ?>" value="<?= $e($l['description'] ?? '') ?>" maxlength="255" aria-label="<?= $word('INVOICES', 'description') ?>"></th>
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
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'save_lines') ?></button> <span class="muted"><?= $say('RETURNS', 'claim_total', \CW\Ui\Html::money(\CW\Invoices\InvoiceMath::fromE2($total))) ?></span></p>
  </form>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack lines">
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
      <tr class="total">
        <th scope="row" class="c-head"><?= $word('STOCK_OPS', 'total') ?></th>
        <td data-label=""></td>
        <td data-label=""></td>
        <td data-label=""></td>
        <td data-label=""></td>
        <td class="num" data-label="<?= $word('INVOICES', 'amount') ?>"><?= $money(\CW\Invoices\InvoiceMath::fromE2($total)) ?></td>
        <td data-label=""></td>
      </tr>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<?php if ($editable): ?>
<section class="card" aria-labelledby="add-h">
  <h2 id="add-h"><?= $word('INVOICES', 'add_title') ?></h2>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-item') ?>">
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
    <button type="submit"><?= $word('STOCK_OPS', 'add_button') ?></button>
  </form>
  <p class="hint"><?= $word('RETURNS', 'price_hint') ?></p>
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
            <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-item') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="version" value="<?= $e($version) ?>">
              <input type="hidden" name="sku_id" value="<?= $e($c['sku_id']) ?>">
              <input type="hidden" name="qty" value="<?= $e($typedAdd['qty']) ?>">
              <input type="hidden" name="warehouse_id" value="<?= $e($typedAdd['warehouse']) ?>">
              <input type="hidden" name="price" value="<?= $e($typedAdd['price']) ?>">
              <button type="submit"><?= $word('STOCK_OPS', 'choose_this') ?></button>
            </form>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-claim') ?>">
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
    <button type="submit"><?= $word('RETURNS', 'claim_button') ?></button>
  </form>
</section>

<details class="fold" open>
  <summary><?= $word('RETURNS', 'details') ?></summary>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/details') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
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
    <label><?= $word('STOCK_OPS', 'note') ?> <input type="text" name="note" value="<?= $e($form['note']) ?>" maxlength="1000"></label>
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'save_details') ?></button></p>
  </form>
</details>
<section class="card final-box" aria-labelledby="final-h">
  <h2 id="final-h"><?= $word('RETURNS', 'post_title') ?></h2>
  <p><?= $word('RETURNS', $claimOnly ? 'post_does_claim' : 'post_does') ?></p>
<?php if ($ruleWaits): ?>
  <p class="muted"><?= $word('STOCK_OPS', 'final_waits') ?></p>
<?php endif; ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <button type="submit" class="primary"><?= $word('RETURNS', 'post_button') ?></button>
  </form>
</section>
<details class="fold">
  <summary><?= $word('STOCK_OPS', 'stop_title') ?></summary>
  <p class="muted"><?= $word('STOCK_OPS', 'stop_text') ?></p>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('STOCK_OPS', 'stop_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($canWithdraw): ?>
<section aria-labelledby="withdraw-h">
  <h2 id="withdraw-h"><?= $word('STOCK_OPS', 'withdraw_title') ?></h2>
  <p class="muted"><?= $word('STOCK_OPS', 'withdraw_text') ?></p>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit"><?= $word('STOCK_OPS', 'withdraw_button') ?></button>
  </form>
</section>
<?php endif; ?>

<?php if (!$doc->isReversal() && $doc->status === 'posted' || $creditNotes !== []): ?>
<section aria-labelledby="credits-h">
  <h2 id="credits-h"><?= $word('INVOICES', 'credit_notes') ?></h2>
<?php if ($creditNotes === []): ?>
  <p class="muted"><?= $word('RETURNS', 'no_credit_notes') ?></p>
<?php else: ?>
  <ul>
<?php foreach ($creditNotes as $c): ?>
    <li><a href="<?= $u('/ui/purchasing/credit-notes/' . $c['id']) ?>"><?= $e($c['number'] ?? \CW\Ui\Words::STOCK_OPS['no_number']) ?></a> <?= $stateChip('DOC_STATUS', $c['status']) ?><?php if ($c['net_total'] !== null): ?> <?= $money($c['net_total']) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($canCredit): ?>
  <form class="inline" method="post" action="/ui/purchasing/credit-notes/from-return">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($creditKey) ?>">
    <input type="hidden" name="dn_id" value="<?= $e($doc->id) ?>">
    <button type="submit"><?= $word('RETURNS', 'book_credit') ?></button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($reverse !== null): ?>
<details class="fold"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('STOCK_OPS', 'cancel_title') ?></summary>
  <p class="muted"><?= $word('RETURNS', 'cancel_text') ?></p>
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
    <p class="actions"><button type="submit" class="danger"><?= $word('STOCK_OPS', 'cancel_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<section aria-labelledby="files-h" id="files">
  <h2 id="files-h"><?= $word('STOCK_OPS', 'files') ?></h2>
<?php if ($files === []): ?>
  <p class="muted"><?= $word('STOCK_OPS', 'no_files') ?></p>
<?php else: ?>
  <ul class="files">
<?php foreach ($files as $file): ?>
    <li><a href="<?= $u('/ui/files/' . $file['id']) ?>"><?= $e($file['original_name']) ?></a> <span class="muted"><?= $e($file['what']) ?> · <?= $e($file['kind']) ?> · <?= $e($file['size']) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($canFile): ?>
  <form class="record" method="post" enctype="multipart/form-data" action="<?= $u($base . '/' . $doc->id . '/files') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('STOCK_OPS', 'file_role') ?>
      <select name="role">
<?php foreach ($fileRoles as $code => $label): ?>
        <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('STOCK_OPS', 'file') ?> <span class="hint"><?= $say('STOCK_OPS', 'file_hint', $maxMb) ?></span><input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'file_button') ?></button></p>
  </form>
<?php endif; ?>
</section>

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
