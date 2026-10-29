<?php
// A supplier's invoice or credit note (pack C, PC17), read like a document (docs/decisions.md PV12, PV19: the owner's approved design): its
// status a solid block beside the title ("Needs a look" when its match needs review, as the list says: PV31), one action bar at the head's
// right (si_bar, PV27: the next step in the accent: accept the differences, take it back, book its credit note or attach a file; the
// others under More; si_action), then what differs from the order and the delivery when its match is off (the
// three ways side by side, the cells that differ tinted), then the paper: the supplier's block, its facts (dates, what it is for, who did
// what), its balance large on the right, the lines, its files and its totals; then its goods on their way, its credit notes, what it records
// for the stock value, and its history. An invoice's draft is the
// one page for its creator (invoice_edit); a credit note's draft keeps its forms here (pack C), for its creator.
?>
<p class="crumbs"><a href="<?= $e($base) ?>"><?= $e($k['tab']) ?></a></p>
<?php
// The status block says what the list says (PV31): a final invoice whose match needs review "Needs a look" (amber), "Final" a quiet line
// beside it; else its status, and its match as a soft tag.
$needsLook = !$doc->isReversal() && $doc->status === 'posted' && $storedMatch === 'needs_review';
$bar = $editable ? '' : $partial('si_bar', ['moreActions' => $moreActions, 'mainAction' => $mainAction, 'doc' => $doc, 'base' => $base, 'rec' => $rec, 'k' => $k,
    'match' => $match, 'typedAccept' => $typedAccept, 'typedCancel' => $typedCancel, 'creditKey' => $creditKey, 'reverse' => $reverse]); ?>
<div class="doc-head">
<?= $head((string) $title, intro: $introKey, look: $lookOnly, side: $editable ? null : $bar,
    chips: $doc->isReversal() ? $chip('off', \CW\Ui\Words::INVOICES['reversal']) : ($needsLook ? $chip('needs', \CW\Ui\Words::INVOICES['needs_look']) : $stateChip('DOC_STATUS', $doc->status)),
    meta: $needsLook ? '<span class="muted">' . $e(\CW\Ui\Words::of('DOC_STATUS', 'posted')) . '</span>'
        : ($storedMatch !== null ? '<span class="tag band ' . $e(\CW\Ui\Words::tone('MATCH_STATE', $storedMatch)) . '">' . $e(\CW\Ui\Words::of('MATCH_STATE', $storedMatch)) . '</span>' : '')) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php if (count($problemsOfError) > 1): ?>
<ul class="problems">
<?php foreach ($problemsOfError as $p): ?>
  <li><?= $e($p) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>
<?php if ($decide !== null): ?>
<?= $partial('decide_box', ['decide' => $decide]) ?>
<?php elseif ($waiting): ?>
<p class="muted"><?= $word('RECORD', 'waiting') ?></p>
<?php endif; ?>
<?php if ($notCreator): ?>
<p class="note read-only"><?= $word('STOCK_OPS', 'not_creator') ?></p>
<?php endif; ?>

<?php if ($editable): ?>
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
<?php if ($kind === 'credit_note' && $rec['credits_document_id'] !== null): ?>
    <dt><?= $word('INVOICES', 'open_invoice') ?></dt><dd><a href="<?= $u('/ui/purchasing/invoices/' . $rec['credits_document_id']) ?>"><?= $e($rec['credits_number'] ?? '') ?></a></dd>
<?php endif; ?>
<?php if ($kind === 'credit_note' && $rec['debit_note_document_id'] !== null): ?>
    <dt><?= $word('INVOICES', 'open_return') ?></dt><dd><a href="<?= $u('/ui/purchasing/returns/' . $rec['debit_note_document_id']) ?>"><?= $e($rec['debit_note_number'] ?? '') ?></a></dd>
<?php endif; ?>
  </dl>
</section>

<?php if ($problems !== []): ?>
<section class="card" aria-labelledby="problems-h">
  <h2 id="problems-h"><?= $word('INVOICES', 'problems') ?></h2>
  <ul class="problems">
<?php foreach ($problems as $p): ?>
    <li><?= $e(\CW\Ui\Controller\InvoicesController::problemText($p)) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($match !== null): ?>
