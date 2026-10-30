<?php
// The supplier invoice on ONE page (docs/decisions.md PV1-PV12; the owner, 29 Oct 2026: "keep always user experience much good",
// "professional: refer Zoho Inventory"), laid out as Zoho Inventory's "New Bill" with CW's own colours, type and words: the head in two
// columns (the supplier, the supplier's number, the dates, "From"), the lines with their headings (each edited in place with its remove;
// the last row adds a line: the order page's item box with "+ Add new item"), a charge, the notes and the file on the left and the totals
// on the right, the bar at the bottom (leave, Save as draft, Save and make final). A new invoice ($isNew: nothing saved, the form posts to
// the list's address with a FormOnce key) or its creator's draft (the form posts to /lines with its version and line count first). One
// form; Enter is Add (its first button). The supplier is the order page's plain select that app.js makes the site's one dropdown (SM11),
// ending with "+ Add new supplier"; "From" is a plain select of every open delivery and order, each naming its supplier, that the script
// narrows to the chosen supplier's. A draft's timed option, how its goods come in and "Stop" are forms of their own after this one (pack J,
// pack C), their controls in the page named with form=.
?>
<?= $head((string) $title, intro: $isNew ? 'invoice_new' : 'supplier_invoice', back: ['/ui/purchasing/invoices', (string) $k['tab']], meta: $isNew ? '' : $stateChip('DOC_STATUS', 'draft'), side: $isNew ? '' : null) ?>
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
<?php if ($problems !== [] && $error === null): ?>
<section class="note" aria-labelledby="problems-h">
  <h2 id="problems-h" class="section-title"><?= $word('INVOICES', 'problems') ?></h2>
  <ul class="problems">
<?php foreach ($problems as $p): ?>
    <li><?= $e(\CW\Ui\Controller\InvoicesController::problemText($p)) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<form class="po-editor doc-form pi-editor" method="post" enctype="multipart/form-data" action="<?= $u($formAction) ?>" novalidate data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if (!$isNew): ?>
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
<?php if ($linesFit): ?>
  <input type="hidden" name="line_count" value="<?= $e(count($lines)) ?>">
<?php endif; ?>
<?php endif; ?>
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <button type="submit" name="action" value="add" class="po-default" tabindex="-1" aria-hidden="true"><?= $word('PI_ONE', 'add') ?></button>
  <fieldset class="po-header doc-grid">
    <legend class="visually-hidden"><?= $e($k['one']) ?></legend>
<?php if ($supplierFixed): ?>
    <p class="po-fixed pi-fixed"><?= $word('INVOICES', 'supplier') ?>: <a href="<?= $u('/ui/purchasing/suppliers/' . $supplierId) ?>"><?= $e($supplierName) ?></a></p>
<?php else: ?>
    <div class="po-box-field">
      <label for="po-supplier"><?= $word('INVOICES', 'supplier') ?></label>
      <select name="supplier_id" id="po-supplier" data-po-supplier data-placeholder="<?= $word('PI_ONE', 'supplier_hint') ?>" data-label="<?= $word('PI_ONE', 'suppliers') ?>"<?php if ($canAddSupplier): ?> data-add="<?= $word('PO_ADD', 'new_supplier') ?>" data-panel="pi-add-supplier"<?php else: ?> data-ask="<?= $e($askSupplier) ?>"<?php endif; ?>>
        <option value=""><?= $word('ORDERS', 'choose_supplier') ?></option>
<?php foreach ($supplierChoices as $s): ?>
        <option value="<?= $e($s['id']) ?>" data-name="<?= $e($s['name']) ?>" data-meta="<?= $e($s['meta']) ?>" data-terms="<?= $e($s['terms']) ?>"<?php if ($s['id'] === $supplierId): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
      </select>
<?php if ($canAddSupplier): ?>
      <details class="card po-add" id="pi-add-supplier" data-po-panel<?php if ($supplierError !== null): ?> open<?php endif; ?>>
        <summary><?= $word('PO_ADD', 'new_supplier') ?></summary>
<?php if ($supplierError !== null): ?>
        <p class="error" role="alert"><?= $e($supplierError) ?></p>
