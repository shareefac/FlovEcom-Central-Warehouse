<?php
// Matching › Store Products as a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W12.6): one search and ONE Show control
// (DC27's pop-over: the store, the state, the sort: the store tabs, the state tabs and the Sort pop-over before), Clear at the right; then the page's
// website products grouped by their state, each group its colour and count over a card with the coloured edge, the state the solid cell;
// a row's Check & match and Open at its end; "Select all on this page" and the bulk bar where a bulk action applies. A store product's
// decision is its own page (TP_Match, W12.1).
?>
<?= $head(\CW\Ui\Words::MENU['store_products'], intro: 'store_products', look: $lookOnly) ?>
<?php if ($store === null): ?>
<?= $empty(\CW\Ui\Words::STORE_PRODUCTS['no_stores'], \CW\Ui\Words::STORE_PRODUCTS['no_stores_text']) ?>
<?php else: ?>
<form class="toolbar" method="get" action="/ui/review/store" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
<?php if ($unsoldShown): ?>
  <input type="hidden" name="unsold" value="1">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('QUEUE', 'text') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('FIND', 'listings') ?>" aria-keyshortcuts="/" data-page-search></div>
<?php $on = ($state !== null ? 1 : 0) + ($sort !== 'sold_30' ? 1 : 0); ?>
  <div class="tb-state">
    <details class="tb-pop tb-show">
      <summary class="btn"><?= $word('STORE_PRODUCTS', 'states') ?><?php if ($on > 0): ?> <span class="count"><?= $n($on) ?><span class="visually-hidden"> <?= $word('CARDS', 'filters_on') ?></span></span><?php endif; ?></summary>
      <div class="pop pop-form">
    <label for="tb-show"><?= $word('BULK', 'stores') ?>
    <select id="tb-show" name="channel">