<section aria-labelledby="match-h" id="match">
  <h2 id="match-h"><?= $word('INVOICES', 'match_title') ?> <?= $chip($match['tone'], $match['word']) ?></h2>
  <p class="muted"><?php if ($match['posted']): ?><?= $word('INVOICES', 'match_text') ?><?php else: ?><?= $word('INVOICES', 'match_preview') ?><?php endif; ?></p>
<?php if ($match['off'] > 0): ?>
  <p class="note"><?= $say('INVOICES', 'match_off', $match['off'], $match['difference']) ?></p>
<?php endif; ?>
<?php if ($match['total_diff'] !== null): ?>
  <p class="muted"><?= $say('INVOICES', 'total_rounding', $match['total_diff']) ?></p>
<?php endif; ?>
<?php if ($rec['accepted_at'] !== null && $storedMatch === 'accepted'): ?>
  <p class="note"><?= $say('INVOICES', 'accepted_line', (string) ($rec['accepted_by_name'] ?? ''), \CW\Ui\Html::when($rec['accepted_at']), (string) $rec['accept_note']) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('INVOICES', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('INVOICES', 'no_lines') ?></p>
<?php else: ?>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/lines') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="line_count" value="<?= $e(count($lines)) ?>">
    <div class="table-wrap">
    <table class="stack lines">
      <thead>
        <tr>
          <th scope="col"><?= $word('INVOICES', 'line') ?></th>
          <th scope="col"><?= $word('INVOICES', 'line_kind') ?></th>
          <th scope="col" class="num"><?= $word('INVOICES', 'packs') ?></th>
          <th scope="col" class="num"><?= $word('INVOICES', 'pack_price') ?></th>
          <th scope="col" class="num"><?= $word('INVOICES', 'amount') ?></th>
          <th scope="col"><?= $word('INVOICES', 'for') ?></th>
          <th scope="col"><?= $word('STOCK_OPS', 'line_remove') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $l): ?>
        <tr>
<?php if ($l['kind'] === 'goods'): ?>
          <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name'] ?? '') ?></a><span class="o-sub"><?= $e($l['code'] ?? '') ?><?php if ($l['supplier_code'] !== null): ?> · <?= $e($l['supplier_code']) ?><?php endif; ?></span></th>
          <td data-label="<?= $word('INVOICES', 'line_kind') ?>"><?= $e($l['kind_word']) ?></td>
          <td class="num" data-label="<?= $word('INVOICES', 'packs') ?>"><input type="text" name="p_<?= $e($l['line_no']) ?>" value="<?= $e($l['packs']) ?>" inputmode="numeric" maxlength="7" aria-label="<?= $word('INVOICES', 'packs') ?>"><span class="o-sub"><?= $e($l['packs_text']) ?></span></td>
          <td class="num" data-label="<?= $word('INVOICES', 'pack_price') ?>"><input type="text" name="pp_<?= $e($l['line_no']) ?>" value="<?= $dec($l['pack_price']) ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('INVOICES', 'pack_price') ?>"></td>
          <td class="num" data-label="<?= $word('INVOICES', 'amount') ?>"><?= $money($l['amount']) ?></td>
<?php else: ?>
          <th scope="row" class="c-head"><input type="text" class="line-what" name="d_<?= $e($l['line_no']) ?>" value="<?= $e($l['description'] ?? '') ?>" maxlength="255" aria-label="<?= $word('INVOICES', 'description') ?>"></th>
          <td data-label="<?= $word('INVOICES', 'line_kind') ?>"><?= $e($l['kind_word']) ?></td>
          <td class="num" data-label="<?= $word('INVOICES', 'packs') ?>"></td>
          <td class="num" data-label="<?= $word('INVOICES', 'pack_price') ?>"></td>
          <td class="num" data-label="<?= $word('INVOICES', 'amount') ?>"><input type="text" name="a_<?= $e($l['line_no']) ?>" value="<?= $dec($l['amount']) ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('INVOICES', 'amount') ?>"></td>
