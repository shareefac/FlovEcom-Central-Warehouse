<?php
// One line of the order's paper (docs/decisions.md RD-B4; one look, LP1, LP4, LP5): its number, the item (read only: the name and its CW code,
// their code, the pack and the VAT under it; on a draft: the name on its own line, then ONE sub-line "CW-014280 · their code · box of 10 ·
// VAT S", then items per pack: LP9), its current stock (doc_stock), Ordered, Received (shown from the confirmation on), the unit
// cost and the line total.
// Read only ($edit false; $l: the line, $showReceived): as words, the item's ONE sub-line as on a draft ("CW-007523 · their code · each ·
// VAT S": the dots are app.css's, LP10); Ordered in items with "3 × box of 10" under it, the unit cost per pack.
// On a draft or a new order ($edit): a row of the lines' grid (docs/decisions.md KG; app.js's contract: data-kg-*), its figures in boxes in
// place (kg-flat: plain text until the mouse is on one or the cursor is in it), each with its label ("Line 3: Packs"):
//  - a saved line ($l; $no, $rowNo, $gone: removed and not sent yet, $bad: its refusal, $editable, $typed): packs ("3 × box of 10 = 30"
//    under them, worked out again by app.js as typed: data-kg-units), the pack's price ("per box of 10", data-kg-per, as the item changes), items per pack on the item's line
//    for an item not set up with the supplier, the x (a submit without the script); a charge: its price;
//  - a new line ($r: DocGrid::rows(), or null for the <template> app.js copies; $replace: the saved line a changed item takes the place of,
//    KG22, KG32; $bad): its boxes have no name, app.js sends the new lines in one field (DocGrid::FIELD).
$l = $l ?? null;
$r = $r ?? null;
$bad = $bad ?? null;
$replace = $replace ?? null;
?>
<?php if (!$edit): ?>
      <tr>
        <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>"><?= $e($l['line_no']) ?></td>
<?php if ($l['kind'] === 'charge'): ?>
        <th scope="row" class="c-head"><span class="o-name"><?= $e($l['description'] ?? \CW\Ui\Words::ORDER['charge']) ?></span> <span class="o-sub"><?= $word('ORDER', 'charge') ?> · <?= $e($l['vat_code']) ?></span></th>
<?= $partial('doc_stock', ['st' => null]) ?>
        <td class="num" data-label="<?= $word('ORDER', 'col_ordered') ?>"></td>
<?php if ($showReceived): ?>
        <td class="num" data-label="<?= $word('ORDER', 'col_received') ?>"></td>
<?php endif; ?>
<?php else: ?>
<?php $toCome = in_array($state, ['approved', 'sent', 'part_received'], true) ? max(0, (int) $l['qty'] - (int) $l['received_units']) : 0; ?>
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a><span class="o-sub kg-sub"><span class="kg-code"><?= $e($l['sku_code']) ?></span><span><?= $e($l['code'] ?? '') ?></span><span><?= $e($l['pack_label']) ?></span><span><?= $say('ORDER', 'vat_code', (string) $l['vat_code']) ?></span></span><?php if (($l['description'] ?? null) !== null && $l['description'] !== ''): ?><span class="o-sub"><?= $e($l['description']) ?></span><?php endif; ?></th>
<?= $partial('doc_stock', ['st' => ($stock ?? [])[(int) $l['sku_id']] ?? null]) ?>
        <td class="num" data-label="<?= $word('ORDER', 'col_ordered') ?>"><?= $n($l['qty']) ?><?php if ((int) $l['units_per_pack'] > 1): ?><span class="o-sub"><?= $say('ORDER', 'packs_of', \CW\Ui\Html::int($l['packs']), (string) $l['pack_label']) ?></span><?php endif; ?></td>
<?php if ($showReceived): ?>
        <td class="num" data-label="<?= $word('ORDER', 'col_received') ?>"><span class="po-got<?php if ((int) $l['received_units'] >= (int) $l['qty']): ?> done<?php elseif ((int) $l['received_units'] > 0): ?> part<?php endif; ?>"><?= $n($l['received_units']) ?></span><?php if ($toCome > 0 && (int) $l['received_units'] > 0): ?> <?= $chip('needs', \CW\Ui\Words::say('ORDER', 'to_come_pill', \CW\Ui\Html::int($toCome))) ?><?php endif; ?></td>
<?php endif; ?>
<?php endif; ?>
        <td class="num" data-label="<?= $word('ORDER', 'col_cost') ?>">£<?= $e($l['price']) ?><?php if ($l['kind'] !== 'charge' && (int) $l['units_per_pack'] > 1): ?><span class="o-sub"><?= $say('ORDER', 'per', (string) $l['pack_label']) ?></span><?php endif; ?></td>
        <td class="num strong" data-label="<?= $word('ORDER', 'col_line_total') ?>"><?= $e($l['net']) ?></td>
      </tr>
