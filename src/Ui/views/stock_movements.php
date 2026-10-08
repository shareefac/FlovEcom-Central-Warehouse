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
      <label><?= $word('STOCK_VIEW', 'type') ?>
        <select name="type">
          <option value=""><?= $word('STOCK_VIEW', 'all_types') ?></option>
<?php foreach ($types as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($f['type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('STOCK_VIEW', 'figure') ?>
        <select name="figure">
          <option value="on_hand"<?php if ($f['figure'] === 'on_hand'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'figure_on_hand') ?></option>
          <option value="all"<?php if ($f['figure'] === 'all'): ?> selected<?php endif; ?>><?= $word('STOCK_VIEW', 'figure_all') ?></option>
        </select>
      </label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/stock/movements"><?= $word('STOCK_VIEW', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<h1><?= $word('MENU', 'movements') ?></h1>
<?= $intro('movements') ?>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['none_filtered'], \CW\Ui\Words::STOCK_VIEW['none_filtered_text'], '/ui/stock/movements', \CW\Ui\Words::STOCK_VIEW['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::STOCK_VIEW['moves_none'], \CW\Ui\Words::STOCK_VIEW['moves_none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php $days = []; foreach ($rows as $r) { $days[$r['day']][] = $r; } ?>
<div class="board-head"><h2 class="board-title"><?= $word('STOCK_VIEW', 'moves_board') ?></h2><p class="board-note"><?= $word('STOCK_VIEW', 'moves_note') ?> <?= $say('STOCK_VIEW', 'moves_shown', \CW\Ui\StockViews::MOVES_PAGE) ?></p></div>
<?php $gi = 0; foreach ($days as $dayLabel => $list): $gi++; ?>
<div class="grp-block">
  <h3 class="grp-title t-primary"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-day-<?= $e($gi) ?>"><?= $e($dayLabel) ?></button><span class="grp-count"><?php if (count($list) === 1): ?><?= $word('STOCK_VIEW', 'moves_one') ?><?php else: ?><?= $say('STOCK_VIEW', 'moves_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap" id="g-day-<?= $e($gi) ?>">
  <table class="stack list ledger board t-primary">
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
        <td data-label="<?= $word('STOCK_VIEW', 'type') ?>"><?= $word('MOVEMENT', $r['type']) ?><?php if ($r['bucket'] !== 'on_hand'): ?><span class="o-sub"><?= $word('STOCK', $r['bucket']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'warehouse') ?>"><?= $e($r['warehouse']) ?></td>
        <td data-label="<?= $word('ITEM', 'change') ?>" class="num chg <?= $e($r['delta'] < 0 ? 'down' : 'up') ?>"><?= $e($r['delta'] > 0 ? '+' . number_format($r['delta']) : number_format($r['delta'])) ?></td>
        <td class="num" data-label="<?= $word('ITEM', 'after') ?>"><?= $n($r['after']) ?></td>
        <td data-label="<?= $word('ITEM', 'ref') ?>"><?= $e($r['ref']) ?><?php if ($r['note'] !== null): ?><span class="o-sub"><?= $e($r['note']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'who') ?>"><?= $e($r['who']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<p class="foot"><?= $word('STOCK_VIEW', 'moves_foot') ?></p>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('STOCK_VIEW', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('STOCK_VIEW', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