<?php endif; ?>
          <td data-label="<?= $word('INVOICES', 'for') ?>"><?php if ($l['grn_number'] !== null): ?><?= $say('INVOICES', 'for_delivery', (string) $l['grn_number'], (int) $l['grn_line_no']) ?><?php endif; ?><?php if ($l['po_number'] !== null): ?><span class="o-sub"><?= $say('INVOICES', 'for_order', (string) $l['po_number'], (int) $l['po_line_no']) ?></span><?php endif; ?><?php if ($l['credit_of_line_no'] !== null): ?><span class="o-sub"><?= $say('INVOICES', 'credits_line', (int) $l['credit_of_line_no']) ?></span><?php endif; ?></td>
          <td data-label="<?= $word('STOCK_OPS', 'line_remove') ?>"><label class="choice"><input type="checkbox" name="x_<?= $e($l['line_no']) ?>" value="1"> <?= $word('STOCK_OPS', 'line_remove') ?></label></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'save_lines') ?></button> <span class="muted"><?= $say('INVOICES', 'lines_add_up', \CW\Ui\Html::money(\CW\Invoices\InvoiceMath::fromE2($sum))) ?></span></p>
  </form>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="add-h">
  <h2 id="add-h"><?= $word('INVOICES', 'add_title') ?></h2>
<?php if ($kind === 'invoice'): ?>
<?php if ($deliveries !== []): ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-delivery') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('INVOICES', 'add_delivery') ?>
      <select name="grn_id" required>
