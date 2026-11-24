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
if (!$canExpenses) {
    // Without expenses.view (profit.view given on the Roles page to a job that does not see the expenses: the Codex check r1, H10) the lines
    // of the expenses, the regular costs and the real profit are not drawn.
    $lines = array_values(array_filter($lines, static fn (array $l): bool => !in_array($l[0], ['real_expenses', 'real_regular', 'real_profit'], true)));
}
$incomplete = array_filter($shops, static fn (array $s): bool => $s['incomplete']) !== [];
?>
<?= $head(\CW\Ui\Words::MENU['profit_real'], intro: 'profit_real', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_real'])) ?>
<?= $partial('profit_options', ['period' => $period, 'periods' => $periods, 'from' => $from, 'to' => $to, 'stores' => $stores, 'storeId' => $storeId, 'warn' => $warn]) ?>
<p class="muted real-days"><?= $say('PROFIT', 'real_days', \CW\Ui\Html::day($from), \CW\Ui\Html::day($to)) ?> <?= $word('PROFIT', 'real_note') ?></p>
<?php if (!$canExpenses): ?>
<p class="note" role="status"><?= $word('PROFIT', 'real_no_expenses') ?></p>
<?php endif; ?>
<?php if ($incomplete): ?>
<p class="note" role="status"><?= $word('PROFIT', 'real_incomplete') ?> <?= $say('PROFIT', 'real_incomplete_units', (string) array_sum(array_column($shops, 'no_cost'))) ?></p>
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
<?php $href = $link === null ? null : ($links[$s['shop_id']][$link] ?? null); ?>
      <td class="num" data-label="<?= $e($s['shop']) ?>"><?php if ($href !== null): ?><a href="<?= $e($href) ?>"><?php endif; ?><?= $e($gbp((int) $s[$field])) ?><?php if ($href !== null): ?></a><?php endif; ?></td>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted"><?= $word('PROFIT', 'real_fees_not_built') ?></p>
<p class="muted"><?= $word('PROFIT', 'real_hayati') ?></p>
