<h1><?= $word('MENU', 'store_products') ?></h1>
<?= $intro('store_products', $lookOnly) ?>
<?php if ($store === null): ?>
<?= $empty(\CW\Ui\Words::STORE_PRODUCTS['no_stores'], \CW\Ui\Words::STORE_PRODUCTS['no_stores_text']) ?>
<?php else: ?>
<?= $partial('store_seg', ['items' => $stores, 'countWords' => \CW\Ui\Words::BULK['store_count_products']]) ?>

<form class="toolbar" method="get" action="/ui/review/store" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="channel" value="<?= $e($store['code']) ?>">
<?php if ($state !== null): ?>
  <input type="hidden" name="state" value="<?= $e($state) ?>">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="sp-q"><?= $word('QUEUE', 'text') ?></label><input id="sp-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-sort" aria-hidden="true"></span><span><?= $word('UI', 'sort') ?></span></summary>
    <div class="pop pop-form">
      <label><?= $word('STORE_PRODUCTS', 'sort_by') ?>
        <select name="sort">
<?php foreach ($sorts as $s): ?>
          <option value="<?= $e($s) ?>"<?php if ($sort === $s): ?> selected<?php endif; ?>><?= $word('STORE_PRODUCTS', 'sort_' . $s) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <div class="pop-actions"><a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>

<nav class="pills state-pills" aria-label="<?= $word('STORE_PRODUCTS', 'states') ?>">
<?php foreach ($states as $s): ?>
  <a href="<?= $e($s['href']) ?>"<?php if ($s['current']): ?> aria-current="page"<?php endif; ?>><?php if ($s['key'] !== null): ?><span class="dot <?= $e($s['tone']) ?>" aria-hidden="true"></span><?php endif; ?><?= $e($s['label']) ?> <strong class="tab-count"><?= $n($s['count']) ?></strong></a>
<?php endforeach; ?>
</nav>

<?php if ($rows === []): ?>
<?php if ($text !== '' || $state !== null): ?>
<?= $empty(\CW\Ui\Words::STORE_PRODUCTS['empty_filter'], \CW\Ui\Words::STORE_PRODUCTS['empty_filter_text'], $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::STORE_PRODUCTS['empty'], \CW\Ui\Words::STORE_PRODUCTS['empty_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php $bulk = $bulkActions !== []; ?>
<?php if ($bulk): ?>
<form class="bulk-form" method="post" action="/ui/review/bulk" data-bulk>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="source" value="store">
<?php foreach ($bulkKeep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<div class="board-head"><h2 class="board-title"><?= $say('STORE_PRODUCTS', 'board', $store['name']) ?></h2><p class="board-note"><?= $say('STORE_PRODUCTS', 'shown', $total, count($rows)) ?><?php if ($bulk): ?> · <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link><?= $word('BULK', 'select_all') ?></a><?php endif; ?></p></div>
<div class="table-wrap">
<table class="stack list board row-strips store-products<?php if ($bulk): ?> picks<?php endif; ?>">
  <thead>
    <tr>
<?php if ($bulk): ?>
      <th scope="col" class="c-pick"><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BULK', 'select_all') ?></span></label></th>
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
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('STORE_STATE', $r['state'])) ?><?php if ($r['picked']): ?> picked<?php endif; ?>">
<?php if ($bulk): ?>
      <td class="c-pick" data-label=""><?php if ($r['pickable']): ?><label class="pick"><input type="checkbox" name="pick_<?= $e($r['listing_id']) ?>" value="1" data-bulk-row<?php if ($r['picked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('BULK', 'pick_row', $r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></span></label>
        <input type="hidden" name="ver_<?= $e($r['listing_id']) ?>" value="<?= $e($r['map_version']) ?>"><input type="hidden" name="prop_<?= $e($r['listing_id']) ?>" value="<?= $e($r['proposal_id']) ?>"><?php endif; ?></td>
<?php endif; ?>
      <th scope="row" class="c-head">
        <a class="o-name" href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        <span class="o-sub"><?php if ($r['brand'] !== null): ?><?= $e($r['brand']) ?> · <?php endif; ?><?= $say('QUEUE', 'option', (string) $r['variant']) ?></span>
      </th>
      <td class="c-status"><?= $stateChip('STORE_STATE', $r['state']) ?></td>
      <td class="c-wide" data-label="<?= $word('STORE_PRODUCTS', 'warehouse') ?>">
<?php if ($r['sku_code'] !== null): ?>
        <?= $e($r['sku_code']) ?> <span class="muted"><?= $e($r['sku_name']) ?></span><span class="o-sub"><?= $e($r['sale_uses']) ?></span>
<?php elseif ($r['band'] !== null && $r['state'] !== 'ignored'): ?>
        <span class="tag band <?= $e(\CW\Ui\Words::tone('BAND', $r['band'])) ?>"><?= $word('BAND', $r['band']) ?></span>
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
      <td class="c-next"><a class="btn sm secondary" href="<?= $e($r['link']) ?>"><?= $word('QUEUE', 'open') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($bulk): ?>
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
<p class="muted"><?= $word('STORE_PRODUCTS', 'unlink_note') ?></p>
<?php endif; ?>
