<p class="crumbs"><a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>">Items from <?= $e($s['code']) ?></a></p>
<h1><?= $e($i['sku_code']) ?> <span class="muted"><?= $e($i['sku_name']) ?></span> from <?= $e($s['code']) ?></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($i['merged_code'] !== null): ?>
<p class="note">The item was merged into <?= $e($i['merged_code']) ?>: add the supplier item to that one instead.</p>
<?php endif; ?>
<?php if ($s['status'] !== 'active'): ?>
<p class="note">The supplier is <?= $e(str_replace('_', ' ', $s['status'])) ?>: no purchase order can be approved for it until a second person approves it.</p>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="si-h">
    <h2 id="si-h">Supplier item</h2>
    <dl>
      <dt>Supplier</dt><dd><a href="<?= $u('/ui/purchasing/suppliers/' . $s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?></dd>
      <dt>Item</dt><dd><a href="<?= $u('/ui/items/' . $i['sku_id']) ?>"><?= $e($i['sku_code']) ?></a> <?= $e($i['sku_name']) ?><?php if ($i['brand'] !== null): ?> <span class="muted"><?= $e($i['brand']) ?></span><?php endif; ?></dd>
      <dt>Supplier's code</dt><dd><?= $e($i['supplier_code']) ?></dd>
      <dt>Supplier's description</dt><dd><?= $e($i['supplier_description']) ?></dd>
      <dt>Pack</dt><dd><?= $e($i['pack']) ?></dd>
      <dt>Minimum order</dt><dd><?= $n($i['moq_packs']) ?> packs, in multiples of <?= $n($i['order_multiple_packs']) ?></dd>
      <dt>Lead days</dt><dd><?php if ($i['lead_days'] === null): ?><span class="muted">the supplier's</span><?php else: ?><?= $e($i['lead_days']) ?><?php endif; ?></dd>
      <dt>Preferred</dt><dd><?php if ((int) $i['is_preferred'] === 1 && (int) $i['is_active'] === 1): ?><span class="tag ok">the preferred supply of this item</span><?php else: ?>no<?php endif; ?></dd>
      <dt>In use</dt><dd><?php if ((int) $i['is_active'] === 1): ?>yes<?php else: ?>no<?php endif; ?></dd>
      <dt>Last price</dt><dd><?php if ($i['price_text'] === null): ?><span class="muted">none yet</span><?php else: ?><?= $e($i['price_text']) ?> per pack (<?= $e($i['unit_text']) ?> per unit), <?= $e($i['last_price_on']) ?>, <?= $e($i['last_price_source']) ?><?php endif; ?></dd>
      <dt>Last PO price</dt><dd><?php if ($i['po_text'] === null): ?><span class="muted">none yet</span><?php else: ?><?= $e($i['po_text']) ?> on <?= $e($i['last_po_on']) ?><?php endif; ?></dd>
    </dl>
<?php if ($canManage && (int) $i['is_active'] === 1): ?>
    <form class="inline" method="post" action="<?= $u('/ui/purchasing/supplier-items/' . $i['id']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($i['version']) ?>">
<?php if ((int) $i['is_preferred'] === 1): ?>
      <input type="hidden" name="preferred" value="0">
      <button type="submit">No longer the preferred supply</button>
<?php else: ?>
      <input type="hidden" name="preferred" value="1">
      <button type="submit" class="primary">Make this the preferred supply</button>
<?php endif; ?>
    </form>
<?php endif; ?>
  </section>

  <section class="card" aria-labelledby="others-h">
    <h2 id="others-h">Other supplies of this item</h2>
<?php if ($others === []): ?>
    <p class="muted">None.</p>
<?php else: ?>
    <table>
      <thead><tr><th scope="col">Supplier</th><th scope="col">Pack</th><th scope="col" class="num">Last price</th><th scope="col"></th></tr></thead>
      <tbody>
<?php foreach ($others as $o): ?>
        <tr<?php if ((int) $o['is_active'] !== 1): ?> class="inactive"<?php endif; ?>>
          <td><a href="<?= $u('/ui/purchasing/supplier-items/' . $o['id']) ?>"><?= $e($o['supplier_code']) ?></a> <?= $e($o['supplier_name']) ?><?php if ($o['status'] !== 'active'): ?> <span class="tag"><?= $e(str_replace('_', ' ', $o['status'])) ?></span><?php endif; ?></td>
          <td><?= $e($o['pack']) ?></td>
          <td class="num"><?= $e($o['price_text']) ?></td>
          <td><?php if ((int) $o['is_preferred'] === 1 && (int) $o['is_active'] === 1): ?><span class="tag ok">preferred</span><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>
