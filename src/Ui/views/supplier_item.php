<p class="crumbs"><a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>"><?= $say('SUPPLIER_ITEMS', 'title', (string) $s['name']) ?></a></p>
<h1><?= $e($title) ?></h1>
<?= $intro('supplier_item', $canManage ? null : \CW\Ui\Words::whoCan('suppliers.manage')) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($i['merged_code'] !== null): ?>
<p class="note"><?= $say('SUPPLIER_ITEMS', 'merged_note', (string) $i['merged_code']) ?></p>
<?php endif; ?>
<?php if ($s['status'] !== 'active'): ?>
<p class="note"><?= $say('SUPPLIER_ITEMS', 'supplier_not_active', \CW\Ui\Words::of('SUPPLIER_STATUS', (string) $s['status'])) ?></p>
<?php endif; ?>

<?php if ($canManage && (int) $i['is_active'] === 1): ?>
<section class="card decide-box" aria-labelledby="main-h">
  <h2 id="main-h" class="visually-hidden"><?= $word('SUPPLIER_ITEMS', 'main') ?></h2>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/supplier-items/' . $i['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($i['version']) ?>">
<?php if ((int) $i['is_preferred'] === 1): ?>
    <input type="hidden" name="preferred" value="0">
    <button type="submit" class="btn big secondary"><span class="btn-title"><?= $word('SUPPLIER_ITEMS', 'stop_main') ?></span> <span class="sub"><?= $word('SUPPLIER_ITEMS', 'stop_main_does') ?></span></button>
<?php else: ?>
    <input type="hidden" name="preferred" value="1">
    <button type="submit" class="primary btn big"><span class="btn-title"><?= $word('SUPPLIER_ITEMS', 'make_main') ?></span> <span class="sub"><?= $word('SUPPLIER_ITEMS', 'make_main_does') ?></span></button>
<?php endif; ?>
  </form>
</section>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="si-h">
    <h2 id="si-h"><?= $word('SUPPLIER_ITEMS', 'how') ?></h2>
    <dl>
      <dt><?= $word('SUPPLIER_ITEMS', 'supplier') ?></dt><dd><a href="<?= $u('/ui/purchasing/suppliers/' . $s['id']) ?>"><?= $e($s['name']) ?></a></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'product') ?></dt><dd><a href="<?= $u('/ui/items/' . $i['sku_id']) ?>"><?= $e($i['sku_name']) ?></a> <span class="muted"><?= $e($i['sku_code']) ?><?php if ($i['brand'] !== null): ?> · <?= $e($i['brand']) ?><?php endif; ?></span></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'their_code') ?></dt><dd><?= $e($i['supplier_code']) ?></dd>
<?php if ($i['supplier_description'] !== null && $i['supplier_description'] !== ''): ?>
      <dt><?= $word('SUPPLIER_ITEMS', 'description') ?></dt><dd><?= $e($i['supplier_description']) ?></dd>
<?php endif; ?>
      <dt><?= $word('SUPPLIER_ITEMS', 'pack') ?></dt><dd><?= $e($i['pack']) ?></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'smallest') ?></dt><dd><?= $say('SUPPLIER_ITEMS', 'smallest_line', $i['moq_packs'], $i['order_multiple_packs']) ?></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'lead') ?></dt><dd><?php if ($i['lead_days'] === null): ?><span class="muted"><?= $word('SUPPLIER_ITEMS', 'supplier_days') ?></span><?php else: ?><?= $n($i['lead_days']) ?><?php endif; ?></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'main') ?></dt><dd><?php if ((int) $i['is_preferred'] === 1 && (int) $i['is_active'] === 1): ?><?= $chip('done', \CW\Ui\Words::SUPPLIER_ITEMS['main_yes']) ?><?php else: ?><?= $word('SUPPLIER_ITEMS', 'main_no') ?><?php endif; ?></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'in_use') ?></dt><dd><?php if ((int) $i['is_active'] === 1): ?><?= $word('SUPPLIER_ITEMS', 'yes') ?><?php else: ?><?= $word('SUPPLIER_ITEMS', 'no') ?><?php endif; ?></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'price_now') ?></dt><dd><?php if ($i['price_text'] === null): ?><span class="muted"><?= $word('SUPPLIER_ITEMS', 'none_yet') ?></span><?php else: ?><?= $say('SUPPLIER_ITEMS', 'price_line', $i['price_text'], (string) $i['unit_text'], (string) $i['price_source'], \CW\Ui\Html::day((string) $i['last_price_on'])) ?><?php endif; ?></dd>
      <dt><?= $word('SUPPLIER_ITEMS', 'po_price') ?></dt><dd><?php if ($i['po_text'] === null): ?><span class="muted"><?= $word('SUPPLIER_ITEMS', 'none_yet') ?></span><?php else: ?><?= $say('SUPPLIER_ITEMS', 'po_line', $i['po_text'], \CW\Ui\Html::day((string) $i['last_po_on'])) ?><?php endif; ?></dd>
    </dl>
  </section>

  <section class="card" aria-labelledby="others-h">
    <h2 id="others-h"><?= $word('SUPPLIER_ITEMS', 'others') ?></h2>
<?php if ($others === []): ?>
    <p class="muted"><?= $word('SUPPLIER_ITEMS', 'others_none') ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table class="stack others">
      <thead><tr><th scope="col"><?= $word('SUPPLIER_ITEMS', 'supplier') ?></th><th scope="col"><?= $word('SUPPLIER_ITEMS', 'pack') ?></th><th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'per_pack') ?></th><th scope="col"><?= $word('SUPPLIER_ITEMS', 'main') ?></th></tr></thead>
      <tbody>
