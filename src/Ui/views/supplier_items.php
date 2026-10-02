<p class="crumbs"><a href="/ui/purchasing/suppliers">Suppliers</a> <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id']) ?>">Back to <?= $e($s['code']) ?></a></p>
<h1>Items from <?= $e($s['code']) ?> <span class="muted"><?= $e($s['name']) ?></span></h1>
<p class="muted">A pack is what the supplier sells and we order in (a box of 24 = 24 central units). Prices are GBP excluding VAT per pack; the
  last price is the newest imported, typed or invoiced price (never a purchase order's own price). The preferred supply of an item is where the
  reorder list orders it from.</p>
<p class="actions">
<?php if ($canManage): ?>
  <a class="button" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items/new') ?>">Add an item</a>
<?php endif; ?>
  <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items.csv') ?>">Download (CSV)</a>
</p>
<?php if ($rows === []): ?>
<p class="note">No item yet.</p>
<?php else: ?>
<table class="supplier-items">
  <thead>
    <tr>
      <th scope="col">Item</th>
      <th scope="col">Supplier code</th>
      <th scope="col">Pack</th>
      <th scope="col" class="num">MOQ (packs)</th>
      <th scope="col" class="num">Multiple</th>
      <th scope="col" class="num">Lead days</th>
      <th scope="col">Preferred</th>
      <th scope="col" class="num">Last price</th>
      <th scope="col" class="num">Per unit</th>
      <th scope="col" class="num">Last PO price</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ((int) $r['is_active'] !== 1): ?> class="inactive"<?php endif; ?>>
      <th scope="row"><a href="<?= $u('/ui/purchasing/supplier-items/' . $r['id']) ?>"><?= $e($r['sku_code']) ?></a> <?= $e($r['sku_name']) ?>
<?php if ($r['merged_code'] !== null): ?> <span class="tag bad">merged into <?= $e($r['merged_code']) ?></span><?php endif; ?>
<?php if ((int) $r['is_active'] !== 1): ?> <span class="tag">not used</span><?php endif; ?></th>
      <td><?= $e($r['supplier_code']) ?></td>
      <td><?= $e($r['pack']) ?></td>
      <td class="num"><?= $n($r['moq_packs']) ?></td>
      <td class="num"><?= $n($r['order_multiple_packs']) ?></td>
      <td class="num"><?= $e($r['lead_days']) ?></td>
      <td><?php if ((int) $r['is_preferred'] === 1 && (int) $r['is_active'] === 1): ?><span class="tag ok">preferred</span><?php endif; ?></td>
      <td class="num"><?= $e($r['price_text']) ?><?php if ($r['last_price_on'] !== null): ?> <span class="muted"><?= $e($r['last_price_on']) ?></span><?php endif; ?></td>
      <td class="num"><?= $e($r['unit_text']) ?></td>
      <td class="num"><?= $e($r['po_text']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