</div>

<?php if ($canManage): ?>
<section aria-labelledby="price-h">
  <h2 id="price-h">Record a price</h2>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/supplier-items/' . $i['id'] . '/price') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($priceKey) ?>">
    <label>Pack price, GBP excl. VAT <input type="text" name="pack_price" value="<?= $e($priceValues['pack_price']) ?>" inputmode="decimal" maxlength="20" required></label>
    <label>Effective on (empty: today) <input type="date" name="effective_on" value="<?= $e($priceValues['effective_on']) ?>" max="<?= $e($today) ?>"></label>
    <label>Note <input type="text" name="note" value="<?= $e($priceValues['note']) ?>" maxlength="255"></label>
    <button type="submit">Record the price</button>
  </form>
  <p class="muted">A price older than the last price goes into the history only.</p>
</section>

<section aria-labelledby="edit-h">
  <h2 id="edit-h">Change</h2>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/supplier-items/' . $i['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($i['version']) ?>">
    <label>Supplier's code <input type="text" name="supplier_code" value="<?= $e($i['supplier_code']) ?>" maxlength="64"></label>
    <label>Supplier's description <input type="text" name="supplier_description" value="<?= $e($i['supplier_description']) ?>" maxlength="255"></label>
    <label>Purchase unit <input type="text" name="purchase_unit" value="<?= $e($i['purchase_unit']) ?>" maxlength="32"></label>
    <label>Central units in one purchase unit <input type="number" name="units_per_pack" value="<?= $e($i['units_per_pack']) ?>" min="1" max="100000"></label>
    <label>Minimum order (packs) <input type="number" name="moq_packs" value="<?= $e($i['moq_packs']) ?>" min="1" max="100000"></label>
    <label>Order in multiples of (packs) <input type="number" name="order_multiple_packs" value="<?= $e($i['order_multiple_packs']) ?>" min="1" max="10000"></label>
    <label>Lead days (empty: the supplier's) <input type="number" name="lead_days" value="<?= $e($i['lead_days']) ?>" min="0" max="120"></label>
    <label class="choice"><input type="checkbox" name="is_preferred" value="1"<?php if ((int) $i['is_preferred'] === 1): ?> checked<?php endif; ?>> The preferred supply of this item</label>
    <label class="choice"><input type="checkbox" name="is_active" value="1"<?php if ((int) $i['is_active'] === 1): ?> checked<?php endif; ?>> In use</label>
    <p class="actions"><button type="submit">Save</button></p>
  </form>
</section>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h">Price history</h2>
<?php if ($history === []): ?>
  <p class="muted">No price recorded yet.</p>
<?php else: ?>
  <table class="history">
    <thead>
      <tr>
        <th scope="col">Effective on</th>
        <th scope="col" class="num">Pack price</th>
        <th scope="col" class="num">Units per pack</th>
        <th scope="col" class="num">Per unit</th>
        <th scope="col">Source</th>
        <th scope="col">Reference</th>
        <th scope="col">Note</th>
        <th scope="col">Recorded (UTC)</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($history as $h): ?>
      <tr>
        <td><?= $e($h['effective_on']) ?></td>
        <td class="num"><?= $e($h['pack_text']) ?></td>
        <td class="num"><?= $n($h['units_per_pack']) ?></td>
        <td class="num"><?= $e($h['unit_text']) ?></td>
        <td><?= $e($h['source']) ?></td>
        <td><?php if ($h['document_id'] !== null): ?><a href="<?= $u('/ui/documents/' . $h['document_id']) ?>"><?= $e($h['document_number'] ?? $h['source_ref']) ?></a><?php else: ?><?= $e($h['source_ref']) ?><?php endif; ?></td>
        <td><?= $e($h['note']) ?></td>
        <td><?= $dt($h['recorded_at']) ?> <span class="muted"><?= $e($h['recorded_by_name'] ?? $h['recorded_actor']) ?></span></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
