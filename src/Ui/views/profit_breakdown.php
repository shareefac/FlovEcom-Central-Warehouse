<?php
// Profit › Profit by product (docs/decisions.md PR26): by shop, product, brand or main supplier, the biggest first by the measure chosen
// (a column head is a link that sorts by it), each with its margin; at most $limit rows. On a phone each row is a card. By shop, its delivery
// too (0077, DL23): charged, its cost on the orders, the carriers' charges not on an order, delivery after its cost; then its expenses, regular
// costs and real profit as Profit > Real profit has them (0079, EX15, EX39: from the shop's first day, "not started" before it; marked not
// complete while any unit counted has no buying price).
// A shop whose real profit has not started on these days (its first day after them; docs/decisions.md EX39): "not started (from 1 Dec 2026)".
$notStarted = static fn (?string $first): string => $first === null ? \CW\Ui\Words::PROFIT['real_not_set'] : \CW\Ui\Words::say('PROFIT', 'real_not_started', \CW\Ui\Html::day($first));
$byWords = ['shop' => \CW\Ui\Words::PROFIT['by_shop'], 'product' => \CW\Ui\Words::PROFIT['by_product'], 'brand' => \CW\Ui\Words::PROFIT['by_brand'],
    'supplier' => \CW\Ui\Words::PROFIT['by_supplier']];
$sortWords = [];
foreach ($sorts as $s) {
    $sortWords[$s] = \CW\Ui\Words::PROFIT['sort_' . $s];
}
$link = [];
foreach ($sortLinks as $sl) {
    $link[$sl['key']] = $sl['href'];
}
?>
<?= $head(\CW\Ui\Words::MENU['profit_breakdown'], intro: 'profit_breakdown', aside: $explain('profit', \CW\Ui\Words::PROFIT['profit'])) ?>
<?= $partial('profit_options', ['period' => $period, 'periods' => $periods, 'from' => $from, 'to' => $to, 'stores' => $stores, 'storeId' => $storeId, 'warn' => $warn,
    'extra' => ['by' => [\CW\Ui\Words::PROFIT['by'], $byWords, $by], 'sort' => [\CW\Ui\Words::PROFIT['sort'], $sortWords, $sort]]]) ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PROFIT['nothing'], \CW\Ui\Words::PROFIT['nothing_text']) ?>
<?php else: ?>
<?php if ($pending > 0): ?>
<p class="note" role="status"><?= $word('PROFIT', 'pending_note') ?></p>
<?php endif; ?>
<?php if ($orderRefunds > 0): ?>
<p class="note"><?= $say('PROFIT', 'order_refunds_note', \CW\Ui\Html::money($orderRefunds / 100)) ?></p>
<?php endif; ?>
<?php if ($more): ?>
<p class="note"><?= $say('PROFIT', 'top_only', $limit) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table profit-breakdown">
  <thead>
    <tr>
      <th scope="col"><?= $e($byWords[$by]) ?></th>
      <th scope="col" class="num"><a href="<?= $e($link['units']) ?>"><?= $word('PROFIT', 'units') ?></a></th>
      <th scope="col" class="num"><a href="<?= $e($link['sale']) ?>"><?= $word('PROFIT', 'goods') ?></a></th>
      <th scope="col" class="num"><a href="<?= $e($link['cost']) ?>"><?= $word('PROFIT', 'cost') ?></a></th>
      <th scope="col" class="num"><a href="<?= $e($link['profit']) ?>"><?= $word('PROFIT', 'profit') ?></a></th>
      <th scope="col" class="num"><a href="<?= $e($link[$sort === 'margin' ? 'margin_low' : 'margin']) ?>"><?= $word('PROFIT', 'margin') ?></a></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'no_cost_short') ?></th>
<?php if ($by === 'shop'): ?>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery_cost') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery_other') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery_result') ?></th>
<?php if ($withExpenses): ?>
      <th scope="col" class="num"><?= $word('PROFIT', 'col_expenses') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'col_regular') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'col_real') ?></th>
<?php endif; ?>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?php if ($r['href'] !== null): ?><a href="<?= $e($r['href']) ?>"><?php endif; ?><?php if ($r['label'] !== ''): ?><?= $e($r['label']) ?><?php elseif ($by === 'brand'): ?><?= $word('PROFIT', 'no_brand') ?><?php elseif ($by === 'supplier'): ?><?= $word('PROFIT', 'no_supplier') ?><?php else: ?><?= $word('PROFIT', 'no_item') ?><?php endif; ?><?php if ($r['href'] !== null): ?></a><?php endif; ?><?php if ($r['code'] !== null): ?> <span class="muted"><?= $e($r['code']) ?></span><?php endif; ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'units') ?>"><?= $n($r['f']['units']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'goods') ?>"><?= $money($r['f']['goods'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money($r['f']['cost'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $money($r['f']['profit'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($r['f']['margin_bp'])) ?><?php if ($r['f']['incomplete']): ?> <span class="muted" title="<?= $word('PROFIT', 'incomplete_note') ?>"><?= $word('PROFIT', 'incomplete') ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'no_cost_short') ?>"><?= $n($r['f']['no_cost']) ?><?php if ($r['f']['pending'] > 0): ?> <span class="muted"><?= $say('PROFIT', 'waiting_count', (string) $r['f']['pending']) ?></span><?php endif; ?></td>
<?php if ($by === 'shop'): ?>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery') ?>"><?= $money($r['f']['delivery'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery_cost') ?>"><?= $money($r['f']['delivery_cost'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery_other') ?>"><?= $money($r['f']['delivery_other'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery_result') ?>"><?= $money($r['f']['delivery_result'] / 100) ?></td>
<?php if ($withExpenses): ?>
<?php $rp = $real[(int) $r['id']] ?? null; ?>
<?php if ($rp === null): ?>
      <td class="num" data-label="<?= $word('PROFIT', 'col_expenses') ?>"><?= $word('PROFIT', 'real_not_a_shop') ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'col_regular') ?>"><?= $word('PROFIT', 'real_not_a_shop') ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'col_real') ?>"><?= $word('PROFIT', 'real_not_a_shop') ?></td>
<?php elseif (!$rp['started']): ?>
      <td class="num muted" data-label="<?= $word('PROFIT', 'col_expenses') ?>"><?= $e($notStarted($rp['from'])) ?></td>
      <td class="num muted" data-label="<?= $word('PROFIT', 'col_regular') ?>"><?= $e($notStarted($rp['from'])) ?></td>
      <td class="num muted" data-label="<?= $word('PROFIT', 'col_real') ?>"><?= $e($notStarted($rp['from'])) ?></td>
<?php else: ?>
      <td class="num" data-label="<?= $word('PROFIT', 'col_expenses') ?>"><?= $e(\CW\Expenses\ExpenseMoney::gbp((int) $rp['expenses'])) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'col_regular') ?>"><?= $e(\CW\Expenses\ExpenseMoney::gbp((int) $rp['regular'])) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'col_real') ?>"><?= $e(\CW\Expenses\ExpenseMoney::gbp((int) $rp['real'])) ?><?php if ($rp['counted_from'] !== null): ?> <span class="hint"><?= $say('PROFIT', 'real_cell_from', \CW\Ui\Html::day((string) $rp['counted_from'])) ?></span><?php endif; ?><?php if ($rp['incomplete']): ?> <?= $chip('needs', \CW\Ui\Words::PROFIT['real_cell_incomplete']) ?><?php endif; ?></td>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