<?php elseif ($l !== null): ?>
<?php
$off = $l['merged_into_sku_id'] !== null || ($l['si_active'] !== null && (int) $l['si_active'] !== 1);
$upp = (int) $l['units_per_pack'];
$packsNow = (string) ($typed['line_' . $no . '_packs'] ?? $l['packs']);
?>
        <tr data-kg-row data-kg-line="<?= $e($no) ?>"<?php if ($l['kind'] !== 'charge'): ?> data-si="<?= $e($l['supplier_item_id'] ?? '') ?>" data-sku="<?= $e($l['supplier_item_id'] === null ? $l['sku_id'] : '') ?>" data-u="<?= $e($l['supplier_item_id'] === null && $upp > 1 ? $upp : '') ?>" data-name="<?= $e($l['sku_name']) ?>" data-code="<?= $e($l['sku_code']) ?>" data-pack="<?= $e($l['pack_label']) ?>" data-their="<?= $e($l['code'] ?? '') ?>" data-stock="<?= $e(($stock ?? [])[(int) $l['sku_id']]['on_hand'] ?? '') ?>" data-coming="<?= $e(($stock ?? [])[(int) $l['sku_id']]['coming'] ?? '') ?>"<?php if ($l['supplier_item_id'] === null): ?> data-kg-pack-of="<?= $say('SUPPLIER_ITEMS', 'pack_of', (string) $l['purchase_unit'], '%s') ?>"<?php endif; ?><?php endif; ?> data-kg-upp="<?= $e($upp) ?>" data-kg-vat="<?= $e($l['vat_code']) ?>" data-kg-rate="<?= $e($l['vat_rate']) ?>" data-kg-e2="<?= $e(\CW\PurchaseOrders\PoMath::e2((string) $l['amount'])) ?>"<?php if ($l['kind'] === 'charge'): ?> data-kg-charge<?php endif; ?><?php if ($off || $bad !== null): ?> class="<?php if ($off): ?>differs<?php endif; ?><?php if ($bad !== null): ?> kg-bad<?php endif; ?>"<?php endif; ?><?php if ($gone): ?> hidden<?php endif; ?>>
          <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>" data-kg-no><?php if (!$gone): ?><?= $e($rowNo) ?><?php endif; ?></td>
<?php if ($l['kind'] === 'charge'): ?>
          <th scope="row" class="c-head c-item"><span class="kg-name o-name"><?= $e($l['description'] ?? \CW\Ui\Words::ORDER['charge']) ?></span> <span class="o-sub"><?= $word('ORDER', 'charge') ?> · <?= $word('ORDER', 'vat') ?> <?= $e($l['vat_code']) ?></span><?php if ($editable): ?><input type="hidden" name="line_<?= $e($no) ?>_packs" value="1"><?php endif; ?><?php if ($bad !== null): ?><span class="o-sub kg-error"><?= $e($bad) ?></span><?php endif; ?></th>
<?= $partial('doc_stock', ['st' => null]) ?>
          <td class="num" data-label="<?= $word('ORDER', 'col_ordered') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'col_cost') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short kg-flat" inputmode="decimal" autocomplete="off" data-kg-cell="price" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?>£<?= $e($l['price']) ?><?php endif; ?></td>
<?php else: ?>
          <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>" class="kg-name o-name" tabindex="-1" title="<?= $e($l['sku_name']) ?>"><?= $e($l['sku_name']) ?></a><?php if ($l['merged_into_sku_id'] !== null): ?> <span class="tag blocked"><?= $word('ORDER', 'replaced') ?></span><?php elseif ($l['si_active'] !== null && (int) $l['si_active'] !== 1): ?> <span class="tag needs"><?= $word('ORDER', 'switched_off') ?></span><?php endif; ?><span class="o-sub kg-sub"><span class="kg-code"><?= $e($l['sku_code']) ?></span><span data-kg-their><?= $e($l['code'] ?? '') ?></span><span data-kg-pack><?= $e($l['pack_label']) ?></span><span class="kg-vat-code" data-kg-vat-code data-words="<?= $word('ORDER', 'vat_code') ?>"><?= $say('ORDER', 'vat_code', (string) $l['vat_code']) ?></span></span><?php if ($l['supplier_item_id'] === null && $editable): ?><label class="o-sub kg-upp"><?= $word('ORDER', 'items_per_pack') ?> <input type="number" name="line_<?= $e($no) ?>_upp" min="1" max="100000" value="<?= $e($typed['line_' . $no . '_upp'] ?? $upp) ?>" class="short kg-flat" data-kg-cell="upp"></label><?php endif; ?><?php if ($bad !== null): ?><span class="o-sub kg-error"><?= $e($bad) ?></span><?php endif; ?></th>
