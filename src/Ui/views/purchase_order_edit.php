<?php
// The purchase order on ONE page (docs/decisions.md PX1-PX10; the owner, 26 Oct 2026: one view, "add new" in the dropdowns, monday.com's
// look, nothing over complicated). A new order (no draft yet: $isNew, the form posts to the list's address with a FormOnce key) or its
// creator's draft (the form posts to /lines with its version first). One form: the supplier, the board of products (each row edited in
// place with its remove; the last row is "+ Add product", the scan box), the delivery charge and the totals in one summary, Save and Confirm.
// Enter is Add (the form's first button). The supplier is a plain select that app.js makes a box with the site's one dropdown (SM11),
// ending with "+ Add new supplier"; the scan box gets the same dropdown, ending with "+ Add new item". Both small forms are fields of this
// form, each with its own button: a fold without the script, opened by the dropdown's last line with it. A person who may not add one
// sees the line naming whom to ask instead. A saved draft has "Cancel draft" beside Save and Confirm (PX11): one small step of its own form
// (its controls name the form po-cancel, drawn after this one, as forms do not nest), the existing cancel route. The professional look
// (docs/decisions.md PV, Zoho Inventory's layout; the order behaves as before, PX1-PX11): the head fields in the shared grid (two columns, one
// on a phone), the board of products with its headings, the totals on the right and the bar at the bottom that stays on the screen
// (doc_bar: leave without saving, Save, Confirm), all inside the summary section the tests know. The lines are the standard grid of
// docs/decisions.md KG (the owner, 6 Nov 2026): a No. column, one line a row on a computer, a line filled in place and the keys of a
// standard screen by app.js; every POST of the form sends the new lines in one field (DocGrid::FIELD). Without the script, as before.
?>
<?php
// ERPNext's head as the demo the owner approved (docs/decisions.md KG26, KG31): the status beside the title (Not saved on a new order), the
// actions on the right: Keys (?), "..." (the rest), Save, and the dark main button Confirm.
$confirmWord = $overLimit ? \CW\Ui\Words::PO['confirm_over_limit'] : \CW\Ui\Words::PO_ONE['confirm'];
$headSide = $partial('doc_head_bar', ['form' => 'po-form', 'default' => \CW\Ui\Words::ORDER['add_button'], 'back' => '/ui/purchasing/orders', 'more' => $isNew ? '' : 'po_head_more', 'moreData' => ['doc' => $doc, 'editable' => $editable], 'buttons' => [
        ['value' => 'save', 'label' => \CW\Ui\Words::PO_ONE['save']],
        ['value' => 'approve', 'label' => $confirmWord, 'primary' => true]]]);
?>
<?= $head((string) $title, intro: $isNew ? 'order_new' : 'order_draft', back: ['/ui/purchasing/orders', \CW\Ui\Words::MENU['orders']],
    aside: $isNew ? $chip('waiting', \CW\Ui\Words::DOC_GRID['not_saved']) : $stateChip('PO_STATE', 'draft'),
    side: $headSide) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($importErrors !== []): ?>
<section class="error" aria-labelledby="import-errors-h">
  <h2 id="import-errors-h"><?= $word('ORDER', 'import_failed') ?></h2>
  <ul>
<?php foreach ($importErrors as $ie): ?>
    <li><?= $e($ie) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($warnings !== []): ?>
<ul class="warnings">
<?php foreach ($warnings as $w): ?>
  <li><?= $e($w) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($supplierNeeds !== null): ?>
<?= $partial('po_supplier_needs', ['needs' => $supplierNeeds]) ?>
<?php endif; ?>

<form id="po-form" class="po-editor kg-doc" method="post" action="<?= $u($formAction) ?>" data-kg-editor="po:<?= $e($isNew ? 'new' : $doc->id) ?>"<?php if (!$isNew): ?> data-kg-address="<?= $u('/ui/purchasing/orders/' . $doc->id) ?>"<?php endif; ?> novalidate data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>"<?php if ($grid['newRows'] !== [] || $grid['removed'] !== []): ?> data-dirty="1"<?php endif; ?><?php if (($errorCode ?? null) === 'version_conflict'): ?> data-kg-conflict<?php endif; ?>>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if (!$isNew): ?>
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="line_count" value="<?= $e($editable ? count($lines) : 0) ?>">
  <input type="hidden" name="lines_editable" value="<?php if ($editable): ?>1<?php else: ?>0<?php endif; ?>">
