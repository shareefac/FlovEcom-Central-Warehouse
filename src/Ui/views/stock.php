<?php
// Inventory › Stock on hand, a simple list (the approved redesign of 30 Oct 2026, the Items artboard's look; docs/decisions.md RD-A7; W8
// before): the title and More (the warehouse, Clear); one search for an item and ONE Show choice (with stock, every item, below zero); then
// the items in two groups (out of stock, in stock), five columns: the item (it opens it; its CW number and brand under it), its status label,
// what is on hand (every warehouse together, or the one chosen; each warehouse's own figure is on the item page), reserved, free to sell.
// The pager under it. Read only.
?>
<?= $head(\CW\Ui\Words::MENU['stock'], intro: 'stock', aside: $explain('stock', \CW\Ui\Words::ITEM['stock']), side: $partial('rda_bar', $bar)) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_VIEW', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('STOCK_VIEW', 'find_hint') ?>"></div>
<?php if ($f['warehouse'] !== null): ?>
  <input type="hidden" name="warehouse" value="<?= $e($f['warehouse']) ?>">
<?php endif; ?>
<?php if ($f['show'] !== 'stock'): ?>
  <input type="hidden" name="show" value="<?= $e($f['show']) ?>">
<?php endif; ?>
  <?= $partial('rda_show', ['choices' => $show, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</form>

<?php if ($groups === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['none_filtered'], \CW\Ui\Words::STOCK_VIEW['none_filtered_text'], '/ui/stock', \CW\Ui\Words::STOCK_VIEW['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['none'], \CW\Ui\Words::STOCK_VIEW['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $key => $list): ?>
<?php $tone = $key === 'in' ? 'done' : 'blocked'; ?>
<div class="grp-block doc-group <?= $e($tone) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($key) ?>"><?= $word('STOCK_VIEW', 'group_' . $key) ?></button> <span class="grp-count" title="<?= $say('STOCK_VIEW', 'shown', count($list), $counts[$key]) ?>"><?= $n($counts[$key]) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($key) ?>">
  <table class="stack list board doc-list stock-lines">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STOCK_VIEW', 'product') ?></th>
        <th scope="col" class="c-status"><?= $word('STOCK_VIEW', 'status') ?></th>
        <th scope="col" class="num"><?= $word('INV_LIST', 'col_on_hand') ?></th>
        <th scope="col" class="num"><?= $word('STOCK_VIEW', 'reserved') ?></th>
        <th scope="col" class="num c-key"><?= $word('STOCK', 'available') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($tone) ?>"><?= $word('STOCK_VIEW', 'group_' . $key) ?></td>
        <td class="num" data-label="<?= $word('INV_LIST', 'col_on_hand') ?>"><?= $n($r['on_hand']) ?></td>
        <td class="num" data-label="<?= $word('STOCK_VIEW', 'reserved') ?>"><?= $n($r['reserved']) ?></td>
        <td class="num c-key" data-label="<?= $word('STOCK', 'available') ?>"><?= $n($r['available']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('STOCK_VIEW', 'showing', $rows_n, $total) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('STOCK_VIEW', 'prev') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('STOCK_VIEW', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
