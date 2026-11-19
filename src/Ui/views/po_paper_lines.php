<?php
// The order's paper, its lines (docs/decisions.md RD-B4, KG10, KG34; one look, LP1, LP4, LP5): a No. column, the item (its name, its CW
// code, then their code, the pack and the VAT on the line under it), its current stock, Ordered, Received (from the confirmation on: a draft
// has received nothing), the unit cost and the line total. Each line is po_paper_line.
// Read only ($edit false): the lines as words; none: "No products yet."
// On a draft or a new order ($edit, inside the editor form): the lines' grid of docs/decisions.md KG (app.js's one engine; its contract in
// docs/dev.md): the table's data-kg-* (the limit, the remove field, the VAT and its rates, the words of doc_grid_words), each line's boxes in
// place (packs, the pack's price, items per pack for an item not set up with the supplier), its x; the empty line last ("+ Add product", the
// product box with the site's one dropdown; its packs and Add without the script); the new line's <template>; then the tools (Add row, Add
// multiple, + New item, Download; Upload when the order is too long for the page) and the key hints with Undo. $editable false: the lines as
// words (too many for one form: PurchaseOrdersController::editorFits), changed by the file.
// Who is here (step 3b, docs/decisions.md LV18): each line names its own id (data-uid; a new line not saved yet has none) and each box its
// place (data-live-cell: item, qty, price, upp), where app.js draws another person's coloured edge, outline and name tag; read only, only on a
// draft that shows who is here ($marks).
$showReceived = !$edit && $doc !== null && $doc->postedAt !== null;
$marks = $marks ?? false;
$liveSave = $liveSave ?? false; // each box saved by itself (LS10): each saved line's version and who changed it
?>
<?php if (!$edit): ?>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack po-lines doc-lines kg-view">
    <thead>
      <tr>
        <th scope="col" class="c-no"><?= $word('DOC_GRID', 'no') ?></th>
        <th scope="col"><?= $word('ORDER', 'col_item') ?></th>
        <th scope="col" class="num c-stock"><?= $word('DOC_GRID', 'stock') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'col_ordered') ?></th>
<?php if ($showReceived): ?>
        <th scope="col" class="num"><?= $word('ORDER', 'col_received') ?></th>
<?php endif; ?>
        <th scope="col" class="num"><?= $word('ORDER', 'col_cost') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'col_line_total') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
<?= $partial('po_paper_line', ['edit' => false, 'l' => $l, 'state' => $state, 'stock' => $stock, 'showReceived' => $showReceived, 'marks' => $marks]) ?>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php else: ?>
<?php $rowNo = 0; ?>
    <div class="table-wrap">
    <table class="stack list board po-lines doc-lines kg-grid" data-kg-grid data-kg-max="<?= $e($grid['max']) ?>" data-kg-remove="line_%d_remove" data-kg-vat="<?= $e($gridVat['code']) ?>" data-kg-rates="<?= $e(json_encode($gridVat['rates'], JSON_UNESCAPED_UNICODE)) ?>" data-kg-max-qty="<?= $e(\CW\PurchaseOrders\PoMath::MAX_PACKS) ?>" data-kg-min-qty="0"<?= $partial('doc_grid_words', []) ?>>
      <thead>
        <tr>
          <th scope="col" class="c-no"><input type="checkbox" class="kg-tick" data-kg-tick-all data-kg-js hidden tabindex="-1" aria-label="<?= $word('DOC_GRID', 'tick_all') ?>"><?= $word('DOC_GRID', 'no') ?></th>
          <th scope="col" class="c-item"><?= $word('ORDER', 'col_item') ?></th>
          <th scope="col" class="num c-stock"><?= $word('DOC_GRID', 'stock') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'col_ordered') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'col_cost') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'col_line_total') ?></th>
          <th scope="col" class="c-x"><span class="visually-hidden"><?= $word('PO_ONE', 'remove_col') ?></span></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $i => $l): ?>
