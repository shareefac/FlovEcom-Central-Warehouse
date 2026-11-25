<?php
// Profit › Real profit (CW-1150; docs/decisions.md EX15; the approved drawing ProfitMonth of 24 Nov 2026): the days chosen (this month unless
// asked) and a column per shop: sales less refunds, what the goods cost, delivery charged, the carriers' bills, payment fees (PayPal's for
// a shop switched on, 0080 PF17; in grey while no shop is), the expenses added, the regular costs, and the real profit. A line opens what is in it. On a phone each row is a card. Each
// shop counts from its own first day (EX39): a shop not started on these days shows "not started (from <day>)" in its column, never a figure;
// one that starts inside them is counted from its day, and the page says so.
$P = \CW\Ui\Words::PROFIT;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
$lines = [
    ['real_sales', 'goods', 'goods'],
    ['real_cost', 'cost', 'goods'],
    ['real_delivery_charged', 'delivery', 'delivery'],
    ['real_delivery', 'delivery_cost', 'delivery'],
    ['real_fees', 'fees', null], // PayPal's (0080, PF17) for a shop switched on; 0 for the others (the note says which)
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
$notStarted = static fn (?string $first): string => $first === null ? \CW\Ui\Words::PROFIT['real_not_set'] : \CW\Ui\Words::say('PROFIT', 'real_not_started', \CW\Ui\Html::day($first));
$later = array_values(array_filter($shops, static fn (array $s): bool => $s['counted_from'] !== null));
// The shops whose PayPal fees are read (0080, PF17: their first fee day set and reached in these days): their fees line is a figure; the others
// keep the "not built yet" note.
$feesOn = array_values(array_filter($shops, static fn (array $s): bool => $s['started'] && ($s['fees_from'] ?? null) !== null));
?>
<?= $head(\CW\Ui\Words::MENU['profit_real'], intro: 'profit_real', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_real'], [$vatPct])) ?>
<?= $partial('profit_options', ['period' => $period, 'periods' => $periods, 'from' => $from, 'to' => $to, 'stores' => $stores, 'storeId' => $storeId, 'warn' => $warn]) ?>
<p class="muted real-days"><?= $say('PROFIT', 'real_days', \CW\Ui\Html::day($from), \CW\Ui\Html::day($to)) ?> <?= $word('PROFIT', 'real_note') ?></p>
<?php if (!$canExpenses): ?>
<p class="note" role="status"><?= $word('PROFIT', 'real_no_expenses') ?></p>
<?php endif; ?>
<?php foreach ($later as $s): ?>
<p class="note" role="status"><?= $say('PROFIT', 'real_counted_from', (string) $s['shop'], \CW\Ui\Html::day((string) $s['counted_from'])) ?></p>
<?php endforeach; ?>
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
    <tr<?php if ($key === 'real_profit'): ?> class="rp-total"<?php elseif ($key === 'real_fees' && $feesOn === []): ?> class="off"<?php endif; ?>>
      <th scope="row"><?= $word('PROFIT', $key) ?></th>
<?php foreach ($shops as $s): ?>
<?php if (!$s['started']): ?>
      <td class="num muted" data-label="<?= $e($s['shop']) ?>"><?= $e($notStarted($s['from'])) ?></td>
<?php else: ?>
<?php $href = $link === null ? null : ($links[$s['shop_id']][$link] ?? null); ?>
      <td class="num" data-label="<?= $e($s['shop']) ?>"><?php if ($href !== null): ?><a href="<?= $e($href) ?>"><?php endif; ?><?= $e($gbp((int) $s[$field])) ?><?php if ($href !== null): ?></a><?php endif; ?><?php if ($key === 'real_profit' && $s['incomplete']): ?> <?= $chip('needs', \CW\Ui\Words::PROFIT['real_cell_incomplete']) ?><?php endif; ?></td>
<?php endif; ?>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php foreach ($feesOn as $s): ?>
<p class="muted"><?= $say('PROFIT', 'real_fees_paypal', (string) $s['shop'], \CW\Ui\Html::day((string) $s['fees_from'])) ?></p>
<?php endforeach; ?>
<?php if (count($feesOn) < count(array_filter($shops, static fn (array $s): bool => $s['started']))): ?>
<p class="muted"><?= $word('PROFIT', 'real_fees_not_built') ?></p>
<?php endif; ?>
<p class="muted"><?= $word('PROFIT', 'real_hayati') ?></p>