<?php foreach ($stores as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-state"><?= $word('STORE_PRODUCTS', 'state') ?>
    <select id="tb-state" name="state">
<?php foreach ($states as $s): ?>
      <option value="<?= $e($s['key'] ?? '') ?>"<?php if ($s['current']): ?> selected<?php endif; ?>><?= $e($s['label']) ?> (<?= $n($s['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-sort"><?= $word('STORE_PRODUCTS', 'sort_by') ?>
    <select id="tb-sort" name="sort">
<?php foreach ($sorts as $s): ?>
      <option value="<?= $e($s) ?>"<?php if ($sort === $s): ?> selected<?php endif; ?>><?= $word('STORE_PRODUCTS', 'sort_' . $s) ?></option>
<?php endforeach; ?>
    </select>
    </label>
        <div class="pop-actions"><button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button></div>
      </div>
    </details>
  </div>
  <div class="tb-end">
<?php if ($text !== '' || $state !== null || $sort !== 'sold_30'): ?>
    <a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>
<?php if ($text !== '' && $total > 0): ?>
<?= $partial('search_note', ['count' => $total, 'text' => $text, 'clear' => $clear_search]) ?>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($text !== '' || $state !== null): ?>
<?= $empty(\CW\Ui\Words::STORE_PRODUCTS['empty_filter'], \CW\Ui\Words::STORE_PRODUCTS['empty_filter_text'] . ($text !== '' ? ' ' . \CW\Ui\Words::FIND['try'] : ''), $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::STORE_PRODUCTS['empty'], \CW\Ui\Words::STORE_PRODUCTS['empty_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php $bulk = $bulkActions !== []; ?>
<?php if ($newHint !== null): ?>
<p class="muted store-new-hint"><?= $e($newHint) ?></p>
<?php endif; ?>
<?php if ($bulk): ?>
<form class="bulk-form" method="post" action="/ui/review/bulk" data-bulk data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="source" value="store">
<?php foreach ($bulkKeep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<?php foreach ($groups as $gi => $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $n(count($g['rows'])) ?></span><?php if ($bulk && $gi === 0): ?> <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link><?= $word('BULK', 'select_all') ?></a><?php endif; ?></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board store-products<?php if ($bulk): ?> picks<?php endif; ?>">
    <thead>
      <tr>
<?php if ($bulk): ?>
        <th scope="col" class="c-pick"><?php if ($gi === 0): ?><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BULK', 'select_all') ?></span></label><?php endif; ?></th>
<?php endif; ?>
        <th scope="col" class="c-item"><?= $word('QUEUE', 'product') ?></th>
        <th scope="col" class="c-status"><?= $word('STORE_PRODUCTS', 'state') ?></th>
        <th scope="col"><?= $word('STORE_PRODUCTS', 'warehouse') ?></th>
        <th scope="col" class="num"><?= $word('QUEUE', 'sold_30') ?></th>
        <th scope="col" class="num"><?= $word('QUEUE', 'sold_365') ?></th>
        <th scope="col"><span class="visually-hidden"><?= $word('QUEUE', 'open') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr id="l-<?= $e($r['listing_id']) ?>"<?php if ($r['picked']): ?> class="picked"<?php endif; ?>>
<?php if ($bulk): ?>
        <td class="c-pick" data-label=""><?php if ($r['pickable']): ?><label class="pick"><input type="checkbox" name="pick_<?= $e($r['listing_id']) ?>" value="1" data-bulk-row<?php if ($r['picked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('BULK', 'pick_row', $r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></span></label>
          <input type="hidden" name="ver_<?= $e($r['listing_id']) ?>" value="<?= $e($r['map_version']) ?>"><input type="hidden" name="prop_<?= $e($r['listing_id']) ?>" value="<?= $e($r['proposal_id']) ?>"><?php endif; ?></td>
<?php endif; ?>
        <th scope="row" class="c-head c-item">
          <a class="o-name" href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
          <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
          <span class="o-sub"><?php if ($r['brand'] !== null): ?><?= $e($r['brand']) ?> · <?php endif; ?><?= $say('QUEUE', 'option', (string) $r['variant']) ?></span>
<?php if ($r['manual'] !== null): ?>
          <?= $partial('one_at_a_time', ['why' => $r['manual']]) ?>
<?php endif; ?>
<?php if ($r['check'] !== null): ?>
          <?= $partial('check_fold', ['check' => $r['check'], 'listing' => $r['listing_id'], 'title' => $r['title'], 'link' => $r['link']]) ?>
<?php endif; ?>
        </th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $e($g['name']) ?></span></td>
        <td class="c-wide" data-label="<?= $word('STORE_PRODUCTS', 'warehouse') ?>">
<?php if ($r['sku_code'] !== null): ?>
          <?= $e($r['sku_code']) ?> <span class="muted"><?= $e($r['sku_name']) ?></span><span class="o-sub"><?= $e($r['sale_uses']) ?></span>
<?php elseif ($r['landing_why'] !== null): ?>
          <span class="o-sub landing-why"><?= $e($r['landing_why']) ?></span>
<?php elseif ($r['band'] !== null && $r['state'] !== 'ignored'): ?>
          <span class="tag <?= $e(\CW\Ui\Words::tone('BAND', $r['band'])) ?>"><?= $word('BAND', $r['band']) ?></span>
<?php if ($r['proposed_new']): ?>
          <?= $word('QUEUE', 'new_product') ?>
<?php elseif ($r['proposed_code'] !== null): ?>
          <?= $e($r['proposed_code']) ?> <span class="muted"><?= $e($r['proposed_name']) ?></span>
<?php endif; ?>
<?php elseif ($r['state'] === 'not_matched'): ?>
          <span class="muted"><?= $word('STORE_PRODUCTS', 'no_suggestion') ?></span>
<?php endif; ?>
<?php if ($r['pending'] !== null): ?>
          <span class="o-sub"><?= $say('STORE_PRODUCTS', 'pending', $r['pending']) ?></span>
<?php endif; ?>
        </td>
        <td class="num" data-label="<?= $word('QUEUE', 'sold_30') ?>"><?= $n($r['units_30d'] ?? 0) ?></td>
        <td class="num" data-label="<?= $word('QUEUE', 'sold_365') ?>"><?= $n($r['units_365d'] ?? 0) ?></td>
        <td class="c-next"><a class="btn sm" href="<?= $e($r['link']) ?>"><?= $word('QUEUE', 'open') ?></a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($bulk): ?>
<?= $partial('bulk_bar', ['actions' => $bulkActions, 'max' => $bulkMax]) ?>
</form>
<?php endif; ?>
<?php endif; ?>
<?php if ($unsold !== null): ?>
<p class="muted unsold-line" data-unsold><?= $e($unsold['text']) ?> <a href="<?= $e($unsold['href']) ?>"><?= $e($unsold['link']) ?></a></p>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
