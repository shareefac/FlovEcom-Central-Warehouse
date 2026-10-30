<?php
// Inventory › Stock history, a simple list (the approved redesign of 30 Oct 2026, the Item artboard's history and the Adjustments artboard's
// list; docs/decisions.md RD-A7; W8 before): the title and More (the warehouse and the figure, Clear); one search for an item and ONE Show
// choice (the kind of change); then the stock ledger newest first, one group a day, five columns: the item (it opens it; the time and its CW
// number under it), what changed (the record it was booked by, with its reason and who did it), the warehouse, the units with their sign,
// what was left after. Older changes by the ledger id. Read only.
?>
<?= $head(\CW\Ui\Words::MENU['movements'], intro: 'movements', menu: $bar) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_VIEW', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('STOCK_VIEW', 'find_hint') ?>"></div>
<?php if ($f['type'] !== null): ?>
  <input type="hidden" name="type" value="<?= $e($f['type']) ?>">
<?php endif; ?>
<?php if ($f['warehouse'] !== null): ?>
  <input type="hidden" name="warehouse" value="<?= $e($f['warehouse']) ?>">
<?php endif; ?>
<?php if ($f['figure'] !== 'on_hand'): ?>
  <input type="hidden" name="figure" value="<?= $e($f['figure']) ?>">
<?php endif; ?>
  <?= $showMine($show) ?>
</form>

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
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-day-<?= $e($gi) ?>"><?= $e($dayLabel) ?></button> <span class="grp-count"><?= $n(count($list)) ?></span></h3>
  <div class="table-wrap doc-board" id="g-day-<?= $e($gi) ?>">
  <table class="stack list board doc-list ledger">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STOCK_VIEW', 'product') ?></th>
        <th scope="col"><?= $word('STOCK_VIEW', 'type') ?></th>
        <th scope="col"><?= $word('ITEM', 'warehouse') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'change') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'after') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['time']) ?> · <?= $e($r['code']) ?></span></th>
        <td data-label="<?= $word('STOCK_VIEW', 'type') ?>"><?php if ($r['record'] !== null): ?><?= $e($r['record']['what']) ?><span class="o-sub"><?= $word('MOVEMENT', $r['type']) ?><?php if ($r['record']['why'] !== null): ?> · <?= $e($r['record']['why']) ?><?php endif; ?><?php if ($r['record']['given'] !== null): ?> · <?= $e($r['record']['given']) ?><?php endif; ?></span><?php else: ?><?= $word('MOVEMENT', $r['type']) ?><?php endif; ?><?php if ($r['bucket'] !== 'on_hand'): ?><span class="o-sub"><?= $word('STOCK', $r['bucket']) ?></span><?php endif; ?><span class="o-sub"><?php if ($r['record'] !== null): ?><a href="<?= $u($r['record']['href']) ?>"><?= $e($r['ref']) ?></a><?php else: ?><?= $e($r['ref']) ?><?php endif; ?><?php if ($r['ref'] !== null && $r['ref'] !== ''): ?> · <?php endif; ?><?= $e($r['who']) ?></span><?php if ($r['note'] !== null): ?><span class="o-sub"><?= $e($r['note']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'warehouse') ?>"><?= $e($r['warehouse']) ?></td>
        <td data-label="<?= $word('ITEM', 'change') ?>" class="num chg <?= $e($r['delta'] < 0 ? 'down' : 'up') ?>"><?= $e($r['delta'] > 0 ? '+' . number_format($r['delta']) : number_format($r['delta'])) ?></td>
        <td class="num" data-label="<?= $word('ITEM', 'after') ?>"><?= $n($r['after']) ?></td>
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
