<?php
// The purchase order on ONE page (docs/decisions.md PX1-PX10; the owner, 26 Oct 2026: one view, "add new" in the dropdowns, monday.com's
// look, nothing over complicated). A new order (no draft yet: $isNew, the form posts to the list's address with a FormOnce key) or its
// creator's draft (the form posts to /lines with its version first). One form: the supplier, the board of products (each row edited in
// place with its remove; the last row is "+ Add product", the scan box), the delivery charge and the totals in one summary, Save and Confirm.
// Enter is Add (the form's first button). The supplier is a plain select that app.js makes a box with the site's one dropdown (SM11),
// ending with "+ Add new supplier"; the scan box gets the same dropdown, ending with "+ Add new item". Both small forms are fields of this
// form, each with its own button: a fold without the script, opened by the dropdown's last line with it. A person who may not add one
// sees the line naming whom to ask instead. A saved draft has "Cancel draft" beside Save and Confirm (PX11): one small step of its own form
// (its controls name the form po-cancel, drawn after this one, as forms do not nest), the existing cancel route.
?>
<p class="crumbs"><a href="/ui/purchasing/orders"><?= $word('MENU', 'orders') ?></a></p>
<?= $head((string) $title, intro: $isNew ? 'order_new' : 'order_draft', meta: $isNew ? '' : $stateChip('PO_STATE', 'draft')) ?>
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

<form class="po-editor" method="post" action="<?= $u($formAction) ?>" novalidate>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if (!$isNew): ?>
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="line_count" value="<?= $e($editable ? count($lines) : 0) ?>">
  <input type="hidden" name="lines_editable" value="<?php if ($editable): ?>1<?php else: ?>0<?php endif; ?>">
<?php endif; ?>
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <button type="submit" name="action" value="add" class="po-default" tabindex="-1" aria-hidden="true"><?= $word('ORDER', 'add_button') ?></button>
  <fieldset class="po-header">
    <legend class="visually-hidden"><?= $word('ORDER', 'order') ?></legend>
<?php if ($supplierFixed): ?>
    <p class="po-fixed"><?= $word('ORDER', 'supplier') ?>: <a href="<?= $u('/ui/purchasing/suppliers/' . ($supplier['id'] ?? 0)) ?>"><?= $e($supplierName) ?></a></p>
<?php else: ?>
    <div class="po-box-field">
      <label for="po-supplier"><?= $word('ORDER', 'supplier') ?></label>
      <select name="supplier_id" id="po-supplier" data-po-supplier data-placeholder="<?= $word('PO_ONE', 'supplier_hint') ?>" data-label="<?= $word('PO_ONE', 'suppliers') ?>"<?php if ($canAddSupplier): ?> data-add="<?= $word('PO_ADD', 'new_supplier') ?>" data-panel="po-add-supplier"<?php else: ?> data-ask="<?= $e($askSupplier) ?>"<?php endif; ?>>
        <option value=""><?= $word('ORDERS', 'choose_supplier') ?></option>
<?php foreach ($supplierChoices as $s): ?>
        <option value="<?= $e($s['id']) ?>" data-name="<?= $e($s['name']) ?>" data-meta="<?= $e($s['meta']) ?>"<?php if ($s['id'] === $supplierId): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
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
        <button type="submit" name="action" value="new_supplier" class="btn secondary"><?= $word('PO_ADD', 'supplier_button') ?></button>
      </details>
<?php else: ?>
      <p class="hint po-ask"><?= $e($askSupplier) ?></p>
<?php endif; ?>
    </div>
<?php endif; ?>
    <label><?= $word('ORDER', 'expected') ?> <input type="date" name="expected_date" value="<?= $e($info['expected_date']) ?>"></label>
    <label><?= $word('ORDER', 'ref') ?> <input type="text" name="external_ref" maxlength="191" value="<?= $e($info['external_ref']) ?>"></label>
    <label><?= $word('ORDER', 'note') ?> <input type="text" name="note" maxlength="1000" value="<?= $e($info['note']) ?>"></label>
  </fieldset>

  <section class="po-board" id="scan" aria-labelledby="lines-h">
    <div class="board-head"><h2 id="lines-h" class="board-sub"><?= $word('ORDER', 'lines') ?></h2></div>
<?php if (!$editable): ?>
    <p class="note"><?= $say('ORDER', 'too_many', $maxLines) ?></p>
<?php endif; ?>
    <div class="table-wrap">
    <table class="stack list board po-lines">
      <thead>
        <tr>
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
<?php $no = $i + 1; ?>
        <tr<?php if ($l['merged_into_sku_id'] !== null || ($l['si_active'] !== null && (int) $l['si_active'] !== 1)): ?> class="differs"<?php endif; ?>>
