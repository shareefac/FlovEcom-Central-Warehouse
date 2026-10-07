<p class="crumbs"><a href="/ui/purchasing/suppliers"><?= $word('MENU', 'suppliers') ?></a> <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id']) ?>"><?= $say('SUPPLIER_ITEMS', 'back', (string) $s['name']) ?></a></p>
<h1><?= $e($title) ?></h1>
<?= $intro('supplier_items', $lookOnly) ?>
<p class="hint"><?= $word('SUPPLIER_ITEMS', 'intro') ?></p>
<p class="actions">
<?php if ($canManage): ?>
  <a class="btn primary" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items/new') ?>"><?= $word('SUPPLIER_ITEMS', 'add') ?></a>
<?php endif; ?>
  <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items.csv') ?>"><?= $word('SUPPLIER_ITEMS', 'download') ?></a>
</p>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::SUPPLIER_ITEMS['none'], \CW\Ui\Words::SUPPLIER_ITEMS[$canManage ? 'none_text' : 'none_look']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list supplier-items">
  <thead>
    <tr>
      <th scope="col"><?= $word('SUPPLIER_ITEMS', 'product') ?></th>
      <th scope="col"><?= $word('SUPPLIER_ITEMS', 'main') ?></th>
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
<?php foreach ($rows as $r): ?>
    <tr class="<?php if ((int) $r['is_active'] !== 1): ?>inactive off<?php elseif ((int) $r['is_preferred'] === 1): ?>done<?php else: ?>info<?php endif; ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/purchasing/supplier-items/' . $r['id']) ?>"><?= $e($r['sku_name']) ?></a><span class="o-no"><?= $e($r['sku_code']) ?></span>
<?php if ($r['merged_code'] !== null): ?> <?= $chip('blocked', \CW\Ui\Words::say('SUPPLIER_ITEMS', 'merged', (string) $r['merged_code'])) ?><?php endif; ?>
<?php if ((int) $r['is_active'] !== 1): ?> <?= $chip('off', \CW\Ui\Words::SUPPLIER_ITEMS['not_used']) ?><?php endif; ?></th>
      <td class="c-status"><?php if ((int) $r['is_preferred'] === 1 && (int) $r['is_active'] === 1): ?><?= $chip('done', \CW\Ui\Words::SUPPLIER_ITEMS['main']) ?><?php endif; ?></td>
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
  </tbody>
</table>
</div>
<?php endif; ?>
