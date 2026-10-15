<p class="crumbs"><a href="/ui/purchasing/orders"><?= $word('MENU', 'orders') ?></a> <a href="<?= $u('/ui/purchasing/orders/' . $doc->id) ?>"><?= $word('PO_ADD', 'item_back') ?></a></p>
<h1><?= $say('PO_ADD', 'item_title', $supplierName) ?></h1>
<?= $intro('order_new_item') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($other !== null): ?>
<section class="card note" aria-labelledby="other-h">
  <h2 id="other-h"><?= $say('PO_ADD', 'barcode_other', $other['code'] . ' ' . $other['name']) ?></h2>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <input type="hidden" name="line_count" value="0">
    <input type="hidden" name="lines_editable" value="0">
    <input type="hidden" name="packs" value="<?= $e($packsToAdd) ?>">
    <button type="submit" name="add_sku" value="<?= $e($other['id']) ?>" class="primary"><?= $word('PO_ADD', 'barcode_use') ?></button>
  </form>
</section>
<?php endif; ?>
<?php if ($same !== null): ?>
<section class="card note same-items" aria-labelledby="same-h">
  <h2 id="same-h"><?= $word('PO_ADD', 'same_title') ?></h2>
  <p><?= $word('PO_ADD', 'same_text') ?></p>
  <ul class="plain">
<?php foreach ($same as $s): ?>
    <li>
      <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
        <input type="hidden" name="line_count" value="0">
        <input type="hidden" name="lines_editable" value="0">
        <input type="hidden" name="packs" value="<?= $e($packsToAdd) ?>">
        <a href="<?= $u('/ui/items/' . $s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?>
        <button type="submit" name="add_sku" value="<?= $e($s['id']) ?>"><?= $word('PO_ADD', 'same_use') ?></button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<form class="record po-new-item" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/new-item') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <fieldset>
    <legend><?= $word('ORDER', 'product') ?></legend>
    <label><?= $word('PO_ADD', 'item_name') ?> <input type="text" name="name" maxlength="255" required value="<?= $e($v['name']) ?>"<?php if ($errorField === 'name'): ?> aria-invalid="true"<?php endif; ?>></label>
    <label><?= $word('PO_ADD', 'item_brand') ?> <input type="text" name="brand" maxlength="100" value="<?= $e($v['brand']) ?>"></label>
    <label><?= $word('PO_ADD', 'item_barcode') ?> <input type="text" name="barcode" maxlength="64" inputmode="numeric" autocomplete="off" value="<?= $e($v['barcode']) ?>"<?php if ($errorField === 'barcode' || $other !== null): ?> aria-invalid="true"<?php endif; ?>></label>
<?php if ($same !== null): ?>
    <label class="choice"><input type="checkbox" name="anyway" value="1"<?php if ($v['anyway'] === '1'): ?> checked<?php endif; ?>> <?= $word('PO_ADD', 'same_anyway') ?></label>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('PO_ADD', 'item_supply') ?></legend>
    <p class="hint"><?= $word('PO_ADD', 'item_supply_text') ?></p>
    <label><?= $word('PO_ADD', 'item_code') ?> <input type="text" name="supplier_code" maxlength="64" autocomplete="off" value="<?= $e($v['supplier_code']) ?>"></label>
    <label><?= $word('PO_ADD', 'item_upp') ?> <input type="number" name="units_per_pack" min="1" max="100000" class="short" value="<?= $e($v['units_per_pack']) ?>"></label>
    <label><?= $word('PO_ADD', 'item_price') ?> <input type="text" name="pack_price" inputmode="decimal" class="short" value="<?= $e($v['pack_price']) ?>"></label>
  </fieldset>
  <label><?= $word('PO_ADD', 'item_packs') ?> <input type="number" name="packs" min="1" max="1000000" class="short" value="<?= $e($v['packs']) ?>"></label>
  <p class="actions"><button type="submit" class="primary"><?= $word('PO_ADD', 'item_button') ?></button> <a href="<?= $u('/ui/purchasing/orders/' . $doc->id) ?>"><?= $word('PO_ADD', 'item_back') ?></a></p>
</form>