<?php endif; ?>
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="<?= $e(\CW\Ui\DocGrid::FIELD) ?>" value="<?= $e($grid['newJson']) ?>" data-kg-field>
  <button type="submit" name="action" value="add" class="po-default" tabindex="-1" aria-hidden="true"><?= $word('ORDER', 'add_button') ?></button>
  <div class="kg-look" data-kg-look role="alert" hidden></div>
  <section class="kg-sec kg-details" aria-labelledby="kg-details-h">
  <h2 class="kg-sec-title" id="kg-details-h"><?= $word('DOC_GRID', 'details') ?></h2>
  <fieldset class="po-header doc-grid" data-kg-swap-part="header">
    <legend class="visually-hidden"><?= $word('ORDER', 'order') ?></legend>
<?php if ($supplierFixed): ?>
    <p class="po-fixed"><?= $word('ORDER', 'supplier') ?>: <a href="<?= $u('/ui/purchasing/suppliers/' . ($supplier['id'] ?? 0)) ?>"><?= $e($supplierName) ?></a></p>
<?php else: ?>
    <div class="po-box-field">
      <label for="po-supplier"><?= $word('ORDER', 'supplier') ?></label>
      <select name="supplier_id" id="po-supplier" data-po-supplier data-placeholder="<?= $word('PO_ONE', 'supplier_hint') ?>" data-label="<?= $word('PO_ONE', 'suppliers') ?>" data-change-ask="<?= $word('DOC_GRID', 'change_ask') ?>" data-change-yes="<?= $word('DOC_GRID', 'change_yes') ?>" data-change-keep="<?= $word('DOC_GRID', 'change_keep') ?>"<?php if ($canAddSupplier): ?> data-add="<?= $word('PO_ADD', 'new_supplier') ?>" data-panel="po-add-supplier"<?php else: ?> data-ask="<?= $e($askSupplier) ?>"<?php endif; ?>>
        <option value=""><?= $word('ORDERS', 'choose_supplier') ?></option>
<?php foreach ($supplierChoices as $s): ?>
        <option value="<?= $e($s['id']) ?>" data-name="<?= $e($s['name']) ?>" data-meta="<?= $e($s['meta']) ?>" data-vat="<?= $e($s['vat']) ?>"<?php if ($s['id'] === $supplierId): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
      </select>
<?php if ($canAddSupplier): ?>
      <details class="card po-add" id="po-add-supplier" data-po-panel<?php if ($supplierError !== null): ?> open<?php endif; ?>>
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
    <label><?= $word('ORDER', 'expected') ?> <input type="date" name="expected_date" value="<?= $e($info['expected_date']) ?>"></label>
    <label><?= $word('ORDER', 'ref') ?> <input type="text" name="external_ref" maxlength="191" value="<?= $e($info['external_ref']) ?>"></label>
    <label class="kg-wide"><?= $word('ORDER', 'note') ?> <input type="text" name="note" maxlength="1000" value="<?= $e($info['note']) ?>"></label>
  </fieldset>
  </section>

  <section class="po-board kg-sec" id="scan" aria-labelledby="lines-h">
    <div class="board-head"><h2 id="lines-h" class="board-sub kg-sec-title"><?= $word('ORDER', 'lines') ?></h2></div>
    <div data-kg-swap-part="notes">
<?php if ($unpriced !== null): ?>
    <p class="note kg-unpriced" role="status"><?= $e($unpriced) ?></p>
<?php endif; ?>
<?php if (!$editable): ?>
    <p class="note"><?= $say('ORDER', 'too_many', $maxLines) ?></p>
<?php endif; ?>
    </div>
