<?php
// Matching › New Variants as a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W12.8): one search and ONE Show control (DC27's
// pop-over: the store, the period, what it shows: the store tabs, the period tabs and the state tabs before), Clear and Export at the right; the line of
// what the store sends and the check on arrival; then the page's variants, newest first, as ONE group (the store's, its count) over a card
// with the coloured edge, each row's state its solid cell, Open at the row's end; "Create as new items" in the bulk bar.
?>
<?= $head(\CW\Ui\Words::MENU['new_variants'], intro: 'new_variants', look: $lookOnly) ?>
<?php if ($store === null): ?>
<?= $empty(\CW\Ui\Words::NEW_VARIANTS['no_stores'], \CW\Ui\Words::NEW_VARIANTS['no_stores_text']) ?>
<?php else: ?>
<form class="toolbar" method="get" action="/ui/review/new-variants" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('NEW_VARIANTS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('FIND', 'variants') ?>" title="<?= $word('NEW_VARIANTS', 'search') ?>" aria-keyshortcuts="/" data-page-search></div>
<?php $on = ($period !== null ? 1 : 0) + ($state !== null ? 1 : 0); ?>
  <div class="tb-state">
    <details class="tb-pop tb-show">
      <summary class="btn"><?= $word('QUEUE', 'show') ?><?php if ($on > 0): ?> <span class="count"><?= $n($on) ?><span class="visually-hidden"> <?= $word('CARDS', 'filters_on') ?></span></span><?php endif; ?></summary>
      <div class="pop pop-form">
    <label for="tb-show"><?= $word('BULK', 'stores') ?>
    <select id="tb-show" name="channel">
<?php foreach ($stores as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-period"><?= $word('NEW_VARIANTS', 'periods') ?>
    <select id="tb-period" name="period">
<?php foreach ($periods as $p): ?>
      <option value="<?= $e($p['value']) ?>"<?php if ($p['current']): ?> selected<?php endif; ?>><?= $e($p['label']) ?></option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-state"><?= $word('NEW_VARIANTS', 'states') ?>
    <select id="tb-state" name="state">
<?php foreach ($states as $s): ?>
      <option value="<?= $e($s['key']) ?>"<?php if ($s['current']): ?> selected<?php endif; ?>><?= $e($s['label']) ?> (<?= $n($s['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    </label>
        <div class="pop-actions"><button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button></div>
      </div>
    </details>
  </div>
  <div class="tb-end">
<?php if ($text !== ''): ?>
    <a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a>
<?php endif; ?>
    <a class="btn ghost sm" href="<?= $e($csv_link) ?>" title="<?= $word('NEW_VARIANTS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
  </div>
</form>
<div class="muted where-from">
  <p><?= $e($storeLine) ?><?php if ($newest !== null): ?> <?= $say('NEW_VARIANTS', 'newest', \CW\Ui\Html::when($newest)) ?><?php endif; ?></p>
<?php if ($checkLine !== null): ?>
  <p><?= $e($checkLine) ?></p>
<?php endif; ?>
  <p><?= $e(implode(' ', $arrivalLines)) ?></p>
</div>
<?php if ($text !== '' && $total > 0): ?>
<?= $partial('search_note', ['count' => $total, 'text' => $text, 'clear' => $clear_link]) ?>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($text !== ''): ?>
<?= $empty(\CW\Ui\Words::NEW_VARIANTS['empty_search'], \CW\Ui\Words::NEW_VARIANTS['empty_search_text'] . ' ' . \CW\Ui\Words::FIND['try'], $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::NEW_VARIANTS['empty'], \CW\Ui\Words::NEW_VARIANTS['empty_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php $bulk = $bulkActions !== []; ?>
<?php if ($bulk): ?>
<form class="bulk-form" method="post" action="/ui/review/bulk" data-bulk data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="source" value="new">
<?php foreach ($bulkKeep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<div class="grp-block doc-group info">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-variants"><?= $say('NEW_VARIANTS', 'board', $store['name']) ?></button> <span class="grp-count"><?= $say('NEW_VARIANTS', 'shown', $total, count($rows)) ?></span><?php if ($bulk): ?> <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link><?= $word('BULK', 'select_all') ?></a><?php endif; ?></h3>
  <div class="table-wrap doc-board" id="g-variants">
  <table class="stack list board new-variants<?php if ($bulk): ?> picks<?php endif; ?>">
    <thead>
      <tr>
<?php if ($bulk): ?>
        <th scope="col" class="c-pick"><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BULK', 'select_all') ?></span></label></th>
<?php endif; ?>
        <th scope="col" class="c-item"><?= $word('NEW_VARIANTS', 'variant') ?></th>
        <th scope="col" class="c-status"><?= $word('NEW_VARIANTS', 'state') ?></th>
        <th scope="col"><?= $word('NEW_VARIANTS', 'option') ?></th>
        <th scope="col"><?= $word('NEW_VARIANTS', 'barcode') ?></th>
        <th scope="col" class="num"><?= $word('NEW_VARIANTS', 'price') ?></th>
        <th scope="col" class="num"><?= $word('QUEUE', 'sold_30') ?></th>
        <th scope="col" class="num"><?= $word('QUEUE', 'sold_365') ?></th>
        <th scope="col"><?= $word('NEW_VARIANTS', 'first_seen') ?></th>
        <th scope="col"><?= $word('NEW_VARIANTS', 'item') ?></th>
        <th scope="col"><span class="visually-hidden"><?= $word('QUEUE', 'open') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr<?php if ($r['picked']): ?> class="picked"<?php endif; ?>>
<?php if ($bulk): ?>
        <td class="c-pick" data-label=""><?php if ($r['pickable']): ?><label class="pick"><input type="checkbox" name="pick_<?= $e($r['listing_id']) ?>" value="1" data-bulk-row<?php if ($r['picked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('BULK', 'pick_row', $r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></span></label>
          <input type="hidden" name="ver_<?= $e($r['listing_id']) ?>" value="<?= $e($r['map_version']) ?>"><input type="hidden" name="prop_<?= $e($r['listing_id']) ?>" value="<?= $e($r['proposal_id']) ?>"><?php endif; ?></td>
<?php endif; ?>
        <th scope="row" class="c-head c-item">
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
        <td class="c-status doc-st <?= $e(\CW\Ui\Words::tone('NEW_VARIANT_STATE', $r['state'])) ?>"><?= $word('NEW_VARIANT_STATE', $r['state']) ?></td>
        <td data-label="<?= $word('NEW_VARIANTS', 'option') ?>"><?= $e($r['variant']) ?></td>
        <td data-label="<?= $word('NEW_VARIANTS', 'barcode') ?>"><?php foreach ($r['barcodes_shown'] as $i => $b): ?><?php if ($i > 0): ?> <br><?php endif; ?><span class="code"><?= $e($b) ?></span><?php endforeach; ?><?php if ($r['barcodes_more'] > 0): ?> <span class="muted small"><?= $say('NEW_VARIANTS', 'more_barcodes', $r['barcodes_more']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('NEW_VARIANTS', 'price') ?>"><?= $money($r['price']) ?></td>
        <td class="num" data-label="<?= $word('QUEUE', 'sold_30') ?>"><?= $n($r['units_30d'] ?? 0) ?></td>
        <td class="num" data-label="<?= $word('QUEUE', 'sold_365') ?>"><?= $n($r['units_365d'] ?? 0) ?></td>
        <td data-label="<?= $word('NEW_VARIANTS', 'first_seen') ?>"><?= $when($r['seen']) ?></td>
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
