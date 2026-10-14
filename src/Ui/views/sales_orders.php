<?php
// Stock › Sales › Orders (pack D, docs/decisions.md PD3-PD5): read only. The toolbar (Filter: state, way in and the days; Export), the
// store selector (All stores and each store of the channel table, with its paid orders waiting to ship), the period in words, then the
// orders newest first, a page at a time. No form here posts anything.
?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
<?php if ($f['channel'] !== null): ?>
  <input type="hidden" name="channel" value="<?= $e($f['channel']) ?>">
<?php endif; ?>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
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
      <label><?= $word('SALE', 'from') ?> <input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
      <label><?= $word('SALE', 'to') ?> <input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="<?= $e($clear) ?>"><?= $word('SALE', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
<?php if ($rows !== []): ?>
  <div class="tb-end"><a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('SALE', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a></div>
<?php endif; ?>
</form>
<div class="head-help">
  <h1><?= $word('MENU', 'sales_orders') ?></h1>
  <?= $explain('sales_orders', \CW\Ui\Words::MENU['sales_orders']) ?>
</div>
<?= $intro('sales_orders') ?>
<?= $partial('store_seg', ['items' => $storeItems, 'countWords' => \CW\Ui\Words::SALE['store_count']]) ?>

<p class="muted sales-period"><?= $e($periodLine) ?><?php foreach ($periodLinks as $pl): ?><?php if (!$pl['current']): ?> · <a href="<?= $e($pl['href']) ?>"><?= $e($pl['label']) ?></a><?php endif; ?><?php endforeach; ?></p>
<?php if ($badDates !== null): ?>
<p class="note"><?= $e($badDates) ?></p>
<?php endif; ?>
<?php if ($stopped !== null): ?>
<p class="note"><?= $e($stopped['text']) ?> <a href="<?= $e($stopped['href']) ?>"><?= $word('SALE', 'read_on') ?></a></p>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($narrowed): ?>
<?= $empty(\CW\Ui\Words::SALE['none_filtered'], \CW\Ui\Words::SALE['none_filtered_text'], $clearAll, \CW\Ui\Words::SALE['clear']) ?>
<?php elseif ($f['channel'] !== null): ?>
<?= $empty(\CW\Ui\Words::SALE['none_store'], \CW\Ui\Words::SALE['none_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::SALE['none'], \CW\Ui\Words::SALE['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('SALE', 'board') ?></h2><p class="board-note"><?= $say('SALE', 'board_note', $page_size) ?> <?php if (count($rows) === 1): ?><?= $word('SALE', 'on_page_one') ?><?php else: ?><?= $say('SALE', 'on_page_many', count($rows)) ?><?php endif; ?></p></div>
<div class="table-wrap">
<table class="stack list board sales-orders">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('SALE', 'order') ?></th>
      <th scope="col"><?= $word('SALE', 'store') ?></th>
      <th scope="col" class="c-status"><?= $word('SALE', 'status') ?></th>
      <th scope="col"><?= $word('SALE', 'placed') ?></th>
      <th scope="col"><?= $word('SALE', 'paid') ?></th>
      <th scope="col"><?= $word('SALE', 'came_in') ?></th>
      <th scope="col" class="num"><?= $word('SALE', 'units') ?></th>
      <th scope="col"><?= $word('SALE', 'outcome') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><?php if ($r['try'] !== null): ?><span class="o-sub"><?= $e($r['try']) ?></span><?php endif; ?></th>
      <td data-label="<?= $word('SALE', 'store') ?>"><?= $e($r['store']) ?></td>
      <td class="c-status"><?= $chip($r['tone'], $r['chipWord']) ?></td>
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
<p class="foot"><?= $word('SALE', 'foot') ?></p>
<?php endif; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('SALE', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('SALE', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
