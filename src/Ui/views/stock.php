<?php
// Stock › Overview, a report (the approved design of 29 Oct 2026, pattern 5, TP_Report; docs/decisions.md W8): the toolbar (one search for
// a product, then the warehouse and what to show), the figures strip (products, in stock, units, with no stock), then the products as a
// board in two groups (out of stock, in stock), each a coloured heading with its count over a card with its colour on the left edge: one
// row per product with its stock in each warehouse, reserved and what the websites can sell; the pager under it. Read only.
?>
<?= $head(\CW\Ui\Words::MENU['stock'], intro: 'stock', aside: $explain('stock', \CW\Ui\Words::ITEM['stock'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_VIEW', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('STOCK_VIEW', 'find_hint') ?>"></div>
  <div class="tb-state">
    <label for="tb-wh"><?= $word('STOCK_VIEW', 'warehouse') ?></label>
    <select id="tb-wh" name="warehouse">
      <option value=""><?= $word('STOCK_VIEW', 'all_warehouses') ?></option>
<?php foreach ($warehouses as $w): ?>
      <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label for="tb-show"><?= $word('STOCK_VIEW', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value="stock"<?php if ($f['show'] === 'stock'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'show_stock') ?></option>
      <option value="all"<?php if ($f['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'show_all') ?></option>
      <option value="negative"<?php if ($f['show'] === 'negative'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'show_negative') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/stock"><?= $word('STOCK_VIEW', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>

<section class="doc-progress rp-figs" aria-label="<?= $word('STOCK_VIEW', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_VIEW', 'tile_products') ?></p><p class="doc-step-big"><?= $n($figures['products']) ?></p><p class="step-sub"><?= $word('STOCK_VIEW', 'tile_products_sub') ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_VIEW', 'tile_in_stock') ?></p><p class="doc-step-big"><?= $n($figures['in_stock']) ?></p><p class="step-sub"><?= $word('STOCK_VIEW', 'tile_in_stock_sub') ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_VIEW', 'tile_units') ?></p><p class="doc-step-big"><?= $n($figures['units']) ?></p><p class="step-sub"><?= $word('STOCK_VIEW', 'tile_units_sub') ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_VIEW', 'tile_none') ?></p><p class="doc-step-big"><?= $n(max(0, $figures['products'] - $figures['in_stock'])) ?></p><p class="step-sub"><?= $word('STOCK_VIEW', 'tile_none_sub') ?></p></div>
</section>

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
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($key) ?>"><?= $word('STOCK_VIEW', 'group_' . $key) ?></button> <span class="grp-count"><?= $say('STOCK_VIEW', 'shown', count($list), $counts[$key]) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($key) ?>">
  <table class="stack list board doc-list stock-lines">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STOCK_VIEW', 'product') ?></th>
        <th scope="col" class="c-status"><?= $word('STOCK_VIEW', 'status') ?></th>
<?php foreach ($warehouses as $w): ?>
        <th scope="col" class="num"><?= $e($w['name']) ?><?php if (!$w['sellable']): ?><span class="sub-h"><?= $word('STOCK_VIEW', 'not_sellable') ?></span><?php endif; ?></th>
<?php endforeach; ?>
        <th scope="col" class="num"><?= $word('STOCK_VIEW', 'reserved') ?></th>
        <th scope="col" class="num c-key"><?= $word('STOCK', 'available') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($tone) ?>"><span class="chip"><?= $word('STOCK_VIEW', 'group_' . $key) ?></span></td>
<?php foreach ($warehouses as $i => $w): ?>
        <td class="num" data-label="<?= $e($w['name']) ?>"><?= $n($r['by_warehouse'][$i]) ?></td>
<?php endforeach; ?>
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
