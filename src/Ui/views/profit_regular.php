<?php
// Profit › Regular costs (CW-1150; docs/decisions.md EX5-EX7, EX14; the approved drawing MonthlyCosts of 24 Nov 2026): every cost with no
// invoice, grouped by its group: name, shop, how often, the amount with VAT, its VAT and the cost without VAT (the owner, 24 Nov 2026: EX38),
// from, until ("no end"); a line ended is greyed; then "Every week in all" (the weekly lines in force today) and how it counts. A row opens the line (its new amount, its end, its history). On a phone each row is a card.
$X = \CW\Ui\Words::EXPENSES;
$R = \CW\Ui\Words::REGULAR;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
?>
<?= $head(\CW\Ui\Words::MENU['profit_regular'], intro: 'profit_regular', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_regular'])) ?>
<?php if ($range !== null): ?>
<p class="note regular-period-total" role="status"><?= $say('REGULAR', 'period_total', $shopName ?? \CW\Ui\Words::EXPENSES['all_shops'], \CW\Ui\Html::day($range[0]), \CW\Ui\Html::day($range[1]), $gbp(array_sum($shares))) ?> <a href="/ui/profit/regular"><?= $word('REGULAR', 'all_lines') ?></a></p>
<?php endif; ?>
<?php if ($groups === []): ?>
<?= $empty($R['none'], $canEdit ? $R['none_text'] : '', $canEdit ? '/ui/profit/regular/new' : null, $canEdit ? \CW\Ui\Words::NEW['profit_regular'] : null) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table regular-costs">
  <thead>
    <tr>
      <th scope="col"><?= $word('REGULAR', 'name') ?></th>
      <th scope="col"><?= $word('REGULAR', 'shop') ?></th>
      <th scope="col"><?= $word('REGULAR', 'every') ?></th>
      <th scope="col" class="num"><?= $word('REGULAR', 'amount') ?></th>
      <th scope="col" class="num"><?= $word('REGULAR', 'vat') ?></th>
      <th scope="col" class="num"><?= $word('REGULAR', 'cost') ?></th>
      <th scope="col"><?= $word('REGULAR', 'from') ?></th>
      <th scope="col"><?= $word('REGULAR', 'until') ?></th>
<?php if ($range !== null): ?>
      <th scope="col" class="num"><?= $word('REGULAR', 'share') ?></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($groups as $g): ?>
    <tr class="rp-group"><th scope="rowgroup" colspan="<?php if ($range !== null): ?>9<?php else: ?>8<?php endif; ?>"><?= $e($g['group']) ?></th></tr>
<?php foreach ($g['rows'] as $r): ?>
    <tr<?php if ($r['ended']): ?> class="off"<?php endif; ?>>
      <td data-label="<?= $word('REGULAR', 'name') ?>"><a href="<?= $u('/ui/profit/regular/' . $r['id']) ?>"><?= $e($r['name']) ?></a></td>
      <td data-label="<?= $word('REGULAR', 'shop') ?>"><?= $e($r['shop']) ?></td>
      <td data-label="<?= $word('REGULAR', 'every') ?>"><?= $e($X['every_' . $r['every']]) ?></td>
      <td class="num" data-label="<?= $word('REGULAR', 'amount') ?>"><?= $e($gbp($r['amount'])) ?></td>
      <td class="num" data-label="<?= $word('REGULAR', 'vat') ?>"><?= $e($gbp($r['vat'])) ?></td>
      <td class="num" data-label="<?= $word('REGULAR', 'cost') ?>"><?= $e($gbp($r['cost'])) ?></td>
      <td data-label="<?= $word('REGULAR', 'from') ?>"><?= $day($r['from']) ?></td>
      <td data-label="<?= $word('REGULAR', 'until') ?>"><?php if ($r['until'] === null): ?><?= $word('EXPENSES', 'no_end') ?><?php else: ?><?= $day($r['until']) ?><?php if ($r['ended']): ?> <?= $chip('off', $R['ended_chip']) ?><?php endif; ?><?php endif; ?></td>
<?php if ($range !== null): ?>
      <td class="num" data-label="<?= $word('REGULAR', 'share') ?>"><?= $e($gbp($shares[$r['id']] ?? 0)) ?></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
<?php endforeach; ?>
    <tr class="rp-total">
      <th scope="row"><?= $word('EXPENSES', 'every_week_total') ?></th>
      <td data-label="<?= $word('REGULAR', 'shop') ?>"></td>
      <td data-label="<?= $word('REGULAR', 'every') ?>"></td>
      <td class="num" data-label="<?= $word('REGULAR', 'amount') ?>"><?= $e($gbp($today['week'])) ?></td>
      <td class="num" data-label="<?= $word('REGULAR', 'vat') ?>"><?= $e($gbp($today['week'] - $todayCost['week'])) ?></td>
      <td class="num" data-label="<?= $word('REGULAR', 'cost') ?>"><?= $e($gbp($todayCost['week'])) ?></td>
      <td data-label="<?= $word('REGULAR', 'from') ?>"></td>
      <td data-label="<?= $word('REGULAR', 'until') ?>"></td>
<?php if ($range !== null): ?>
      <td class="num" data-label="<?= $word('REGULAR', 'share') ?>"><?= $e($gbp(array_sum($shares))) ?></td>
<?php endif; ?>
    </tr>
  </tbody>
</table>
</div>
<?php if ($today['month'] > 0 || $today['year'] > 0): ?>
<p class="muted"><?= $say('REGULAR', 'also_in_force', $gbp($today['month']), $gbp($todayCost['month']), $gbp($today['year']), $gbp($todayCost['year'])) ?></p>
<?php endif; ?>
<?php endif; ?>
<p class="muted"><?= $word('REGULAR', 'how_counts') ?></p>
<p class="muted"><?= $word('REGULAR', 'invoice_note') ?></p>