<?php
// The lines as a standard grid (docs/decisions.md KG1-KG11): a No. column, rows one line high on a computer, the empty line last (the product
// box). The data-kg-* attributes are app.js's (one engine for the order and the supplier invoice): the cells a key moves between, each line's
// figures for the totals, the order's limit, the VAT of a new line (the supplier's, kept in its option for the new page).
$rowNo = 0;
?>
    <div class="table-wrap">
    <table class="stack list board po-lines doc-lines kg-grid" data-kg-grid data-kg-max="<?= $e($grid['max']) ?>" data-kg-remove="line_%d_remove" data-kg-vat="<?= $e($gridVat['code']) ?>" data-kg-rates="<?= $e(json_encode($gridVat['rates'], JSON_UNESCAPED_UNICODE)) ?>" data-kg-max-qty="<?= $e(\CW\PurchaseOrders\PoMath::MAX_PACKS) ?>" data-kg-min-qty="0"<?= $partial('doc_grid_words', []) ?>>
      <thead>
        <tr>
          <th scope="col" class="c-no"><input type="checkbox" class="kg-tick" data-kg-tick-all data-kg-js hidden tabindex="-1" aria-label="<?= $word('DOC_GRID', 'tick_all') ?>"><?= $word('DOC_GRID', 'no') ?></th>
          <th scope="col" class="c-item"><?= $word('ORDER', 'product') ?></th>
          <th scope="col"><?= $word('ORDER', 'their_code') ?></th>
          <th scope="col"><?= $word('ORDER', 'pack') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'packs') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'per_pack') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'total') ?></th>
          <th scope="col"><span class="visually-hidden"><?= $word('PO_ONE', 'remove_col') ?></span></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $i => $l): ?>
<?php $no = $i + 1; $gone = $editable && isset($grid['removed'][$no]); $bad = $grid['bad'] === 'line:' . $no; if (!$gone) { $rowNo++; } ?>
        <tr data-kg-row data-kg-line="<?= $e($no) ?>"<?php if ($l['kind'] !== 'charge'): ?> data-si="<?= $e($l['supplier_item_id'] ?? '') ?>" data-sku="<?= $e($l['supplier_item_id'] === null ? $l['sku_id'] : '') ?>" data-u="<?= $e($l['supplier_item_id'] === null && (int) $l['units_per_pack'] > 1 ? $l['units_per_pack'] : '') ?>" data-name="<?= $e($l['sku_name']) ?>" data-code="<?= $e($l['sku_code']) ?>" data-pack="<?= $e($l['pack_label']) ?>" data-their="<?= $e($l['code'] ?? '') ?>"<?php endif; ?> data-kg-upp="<?= $e($l['units_per_pack']) ?>" data-kg-vat="<?= $e($l['vat_code']) ?>" data-kg-rate="<?= $e($l['vat_rate']) ?>" data-kg-e2="<?= $e(\CW\PurchaseOrders\PoMath::e2((string) $l['amount'])) ?>"<?php if ($l['kind'] === 'charge'): ?> data-kg-charge<?php endif; ?><?php if ($l['merged_into_sku_id'] !== null || ($l['si_active'] !== null && (int) $l['si_active'] !== 1) || $bad): ?> class="<?php if ($l['merged_into_sku_id'] !== null || ($l['si_active'] !== null && (int) $l['si_active'] !== 1)): ?>differs<?php endif; ?><?php if ($bad): ?> kg-bad<?php endif; ?>"<?php endif; ?><?php if ($gone): ?> hidden<?php endif; ?>>
          <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>" data-kg-no><?php if (!$gone): ?><?= $e($rowNo) ?><?php endif; ?></td>
<?php if ($l['kind'] === 'charge'): ?>
          <th scope="row" class="c-head c-item"><span class="kg-name"><?= $e($l['description'] ?? \CW\Ui\Words::ORDER['charge']) ?></span><?php if ($editable): ?><input type="hidden" name="line_<?= $e($no) ?>_packs" value="1"><?php endif; ?><?php if ($bad): ?><span class="o-sub kg-error"><?= $e($grid['badText']) ?></span><?php endif; ?></th>
          <td data-label="<?= $word('ORDER', 'their_code') ?>"></td>
          <td data-label="<?= $word('ORDER', 'pack') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" autocomplete="off" data-kg-cell="price" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
