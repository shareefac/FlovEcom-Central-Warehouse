<?php
// Profit › Profit by product (docs/decisions.md PR26): by shop, product, brand or main supplier, the biggest first by the measure chosen
// (a column head is a link that sorts by it), each with its margin; at most $limit rows. On a phone each row is a card.
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
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($r['f']['margin_bp'])) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'no_cost_short') ?>"><?= $n($r['f']['no_cost']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
