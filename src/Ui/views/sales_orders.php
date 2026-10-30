<?php
// Sales › Sales orders (pack D, docs/decisions.md PD3-PD5), a simple list (the approved Sales.dc.html of 30 Oct 2026, docs/decisions.md
// RD-B3; before it W10, DC33): read only. Download at the head's right (its one button: no main action on a read-only list); one Show (All
// stores and each store of the channel table, with its paid orders waiting to ship) with its Show button; the rest of the old Show row
// under More (the period: any time or the last days, From / To typed; the state; the way in, sent by the row's one Show button; Clear);
// the period in words; then the page's orders grouped as the design groups them (waiting to ship, shipped, returned or cancelled; the
// store's unpaid ones after them), newest first in each, every group a heading with its coloured dot and its count over a white table card;
// four columns: the order (it opens it; what became of its units under it when they ended more than one way), the store, placed, units.
// No form here posts anything.
?>
<?= $head(\CW\Ui\Words::MENU['sales_orders'], intro: 'sales_orders', aside: $explain('sales_orders', \CW\Ui\Words::MENU['sales_orders']),
    side: $rows_n > 0 ? '<a class="btn" href="' . $e($csv) . '" title="' . $word('SALE', 'download') . '" download>' . $word('SALE', 'download_short') . '</a>' : '') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="period" value="<?= $e($f['period']) ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('DOC_LIST', 'show') ?></label>
    <select id="tb-show" name="channel" title="<?= $say('BULK', 'store_count_title', \CW\Ui\Words::SALE['store_count']) ?>">
<?php foreach ($storeItems as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
    <details class="more tb-more"<?php if ($f['state'] !== null || $f['origin'] !== null || $f['from'] !== null || $f['to'] !== null): ?> open<?php endif; ?>>
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu sales-more">
<?php foreach ($periodLinks as $pl): ?>
<?php if (!$pl['current']): ?>
        <a class="menu-item" href="<?= $e($pl['href']) ?>"><?= $e($pl['label']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
        <label><?= $word('SALE', 'state') ?>
          <select name="state">
            <option value=""><?= $word('SALE', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
            <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label><?= $word('SALE', 'origin') ?>
          <select name="origin">
            <option value=""><?= $word('SALE', 'any_origin') ?></option>
<?php foreach ($origins as $code => $label): ?>
            <option value="<?= $e($code) ?>"<?php if ($f['origin'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label class="tb-day"><?= $word('SALE', 'from') ?> <input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
        <label class="tb-day"><?= $word('SALE', 'to') ?> <input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
<?php if ($filtered): ?>
        <a class="menu-item" href="<?= $e($clear) ?>"><?= $word('SALE', 'clear') ?></a>
<?php endif; ?>
      </div>
    </details>
  </div>
</form>

<p class="muted sales-period"><?= $e($periodLine) ?></p>
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
        <th scope="col"><?= $word('SALE', 'store') ?></th>
        <th scope="col"><?= $word('SALE', 'placed') ?></th>
        <th scope="col" class="num"><?= $word('SALE', 'units') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><?php if ($r['mixed'] !== null): ?><span class="o-sub"><?= $e($r['mixed']) ?></span><?php endif; ?><?php if ($r['unlinked'] !== null): ?><span class="o-sub"><?= $e($r['unlinked']) ?></span><?php endif; ?><?php if ($r['try'] !== null): ?><span class="o-sub"><?= $e($r['try']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('SALE', 'store') ?>"><?= $e($r['store']) ?></td>
        <td data-label="<?= $word('SALE', 'placed') ?>"><?= $when($r['placed']) ?></td>
        <td class="num" data-label="<?= $word('SALE', 'units') ?>"><?= $n($r['units']) ?></td>
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