<?php endif; ?>
        <label><?= $word('PO_ADD', 'supplier_name') ?> <input type="text" name="new_supplier_name" maxlength="128" autocomplete="off" value="<?= $e($newSupplier['name']) ?>"></label>
        <label><?= $word('PO_ADD', 'supplier_email') ?> <input type="email" name="new_supplier_email" maxlength="191" autocomplete="off" value="<?= $e($newSupplier['email']) ?>"></label>
        <label><?= $word('PO_ADD', 'supplier_phone') ?> <input type="tel" name="new_supplier_phone" maxlength="32" autocomplete="off" value="<?= $e($newSupplier['phone']) ?>"></label>
        <button type="submit" name="action" value="new_supplier" class="btn"><?= $word('PO_ADD', 'supplier_button') ?></button>
      </details>
<?php else: ?>
      <p class="hint po-ask"><?= $e($askSupplier) ?></p>
<?php endif; ?>
    </div>
<?php endif; ?>
    <label><?= $word('PI_ONE', 'number') ?> <input type="text" name="invoice_number" maxlength="64" autocomplete="off" value="<?= $e($form['invoice_number']) ?>"></label>
    <div class="doc-field doc-wide">
      <label for="pi-from"><?= $word('PI_ONE', 'from') ?></label>
      <div class="doc-pair">
        <select id="pi-from" name="from" data-pi-from>
          <option value=""><?= $word('PI_ONE', 'from_none') ?></option>
<?php if ($sources['deliveries'] !== []): ?>
          <optgroup label="<?= $word('PI_ONE', 'from_deliveries') ?>">
<?php foreach ($sources['deliveries'] as $o): ?>
            <option value="<?= $e($o['value']) ?>" data-supplier="<?= $e($o['supplier']) ?>"<?php if ($from === $o['value']): ?> selected<?php endif; ?>><?= $e($o['label']) ?></option>
<?php endforeach; ?>
          </optgroup>
<?php endif; ?>
<?php if ($sources['orders'] !== []): ?>
          <optgroup label="<?= $word('PI_ONE', 'from_orders') ?>">
<?php foreach ($sources['orders'] as $o): ?>
            <option value="<?= $e($o['value']) ?>" data-supplier="<?= $e($o['supplier']) ?>"<?php if ($from === $o['value']): ?> selected<?php endif; ?>><?= $e($o['label']) ?></option>
<?php endforeach; ?>
          </optgroup>
<?php endif; ?>
        </select>
        <button type="submit" name="action" value="from" class="btn soft"><?= $word('PI_ONE', 'fill') ?></button>
      </div>
    </div>
    <label><?= $word('PI_ONE', 'date') ?> <input type="date" name="invoice_date" value="<?= $e($form['invoice_date']) ?>"></label>
    <label><?= $word('PI_ONE', 'due') ?> <input type="date" name="due_date" value="<?= $e($form['due_date']) ?>" data-pi-due<?php if ($terms !== null): ?> data-terms="<?= $e($terms) ?>"<?php endif; ?><?php if ($dueAuto): ?> data-auto="1"<?php endif; ?>>
      <span class="hint" data-pi-due-hint data-words="<?= $word('PI_ONE', 'terms_hint') ?>"<?php if ($terms === null): ?> hidden<?php endif; ?>><?php if ($terms !== null): ?><?= $say('PI_ONE', 'terms_hint', $terms) ?><?php endif; ?></span></label>
<?php if ($isNew): ?>
    <input type="hidden" name="due_shown" value="<?= $e($dueShown) ?>">
<?php endif; ?>
  </fieldset>

  <section class="po-board" id="lines" aria-labelledby="lines-h">
    <div class="board-head"><h2 id="lines-h" class="board-sub"><?= $word('PI_ONE', 'lines') ?></h2></div>
<?php if ($match !== null): ?>
    <p class="note match-note" id="match"><?= $word('INVOICES', 'match_title') ?> <?= $chip($match['tone'], $match['word']) ?> <?= $word('INVOICES', 'match_preview') ?><?php if ($match['off'] > 0): ?> <?= $say('INVOICES', 'match_off', $match['off'], $match['difference']) ?><?php endif; ?></p>
