<?php
// Profit › Real profit (CW-1150; docs/decisions.md EX15; the approved drawing ProfitMonth of 24 Nov 2026): the days chosen (this month unless
// asked) and a column per shop: sales less refunds, what the goods cost, delivery charged, the carriers' bills, payment fees (in grey: not
// built yet), the expenses added, the regular costs, and the real profit. A line opens what is in it. On a phone each row is a card.
$P = \CW\Ui\Words::PROFIT;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
$lines = [
    ['real_sales', 'goods', 'goods'],
    ['real_cost', 'cost', 'goods'],
    ['real_delivery_charged', 'delivery', 'delivery'],
    ['real_delivery', 'delivery_cost', 'delivery'],
    ['real_fees', 'fees', null],
    ['real_expenses', 'expenses', 'expenses'],
    ['real_regular', 'regular', 'regular'],
    ['real_profit', 'real', null],
];
$incomplete = array_filter($shops, static fn (array $s): bool => $s['incomplete']) !== [];
?>
<?= $head(\CW\Ui\Words::MENU['profit_real'], intro: 'profit_real', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_real'])) ?>
<?= $partial('profit_options', ['period' => $period, 'periods' => $periods, 'from' => $from, 'to' => $to, 'stores' => $stores, 'storeId' => $storeId, 'warn' => $warn]) ?>
<p class="muted"><?= $say('PROFIT', 'real_days', \CW\Ui\Html::day($from), \CW\Ui\Html::day($to)) ?> <?= $word('PROFIT', 'real_note') ?></p>
<?php if ($incomplete): ?>
<p class="note" role="status"><?= $word('PROFIT', 'real_incomplete') ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table real-profit">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'real_line') ?></th>
<?php foreach ($shops as $s): ?>
      <th scope="col" class="num"><?= $e($s['shop']) ?></th>
<?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($lines as [$key, $field, $link]): ?>
    <tr class="<?= $key === 'real_profit' ? 'rp-total' : ($key === 'real_fees' ? 'off' : '') ?>">
      <th scope="row"><?= $word('PROFIT', $key) ?></th>
<?php foreach ($shops as $s): ?>
<?php $href = $link === null || ($link === 'expenses' || $link === 'regular') && !$canExpenses ? null : ($links[$s['shop_id']][$link] ?? null); ?>
      <td class="num" data-label="<?= $e($s['shop']) ?>"><?php if ($href !== null): ?><a href="<?= $e($href) ?>"><?php endif; ?><?= $e($gbp((int) $s[$field])) ?><?php if ($href !== null): ?></a><?php endif; ?></td>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted"><?= $word('PROFIT', 'real_fees_not_built') ?></p>
<p class="muted"><?= $word('PROFIT', 'real_hayati') ?></p>