<?= $partial('doc_stock', ['st' => ($stock ?? [])[(int) $l['sku_id']] ?? null]) ?>
          <td class="num" data-label="<?= $word('ORDER', 'col_ordered') ?>"><?php if ($editable): ?><input type="number" name="line_<?= $e($no) ?>_packs" min="0" max="1000000" value="<?= $e($packsNow) ?>" class="short kg-flat" data-kg-cell="qty" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'packs') ?>"><span class="o-sub kg-units" data-kg-units data-words="<?= $word('ORDER', 'packs_units') ?>"><?php if ($upp > 1 && preg_match('/^[0-9]{1,7}$/', $packsNow) === 1): ?><?= $say('ORDER', 'packs_units', \CW\Ui\Html::int($packsNow), (string) $l['pack_label'], \CW\Ui\Html::int((int) $packsNow * $upp)) ?><?php endif; ?></span><?php else: ?><?= $n($l['qty']) ?><?php if ($upp > 1): ?><span class="o-sub"><?= $say('ORDER', 'packs_of', \CW\Ui\Html::int($l['packs']), (string) $l['pack_label']) ?></span><?php endif; ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'col_cost') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short kg-flat" inputmode="decimal" autocomplete="off" data-kg-cell="price" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?>£<?= $e($l['price']) ?><?php endif; ?><span class="o-sub" data-kg-per data-words="<?= $word('ORDER', 'per') ?>"><?php if ($upp > 1): ?><?= $say('ORDER', 'per', (string) $l['pack_label']) ?><?php endif; ?></span></td>
<?php endif; ?>
          <td class="num strong" data-label="<?= $word('ORDER', 'col_line_total') ?>" data-kg-amount><?= $e($l['net']) ?></td>
          <td class="c-status"><?php if ($editable): ?><button type="submit" name="remove_line" value="<?= $e($no) ?>" class="btn ghost po-x" data-kg-x aria-label="<?= $say('PO_ONE', 'remove', $no) ?>">&times;</button><?php if ($gone): ?><input type="hidden" name="line_<?= $e($no) ?>_remove" value="1"><?php endif; ?><?php endif; ?></td>
        </tr>
<?php else: ?>
        <tr data-kg-row data-kg-new<?php if ($r !== null): ?> data-si="<?= $e($r['si']) ?>" data-sku="<?= $e($r['sku']) ?>" data-u="<?= $e($r['u']) ?>" data-kg-upp="<?= $e($r['upp']) ?>" data-name="<?= $e($r['name']) ?>" data-code="<?= $e($r['code']) ?>" data-pack="<?= $e($r['pack']) ?>" data-their="<?= $e($r['their']) ?>" data-stock="<?= $e($r['stock']['on_hand'] ?? '') ?>" data-coming="<?= $e($r['stock']['coming'] ?? '') ?>"<?php endif; ?><?php if ($replace !== null): ?> data-kg-replace="<?= $e($replace) ?>"<?php endif; ?><?php if ($bad !== null): ?> class="kg-bad"<?php endif; ?>>
          <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>" data-kg-no><?php if ($r !== null): ?><?= $e($no) ?><?php endif; ?></td>
          <th scope="row" class="c-head c-item"><span class="kg-name o-name" data-kg-name><?= $e($r['name'] ?? '') ?></span><span class="o-sub kg-sub"><span class="kg-code" data-kg-code><?= $e($r['code'] ?? '') ?></span><span data-kg-their><?= $e($r['their'] ?? '') ?></span><span data-kg-pack><?= $e($r['pack'] ?? '') ?></span></span><?php if ($bad !== null): ?><span class="o-sub kg-error"><?= $e($bad) ?></span><?php endif; ?></th>
<?= $partial('doc_stock', ['st' => $r['stock'] ?? null]) ?>
          <td class="num" data-label="<?= $word('ORDER', 'col_ordered') ?>"><input type="text" value="<?= $e($r['q'] ?? '1') ?>" class="short kg-flat" inputmode="numeric" maxlength="7" autocomplete="off" data-kg-cell="qty" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $word('ORDER', 'packs') ?>"<?php if ($bad !== null): ?> aria-invalid="true"<?php endif; ?>><span class="o-sub kg-units" data-kg-units data-words="<?= $word('ORDER', 'packs_units') ?>"><?php if ($r !== null && (int) $r['upp'] > 1 && preg_match('/^[0-9]{1,7}$/', (string) $r['q']) === 1): ?><?= $say('ORDER', 'packs_units', \CW\Ui\Html::int($r['q']), (string) $r['pack'], \CW\Ui\Html::int((int) $r['q'] * (int) $r['upp'])) ?><?php endif; ?></span></td>
          <td class="num" data-label="<?= $word('ORDER', 'col_cost') ?>"><input type="text" value="<?= $e($r['p'] ?? '') ?>" class="short kg-flat" inputmode="decimal" maxlength="16" autocomplete="off" data-kg-cell="price" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $word('ORDER', 'per_pack') ?>"><span class="o-sub" data-kg-per data-words="<?= $word('ORDER', 'per') ?>"><?php if ($r !== null && (int) $r['upp'] > 1 && $r['pack'] !== ''): ?><?= $say('ORDER', 'per', (string) $r['pack']) ?><?php endif; ?></span></td>
          <td class="num strong" data-label="<?= $word('ORDER', 'col_line_total') ?>" data-kg-amount><?= $e($r['amount'] ?? '') ?></td>
          <td class="c-status"><button type="button" class="btn ghost po-x" data-kg-x tabindex="-1" aria-label="<?= $word('PO_ONE', 'remove_col') ?>">&times;</button></td>
        </tr>
<?php endif; ?>
