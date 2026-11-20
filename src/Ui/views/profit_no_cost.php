<?php
// Profit › No buying price (docs/decisions.md PR28): the products sold on the days and shop chosen whose units have no buying price (their
// profit is not known): the units, what those units sold for ex VAT, and a link to the product's page (a website product with no CW
// product is named by its number). The most units first; at most $limit rows. Units still waiting for their cost (minutes) are said, never
// "every product has a buying price" while they wait (the Codex re-check of e5d2e5a, S2).
?>
<?= $head(\CW\Ui\Words::MENU['profit_no_cost'], intro: 'profit_no_cost', aside: $explain('profit_cost', \CW\Ui\Words::PROFIT['cost_from'])) ?>
<?= $partial('profit_options', ['period' => $period, 'periods' => $periods, 'from' => $from, 'to' => $to, 'stores' => $stores, 'storeId' => $storeId, 'warn' => $warn]) ?>
<?php if ($pending > 0): ?>
<p class="note" role="status"><?= $word('PROFIT', 'no_cost_waiting') ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?php if ($pending === 0): ?>
<?= $empty(\CW\Ui\Words::PROFIT['no_cost_none'], \CW\Ui\Words::PROFIT['no_cost_none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table profit-no-cost">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'item') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'units') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'sale_of_these') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'lines') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?php if ($r['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>" title="<?= $word('PROFIT', 'item_link') ?>"><?= $e($r['name']) ?></a> <span class="muted"><?= $e((string) $r['code']) ?></span><?php else: ?><?= $say('PROFIT', 'store_variant', (string) $r['store'], (string) $r['variant']) ?><?php endif; ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'units') ?>"><?= $n($r['units']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'sale_of_these') ?>"><?= $money($r['sale'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'lines') ?>"><?= $n($r['lines']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="note"><?= $say('PROFIT', 'top_only', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
