<?php
// A supplier's products as a board (docs/decisions.md W9.3): grouped main supplier, a backup supplier, no longer bought here, each group a
// coloured heading with its count over a card with its colour on the left edge; a row opens the product as this supplier sells it; "+ Add
// a product" at the end of the first group for a buyer. The supplier's "Items and packs" tab and its products page draw the same board.
// $rows: SupplierItemsController::rowsOf(..., shown: true); $canManage; $supplierId.
$groups = \CW\Ui\Controller\SupplierItemsController::board($rows);
?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::SUPPLIER_ITEMS['none'], \CW\Ui\Words::SUPPLIER_ITEMS[$canManage ? 'none_text' : 'none_look'], $canManage ? '/ui/purchasing/suppliers/' . $supplierId . '/items/new' : null, $canManage ? \CW\Ui\Words::SUPPLIER_ITEMS['add'] : null) ?>
<?php else: ?>
<?php foreach ($groups as $i => $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('SUPPLIER_ITEMS', 'group_one') ?><?php else: ?><?= $say('SUPPLIER_ITEMS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list supplier-items">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('SUPPLIER_ITEMS', 'product') ?></th>
        <th scope="col" class="c-status"><?= $word('SUPPLIER_ITEMS', 'main') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'their_code') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'pack') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'moq') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'steps') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'lead') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'per_pack') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'per_item') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'last_order') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/purchasing/supplier-items/' . $r['id']) ?>"><?= $e($r['sku_name']) ?></a><span class="o-sub"><?= $e($r['sku_code']) ?><?php if ($r['merged_code'] !== null): ?> · <?= $say('SUPPLIER_ITEMS', 'merged', (string) $r['merged_code']) ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><?= $e($g['name']) ?></td>
        <td data-label="<?= $word('SUPPLIER_ITEMS', 'their_code') ?>"><?= $e($r['supplier_code']) ?></td>
        <td data-label="<?= $word('SUPPLIER_ITEMS', 'pack') ?>"><?= $e($r['pack']) ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'moq') ?>"><?= $n($r['moq_packs']) ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'steps') ?>"><?= $n($r['order_multiple_packs']) ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'lead') ?>"><?php if ($r['lead_days'] === null): ?><span class="muted"><?= $word('SUPPLIER_ITEMS', 'supplier_days') ?></span><?php else: ?><?= $n($r['lead_days']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'per_pack') ?>"><?= $e($r['price_text']) ?><?php if ($r['last_price_on'] !== null): ?> <span class="o-sub"><?= $day($r['last_price_on']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'per_item') ?>"><?= $e($r['unit_text']) ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'last_order') ?>"><?= $e($r['po_text']) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($i === 0 && $canManage): ?>
      <tr class="grp-add"><td colspan="10" data-label=""><a class="add-item" href="<?= $u('/ui/purchasing/suppliers/' . $supplierId . '/items/new') ?>"><?= $word('SUPPLIER_ITEMS', 'add_row') ?></a></td></tr>
<?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
