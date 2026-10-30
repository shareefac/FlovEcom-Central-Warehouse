<?php
// Stock › Sales › Orders (pack D, docs/decisions.md PD3-PD5), a board (the approved design of 29 Oct 2026, pattern 1; docs/decisions.md W10):
// read only. One Show row (the state the list reads, with the way in, the days typed and the store beside it: All stores and each store of the
// channel table, with its paid orders waiting to ship; docs/decisions.md DC33), Clear and Export at the right; the period in words; then the page's
// orders grouped by state, newest first in each, every group a coloured heading with its count over a card with its colour on the left
// edge, the status cell filled with it, the order's number opening it. No form here posts anything.
?>
<?= $head(\CW\Ui\Words::MENU['sales_orders'], intro: 'sales_orders', aside: $explain('sales_orders', \CW\Ui\Words::MENU['sales_orders'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="period" value="<?= $e($f['period']) ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('DOC_LIST', 'show') ?></label>
    <select id="tb-show" name="state">
      <option value=""><?= $word('SALE', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-origin"><?= $word('SALE', 'origin') ?></label>
    <select id="tb-origin" name="origin" title="<?= $word('SALE', 'origin') ?>">
      <option value=""><?= $word('SALE', 'any_origin') ?></option>
<?php foreach ($origins as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['origin'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label class="tb-day"><?= $word('SALE', 'from') ?> <input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
    <label class="tb-day"><?= $word('SALE', 'to') ?> <input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
    <label class="visually-hidden" for="tb-store"><?= $word('BULK', 'stores') ?></label>
    <select id="tb-store" name="channel" title="<?= $say('BULK', 'store_count_title', \CW\Ui\Words::SALE['store_count']) ?>">
<?php foreach ($storeItems as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="<?= $e($clear) ?>"><?= $word('SALE', 'clear') ?></a>
<?php endif; ?>
<?php if ($rows_n > 0): ?>
    <a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('SALE', 'download') ?>" download><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
<?php endif; ?>
  </div>
</form>

<p class="muted sales-period"><?= $e($periodLine) ?><?php foreach ($periodLinks as $pl): ?><?php if (!$pl['current']): ?> · <a href="<?= $e($pl['href']) ?>"><?= $e($pl['label']) ?></a><?php endif; ?><?php endforeach; ?></p>
<?php if ($badDates !== null): ?>
<p class="note"><?= $e($badDates) ?></p>
<?php endif; ?>
<?php if ($stopped !== null): ?>
<p class="note"><?= $e($stopped['text']) ?> <a href="<?= $e($stopped['href']) ?>"><?= $word('SALE', 'read_on') ?></a></p>
<?php endif; ?>

<?php if ($groups === []): ?>
<?php if ($narrowed): ?>
<?= $empty(\CW\Ui\Words::SALE['none_filtered'], \CW\Ui\Words::SALE['none_filtered_text'], $clearAll, \CW\Ui\Words::SALE['clear']) ?>
<?php elseif ($f['channel'] !== null): ?>
<?= $empty(\CW\Ui\Words::SALE['none_store'], \CW\Ui\Words::SALE['none_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::SALE['none'], \CW\Ui\Words::SALE['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g => $grp): ?>
<div class="grp-block doc-group <?= $e($grp['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($grp['name']) ?></button> <span class="grp-count"><?php if (count($grp['rows']) === 1): ?><?= $word('RESV', 'group_one') ?><?php else: ?><?= $say('RESV', 'group_many', count($grp['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list sales-orders">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('SALE', 'order') ?></th>
        <th scope="col" class="c-status"><?= $word('SALE', 'status') ?></th>
        <th scope="col"><?= $word('SALE', 'store') ?></th>
        <th scope="col"><?= $word('SALE', 'placed') ?></th>
        <th scope="col"><?= $word('SALE', 'paid') ?></th>
        <th scope="col"><?= $word('SALE', 'came_in') ?></th>
        <th scope="col" class="num"><?= $word('SALE', 'units') ?></th>
        <th scope="col"><?= $word('SALE', 'outcome') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><?php if ($r['try'] !== null): ?><span class="o-sub"><?= $e($r['try']) ?></span><?php endif; ?></th>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><?= $e($r['chipWord']) ?></td>
        <td data-label="<?= $word('SALE', 'store') ?>"><?= $e($r['store']) ?></td>
        <td data-label="<?= $word('SALE', 'placed') ?>"><?= $when($r['placed']) ?></td>
        <td data-label="<?= $word('SALE', 'paid') ?>"><?= $when($r['paid']) ?></td>
        <td data-label="<?= $word('SALE', 'came_in') ?>"><?= $e($r['origin']) ?></td>
        <td class="num" data-label="<?= $word('SALE', 'units') ?>"><?= $n($r['units']) ?></td>
        <td class="c-wide" data-label="<?= $word('SALE', 'outcome') ?>"><?= $e($r['outcome']) ?><?php if ($r['outcome_at'] !== null): ?><span class="o-sub"><?= $e($r['outcome_at']) ?></span><?php endif; ?><?php if ($r['unlinked'] !== null): ?><span class="o-sub"><?= $e($r['unlinked']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('SALE', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('SALE', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
