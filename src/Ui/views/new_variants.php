<?php
// Products › New Variants (docs/decisions.md U131-U138): one store's variants first seen in a period, newest first, with the "seed"
// (tick, "Create as new items") in the existing action bar. The stores come from the channel table; every word from Words. In the
// owner's words (U122) a store's sellable option is a VARIANT and the warehouse record it is matched to an ITEM. Each row says what
// it is (New, Suggested match, No suggestion, …) and why, so a person sees which rows the seed takes before ticking; a store where
// no computer check has marked a variant New is told so in the box ($checkLine), and the box says whether the check on arrival is on
// for the store, how many variants wait for it and what its last run did there ($arrivalLines, U144).
?>
<?= $head(\CW\Ui\Words::MENU['new_variants'], intro: 'new_variants', look: $lookOnly) ?>
<?php if ($store === null): ?>
<?= $empty(\CW\Ui\Words::NEW_VARIANTS['no_stores'], \CW\Ui\Words::NEW_VARIANTS['no_stores_text']) ?>
<p class="muted"><?= $word('NEW_VARIANTS', 'where_from') ?></p>
<?php else: ?>
<?= $partial('store_seg', ['items' => $stores, 'countWords' => \CW\Ui\Words::NEW_VARIANTS['store_count']]) ?>
<div class="alert info where-from" role="note">
  <p><?= $word('NEW_VARIANTS', 'where_from') ?></p>
  <p><?= $e($storeLine) ?><?php if ($newest !== null): ?> <?= $say('NEW_VARIANTS', 'newest', \CW\Ui\Html::when($newest)) ?><?php endif; ?></p>
<?php if ($checkLine !== null): ?>
  <p><?= $e($checkLine) ?></p>
<?php endif; ?>
  <p><?= $e(implode(' ', $arrivalLines)) ?></p>
</div>

<form class="toolbar" method="get" action="/ui/review/new-variants" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="channel" value="<?= $e($store['code']) ?>">
<?php if ($period !== null): ?>
  <input type="hidden" name="period" value="<?= $e($period) ?>">
<?php endif; ?>
<?php if ($state !== null): ?>
  <input type="hidden" name="state" value="<?= $e($state) ?>">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="nv-q"><?= $word('NEW_VARIANTS', 'search') ?></label><input id="nv-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('FIND', 'variants') ?>" title="<?= $word('NEW_VARIANTS', 'search') ?>" aria-keyshortcuts="/" data-page-search></div>
  <div class="tb-end">
    <a class="btn ghost sm" href="<?= $e($csv_link) ?>" title="<?= $word('NEW_VARIANTS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
  </div>
</form>
<?php if ($text !== '' && $total > 0): ?>
<?= $partial('search_note', ['count' => $total, 'text' => $text, 'clear' => $clear_link]) ?>
<?php endif; ?>

<nav class="tabs period-tabs" aria-label="<?= $word('NEW_VARIANTS', 'periods') ?>">
<?php foreach ($periods as $p): ?>
  <a class="tab" href="<?= $e($p['href']) ?>"<?php if ($p['current']): ?> aria-current="page"<?php endif; ?>><?= $e($p['label']) ?></a>
<?php endforeach; ?>
</nav>
<nav class="tabs state-tabs" aria-label="<?= $word('NEW_VARIANTS', 'states') ?>">
<?php foreach ($states as $s): ?>
  <a class="tab" href="<?= $e($s['href']) ?>"<?php if ($s['current']): ?> aria-current="page"<?php endif; ?>><?php if ($s['tone'] !== null): ?><span class="dot <?= $e($s['tone']) ?>" aria-hidden="true"></span><?php endif; ?><?= $e($s['label']) ?> <span class="count"><?= $n($s['count']) ?></span></a>
<?php endforeach; ?>
</nav>