<?php endif; ?>
<?php if (!$linesFit): ?>
    <p class="note"><?= $word('PI_ONE', 'too_many') ?></p>
<?php endif; ?>
    <div class="table-wrap">
    <table class="stack list board doc-lines pi-lines">
      <thead>
        <tr>
          <th scope="col" class="c-item"><?= $word('PI_ONE', 'item') ?></th>
          <th scope="col" class="num"><?= $word('PI_ONE', 'qty') ?></th>
          <th scope="col" class="num"><?= $word('PI_ONE', 'price') ?></th>
          <th scope="col" class="num"><?= $word('PI_ONE', 'amount') ?></th>
          <th scope="col"><span class="visually-hidden"><?= $word('PI_ONE', 'remove_col') ?></span></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $l): ?>
<?php $no = (int) $l['line_no']; ?>
        <tr>
<?php if ($l['kind'] === 'goods'): ?>
          <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name'] ?? '') ?></a><span class="o-sub"><?= $e($l['code'] ?? '') ?><?php if ($l['supplier_code'] !== null): ?> · <?= $e($l['supplier_code']) ?><?php endif; ?><?php if ($l['grn_number'] !== null): ?> · <?= $say('INVOICES', 'for_delivery', (string) $l['grn_number'], (int) $l['grn_line_no']) ?><?php endif; ?><?php if ($l['po_number'] !== null): ?> · <?= $say('INVOICES', 'for_order', (string) $l['po_number'], (int) $l['po_line_no']) ?><?php endif; ?></span></th>
<?php if ($linesFit): ?>
          <td class="num" data-label="<?= $word('PI_ONE', 'qty') ?>"><input type="text" name="p_<?= $e($no) ?>" value="<?= $e($typedLines['p_' . $no] ?? $l['packs']) ?>" inputmode="numeric" maxlength="7" class="short" aria-label="<?= $say('PI_ONE', 'line_n', $no) ?>: <?= $word('PI_ONE', 'qty') ?>"><span class="o-sub"><?= $e($l['packs_text']) ?></span></td>
          <td class="num" data-label="<?= $word('PI_ONE', 'price') ?>"><input type="text" name="pp_<?= $e($no) ?>" value="<?= $e($typedLines['pp_' . $no] ?? $l['price_text']) ?>" inputmode="decimal" maxlength="16" class="short" aria-label="<?= $say('PI_ONE', 'line_n', $no) ?>: <?= $word('PI_ONE', 'price') ?>"></td>
<?php else: ?>
          <td class="num" data-label="<?= $word('PI_ONE', 'qty') ?>"><?= $n($l['packs']) ?><span class="o-sub"><?= $e($l['packs_text']) ?></span></td>
          <td class="num" data-label="<?= $word('PI_ONE', 'price') ?>"><?= $money($l['pack_price']) ?></td>
<?php endif; ?>
          <td class="num" data-label="<?= $word('PI_ONE', 'amount') ?>"><?= $money($l['amount']) ?></td>
<?php else: ?>
<?php if ($linesFit): ?>
          <th scope="row" class="c-head c-item"><input type="text" class="line-what" name="d_<?= $e($no) ?>" value="<?= $e($typedLines['d_' . $no] ?? ($l['description'] ?? '')) ?>" maxlength="255" aria-label="<?= $word('PI_ONE', 'charge_words') ?>"><span class="o-sub"><?= $e($l['kind_word']) ?></span></th>
<?php else: ?>
          <th scope="row" class="c-head c-item"><?= $e($l['description'] ?? '') ?><span class="o-sub"><?= $e($l['kind_word']) ?></span></th>
<?php endif; ?>
          <td class="num" data-label="<?= $word('PI_ONE', 'qty') ?>"></td>
          <td class="num" data-label="<?= $word('PI_ONE', 'price') ?>"></td>
          <td class="num" data-label="<?= $word('PI_ONE', 'amount') ?>"><?php if ($linesFit): ?><input type="text" name="a_<?= $e($no) ?>" value="<?= $e($typedLines['a_' . $no] ?? $l['amount_text']) ?>" inputmode="decimal" maxlength="16" class="short" aria-label="<?= $word('PI_ONE', 'amount') ?>"><?php else: ?><?= $money($l['amount']) ?><?php endif; ?></td>