<?php foreach ($deliveries as $d): ?>
        <option value="<?= $e($d['id']) ?>"><?= $e($d['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= $word('INVOICES', 'add_delivery_button') ?></button>
  </form>
<?php else: ?>
  <p class="muted"><?= $word('INVOICES', 'no_deliveries') ?></p>
<?php endif; ?>
<?php if ($orders !== []): ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-order') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('INVOICES', 'add_order') ?>
      <select name="po_id" required>
<?php foreach ($orders as $o): ?>
        <option value="<?= $e($o['id']) ?>"><?= $e($o['number']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= $word('INVOICES', 'add_order_button') ?></button>
  </form>
  <p class="hint"><?= $word('INVOICES', 'add_order_hint') ?></p>
<?php endif; ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-item') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('STOCK_OPS', 'add_q') ?> <input type="search" name="q" value="<?= $e($typedAdd['q']) ?>" maxlength="100" autocomplete="off"></label>
    <label><?= $word('INVOICES', 'packs') ?> <input type="text" name="packs" value="<?= $e($typedAdd['packs']) ?>" inputmode="numeric" maxlength="7" required></label>
    <label><?= $word('INVOICES', 'pack_price') ?> <input type="text" name="price" value="<?= $e($typedAdd['price']) ?>" inputmode="decimal" maxlength="16"></label>
    <button type="submit"><?= $word('STOCK_OPS', 'add_button') ?></button>
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
            <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-item') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="version" value="<?= $e($version) ?>">
              <input type="hidden" name="sku_id" value="<?= $e($c['sku_id']) ?>">
              <input type="hidden" name="packs" value="<?= $e($typedAdd['packs']) ?>">
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
<?php else: ?>
<?php if ($creditLines !== []): ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-credit') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('INVOICES', 'credit_line') ?>
      <select name="line" required>
<?php foreach ($creditLines as $cl): ?>
        <option value="<?= $e($cl['line_no']) ?>"<?php if ($typedCredit['line'] === (string) $cl['line_no']): ?> selected<?php endif; ?>><?= $e($cl['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('INVOICES', 'units') ?> <input type="text" name="units" value="<?= $e($typedCredit['units']) ?>" inputmode="numeric" maxlength="8" required></label>
    <label><?= $word('INVOICES', 'unit_price') ?> <input type="text" name="price" value="<?= $e($typedCredit['price']) ?>" inputmode="decimal" maxlength="16"></label>
    <button type="submit"><?= $word('STOCK_OPS', 'add_button') ?></button>
  </form>
<?php else: ?>
  <p class="muted"><?= $word('INVOICES', 'credit_needs_invoice') ?></p>
<?php endif; ?>
<?php endif; ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add-charge') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('INVOICES', 'line_kind') ?>
      <select name="line_kind">
<?php foreach ($chargeKinds as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($typedCharge['kind'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('INVOICES', 'amount') ?> <input type="text" name="amount" value="<?= $e($typedCharge['amount']) ?>" inputmode="decimal" maxlength="16" required></label>
    <label><?= $word('INVOICES', 'description') ?> <input type="text" name="description" value="<?= $e($typedCharge['description']) ?>" maxlength="255"></label>
    <button type="submit"><?= $word('STOCK_OPS', 'add_button') ?></button>
  </form>
</section>

<details class="fold" open>
  <summary><?= $e($k['details']) ?></summary>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/details') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $e($k['number']) ?> <input type="text" name="invoice_number" value="<?= $e($form['invoice_number']) ?>" maxlength="64"></label>
    <label><?= $e($k['date']) ?> <input type="date" name="invoice_date" value="<?= $e($form['invoice_date']) ?>"></label>
    <label><?= $word('INVOICES', 'net') ?> <span class="hint"><?= $word('INVOICES', 'net_hint') ?></span><input type="text" name="net_total" value="<?= $e($form['net_total']) ?>" inputmode="decimal" maxlength="16"></label>
    <label><?= $word('INVOICES', 'vat') ?> <span class="hint"><?= $word('INVOICES', 'vat_hint') ?></span><input type="text" name="vat_total" value="<?= $e($form['vat_total']) ?>" inputmode="decimal" maxlength="16"></label>
    <label><?= $e($k['gross']) ?> <span class="hint"><?= $word('INVOICES', 'gross_hint') ?></span><input type="text" name="gross_total" value="<?= $e($form['gross_total']) ?>" inputmode="decimal" maxlength="16"></label>
<?php if ($kind === 'credit_note'): ?>
    <label><?= $word('INVOICES', 'credits') ?>
      <select name="credits_id">
        <option value=""><?= $word('INVOICES', 'on_account') ?></option>
<?php foreach ($invoiceChoices as $ic): ?>
        <option value="<?= $e($ic['id']) ?>"<?php if ($form['credits_id'] === (string) $ic['id']): ?> selected<?php endif; ?>><?= $e($ic['number']) ?><?php if ($ic['external_ref'] !== null): ?> (<?= $e($ic['external_ref']) ?>)<?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('INVOICES', 'debit_note') ?>
      <select name="debit_note_id">
        <option value=""><?= $word('INVOICES', 'no_return') ?></option>
<?php foreach ($returnChoices as $rc): ?>
        <option value="<?= $e($rc['id']) ?>"<?php if ($form['debit_note_id'] === (string) $rc['id']): ?> selected<?php endif; ?>><?= $e($rc['number']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
    <label><?= $word('STOCK_OPS', 'note') ?> <input type="text" name="note" value="<?= $e($form['note']) ?>" maxlength="1000"></label>
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'save_details') ?></button></p>
  </form>
</details>
<?php if ($arrival !== null && $arrival['editable']): ?>
<section class="card" aria-labelledby="how-h" id="how">
  <h2 id="how-h"><?= $word('ARRIVALS', 'how_title') ?></h2>
<?php if ($arrival['open'] === 0): ?>
  <p class="muted"><?= $word('ARRIVALS', 'lines_on_delivery') ?></p>
<?php else: ?>
  <p class="muted"><?php if ($arrival['sellsBefore']): ?><?= $word('ARRIVALS', 'sells_before') ?><?php else: ?><?= $word('ARRIVALS', 'sells_after') ?><?php endif; ?></p>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/receiving') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <fieldset>
      <legend><?= $word('ARRIVALS', 'how_title') ?></legend>
<?php foreach (\CW\Ui\Words::RECEIVE_MODE as $code => $label): ?>
      <label class="choice"><input type="radio" name="receive_mode" value="<?= $e($code) ?>"<?php if ($arrival['mode'] === $code): ?> checked<?php endif; ?>> <?= $e($label) ?></label>
<?php endforeach; ?>
      <p class="hint"><?= $word('ARRIVALS', 'how_count') ?></p>
      <p class="hint"><?= $word('ARRIVALS', 'how_received') ?></p>
    </fieldset>
<?php if ($arrival['mode'] === 'received'): ?>
    <div class="table-wrap">
    <table class="stack">
      <thead><tr><th scope="col"><?= $word('ARRIVALS', 'line') ?></th><th scope="col"><?= $word('ARRIVALS', 'item') ?></th><th scope="col"><?= $word('ARRIVALS', 'stamp') ?></th></tr></thead>
      <tbody>
<?php foreach ($arrival['stampLines'] as $sl): ?>
        <tr>
          <th scope="row" class="c-head"><?= $e($sl['line_no']) ?></th>
          <td data-label="<?= $word('ARRIVALS', 'item') ?>"><?= $e($sl['code']) ?> <?= $e($sl['name']) ?></td>
          <td data-label="<?= $word('ARRIVALS', 'stamp') ?>"><?php if ($sl['required']): ?>
            <select name="stamp_<?= $e($sl['line_no']) ?>">
              <option value=""><?= $word('ARRIVALS', 'stamp_choose') ?></option>
<?php foreach (\CW\Ui\Words::STAMP_ANSWER as $code => $label): ?>
              <option value="<?= $e($code) ?>"<?php if ($sl['answer'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
            </select><?php else: ?><span class="muted"><?= $word('ARRIVALS', 'stamp_not_needed') ?></span><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
    <p class="actions"><button type="submit"><?= $word('ARRIVALS', 'save_how') ?></button></p>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>
<section class="card final-box" aria-labelledby="final-h">
  <h2 id="final-h"><?= $e($k['post_title']) ?></h2>
  <p><?= $e($k['post_does']) ?></p>
<?php if ($ruleWaits): ?>
  <p class="muted"><?= $word('STOCK_OPS', 'final_waits') ?></p>
<?php endif; ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <button type="submit" class="primary"><?= $e($k['post_button']) ?></button>
  </form>
<?php if ($arrival !== null && $arrival['editable']): ?>
  <h3><?= $word('ARRIVALS', 'when_title') ?></h3>
<?php if ($arrival['failed'] !== null): ?>
  <p class="error" role="alert"><?= $say('ARRIVALS', 'failed', $arrival['failed']) ?></p>
<?php elseif ($arrival['at'] !== null): ?>
  <p><?= $say('ARRIVALS', 'timed', $arrival['at'], $arrival['by']) ?></p>
<?php else: ?>
  <p class="muted"><?= $word('ARRIVALS', 'when_text') ?></p>
<?php endif; ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/schedule') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('ARRIVALS', 'at') ?> <input type="datetime-local" name="submit_at" value="<?= $e($clockChoice['typed'] ?? $arrival['atValue']) ?>" required></label>
<?php if ($clockChoice !== null): ?>
    <fieldset>
      <legend><?= $say('ARRIVALS', 'clock_title', $clockChoice['time']) ?></legend>
      <label class="choice"><input type="radio" name="submit_clock" value="BST" required> <?= $say('ARRIVALS', 'clock_bst', $clockChoice['time']) ?></label>
      <label class="choice"><input type="radio" name="submit_clock" value="GMT" required> <?= $say('ARRIVALS', 'clock_gmt', $clockChoice['time']) ?></label>
    </fieldset>
<?php endif; ?>
    <button type="submit"><?= $word('ARRIVALS', 'set_time') ?></button>
  </form>
<?php if ($arrival['at'] !== null): ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/schedule') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="clear" value="1">
    <button type="submit"><?= $word('ARRIVALS', 'take_off') ?></button>
  </form>
<?php endif; ?>
<?php endif; ?>
</section>
<details class="fold">
  <summary><?= $word('STOCK_OPS', 'stop_title') ?></summary>
  <p class="muted"><?= $word('INVOICES', 'stop_text') ?></p>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('STOCK_OPS', 'stop_button') ?></button></p>
  </form>
</details>
<?php else: ?>
<?php if ($canWithdraw): ?>
<p class="muted"><?= $word('STOCK_OPS', 'withdraw_text') ?></p>
<?php endif; ?>

<?php if ($diff !== null): ?>
<section class="doc-diff" aria-labelledby="diff-h" id="match">
  <div class="doc-diff-head">
    <h2 id="diff-h"><?= $e($diff['title']) ?></h2>
    <p class="muted"><?= $e($diff['total']) ?></p>
  </div>
<?php if ($rec['accepted_at'] !== null && $storedMatch === 'accepted'): ?>
  <p class="note"><?= $say('INVOICES', 'accepted_line', (string) ($rec['accepted_by_name'] ?? ''), \CW\Ui\Html::when($rec['accepted_at']), (string) $rec['accept_note']) ?></p>
<?php endif; ?>
  <div class="table-wrap">
  <table class="stack doc-diff-lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('DOC', 'item') ?></th>
        <th scope="col" class="num"><?= $word('DOC', 'ordered') ?></th>
        <th scope="col" class="num"><?= $word('DOC', 'received') ?></th>
        <th scope="col" class="num"><?= $word('DOC', 'invoiced') ?></th>
        <th scope="col" class="num"><?= $word('DOC', 'price_order') ?></th>
        <th scope="col" class="num"><?= $word('DOC', 'price_invoice') ?></th>
        <th scope="col" class="num"><?= $word('DOC', 'difference') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($diff['rows'] as $d): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($d['name']) ?><span class="o-sub"><?= $e($d['code']) ?></span></th>
        <td class="num" data-label="<?= $word('DOC', 'ordered') ?>"><?php if ($d['ordered'] !== null): ?><?= $n($d['ordered']) ?><?php endif; ?></td>
        <td data-label="<?= $word('DOC', 'received') ?>" class="num<?php if ($d['qty_off']): ?> diff<?php endif; ?>"><?php if ($d['received'] !== null): ?><?= $n($d['received']) ?><?php endif; ?><?php if ($d['received_note'] !== null): ?> <span class="o-sub"><?= $e($d['received_note']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DOC', 'invoiced') ?>" class="num<?php if ($d['qty_off']): ?> diff<?php endif; ?>"><?= $n($d['invoiced']) ?><?php if ($d['invoiced_note'] !== null): ?> <span class="o-sub"><?= $e($d['invoiced_note']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DOC', 'price_order') ?>" class="num<?php if ($d['price_off']): ?> diff<?php endif; ?>"><?php if ($d['ref'] !== null): ?><?= $e($d['ref']) ?><?php if ($d['ref_delivery']): ?><span class="o-sub"><?= $word('DOC', 'from_delivery') ?></span><?php endif; ?><?php endif; ?></td>
        <td data-label="<?= $word('DOC', 'price_invoice') ?>" class="num<?php if ($d['price_off']): ?> diff<?php endif; ?>"><?php if ($d['price'] !== null): ?><?= $e($d['price']) ?><?php endif; ?></td>
        <td data-label="<?= $word('DOC', 'difference') ?>" class="num<?php if ($d['diff'] !== null): ?> diff<?php endif; ?>"><?php if ($d['diff'] !== null): ?><?= $e($d['diff']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<article class="doc-view" aria-labelledby="lines-h">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
  <h2 id="lines-h" class="doc-label"><?= $word('INVOICES', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('INVOICES', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack lines doc-lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('PI_ONE', 'item') ?></th>
        <th scope="col" class="num"><?= $word('PI_ONE', 'qty') ?></th>
        <th scope="col" class="num"><?= $word('PI_ONE', 'price') ?></th>
        <th scope="col" class="num"><?= $word('PI_ONE', 'amount') ?></th>
        <th scope="col"><?= $word('INVOICES', 'for') ?></th>
<?php if ($match !== null): ?>
        <th scope="col" class="c-status"><?= $word('INVOICES', 'match') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <th scope="row" class="c-head"><?php if ($l['kind'] === 'goods'): ?><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name'] ?? '') ?></a><span class="o-sub"><?= $e($l['code'] ?? '') ?><?php if ($l['supplier_code'] !== null): ?> · <?= $e($l['supplier_code']) ?><?php endif; ?></span><?php else: ?><?= $e($l['description'] ?? '') ?><span class="o-sub"><?= $e($l['kind_word']) ?></span><?php endif; ?></th>
        <td class="num" data-label="<?= $word('PI_ONE', 'qty') ?>"><?php if ($l['packs'] !== null && $l['kind'] === 'goods'): ?><?= $n($l['packs']) ?><span class="o-sub"><?= $e($l['packs_text'] ?? '') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('PI_ONE', 'price') ?>"><?php if ($l['pack_price'] !== null && $l['kind'] === 'goods'): ?><?= $money($l['pack_price']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('PI_ONE', 'amount') ?>"><?= $money($l['amount']) ?></td>
        <td data-label="<?= $word('INVOICES', 'for') ?>"><?php if ($l['grn_number'] !== null): ?><a href="<?= $u('/ui/receiving/' . $l['grn_document_id']) ?>"><?= $say('INVOICES', 'for_delivery', (string) $l['grn_number'], (int) $l['grn_line_no']) ?></a><?php endif; ?><?php if ($l['po_number'] !== null): ?><span class="o-sub"><?= $say('INVOICES', 'for_order', (string) $l['po_number'], (int) $l['po_line_no']) ?></span><?php endif; ?><?php if ($l['credit_of_line_no'] !== null): ?><span class="o-sub"><?= $say('INVOICES', 'credits_line', (int) $l['credit_of_line_no']) ?></span><?php endif; ?></td>
<?php if ($match !== null): ?>
        <td class="c-status"><?php if (isset($match['lines'][$l['line_no']])): ?><?php $ml = $match['lines'][$l['line_no']]; ?><?= $chip($ml['tone'], $ml['state_word']) ?><span class="o-sub"><?= $e($ml['billed']) ?> · <?= $e($ml['there']) ?> · <?= $e($ml['ref']) ?></span><?php if ($ml['why'] !== ''): ?><span class="o-sub"><?= $e($ml['why']) ?><?php if ($ml['diff'] !== ''): ?> (<?= $e($ml['diff']) ?>)<?php endif; ?></span><?php endif; ?><?php if (($ml['info'] ?? null) !== null): ?><span class="o-sub"><?= $e($ml['info']) ?></span><?php endif; ?><?php if ($ml['credited'] !== null): ?><span class="o-sub"><?= $e($ml['credited']) ?></span><?php endif; ?><?php endif; ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <div class="doc-foot">
    <div class="doc-notes" id="files">
<?php if ($doc->note !== null && $doc->note !== ''): ?>
      <p class="doc-label"><?= $word('PI_ONE', 'notes') ?></p><p class="pre"><?= $e($doc->note) ?></p>
<?php endif; ?>
      <p class="doc-label"><?= $word('STOCK_OPS', 'files') ?></p>
<?php if ($files === []): ?>
      <p class="muted"><?= $word('STOCK_OPS', 'no_files') ?></p>
<?php else: ?>
      <ul class="files">
<?php foreach ($files as $file): ?>
        <li><a href="<?= $u('/ui/files/' . $file['id']) ?>"><?= $e($file['original_name']) ?></a> <span class="muted"><?= $e($file['what']) ?> · <?= $e($file['size']) ?></span></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
<?php if ($canFile): ?>
      <form class="record doc-drop" method="post" enctype="multipart/form-data" action="<?= $u($base . '/' . $doc->id . '/files') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <label><?= $word('STOCK_OPS', 'file') ?> <input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
        <label><?= $word('STOCK_OPS', 'file_role') ?>
          <select name="role">
<?php foreach ($fileRoles as $code => $label): ?>
            <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <p class="actions"><button type="submit" class="btn secondary sm"><?= $word('STOCK_OPS', 'file_button') ?></button></p>
      </form>
<?php endif; ?>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totalRows]) ?>
  </div>
</article>
<?php if ($match !== null && $match['total_diff'] !== null): ?>
<p class="muted"><?= $say('INVOICES', 'total_rounding', $match['total_diff']) ?></p>
<?php endif; ?>
<?php endif; ?>

<?php if ($arrival !== null && !$arrival['editable'] && ($arrival['rows'] !== [] || $arrival['receipt'] !== null || $arrival['at'] !== null)): ?>
<section aria-labelledby="goods-h" id="goods">
  <h2 id="goods-h"><?= $word('ARRIVALS', 'on_way_title') ?><?php if ($arrival['mode'] !== null): ?> <?= $chip('info', \CW\Ui\Words::RECEIVE_MODE[$arrival['mode']]) ?><?php endif; ?></h2>
<?php if ($arrival['at'] !== null): ?>
  <p><?= $say('ARRIVALS', 'timed', $arrival['at'], $arrival['by']) ?></p>
<?php endif; ?>
<?php if ($arrival['receipt'] !== null): ?>
  <p><?= $word('ARRIVALS', 'booked_received') ?> <a href="<?= $u($arrival['receipt']['href']) ?>"><?= $e($arrival['receipt']['number']) ?></a></p>
<?php endif; ?>
<?php if ($arrival['rows'] !== []): ?>
  <div class="table-wrap">
  <table class="stack">
    <thead><tr><th scope="col"><?= $word('ARRIVALS', 'line') ?></th><th scope="col"><?= $word('ARRIVALS', 'item') ?></th><th scope="col" class="num"><?= $word('ARRIVALS', 'units') ?></th>
      <th scope="col"><?= $word('ARRIVALS', 'sold_before') ?></th><th scope="col" class="c-status"><?= $word('ARRIVALS', 'state') ?></th></tr></thead>
    <tbody>
<?php foreach ($arrival['rows'] as $ar): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($ar['line_no']) ?></th>
        <td data-label="<?= $word('ARRIVALS', 'item') ?>"><?= $e($ar['code']) ?> <?= $e($ar['name']) ?></td>
        <td class="num" data-label="<?= $word('ARRIVALS', 'units') ?>"><?= $n($ar['units']) ?></td>
        <td data-label="<?= $word('ARRIVALS', 'sold_before') ?>"><?php if ($ar['sellable']): ?><?= $word('ARRIVALS', 'yes') ?><?php else: ?><?= $word('ARRIVALS', 'no') ?><?php endif; ?></td>
        <td class="c-status"><?= $stateChip('ARRIVAL_STATE', $ar['state']) ?><?php if ($ar['receipt'] !== null): ?> <a href="<?= $u($ar['receipt']['href']) ?>"><?= $e($ar['receipt']['number']) ?></a><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>
<?php if ($kind === 'invoice' && !$doc->isReversal() && ($rec['credit_notes'] !== [] || $claims !== [])): ?>
<section aria-labelledby="credits-h">
  <h2 id="credits-h"><?= $word('INVOICES', 'credit_notes') ?></h2>
<?php foreach ($claims as $c): ?>
  <p><?= $e($c['text']) ?> <a href="<?= $u('/ui/purchasing/returns/' . $c['id']) ?>"><?= $e($c['number']) ?></a></p>
<?php endforeach; ?>
<?php if ($rec['credit_notes'] === []): ?>
  <p class="muted"><?= $word('INVOICES', 'no_credit_notes') ?></p>
<?php else: ?>
  <ul>
<?php foreach ($rec['credit_notes'] as $c): ?>
    <li><a href="<?= $u('/ui/purchasing/credit-notes/' . $c['id']) ?>"><?= $e($c['number'] ?? \CW\Ui\Words::STOCK_OPS['no_number']) ?></a> <?= $stateChip('DOC_STATUS', $c['status']) ?><?php if ($c['gross_total'] !== null): ?> <?= $money($c['gross_total']) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>

</section>
<?php endif; ?>

<?php if ($events !== []): ?>
<section aria-labelledby="events-h">
  <h2 id="events-h"><?= $word('INVOICES', 'events_title') ?></h2>
  <p class="muted"><?= $word('INVOICES', 'events_text') ?></p>
  <div class="table-wrap">
  <table class="stack lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('INVOICES', 'event_item') ?></th>
        <th scope="col"><?= $word('INVOICES', 'event_kind') ?></th>
        <th scope="col" class="num"><?= $word('INVOICES', 'units') ?></th>
        <th scope="col" class="num"><?= $word('INVOICES', 'amount') ?></th>
        <th scope="col"><?= $word('INVOICES', 'event_basis') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($events as $ev): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($ev['code'] ?? '') ?><?php if ($ev['grn_number'] !== null): ?><span class="o-sub"><?= $e($ev['grn_number']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('INVOICES', 'event_kind') ?>"><?= $word('VALUE_EVENT', $ev['kind']) ?></td>
        <td class="num" data-label="<?= $word('INVOICES', 'units') ?>"><?= $n($ev['units']) ?></td>
        <td class="num" data-label="<?= $word('INVOICES', 'amount') ?>"><?= $money($ev['amount']) ?></td>
        <td data-label="<?= $word('INVOICES', 'event_basis') ?>"><?= $word('VALUE_BASIS', $ev['basis']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if ($editable): ?>
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
