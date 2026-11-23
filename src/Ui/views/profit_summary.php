<?php
// Profit › Profit summary (docs/decisions.md PR24): today, yesterday, the last 7 and 30 days, this month and last month, for all shops or
// one: orders, goods sold ex VAT, buying cost, profit, margin, delivery charged ex VAT, and the units with no buying price (a link to their
// list). One row per period; on a phone each row is a card. Delivery in a table of its own (0077, DL23): charged, its cost on the orders, the
// carriers' charges not on an order, and delivery after its cost.
?>
<?= $head(\CW\Ui\Words::MENU['profit_summary'], intro: 'profit_summary', aside: $explain('profit', \CW\Ui\Words::PROFIT['profit'])) ?>
<?= $partial('profit_options', ['period' => null, 'periods' => [], 'from' => '', 'to' => '', 'stores' => $stores, 'storeId' => $storeId]) ?>
<p class="muted"><?= $say('PROFIT', 'today_is', \CW\Ui\Html::day($today)) ?></p>
<?php if (array_sum(array_map(static fn (array $r): int => (int) $r['f']['pending'], $rows)) > 0): ?>
<p class="note" role="status"><?= $word('PROFIT', 'pending_note') ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table profit-summary">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'period') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'orders') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'goods') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'cost') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'profit') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'margin') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'no_cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="<?= $e($r['orders_href']) ?>"><?= $e($r['label']) ?></a></th>
      <td class="num" data-label="<?= $word('PROFIT', 'orders') ?>"><?= $n($r['f']['orders']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'goods') ?>"><?= $money($r['f']['goods'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money($r['f']['cost'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $money($r['f']['profit'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($r['f']['margin_bp'])) ?><?php if ($r['f']['incomplete']): ?> <span class="muted" title="<?= $word('PROFIT', 'incomplete_note') ?>"><?= $word('PROFIT', 'incomplete') ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery') ?>"><?= $money($r['f']['delivery'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'no_cost') ?>"><?php if ($r['f']['no_cost'] > 0): ?><a href="<?= $e($r['no_cost_href']) ?>"><?= $n($r['f']['no_cost']) ?></a><?php else: ?><?= $n(0) ?><?php endif; ?><?php if ($r['f']['pending'] > 0): ?> <span class="muted"><?= $say('PROFIT', 'waiting_count', (string) $r['f']['pending']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>

<h2><?= $word('PROFIT', 'delivery_table') ?></h2>
<div class="table-wrap">
<table class="stack rp-table profit-delivery-summary">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'period') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery_cost') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery_other') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery_result') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?= $e($r['label']) ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery') ?>"><?= $money($r['f']['delivery'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery_cost') ?>"><?= $money($r['f']['delivery_cost'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery_other') ?>"><?= $money($r['f']['delivery_other'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery_result') ?>"><?= $money($r['f']['delivery_result'] / 100) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