<?php else: ?>
          <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>" class="kg-name" tabindex="-1"><?= $e($l['sku_name']) ?></a> <span class="kg-code"><?= $e($l['sku_code']) ?></span><?php if ($l['merged_into_sku_id'] !== null): ?> <span class="tag blocked"><?= $word('ORDER', 'replaced') ?></span><?php elseif ($l['si_active'] !== null && (int) $l['si_active'] !== 1): ?> <span class="tag needs"><?= $word('ORDER', 'switched_off') ?></span><?php endif; ?><?php if ($bad): ?><span class="o-sub kg-error"><?= $e($grid['badText']) ?></span><?php endif; ?></th>
          <td data-label="<?= $word('ORDER', 'their_code') ?>"><?= $e($l['code']) ?></td>
          <td data-label="<?= $word('ORDER', 'pack') ?>"><?php if ($l['supplier_item_id'] === null && $editable): ?><label><?= $word('ORDER', 'items_per_pack') ?> <input type="number" name="line_<?= $e($no) ?>_upp" min="1" max="100000" value="<?= $e($typed['line_' . $no . '_upp'] ?? $l['units_per_pack']) ?>" class="short" data-kg-cell="upp"></label><?php else: ?><?= $e($l['pack_label']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"><?php if ($editable): ?><input type="number" name="line_<?= $e($no) ?>_packs" min="0" max="1000000" value="<?= $e($typed['line_' . $no . '_packs'] ?? $l['packs']) ?>" class="short" data-kg-cell="qty" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'packs') ?>"><?php else: ?><?= $n($l['packs']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" autocomplete="off" data-kg-cell="price" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
<?php endif; ?>
          <td class="num" data-label="<?= $word('ORDER', 'total') ?>" data-kg-amount><?= $e($l['net']) ?></td>
          <td class="c-status"><?php if ($editable): ?><button type="submit" name="remove_line" value="<?= $e($no) ?>" class="btn ghost po-x" data-kg-x aria-label="<?= $say('PO_ONE', 'remove', $no) ?>">&times;</button><?php if ($gone): ?><input type="hidden" name="line_<?= $e($no) ?>_remove" value="1"><?php endif; ?><?php endif; ?></td>
        </tr>
<?php if ($gone && isset($grid['swapped'][$no])): ?>
<?php $rowNo++; $sw = $grid['swapped'][$no]; ?>
<?= $partial('po_grid_line', ['r' => $sw, 'no' => $rowNo, 'bad' => $grid['bad'] === $sw['key'] ? $grid['badText'] : null, 'replace' => $no]) ?>
<?php endif; ?>
<?php endforeach; ?>
<?php foreach ($grid['newRows'] as $r): ?>
<?php $rowNo++; ?>
<?= $partial('po_grid_line', ['r' => $r, 'no' => $rowNo, 'bad' => $grid['bad'] === $r['key'] ? $grid['badText'] : null]) ?>
<?php endforeach; ?>
        <tr class="grp-add po-add-row" data-kg-empty>
          <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>" data-kg-no><?= $e($rowNo + 1) ?></td>
          <td class="c-item" colspan="3" data-label="">
            <div class="po-box-field">
              <label for="po-q" class="visually-hidden"><?= $word('PO_ONE', 'product_box') ?></label>
              <input id="po-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autocomplete="off" placeholder="<?= $word('PO_ONE', 'add_product') ?>"<?php if (!$isNew): ?> autofocus<?php endif; ?> aria-keyshortcuts="Control+Enter Meta+Enter Insert" data-po-pick data-options="<?= $e($productsUrl) ?>" data-loading="<?= $word('PO_ONE', 'loading') ?>" data-failed="<?= $word('PO_ONE', 'failed') ?>"<?php if ($canAddItem): ?> data-add="<?= $word('PO_ADD', 'new_item') ?>" data-panel="po-add-item"<?php else: ?> data-ask="<?= $e($askItem) ?>"<?php endif; ?>>
              <input type="hidden" name="pick_si" value="<?= $e($pick['si']) ?>">
              <input type="hidden" name="pick_sku" value="<?= $e($pick['sku']) ?>">
              <ul class="sm-list" id="po-q-list" role="listbox" aria-label="<?= $word('PO_ONE', 'products') ?>" data-po-list hidden><?php if ($pick['choice'] !== null): ?><li role="option" aria-selected="false" data-label="<?= $e($pick['choice']['label']) ?>" data-si="<?= $e($pick['choice']['si']) ?>" data-sku="<?= $e($pick['choice']['sku']) ?>"><span class="sm-opt-name"><?= $e($pick['choice']['name']) ?></span> <span class="sm-opt-meta"><?= $e($pick['choice']['meta']) ?></span></li><?php endif; ?></ul>
              <p class="sm-msg muted" data-po-note aria-live="polite" hidden></p>
            </div>
          </td>
          <td class="num kg-nojs" data-label="<?= $word('ORDER', 'packs_label') ?>"><label class="visually-hidden" for="po-packs"><?= $word('ORDER', 'packs_label') ?></label><input id="po-packs" type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short"></td>
          <td colspan="3" class="po-add-go kg-nojs" data-label=""><button type="submit" name="action" value="add" class="btn"><?= $word('ORDER', 'add_button') ?></button></td>
        </tr>
      </tbody>
    </table>
    </div>
    <template data-kg-row><?= $partial('po_grid_line', ['r' => null, 'no' => 0, 'bad' => null]) ?></template>
<?= $partial('doc_grid_tools', ['newItem' => $canAddItem ? 'po-add-item' : null, 'download' => $isNew ? null : '/ui/purchasing/orders/' . $doc->id . '/lines.xlsx',
    'upload' => !$isNew && !$editable ? '#po-upload' : null]) ?>
<?= $partial('doc_grid_keys', []) ?>
<?php if ($canAddItem): ?>
    <details class="card po-add" id="po-add-item" data-po-panel data-ni-url="<?= $e(\CW\Ui\Controller\PoAddNew::NEW_ITEM['po']) ?>"<?php if ($supplierFixed): ?> data-supplier="<?= $e($supplierId) ?>"<?php endif; ?><?php if ($itemOpen): ?> open<?php endif; ?>>
      <summary><?= $word('PO_ADD', 'new_item') ?></summary>
      <input type="hidden" name="new_item_key" value="<?= $e(\CW\Ui\FormOnce::newKey()) ?>" data-ni-key>
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
  </section>
<?php if ($choices !== null): ?>
  <section class="choices" aria-labelledby="choices-h">
    <h2 id="choices-h"><?= $say('ORDER', 'choose', $q) ?></h2>
    <ul class="plain">
<?php foreach ($choices as $c): ?>
      <li>
<?php if ($c['supplier_item_id'] !== null): ?>
        <button class="btn" type="submit" name="add_si" value="<?= $e($c['supplier_item_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php else: ?>
        <button class="btn" type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php endif; ?>
        <?= $e($c['name']) ?> <span class="muted"><?= $e($c['sku_code']) ?></span><?php if ($c['brand'] !== null): ?> <span class="muted"><?= $e($c['brand']) ?></span><?php endif; ?>
<?php if ($c['supplier_item_id'] !== null): ?>
        <span class="tag done"><?= $say('ORDER', 'choice_set_up', (string) ($c['supplier_code'] ?? \CW\Ui\Words::ORDER['choice_no_code']), \CW\Ui\Controller\PurchaseOrdersController::pack((string) $c['purchase_unit'], (int) $c['units_per_pack'])) ?></span>
<?php else: ?>
        <span class="tag needs"><?= $word('ORDER', 'choice_new') ?></span>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

  <section class="po-summary doc-summary kg-sec" aria-label="<?= $word('PO_ONE', 'summary') ?>" data-kg-swap-part="summary">
    <div class="card totals doc-totals kg-totals">
      <div class="kg-fields kg-totals-row">
        <div class="kg-field"><span class="kg-label"><?= $word('DOC_GRID', 'total_qty') ?></span><output class="kg-value" data-kg-total="units"><?= $n($units) ?></output></div>
        <div class="kg-field"><span class="kg-label"><?= $word('ORDER', 'products') ?></span><output class="kg-value" data-kg-total="count" data-one="<?= $word('ORDER', 'products_line_one') ?>" data-many="<?= $word('ORDER', 'products_line') ?>"><?= $say('ORDER', count($lines) === 1 ? 'products_line_one' : 'products_line', count($lines), $units) ?></output></div>
        <div class="kg-field"><span class="kg-label"><?= $word('DOC_GRID', 'total_net') ?></span><output class="kg-value" data-kg-total="net"><?= $e($totals['net']) ?></output></div>
      </div>
      <h3 class="kg-sec-title"><?= $word('DOC_GRID', 'taxes') ?></h3>
      <div class="table-wrap kg-taxes-wrap">
      <table class="stack kg-taxes">
        <thead><tr><th scope="col" class="c-no"><?= $word('DOC_GRID', 'no') ?></th><th scope="col"><?= $word('DOC_GRID', 'tax_type') ?></th><th scope="col" class="num"><?= $word('DOC_GRID', 'tax_rate') ?></th><th scope="col" class="num"><?= $word('DOC_GRID', 'tax_net') ?></th><th scope="col" class="num"><?= $word('DOC_GRID', 'tax_vat') ?></th><th scope="col" class="num"><?= $word('DOC_GRID', 'tax_total') ?></th></tr></thead>
        <tbody data-kg-vat-rows data-words="<?= $word('ORDER', 'vat_line') ?>">
<?php $taxNo = 0; ?>
<?php foreach ($totals['by_code'] as $code => $v): ?>
<?php $taxNo++; ?>
          <tr><td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>"><?= $e($taxNo) ?></td><th scope="row"><?= $say('ORDER', 'vat_line', (string) $code, $vatLabels[$code] ?? $v['rate'] . '%') ?></th><td class="num" data-label="<?= $word('DOC_GRID', 'tax_rate') ?>"><?= $e($v['rate']) ?>%</td><td class="num" data-label="<?= $word('DOC_GRID', 'tax_net') ?>"><?= $e($v['net'] ?? '') ?></td><td class="num" data-label="<?= $word('DOC_GRID', 'tax_vat') ?>"><?= $e($v['vat']) ?></td><td class="num" data-label="<?= $word('DOC_GRID', 'tax_total') ?>"><?= $e($v['total'] ?? '') ?></td></tr>
<?php endforeach; ?>
        </tbody>
        <tbody class="kg-charge-rows">
          <tr><td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>"></td><th scope="row"><label for="po-delivery"><?= $word('PO_ONE', 'delivery') ?></label></th><td class="num" data-label="<?= $word('DOC_GRID', 'tax_rate') ?>" data-kg-delivery-rate></td><td class="num" data-label="<?= $word('DOC_GRID', 'tax_net') ?>"><input id="po-delivery" type="text" name="charge_amount" inputmode="decimal" class="short" value="<?= $e($typed['charge_amount'] ?? '') ?>" data-kg-delivery></td><td class="num" data-label="<?= $word('DOC_GRID', 'tax_vat') ?>" data-kg-delivery-vat></td><td class="num" data-label="<?= $word('DOC_GRID', 'tax_total') ?>" data-kg-delivery-total></td></tr>
        </tbody>
      </table>
      </div>
      <div class="kg-grand"><span class="kg-label"><?= $word('ORDER', 'gross') ?></span><output class="kg-value"><strong data-kg-total="gross"><?= $e($totals['gross']) ?></strong></output></div>
<?php if (!$isNew): ?>
    <details class="po-cancel"<?php if ($reasonError === 'cancel'): ?> open<?php endif; ?>>
      <summary class="btn sm"><?= $word('PO_ONE', 'cancel') ?></summary>
      <label><?= $word('ORDER', 'cancel_why') ?> <select name="reason_code" form="po-cancel" required<?php if ($reasonError === 'cancel'): ?> aria-invalid="true"<?php endif; ?>>
        <option value=""><?= $word('ORDER', 'choose_reason') ?></option>
<?php foreach ($cancelReasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select></label>
      <label><?= $word('PO_ONE', 'cancel_note') ?> <input type="text" name="note" form="po-cancel" maxlength="400"></label>
      <button type="submit" form="po-cancel" class="btn risky sm"><?= $word('ORDER', 'cancel_draft_button') ?></button>
    </details>
<?php endif; ?>
    </div>
  </section>
  <details class="kg-sec kg-more" data-kg-swap-part="more">
    <summary class="kg-sec-title"><?= $word('DOC_GRID', 'more_info') ?></summary>
    <dl class="kg-fields kg-read">
      <div class="kg-field"><dt><?= $word('DOC_GRID', 'our_number') ?></dt><dd><?= $word('DOC_GRID', 'number_later') ?></dd></div>
      <div class="kg-field"><dt><?= $word('ORDER', 'order_date') ?></dt><dd><?= $day($info['doc_date']) ?></dd></div>
      <div class="kg-field"><dt><?= $word('DOC_GRID', 'status') ?></dt><dd><?php if ($isNew): ?><?= $word('DOC_GRID', 'not_saved') ?><?php else: ?><?= $word('PO_STATE', 'draft') ?><?php endif; ?></dd></div>
<?php if (!$isNew): ?>
      <div class="kg-field"><dt><?= $word('DOC_GRID', 'made') ?></dt><dd><?= $when($doc->createdAt) ?></dd></div>
<?php endif; ?>
    </dl>
  </details>
</form>
<?php if (!$isNew): ?>
<form id="po-cancel" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/cancel') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
</form>
<?php endif; ?>
<?php if (!$isNew && !$editable): ?>

<form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines/import') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="mode" value="replace">
  <p><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>" download><?= $word('ORDER', 'download_xlsx') ?></a></p>
  <label><?= $say('ORDER', 'file_label', $maxMb, $maxRows) ?> <input type="file" name="file" required></label>
  <p class="actions"><button type="submit" class="btn"><?= $word('ORDER', 'import') ?></button></p>
</form>
<?php endif; ?>
