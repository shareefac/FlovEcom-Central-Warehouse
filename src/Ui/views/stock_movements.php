<?php
// Stock › Movements, a report (the approved design of 29 Oct 2026, pattern 5, TP_Report; docs/decisions.md W8): the toolbar (one search
// for a product, then the warehouse, the kind of change and the figure), the figures strip (the changes this page shows, the units they
// added, the units they took away), then the stock ledger newest first as a board, one group a day, each a heading with its count over a
// card with the coloured left edge; older changes by the ledger id. Read only.
?>
<?= $head(\CW\Ui\Words::MENU['movements'], intro: 'movements') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_VIEW', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('STOCK_VIEW', 'find_hint') ?>"></div>
  <div class="tb-state">
    <label class="visually-hidden" for="tb-wh"><?= $word('STOCK_VIEW', 'warehouse') ?></label>
    <select id="tb-wh" name="warehouse">
      <option value=""><?= $word('STOCK_VIEW', 'all_warehouses') ?></option>
<?php foreach ($warehouses as $w): ?>
      <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-type"><?= $word('STOCK_VIEW', 'type') ?></label>
    <select id="tb-type" name="type">
      <option value=""><?= $word('STOCK_VIEW', 'all_types') ?></option>
<?php foreach ($types as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-figure"><?= $word('STOCK_VIEW', 'figure') ?></label>
    <select id="tb-figure" name="figure">
      <option value="on_hand"<?php if ($f['figure'] === 'on_hand'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'figure_on_hand') ?></option>
      <option value="all"<?php if ($f['figure'] === 'all'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'figure_all') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/stock/movements"><?= $word('STOCK_VIEW', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>

<section class="doc-progress rp-figs" aria-label="<?= $word('STOCK_VIEW', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_VIEW', 'fig_moves') ?></p><p class="doc-step-big"><?= $n($figures['moves']) ?></p><p class="step-sub"><?= $say('STOCK_VIEW', 'moves_shown', \CW\Ui\StockViews::MOVES_PAGE) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_VIEW', 'fig_in') ?></p><p class="doc-step-big"><?= $n($figures['in']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_VIEW', 'fig_out') ?></p><p class="doc-step-big"><?= $n($figures['out']) ?></p></div>
</section>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['none_filtered'], \CW\Ui\Words::STOCK_VIEW['none_filtered_text'], '/ui/stock/movements', \CW\Ui\Words::STOCK_VIEW['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['moves_none'], \CW\Ui\Words::STOCK_VIEW['moves_none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php $days = []; foreach ($rows as $r) { $days[$r['day']][] = $r; } ?>
<?php $gi = 0; foreach ($days as $dayLabel => $list): $gi++; ?>
<div class="grp-block doc-group info">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-day-<?= $e($gi) ?>"><?= $e($dayLabel) ?></button> <span class="grp-count"><?php if (count($list) === 1): ?><?= $word('STOCK_VIEW', 'moves_one') ?><?php else: ?><?= $say('STOCK_VIEW', 'moves_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-day-<?= $e($gi) ?>">
  <table class="stack list board doc-list ledger">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STOCK_VIEW', 'product') ?></th>
        <th scope="col"><?= $word('STOCK_VIEW', 'type') ?></th>
        <th scope="col"><?= $word('ITEM', 'warehouse') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'change') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'after') ?></th>
        <th scope="col"><?= $word('ITEM', 'ref') ?></th>
        <th scope="col"><?= $word('ITEM', 'who') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['time']) ?> · <?= $e($r['code']) ?></span></th>
        <td data-label="<?= $word('STOCK_VIEW', 'type') ?>"><?php if ($r['record'] !== null): ?><?= $e($r['record']['what']) ?><span class="o-sub"><?= $word('MOVEMENT', $r['type']) ?><?php if ($r['record']['why'] !== null): ?> · <?= $e($r['record']['why']) ?><?php endif; ?><?php if ($r['record']['given'] !== null): ?> · <?= $e($r['record']['given']) ?><?php endif; ?></span><?php else: ?><?= $word('MOVEMENT', $r['type']) ?><?php endif; ?><?php if ($r['bucket'] !== 'on_hand'): ?><span class="o-sub"><?= $word('STOCK', $r['bucket']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'warehouse') ?>"><?= $e($r['warehouse']) ?></td>
        <td data-label="<?= $word('ITEM', 'change') ?>" class="num chg <?= $e($r['delta'] < 0 ? 'down' : 'up') ?>"><?= $e($r['delta'] > 0 ? '+' . number_format($r['delta']) : number_format($r['delta'])) ?></td>
        <td class="num" data-label="<?= $word('ITEM', 'after') ?>"><?= $n($r['after']) ?></td>
        <td data-label="<?= $word('ITEM', 'ref') ?>"><?php if ($r['record'] !== null): ?><a href="<?= $u($r['record']['href']) ?>"><?= $e($r['ref']) ?></a><?php else: ?><?= $e($r['ref']) ?><?php endif; ?><?php if ($r['note'] !== null): ?><span class="o-sub"><?= $e($r['note']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'who') ?>"><?= $e($r['who']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('STOCK_VIEW', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('STOCK_VIEW', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