<?php if ($rows === []): ?>
<?php if ($text !== ''): ?>
<?= $empty(\CW\Ui\Words::NEW_VARIANTS['empty_search'], \CW\Ui\Words::NEW_VARIANTS['empty_search_text'] . ' ' . \CW\Ui\Words::FIND['try'], $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::NEW_VARIANTS['empty'], \CW\Ui\Words::NEW_VARIANTS['empty_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php $bulk = $bulkActions !== []; ?>
<?php if ($bulk): ?>
<form class="bulk-form" method="post" action="/ui/review/bulk" data-bulk>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="source" value="new">
<?php foreach ($bulkKeep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<div class="board-head"><h2 class="board-title"><?= $say('NEW_VARIANTS', 'board', $store['name']) ?></h2><p class="board-note"><?= $say('NEW_VARIANTS', 'shown', $total, count($rows)) ?><?php if ($bulk): ?> · <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link><?= $word('BULK', 'select_all') ?></a><?php endif; ?></p></div>
<div class="table-wrap">
<table class="stack list board row-strips new-variants<?php if ($bulk): ?> picks<?php endif; ?>">
  <thead>
    <tr>
<?php if ($bulk): ?>
      <th scope="col" class="c-pick"><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BULK', 'select_all') ?></span></label></th>
<?php endif; ?>
      <th scope="col" class="c-item"><?= $word('NEW_VARIANTS', 'variant') ?></th>
      <th scope="col"><?= $word('NEW_VARIANTS', 'option') ?></th>
      <th scope="col"><?= $word('NEW_VARIANTS', 'barcode') ?></th>
      <th scope="col" class="num"><?= $word('NEW_VARIANTS', 'price') ?></th>
      <th scope="col" class="num"><?= $word('QUEUE', 'sold_30') ?></th>
      <th scope="col" class="num"><?= $word('QUEUE', 'sold_365') ?></th>
      <th scope="col"><?= $word('NEW_VARIANTS', 'first_seen') ?></th>
      <th scope="col" class="c-status"><?= $word('NEW_VARIANTS', 'state') ?></th>
      <th scope="col"><?= $word('NEW_VARIANTS', 'item') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $word('QUEUE', 'open') ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('NEW_VARIANT_STATE', $r['state'])) ?><?php if ($r['picked']): ?> picked<?php endif; ?>">
<?php if ($bulk): ?>
      <td class="c-pick" data-label=""><?php if ($r['pickable']): ?><label class="pick"><input type="checkbox" name="pick_<?= $e($r['listing_id']) ?>" value="1" data-bulk-row<?php if ($r['picked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('BULK', 'pick_row', $r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></span></label>
        <input type="hidden" name="ver_<?= $e($r['listing_id']) ?>" value="<?= $e($r['map_version']) ?>"><input type="hidden" name="prop_<?= $e($r['listing_id']) ?>" value="<?= $e($r['proposal_id']) ?>"><?php endif; ?></td>
<?php endif; ?>
      <th scope="row" class="c-head">
        <a class="o-name" href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
<?php if ($r['brand'] !== null): ?>
        <span class="o-sub muted"><?= $e($r['brand']) ?></span>
<?php endif; ?>
<?php if ($r['manual'] !== null): ?>
        <?= $partial('one_at_a_time', ['why' => $r['manual']]) ?>
<?php endif; ?>
      </th>
      <td data-label="<?= $word('NEW_VARIANTS', 'option') ?>"><?= $e($r['variant']) ?></td>
      <td data-label="<?= $word('NEW_VARIANTS', 'barcode') ?>"><?php foreach ($r['barcodes_shown'] as $i => $b): ?><?php if ($i > 0): ?> <br><?php endif; ?><span class="code"><?= $e($b) ?></span><?php endforeach; ?><?php if ($r['barcodes_more'] > 0): ?> <span class="muted small"><?= $say('NEW_VARIANTS', 'more_barcodes', $r['barcodes_more']) ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('NEW_VARIANTS', 'price') ?>"><?= $money($r['price']) ?></td>
      <td class="num" data-label="<?= $word('QUEUE', 'sold_30') ?>"><?= $n($r['units_30d'] ?? 0) ?></td>
      <td class="num" data-label="<?= $word('QUEUE', 'sold_365') ?>"><?= $n($r['units_365d'] ?? 0) ?></td>
      <td data-label="<?= $word('NEW_VARIANTS', 'first_seen') ?>"><?= $when($r['seen']) ?></td>
      <td class="c-status"><?= $stateChip('NEW_VARIANT_STATE', $r['state']) ?></td>
      <td class="c-wide" data-label="<?= $word('NEW_VARIANTS', 'item') ?>"><?= $e($r['detail']) ?><?php if ($r['name'] !== null): ?> <span class="muted"><?= $e($r['name']) ?></span><?php endif; ?>
<?php foreach ($r['notes'] as $note): ?>
        <span class="o-sub site-note"><?= $e($note) ?></span>
<?php endforeach; ?>
      </td>
      <td class="c-next"><a class="btn sm" href="<?= $e($r['link']) ?>"><?= $word('QUEUE', 'open') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($bulk): ?>
<p class="muted"><?= $word('NEW_VARIANTS', 'seed_note') ?></p>
<?= $partial('bulk_bar', ['actions' => $bulkActions, 'max' => $bulkMax]) ?>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