<?php foreach ($others as $o): ?>
        <tr<?php if ((int) $o['is_active'] !== 1): ?> class="inactive"<?php endif; ?>>
          <th scope="row" class="c-head"><a href="<?= $u('/ui/purchasing/supplier-items/' . $o['id']) ?>"><?= $e($o['supplier_name']) ?></a><?php if ($o['status'] !== 'active'): ?> <?= $stateChip('SUPPLIER_STATUS', (string) $o['status']) ?><?php endif; ?></th>
          <td data-label="<?= $word('SUPPLIER_ITEMS', 'pack') ?>"><?= $e($o['pack']) ?></td>
          <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'per_pack') ?>"><?= $e($o['price_text']) ?></td>
          <td data-label="<?= $word('SUPPLIER_ITEMS', 'main') ?>"><?php if ((int) $o['is_preferred'] === 1 && (int) $o['is_active'] === 1): ?><?= $chip('done', \CW\Ui\Words::SUPPLIER_ITEMS['yes']) ?><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
  </section>
</div>

<?php if ($canManage): ?>
<section class="card box" aria-labelledby="price-h">
  <h2 id="price-h"><?= $word('SUPPLIER_ITEMS', 'price') ?></h2>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/supplier-items/' . $i['id'] . '/price') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($priceKey) ?>">
    <label><?= $word('SUPPLIER_ITEMS', 'price_label') ?> <input type="text" name="pack_price" value="<?= $e($priceValues['pack_price']) ?>" inputmode="decimal" maxlength="20" class="short" required></label>
    <label><?= $word('SUPPLIER_ITEMS', 'price_on') ?> <input type="date" name="effective_on" value="<?= $e($priceValues['effective_on']) ?>" max="<?= $e($today) ?>"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'price_note') ?> <input type="text" name="note" value="<?= $e($priceValues['note']) ?>" maxlength="255"></label>
    <p class="actions"><button type="submit" class="primary"><?= $word('SUPPLIER_ITEMS', 'price_button') ?></button> <span class="hint"><?= $word('SUPPLIER_ITEMS', 'price_hint') ?></span></p>
  </form>
</section>

<details class="action">
  <summary><?= $word('SUPPLIER_ITEMS', 'change') ?></summary>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/supplier-items/' . $i['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($i['version']) ?>">
    <label><?= $word('SUPPLIER_ITEMS', 'code') ?> <input type="text" name="supplier_code" value="<?= $e($i['supplier_code']) ?>" maxlength="64"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'description') ?> <input type="text" name="supplier_description" value="<?= $e($i['supplier_description']) ?>" maxlength="255"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'unit') ?> <input type="text" name="purchase_unit" value="<?= $e($i['purchase_unit']) ?>" maxlength="32"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'upp') ?> <input type="number" name="units_per_pack" value="<?= $e($i['units_per_pack']) ?>" min="1" max="100000" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'moq_label') ?> <input type="number" name="moq_packs" value="<?= $e($i['moq_packs']) ?>" min="1" max="100000" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'steps_label') ?> <input type="number" name="order_multiple_packs" value="<?= $e($i['order_multiple_packs']) ?>" min="1" max="10000" class="short"></label>
    <label><?= $word('SUPPLIER_ITEMS', 'lead_label') ?> <input type="number" name="lead_days" value="<?= $e($i['lead_days']) ?>" min="0" max="120" class="short"></label>
    <label class="choice"><input type="checkbox" name="is_preferred" value="1"<?php if ((int) $i['is_preferred'] === 1): ?> checked<?php endif; ?>> <?= $word('SUPPLIER_ITEMS', 'main_tick') ?></label>
    <label class="choice"><input type="checkbox" name="is_active" value="1"<?php if ((int) $i['is_active'] === 1): ?> checked<?php endif; ?>> <?= $word('SUPPLIER_ITEMS', 'in_use_tick') ?></label>
    <p class="actions"><button type="submit"><?= $word('SUPPLIER_ITEMS', 'save') ?></button></p>
  </form>
</details>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('SUPPLIER_ITEMS', 'history') ?></h2>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('SUPPLIER_ITEMS', 'no_history') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack history">
    <thead>
      <tr>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'from') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'per_pack') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'items_in_pack') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'per_item') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'source') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'note') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'recorded') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($history as $h): ?>
      <tr>
        <th scope="row" class="c-head"><?= $day($h['effective_on']) ?></th>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'per_pack') ?>"><?= $e($h['pack_text']) ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'items_in_pack') ?>"><?= $n($h['units_per_pack']) ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'per_item') ?>"><?= $e($h['unit_text']) ?></td>
        <td data-label="<?= $word('SUPPLIER_ITEMS', 'source') ?>"><?php if ($h['document_id'] !== null && $canSeeOrders): ?><a href="<?= $u('/ui/documents/' . $h['document_id']) ?>"><?= $e($h['source_text']) ?></a><?php else: ?><?= $e($h['source_text']) ?><?php endif; ?></td>
        <td data-label="<?= $word('SUPPLIER_ITEMS', 'note') ?>"><?= $e($h['note']) ?></td>
        <td data-label="<?= $word('SUPPLIER_ITEMS', 'recorded') ?>"><?= $say('SUPPLIER_ITEMS', 'recorded_line', \CW\Ui\Html::when((string) $h['recorded_at']), (string) ($h['recorded_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