<?php if ($l['kind'] === 'charge'): ?>
          <th scope="row" class="c-head c-item"><?= $e($l['description'] ?? \CW\Ui\Words::ORDER['charge']) ?><?php if ($editable): ?><input type="hidden" name="line_<?= $e($no) ?>_packs" value="1"><?php endif; ?></th>
          <td data-label="<?= $word('ORDER', 'their_code') ?>"></td>
          <td data-label="<?= $word('ORDER', 'pack') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
<?php else: ?>
          <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a> <span class="o-sub"><?= $e($l['sku_code']) ?></span><?php if ($l['merged_into_sku_id'] !== null): ?> <span class="tag bad"><?= $word('ORDER', 'replaced') ?></span><?php elseif ($l['si_active'] !== null && (int) $l['si_active'] !== 1): ?> <span class="tag warn"><?= $word('ORDER', 'switched_off') ?></span><?php endif; ?></th>
          <td data-label="<?= $word('ORDER', 'their_code') ?>"><?= $e($l['code']) ?></td>
          <td data-label="<?= $word('ORDER', 'pack') ?>"><?php if ($l['supplier_item_id'] === null && $editable): ?><label><?= $word('ORDER', 'items_per_pack') ?> <input type="number" name="line_<?= $e($no) ?>_upp" min="1" max="100000" value="<?= $e($typed['line_' . $no . '_upp'] ?? $l['units_per_pack']) ?>" class="short"></label><?php else: ?><?= $e($l['pack_label']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"><?php if ($editable): ?><input type="number" name="line_<?= $e($no) ?>_packs" min="0" max="1000000" value="<?= $e($typed['line_' . $no . '_packs'] ?? $l['packs']) ?>" class="short" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'packs') ?>"><?php else: ?><?= $n($l['packs']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
<?php endif; ?>
          <td class="num" data-label="<?= $word('ORDER', 'total') ?>"><?= $e($l['net']) ?></td>
          <td class="c-status"><?php if ($editable): ?><button type="submit" name="remove_line" value="<?= $e($no) ?>" class="btn ghost po-x" aria-label="<?= $say('PO_ONE', 'remove', $no) ?>">&times;</button><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
        <tr class="grp-add po-add-row">
          <td colspan="7" data-label="">
            <div class="po-add-line">
              <div class="po-box-field">
                <label for="po-q" class="visually-hidden"><?= $word('PO_ONE', 'product_box') ?></label>
                <input id="po-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autocomplete="off" placeholder="<?= $word('PO_ONE', 'add_product') ?>"<?php if (!$isNew): ?> autofocus<?php endif; ?> data-po-pick data-options="<?= $e($productsUrl) ?>" data-loading="<?= $word('PO_ONE', 'loading') ?>" data-failed="<?= $word('PO_ONE', 'failed') ?>"<?php if ($canAddItem): ?> data-add="<?= $word('PO_ADD', 'new_item') ?>" data-panel="po-add-item"<?php else: ?> data-ask="<?= $e($askItem) ?>"<?php endif; ?>>
                <input type="hidden" name="pick_si" value="<?= $e($pick['si']) ?>">
                <input type="hidden" name="pick_sku" value="<?= $e($pick['sku']) ?>">
                <ul class="sm-list" id="po-q-list" role="listbox" aria-label="<?= $word('PO_ONE', 'products') ?>" data-po-list hidden><?php if ($pick['choice'] !== null): ?><li role="option" aria-selected="false" data-label="<?= $e($pick['choice']['label']) ?>" data-si="<?= $e($pick['choice']['si']) ?>" data-sku="<?= $e($pick['choice']['sku']) ?>"><span class="sm-opt-name"><?= $e($pick['choice']['name']) ?></span> <span class="sm-opt-meta"><?= $e($pick['choice']['meta']) ?></span></li><?php endif; ?></ul>
                <p class="sm-msg muted" data-po-note aria-live="polite" hidden></p>
              </div>
              <label class="po-packs"><?= $word('ORDER', 'packs_label') ?> <input type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short"></label>
              <button type="submit" name="action" value="add" class="btn secondary"><?= $word('ORDER', 'add_button') ?></button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
    </div>
<?php if ($canAddItem): ?>
    <details class="card po-add" id="po-add-item" data-po-panel<?php if ($itemOpen): ?> open<?php endif; ?>>
      <summary><?= $word('PO_ADD', 'new_item') ?></summary>
<?php if ($itemError !== null): ?>
      <p class="error" role="alert"><?= $e($itemError) ?></p>
<?php endif; ?>
<?php if ($itemOther !== null): ?>
      <p class="note"><?= $say('PO_ADD', 'barcode_other', $itemOther['label']) ?> <button type="submit" name="add_sku" value="<?= $e($itemOther['id']) ?>" class="btn secondary"><?= $word('PO_ADD', 'barcode_use') ?></button></p>
<?php endif; ?>
<?php if ($itemSame !== null): ?>
      <div class="note same-items">
        <p><strong><?= $word('PO_ADD', 'same_title') ?></strong> <?= $word('PO_ADD', 'same_text') ?></p>
        <ul class="plain">
<?php foreach ($itemSame as $s): ?>
          <li><a href="<?= $u('/ui/items/' . $s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?> <button type="submit" name="add_sku" value="<?= $e($s['id']) ?>" class="btn secondary"><?= $word('PO_ADD', 'same_use') ?></button></li>
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
      <button type="submit" name="action" value="new_item" class="btn secondary"><?= $word('PO_ADD', 'item_button') ?></button>
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
        <button type="submit" name="add_si" value="<?= $e($c['supplier_item_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php else: ?>
        <button type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php endif; ?>
        <?= $e($c['name']) ?> <span class="muted"><?= $e($c['sku_code']) ?></span><?php if ($c['brand'] !== null): ?> <span class="muted"><?= $e($c['brand']) ?></span><?php endif; ?>
<?php if ($c['supplier_item_id'] !== null): ?>
        <span class="tag ok"><?= $say('ORDER', 'choice_set_up', (string) ($c['supplier_code'] ?? \CW\Ui\Words::ORDER['choice_no_code']), \CW\Ui\Controller\PurchaseOrdersController::pack((string) $c['purchase_unit'], (int) $c['units_per_pack'])) ?></span>
<?php else: ?>
        <span class="tag warn"><?= $word('ORDER', 'choice_new') ?></span>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

  <section class="card totals po-summary" aria-label="<?= $word('PO_ONE', 'summary') ?>">
    <dl>
      <dt><?= $word('ORDER', 'products') ?></dt><dd><?= $say('ORDER', count($lines) === 1 ? 'products_line_one' : 'products_line', count($lines), $units) ?></dd>
      <dt><label for="po-delivery"><?= $word('PO_ONE', 'delivery') ?></label></dt><dd><input id="po-delivery" type="text" name="charge_amount" inputmode="decimal" class="short" value="<?= $e($typed['charge_amount'] ?? '') ?>"></dd>
      <dt><?= $word('ORDER', 'net') ?></dt><dd><?= $e($totals['net']) ?></dd>
<?php foreach ($totals['by_code'] as $code => $v): ?>
      <dt><?= $say('ORDER', 'vat_line', (string) $code, $vatLabels[$code] ?? $v['rate'] . '%') ?></dt><dd><?= $e($v['vat']) ?></dd>
<?php endforeach; ?>
      <dt><?= $word('ORDER', 'gross') ?></dt><dd><strong><?= $e($totals['gross']) ?></strong></dd>
    </dl>
    <div class="actions">
      <button type="submit" name="action" value="save" class="btn secondary"><?= $word('PO_ONE', 'save') ?></button>
      <button type="submit" name="action" value="approve" class="btn primary"><?php if ($overLimit): ?><?= $word('PO', 'confirm_over_limit') ?><?php else: ?><?= $word('PO_ONE', 'confirm') ?><?php endif; ?></button>
    </div>
<?php if (!$isNew): ?>
    <details class="po-cancel"<?php if ($reasonError === 'cancel'): ?> open<?php endif; ?>>
      <summary class="btn secondary sm"><?= $word('PO_ONE', 'cancel') ?></summary>
      <label><?= $word('ORDER', 'cancel_why') ?> <select name="reason_code" form="po-cancel" required<?php if ($reasonError === 'cancel'): ?> aria-invalid="true"<?php endif; ?>>
        <option value=""><?= $word('ORDER', 'choose_reason') ?></option>
<?php foreach ($cancelReasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select></label>
      <label><?= $word('PO_ONE', 'cancel_note') ?> <input type="text" name="note" form="po-cancel" maxlength="400"></label>
      <button type="submit" form="po-cancel" class="btn danger-outline sm"><?= $word('ORDER', 'cancel_draft_button') ?></button>
    </details>
<?php endif; ?>
  </section>
</form>
<?php if (!$isNew): ?>
<form id="po-cancel" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/cancel') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
</form>
<?php endif; ?>
<?php if (!$isNew && !$editable): ?>

<form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines/import') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="mode" value="replace">
  <p><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>"><?= $word('ORDER', 'download_xlsx') ?></a></p>
  <label><?= $say('ORDER', 'file_label', $maxMb, $maxRows) ?> <input type="file" name="file" required></label>
  <p class="actions"><button type="submit" class="btn secondary"><?= $word('ORDER', 'import') ?></button></p>
</form>
<?php endif; ?>
