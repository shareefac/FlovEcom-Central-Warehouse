<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_VIEW', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('STOCK_VIEW', 'warehouse') ?>
        <select name="warehouse">
          <option value=""><?= $word('STOCK_VIEW', 'all_warehouses') ?></option>
<?php foreach ($warehouses as $w): ?>
          <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('STOCK_VIEW', 'show') ?>
        <select name="show">
          <option value="stock"<?php if ($f['show'] === 'stock'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'show_stock') ?></option>
          <option value="all"<?php if ($f['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'show_all') ?></option>
          <option value="negative"<?php if ($f['show'] === 'negative'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'show_negative') ?></option>
        </select>
      </label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/stock"><?= $word('STOCK_VIEW', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<div class="head-help">
  <h1><?= $word('MENU', 'stock') ?></h1>
  <?= $explain('stock', \CW\Ui\Words::ITEM['stock']) ?>
</div>
<?= $intro('stock') ?>

<section class="home-tiles" aria-label="<?= $word('STOCK_VIEW', 'figures') ?>">
  <div class="kpis-wrap"><ul class="kpis four">
    <li class="kpi"><p class="kpi-label"><?= $word('STOCK_VIEW', 'tile_products') ?></p><p class="kpi-value"><?= $n($figures['products']) ?></p><p class="kpi-sub"><?= $word('STOCK_VIEW', 'tile_products_sub') ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('STOCK_VIEW', 'tile_in_stock') ?></p><p class="kpi-value"><?= $n($figures['in_stock']) ?></p><p class="kpi-sub"><?= $word('STOCK_VIEW', 'tile_in_stock_sub') ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('STOCK_VIEW', 'tile_units') ?></p><p class="kpi-value"><?= $n($figures['units']) ?></p><p class="kpi-sub"><?= $word('STOCK_VIEW', 'tile_units_sub') ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('STOCK_VIEW', 'tile_none') ?></p><p class="kpi-value"><?= $n(max(0, $figures['products'] - $figures['in_stock'])) ?></p><p class="kpi-sub"><?= $word('STOCK_VIEW', 'tile_none_sub') ?></p></li>
  </ul></div>
</section>
<p class="note"><?= $word('STOCK_VIEW', 'later') ?></p>

<?php if ($groups === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['none_filtered'], \CW\Ui\Words::STOCK_VIEW['none_filtered_text'], '/ui/stock', \CW\Ui\Words::STOCK_VIEW['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['none'], \CW\Ui\Words::STOCK_VIEW['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('STOCK_VIEW', 'board') ?></h2><p class="board-note"><?= $word('STOCK_VIEW', 'board_note') ?><?php if ($pages > 1): ?> <?= $say('STOCK_VIEW', 'page', $page_no, $pages) ?><?php endif; ?></p></div>
<?php foreach ($groups as $key => $list): ?>
<?php $tone = $key === 'in' ? 'done' : 'blocked'; ?>
<div class="grp-block">
  <h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($key) ?>"><?= $word('STOCK_VIEW', 'group_' . $key) ?></button><span class="grp-count"><?= $say('STOCK_VIEW', 'shown', count($list), $counts[$key]) ?></span></h3>
  <div class="table-wrap" id="g-<?= $e($key) ?>">
  <table class="stack list stock-lines board <?= $e($tone) ?>">
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
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
        <td class="c-status"><?= $chip($tone, \CW\Ui\Words::STOCK_VIEW['group_' . $key]) ?></td>
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
<p class="foot"><?= $word('STOCK_VIEW', 'foot') ?></p>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('STOCK_VIEW', 'showing', $rows_n, $total) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('STOCK_VIEW', 'prev') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('STOCK_VIEW', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
