<?php
// Sales › Shipments (pack D, docs/decisions.md PD6), a simple list (the approved redesign of 30 Oct 2026, docs/decisions.md RD-B6; before
// it W10): read only. One Show row (the four lists: waiting to ship, sent today, sent this week, sending undone; and the store, with each
// store's paid orders waiting to ship: docs/decisions.md DC33); then the list chosen, its rows grouped (late, then on time; a day of
// sending; sending undone), every group a heading with its coloured dot and its count over a white table card, five columns, the status a
// small pill, the order's number opening it (the old sixth and seventh columns under a cell: how long it waits under Paid, the order's note
// under its number, what its units are now under the status, who undid a sending under when; a late one says after how many hours it is
// late). No figures strip on a list (RD-B8): each list's count is in its Show choice. No form here posts anything.
?>
<?= $head(\CW\Ui\Words::MENU['sales_dispatch'], intro: 'sales_dispatch', aside: $explain('sales_dispatch', \CW\Ui\Words::MENU['sales_dispatch'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('DISPATCH', 'show') ?></label>
    <select id="tb-show" name="show">
<?php foreach ($shows as $s): ?>
      <option value="<?= $e($s['value']) ?>"<?php if ($s['current']): ?> selected<?php endif; ?>><?= $e($s['label']) ?> (<?= $n($s['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-store"><?= $word('BULK', 'stores') ?></label>
    <select id="tb-store" name="channel" title="<?= $say('BULK', 'store_count_title', \CW\Ui\Words::DISPATCH['store_count']) ?>">
<?php foreach ($storeItems as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>

<?php if ($lateCapped !== null): ?>
<p class="note"><?= $e($lateCapped) ?></p>
<?php endif; ?>
<?php if ($extra !== null): ?>
<p class="muted"><?= $e($extra) ?></p>
<?php endif; ?>

<?php if ($groups === []): ?>
<?= $empty($none[0], $none[1]) ?>
<?php else: ?>
<?php foreach ($groups as $grp): ?>
<div class="grp-block doc-group <?= $e($grp['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="<?= $e($grp['id']) ?>"><?= $e($grp['name']) ?></button> <span class="grp-count"><?php if (count($grp['rows']) === 1): ?><?= $word('RESV', 'group_one') ?><?php else: ?><?= $say('RESV', 'group_many', count($grp['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="<?= $e($grp['id']) ?>">
<?php if ($show === 'waiting'): ?>
  <table class="stack list board doc-list dispatch-waiting">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DISPATCH', 'order') ?></th>
        <th scope="col"><?= $word('DISPATCH', 'store') ?></th>
        <th scope="col" class="c-status"><?= $word('DISPATCH', 'status') ?></th>
        <th scope="col"><?= $word('DISPATCH', 'paid') ?></th>
        <th scope="col" class="num"><?= $word('DISPATCH', 'units') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><?php if ($r['note'] !== ''): ?><span class="o-sub"><?= $e($r['note']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('DISPATCH', 'store') ?>"><?= $e($r['store']) ?></td>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $e($r['chipWord']) ?></span><?php if ($r['group'] === 'late'): ?><span class="o-sub"><?= $say('DISPATCH', 'tile_late_sub', $lateHours) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DISPATCH', 'paid') ?>"><?= $when($r['paid']) ?><?php if ($r['waited'] !== ''): ?><span class="o-sub"><?= $word('DISPATCH', 'waiting') ?>: <?= $e($r['waited']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DISPATCH', 'units') ?>"><?= $n($r['units']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php elseif ($show === 'undone'): ?>
  <table class="stack list board doc-list dispatch-undone">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DISPATCH', 'order') ?></th>
        <th scope="col"><?= $word('DISPATCH', 'store') ?></th>
        <th scope="col" class="c-status"><?= $word('DISPATCH', 'now') ?></th>
        <th scope="col"><?= $word('DISPATCH', 'unit') ?></th>
        <th scope="col"><?= $word('DISPATCH', 'undone_at') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><?php if ($r['href'] !== null): ?><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><?php else: ?><?= $e($r['ref']) ?><?php endif; ?></th>
        <td data-label="<?= $word('DISPATCH', 'store') ?>"><?= $e($r['store']) ?></td>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $e($r['now']) ?></span></td>
        <td data-label="<?= $word('DISPATCH', 'unit') ?>"><?= $e($r['unit']) ?></td>
        <td data-label="<?= $word('DISPATCH', 'undone_at') ?>"><?= $when($r['at']) ?><span class="o-sub"><?= $word('DISPATCH', 'who') ?>: <?= $e($r['who']) ?></span></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <table class="stack list board doc-list dispatch-sent">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DISPATCH', 'order') ?></th>
        <th scope="col"><?= $word('DISPATCH', 'store') ?></th>
        <th scope="col" class="c-status"><?= $word('DISPATCH', 'status') ?></th>
        <th scope="col"><?= $word('DISPATCH', 'sent') ?></th>
        <th scope="col" class="num"><?= $word('DISPATCH', 'units') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a></th>
        <td data-label="<?= $word('DISPATCH', 'store') ?>"><?= $e($r['store']) ?></td>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $e($r['chipWord']) ?></span><?php if ($r['now'] !== '' && $r['now'] !== $r['chipWord']): ?><span class="o-sub"><?= $word('DISPATCH', 'now') ?>: <?= $e($r['now']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DISPATCH', 'sent') ?>"><?= $when($r['sent']) ?></td>
        <td class="num" data-label="<?= $word('DISPATCH', 'units') ?>"><?= $n($r['units']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($next_link !== null || $start_link !== null): ?>
<p class="pager"><?php if ($start_link !== null): ?><a href="<?= $e($start_link) ?>"><?= $word('DISPATCH', 'back') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $e($next_word) ?></a><?php endif; ?></p>
<?php endif; ?>
