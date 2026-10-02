<p class="crumbs"><a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>">Items from <?= $e($s['code']) ?></a></p>
<h1>Add an item to <?= $e($s['code']) ?> <span class="muted"><?= $e($s['name']) ?></span></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="filters" method="get" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items/new') ?>" role="search">
  <label>Find the item: CW code, barcode or name words <input type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autofocus></label>
  <button type="submit">Search</button>
</form>
<?php if ($q !== '' && $items === []): ?>
<p class="note">No item matches "<?= $e($q) ?>".</p>
<?php endif; ?>
<?php if ($items !== []): ?>
<form class="record" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="q" value="<?= $e($q) ?>">
  <fieldset>
    <legend>Item</legend>
<?php foreach ($items as $it): ?>
    <label class="choice"><input type="radio" name="sku_id" value="<?= $e($it['id']) ?>"<?php if ($chosen === $it['id']): ?> checked<?php endif; ?><?php if ($it['merged']): ?> disabled<?php endif; ?> required>
      <?= $e($it['code']) ?> <?= $e($it['name']) ?><?php if ($it['brand'] !== null): ?> <span class="muted"><?= $e($it['brand']) ?></span><?php endif; ?>
<?php if ($it['have'] !== ''): ?> <span class="tag">already from this supplier: <?= $e($it['have']) ?></span><?php endif; ?></label>
<?php endforeach; ?>
  </fieldset>
  <fieldset>
    <legend>How the supplier sells it</legend>
    <label>Supplier's code <input type="text" name="supplier_code" value="<?= $e($v['supplier_code']) ?>" maxlength="64"></label>
    <label>Supplier's description <input type="text" name="supplier_description" value="<?= $e($v['supplier_description']) ?>" maxlength="255"></label>
    <label>Purchase unit (box, case, each ...) <input type="text" name="purchase_unit" value="<?= $e($v['purchase_unit']) ?>" maxlength="32" placeholder="each"></label>
    <label>Central units in one purchase unit <input type="number" name="units_per_pack" value="<?= $e($v['units_per_pack']) ?>" min="1" max="100000" placeholder="1"></label>
    <label>Minimum order (packs) <input type="number" name="moq_packs" value="<?= $e($v['moq_packs']) ?>" min="1" max="100000" placeholder="1"></label>
    <label>Order in multiples of (packs) <input type="number" name="order_multiple_packs" value="<?= $e($v['order_multiple_packs']) ?>" min="1" max="10000" placeholder="1"></label>
    <label>Lead days (empty: the supplier's) <input type="number" name="lead_days" value="<?= $e($v['lead_days']) ?>" min="0" max="120"></label>
    <label>The preferred supply of this item (the reorder list and its draft orders use the preferred supply)
      <select name="is_preferred">
        <option value="auto"<?php if ($v['is_preferred'] === 'auto'): ?> selected<?php endif; ?>>Yes, unless the item already has a preferred supply</option>
        <option value="1"<?php if ($v['is_preferred'] === '1'): ?> selected<?php endif; ?>>Yes, instead of its current one</option>
        <option value="0"<?php if ($v['is_preferred'] === '0'): ?> selected<?php endif; ?>>No, an alternative supply</option>
      </select>
    </label>
  </fieldset>
  <fieldset>
    <legend>Price (optional)</legend>
    <label>Pack price, GBP excl. VAT <input type="text" name="pack_price" value="<?= $e($v['pack_price']) ?>" inputmode="decimal" maxlength="20"></label>
    <label>Effective on (empty: today) <input type="date" name="effective_on" value="<?= $e($v['effective_on']) ?>" max="<?= $e($today) ?>"></label>
  </fieldset>
  <p class="actions"><button type="submit" class="primary">Add the item</button></p>
</form>
<?php elseif ($q === ''): ?>
<p class="muted">Search for the item first: a CW code (CW-000123), a barcode, or words of its name.</p>
<?php endif; ?>
