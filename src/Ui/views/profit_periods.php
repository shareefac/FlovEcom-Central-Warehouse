<?php
// Profit › Profit by day (docs/decisions.md PR25): the days chosen, day by day, week by week (from Monday) or month by month, newest
// first, each with its margin, and their total. On a phone each row is a card.
$groupWords = ['day' => \CW\Ui\Words::PROFIT['group_day'], 'week' => \CW\Ui\Words::PROFIT['group_week'], 'month' => \CW\Ui\Words::PROFIT['group_month']];
?>
<?= $head(\CW\Ui\Words::MENU['profit_periods'], intro: 'profit_periods', aside: $explain('profit', \CW\Ui\Words::PROFIT['profit'])) ?>
<?= $partial('profit_options', ['period' => $period, 'periods' => $periods, 'from' => $from, 'to' => $to, 'stores' => $stores, 'storeId' => $storeId, 'warn' => $warn,
    'extra' => ['by' => [\CW\Ui\Words::PROFIT['by'], $groupWords, $by]]]) ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PROFIT['nothing'], \CW\Ui\Words::PROFIT['nothing_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table profit-periods">
  <thead>
    <tr>
      <th scope="col"><?= $e($groupWords[$by]) ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'orders') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'units') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'goods') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'cost') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'profit') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'margin') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'no_cost_short') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?php if ($by === 'month'): ?><?= $e((new \DateTimeImmutable($r['from']))->format('F Y')) ?><?php else: ?><?= $day($r['from']) ?><?php endif; ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'orders') ?>"><?= $n($r['f']['orders']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'units') ?>"><?= $n($r['f']['units']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'goods') ?>"><?= $money($r['f']['goods'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money($r['f']['cost'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $money($r['f']['profit'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($r['f']['margin_bp'])) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery') ?>"><?= $money($r['f']['delivery'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'no_cost_short') ?>"><?= $n($r['f']['no_cost']) ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="rp-total">
      <th scope="row"><?= $word('PROFIT', 'total') ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'orders') ?>"><?= $n($total['orders']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'units') ?>"><?= $n($total['units']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'goods') ?>"><?= $money($total['goods'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money($total['cost'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $money($total['profit'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($total['margin_bp'])) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery') ?>"><?= $money($total['delivery'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'no_cost_short') ?>"><?= $n($total['no_cost']) ?></td>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>
