<p class="crumbs"><a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>"><?= $say('SUPPLIER_ITEMS', 'title', (string) $s['name']) ?></a></p>
<h1><?= $e($title) ?></h1>
<?= $intro('supplier_item_form') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="filters" method="get" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items/new') ?>" role="search">
  <label><?= $word('SUPPLIER_ITEMS', 'find') ?> <input type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autofocus></label>
  <button type="submit"><?= $word('SUPPLIER_ITEMS', 'find_button') ?></button>
</form>
<?php if ($q !== '' && $items === []): ?>
<?= $empty(\CW\Ui\Words::say('SUPPLIER_ITEMS', 'find_none', $q)) ?>
<?php endif; ?>
<?php if ($items !== []): ?>
<form class="record" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="q" value="<?= $e($q) ?>">
  <fieldset>
    <legend><?= $word('SUPPLIER_ITEMS', 'which') ?></legend>
<?php foreach ($items as $it): ?>
    <label class="choice"><input type="radio" name="sku_id" value="<?= $e($it['id']) ?>"<?php if ($chosen === $it['id']): ?> checked<?php endif; ?><?php if ($it['merged']): ?> disabled<?php endif; ?> required>
      <?= $e($it['name']) ?> <span class="muted"><?= $e($it['code']) ?><?php if ($it['brand'] !== null): ?> · <?= $e($it['brand']) ?><?php endif; ?></span>
<?php if ($it['merged']): ?> <span class="tag"><?= $word('SUPPLIER_ITEMS', 'joined') ?></span><?php endif; ?>
<?php if ($it['have'] !== ''): ?> <span class="tag warn"><?= $say('SUPPLIER_ITEMS', 'already', $it['have']) ?></span><?php endif; ?></label>
<?php endforeach; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('SUPPLIER_ITEMS', 'sells') ?></legend>
    <label><?= $word('SUPPLIER_ITEMS', 'code') ?> <input type="text" name="supplier_code" value="<?= $e($v['supplier_code']) ?>" maxlength="64"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'description') ?> <input type="text" name="supplier_description" value="<?= $e($v['supplier_description']) ?>" maxlength="255"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'unit') ?> <input type="text" name="purchase_unit" value="<?= $e($v['purchase_unit']) ?>" maxlength="32" placeholder="<?= $word('SUPPLIER_ITEMS', 'each') ?>"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'upp') ?> <input type="number" name="units_per_pack" value="<?= $e($v['units_per_pack']) ?>" min="1" max="100000" placeholder="1" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'moq_label') ?> <input type="number" name="moq_packs" value="<?= $e($v['moq_packs']) ?>" min="1" max="100000" placeholder="1" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'steps_label') ?> <input type="number" name="order_multiple_packs" value="<?= $e($v['order_multiple_packs']) ?>" min="1" max="10000" placeholder="1" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'lead_label') ?> <input type="number" name="lead_days" value="<?= $e($v['lead_days']) ?>" min="0" max="120" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'main_choice') ?>
      <select name="is_preferred">
        <option value="auto"<?php if ($v['is_preferred'] === 'auto'): ?> selected<?php endif; ?>><?= $word('SUPPLIER_ITEMS', 'main_auto') ?></option>
        <option value="1"<?php if ($v['is_preferred'] === '1'): ?> selected<?php endif; ?>><?= $word('SUPPLIER_ITEMS', 'main_replace') ?></option>
        <option value="0"<?php if ($v['is_preferred'] === '0'): ?> selected<?php endif; ?>><?= $word('SUPPLIER_ITEMS', 'main_backup') ?></option>
      </select>
    </label>
  </fieldset>
  <fieldset>
    <legend><?= $word('SUPPLIER_ITEMS', 'price_optional') ?></legend>
    <label><?= $word('SUPPLIER_ITEMS', 'price_label') ?> <input type="text" name="pack_price" value="<?= $e($v['pack_price']) ?>" inputmode="decimal" maxlength="20" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'price_on') ?> <input type="date" name="effective_on" value="<?= $e($v['effective_on']) ?>" max="<?= $e($today) ?>"></label>
  </fieldset>
  <p class="actions"><button type="submit" class="primary"><?= $word('SUPPLIER_ITEMS', 'add_button') ?></button></p>
</form>
<?php elseif ($q === ''): ?>
<p class="hint"><?= $word('SUPPLIER_ITEMS', 'find_first') ?></p>
<?php endif; ?>