<?php endif; ?>
          <td class="c-status"><?php if ($linesFit): ?><button type="submit" name="remove_line" value="<?= $e($no) ?>" class="btn ghost po-x" aria-label="<?= $say('PI_ONE', 'remove', $no) ?>">&times;</button><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
        <tr class="grp-add po-add-row">
          <td class="c-item" data-label="">
            <div class="po-box-field">
              <label for="po-q" class="visually-hidden"><?= $word('PI_ONE', 'item_box') ?></label>
              <input id="po-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autocomplete="off" placeholder="<?= $word('PI_ONE', 'add_line') ?>" data-po-pick data-options="<?= $e($productsUrl) ?>" data-loading="<?= $word('PI_ONE', 'loading') ?>" data-failed="<?= $word('PI_ONE', 'failed') ?>"<?php if ($canAddItem): ?> data-add="<?= $word('PO_ADD', 'new_item') ?>" data-panel="pi-add-item"<?php else: ?> data-ask="<?= $e($askItem) ?>"<?php endif; ?>>
              <input type="hidden" name="pick_si" value="<?= $e($pick['si']) ?>">
              <input type="hidden" name="pick_sku" value="<?= $e($pick['sku']) ?>">
              <ul class="sm-list" id="po-q-list" role="listbox" aria-label="<?= $word('PI_ONE', 'items') ?>" data-po-list hidden><?php if ($pick['choice'] !== null): ?><li role="option" aria-selected="false" data-label="<?= $e($pick['choice']['label']) ?>" data-si="<?= $e($pick['choice']['si']) ?>" data-sku="<?= $e($pick['choice']['sku']) ?>"><span class="sm-opt-name"><?= $e($pick['choice']['name']) ?></span> <span class="sm-opt-meta"><?= $e($pick['choice']['meta']) ?></span></li><?php endif; ?></ul>
              <p class="sm-msg muted" data-po-note aria-live="polite" hidden></p>
            </div>
          </td>
          <td class="num" data-label="<?= $word('PI_ONE', 'qty') ?>"><label class="visually-hidden" for="pi-packs"><?= $word('PI_ONE', 'packs') ?></label><input id="pi-packs" type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short"></td>
          <td class="num" data-label="<?= $word('PI_ONE', 'price') ?>"><label class="visually-hidden" for="pi-price"><?= $word('PI_ONE', 'pack_price') ?></label><input id="pi-price" type="text" name="price" inputmode="decimal" maxlength="16" value="<?= $e($price) ?>" class="short"></td>
          <td colspan="2" class="po-add-go" data-label=""><button type="submit" name="action" value="add" class="btn"><?= $word('PI_ONE', 'add') ?></button></td>
        </tr>
      </tbody>
    </table>
    </div>
<?php if ($canAddItem): ?>
    <details class="card po-add" id="pi-add-item" data-po-panel<?php if ($itemOpen): ?> open<?php endif; ?>>
      <summary><?= $word('PO_ADD', 'new_item') ?></summary>
<?php if ($itemError !== null): ?>
      <p class="error" role="alert"><?= $e($itemError) ?></p>
<?php endif; ?>
<?php if ($itemOther !== null): ?>
      <p class="note"><?= $say('PO_ADD', 'barcode_other', $itemOther['label']) ?> <button type="submit" name="add_sku" value="<?= $e($itemOther['id']) ?>" class="btn"><?= $word('PO_ADD', 'barcode_use') ?></button></p>
<?php endif; ?>
<?php if ($itemSame !== null): ?>
      <div class="note same-items">
        <p><strong><?= $word('PO_ADD', 'same_title') ?></strong> <?= $word('PO_ADD', 'same_text') ?></p>
        <ul class="plain">