<?php $no = $i + 1; $gone = $editable && isset($grid['removed'][$no]); $bad = $grid['bad'] === 'line:' . $no; if (!$gone) { $rowNo++; } ?>
<?= $partial('po_paper_line', ['edit' => true, 'l' => $l, 'no' => $no, 'rowNo' => $rowNo, 'gone' => $gone, 'bad' => $bad ? $grid['badText'] : null, 'editable' => $editable,
    'typed' => $typed, 'stock' => $stock, 'liveSave' => $liveSave]) ?>
<?php if ($gone && isset($grid['swapped'][$no])): ?>
<?php $rowNo++; $sw = $grid['swapped'][$no]; ?>
<?= $partial('po_paper_line', ['edit' => true, 'r' => $sw, 'no' => $rowNo, 'bad' => $grid['bad'] === $sw['key'] ? $grid['badText'] : null, 'replace' => $no,
    'takesUid' => $l['uid'] ?? null]) ?>
<?php endif; ?>
<?php endforeach; ?>
<?php foreach ($grid['newRows'] as $r): ?>
<?php $rowNo++; ?>
<?= $partial('po_paper_line', ['edit' => true, 'r' => $r, 'no' => $rowNo, 'bad' => $grid['bad'] === $r['key'] ? $grid['badText'] : null]) ?>
<?php endforeach; ?>
        <tr class="grp-add po-add-row" data-kg-empty>
          <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>" data-kg-no><?= $e($rowNo + 1) ?></td>
          <td class="c-item" colspan="2" data-label="">
            <div class="po-box-field">
              <label for="po-q" class="visually-hidden"><?= $word('PO_ONE', 'product_box') ?></label>
              <input id="po-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autocomplete="off" class="kg-flat" placeholder="<?= $word('PO_ONE', 'add_product') ?>"<?php if (!$isNew): ?> autofocus<?php endif; ?> aria-keyshortcuts="Control+Enter Meta+Enter Insert" data-po-pick data-options="<?= $e($productsUrl) ?>" data-loading="<?= $word('PO_ONE', 'loading') ?>" data-failed="<?= $word('PO_ONE', 'failed') ?>"<?php if ($canAddItem): ?> data-add="<?= $word('PO_ADD', 'new_item') ?>" data-panel="po-add-item"<?php else: ?> data-ask="<?= $e($askItem) ?>"<?php endif; ?>>
              <input type="hidden" name="pick_si" value="<?= $e($pick['si']) ?>">
              <input type="hidden" name="pick_sku" value="<?= $e($pick['sku']) ?>">
              <ul class="sm-list" id="po-q-list" role="listbox" aria-label="<?= $word('PO_ONE', 'products') ?>" data-po-list hidden><?php if ($pick['choice'] !== null): ?><li role="option" aria-selected="false" data-label="<?= $e($pick['choice']['label']) ?>" data-si="<?= $e($pick['choice']['si']) ?>" data-sku="<?= $e($pick['choice']['sku']) ?>"><span class="sm-opt-name"><?= $e($pick['choice']['name']) ?></span> <span class="sm-opt-meta"><?= $e($pick['choice']['meta']) ?></span></li><?php endif; ?></ul>
              <p class="sm-msg muted" data-po-note aria-live="polite" hidden></p>
            </div>
          </td>
          <td class="num kg-nojs" data-label="<?= $word('ORDER', 'packs_label') ?>"><label class="visually-hidden" for="po-packs"><?= $word('ORDER', 'packs_label') ?></label><input id="po-packs" type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short kg-flat"></td>
          <td colspan="3" class="po-add-go kg-nojs" data-label=""><button type="submit" name="action" value="add" class="btn"><?= $word('ORDER', 'add_button') ?></button></td>
        </tr>
      </tbody>
    </table>
    </div>
    <template data-kg-row><?= $partial('po_paper_line', ['edit' => true, 'r' => null, 'no' => 0, 'bad' => null]) ?></template>
<?= $partial('doc_grid_tools', ['newItem' => $canAddItem ? 'po-add-item' : null, 'download' => $isNew ? null : '/ui/purchasing/orders/' . $doc->id . '/lines.xlsx',
    'upload' => !$isNew && !$editable ? '#po-upload' : null]) ?>
<?= $partial('doc_grid_keys', []) ?>
<?php endif; ?>