<?php foreach ($itemSame as $s): ?>
          <li><a href="<?= $u('/ui/items/' . $s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?> <button type="submit" name="add_sku" value="<?= $e($s['id']) ?>" class="btn"><?= $word('PO_ADD', 'same_use') ?></button></li>
<?php endforeach; ?>
        </ul>
      </div>
<?php endif; ?>
      <label><?= $word('PO_ADD', 'item_name') ?> <input type="text" name="item_name" maxlength="255" autocomplete="off" value="<?= $e($item['name']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_brand') ?> <input type="text" name="item_brand" maxlength="100" value="<?= $e($item['brand']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_barcode') ?> <input type="text" name="item_barcode" maxlength="64" inputmode="numeric" autocomplete="off" value="<?= $e($item['barcode']) ?>"></label>
<?php if ($itemSame !== null): ?>
      <label class="choice"><input type="checkbox" name="item_anyway" value="1"<?php if ($item['anyway']): ?> checked<?php endif; ?>> <?= $word('PO_ADD', 'same_anyway') ?></label>
<?php endif; ?>
      <label><?= $word('PO_ADD', 'item_code') ?> <input type="text" name="item_code" maxlength="64" autocomplete="off" value="<?= $e($item['code']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_upp') ?> <input type="number" name="item_upp" min="1" max="100000" class="short" value="<?= $e($item['upp']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_price') ?> <input type="text" name="item_price" inputmode="decimal" class="short" value="<?= $e($item['price']) ?>"></label>
      <button type="submit" name="action" value="new_item" class="btn"><?= $word('PO_ADD', 'item_button') ?></button>
    </details>
<?php else: ?>
    <p class="hint po-ask"><?= $e($askItem) ?></p>
<?php endif; ?>
    <details class="pi-charge"<?php if ($chargeOpen): ?> open<?php endif; ?>>
      <summary><?= $word('PI_ONE', 'add_charge') ?></summary>
      <div class="doc-charge">
        <label><?= $word('PI_ONE', 'charge_kind') ?>
          <select name="charge_kind">
<?php foreach ($chargeKinds as $code => $label): ?>
            <option value="<?= $e($code) ?>"<?php if ($charge['kind'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label><?= $word('PI_ONE', 'charge_amount') ?> <input type="text" name="charge_amount" inputmode="decimal" maxlength="16" value="<?= $e($charge['amount']) ?>"></label>
        <label><?= $word('PI_ONE', 'charge_words') ?> <input type="text" name="charge_description" maxlength="255" value="<?= $e($charge['description']) ?>"></label>
        <button type="submit" name="action" value="add_charge" class="btn"><?= $word('PI_ONE', 'charge_button') ?></button>
      </div>
    </details>
  </section>
<?php if ($choices !== null): ?>
  <section class="choices" aria-labelledby="choices-h">
    <h2 id="choices-h"><?= $say('PI_ONE', 'choose', $q) ?></h2>
    <ul class="plain">
<?php foreach ($choices as $c): ?>
      <li><button class="btn" type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>"><?= $word('PI_ONE', 'choose_this') ?></button> <?= $e($c['name']) ?> <span class="muted"><?= $e($c['code']) ?></span></li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

  <div class="doc-foot">
    <div class="doc-notes">
      <label><?= $word('PI_ONE', 'notes') ?> <textarea name="note" maxlength="1000" rows="3"><?= $e($form['note']) ?></textarea></label>
      <div class="doc-pair doc-drop">
        <label><?= $word('PI_ONE', 'file') ?> <input type="file" name="file" accept="application/pdf,image/jpeg,image/png"></label>
        <label><?= $word('PI_ONE', 'file_kind') ?>
          <select name="file_role">
<?php foreach ($fileRoles as $code => $label): ?>
            <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
      </div>
<?php if ($files !== []): ?>
      <p class="doc-label"><?= $word('PI_ONE', 'files') ?></p>
      <ul class="files">
<?php foreach ($files as $file): ?>
        <li><a href="<?= $u('/ui/files/' . $file['id']) ?>"><?= $e($file['original_name']) ?></a> <span class="muted"><?= $e($file['what']) ?> · <?= $e($file['size']) ?></span></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totals]) ?>
  </div>
<?php if ($arrival !== null && $arrival['editable']): ?>
  <details class="doc-later"<?php if ($clockChoice !== null || $arrival['failed'] !== null): ?> open<?php endif; ?>>
    <summary><?= $word('ARRIVALS', 'when_title') ?></summary>
<?php if ($arrival['failed'] !== null): ?>
    <p class="error" role="alert"><?= $say('ARRIVALS', 'failed', $arrival['failed']) ?></p>
<?php elseif ($arrival['at'] !== null): ?>
    <p><?= $say('ARRIVALS', 'timed', $arrival['at'], $arrival['by']) ?></p>
<?php endif; ?>
    <div class="doc-charge">
      <label><?= $word('ARRIVALS', 'at') ?> <input type="datetime-local" name="submit_at" form="pi-schedule" value="<?= $e($clockChoice['typed'] ?? $arrival['atValue']) ?>" required></label>
<?php if ($clockChoice !== null): ?>
      <fieldset>
        <legend><?= $say('ARRIVALS', 'clock_title', $clockChoice['time']) ?></legend>
        <label class="choice"><input type="radio" name="submit_clock" value="BST" form="pi-schedule" required> <?= $say('ARRIVALS', 'clock_bst', $clockChoice['time']) ?></label>
        <label class="choice"><input type="radio" name="submit_clock" value="GMT" form="pi-schedule" required> <?= $say('ARRIVALS', 'clock_gmt', $clockChoice['time']) ?></label>
      </fieldset>
<?php endif; ?>
      <button type="submit" form="pi-schedule" class="btn"><?= $word('ARRIVALS', 'set_time') ?></button>
<?php if ($arrival['at'] !== null): ?>
      <button type="submit" form="pi-unschedule" class="btn ghost"><?= $word('ARRIVALS', 'take_off') ?></button>
<?php endif; ?>
    </div>
  </details>
<?php endif; ?>
<?= $partial('doc_bar', ['back' => '/ui/purchasing/invoices', 'buttons' => [['value' => 'save', 'label' => \CW\Ui\Words::PI_ONE['draft']],
    ['value' => 'post', 'label' => \CW\Ui\Words::PI_ONE['final'], 'primary' => true]]]) ?>
</form>
<?php if (!$isNew): ?>
<form id="pi-schedule" method="post" action="<?= $u('/ui/purchasing/invoices/' . $doc->id . '/schedule') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
</form>
<?php if ($arrival !== null && $arrival['at'] !== null): ?>
<form id="pi-unschedule" method="post" action="<?= $u('/ui/purchasing/invoices/' . $doc->id . '/schedule') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="clear" value="1">
</form>
<?php endif; ?>
<?php if ($arrival !== null && $arrival['editable']): ?>
<details class="fold" id="how">
  <summary><?= $word('ARRIVALS', 'how_title') ?></summary>
<?php if ($arrival['open'] === 0): ?>
  <p class="muted"><?= $word('ARRIVALS', 'lines_on_delivery') ?></p>
<?php else: ?>
  <p class="muted"><?php if ($arrival['sellsBefore']): ?><?= $word('ARRIVALS', 'sells_before') ?><?php else: ?><?= $word('ARRIVALS', 'sells_after') ?><?php endif; ?></p>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/invoices/' . $doc->id . '/receiving') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <fieldset>
      <legend><?= $word('ARRIVALS', 'how_title') ?></legend>
<?php foreach (\CW\Ui\Words::RECEIVE_MODE as $code => $label): ?>
      <label class="choice"><input type="radio" name="receive_mode" value="<?= $e($code) ?>"<?php if ($arrival['mode'] === $code): ?> checked<?php endif; ?>> <?= $e($label) ?></label>
<?php endforeach; ?>
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
    <p class="actions"><button type="submit" class="btn"><?= $word('ARRIVALS', 'save_how') ?></button></p>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>
<details class="fold"<?php if ($typedStop !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('STOCK_OPS', 'stop_title') ?></summary>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/invoices/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
    <p class="actions"><button type="submit" class="btn risky"><?= $word('STOCK_OPS', 'stop_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
