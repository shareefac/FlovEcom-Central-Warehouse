<?php
// Products › By Item (docs/decisions.md U113-U122): one row per warehouse item, one cell per store (the stores come from the
// channel table: the columns are whatever it holds). A cell is the store's name on a phone (data-label), where each row is a card.
// In the owner's words (U122) the row is an ITEM, what a store sells is a VARIANT, and "Product:" names the variant's parent page.
// Since U123-U124: the number of rows is chosen above the list, and a person who may decide gets a tick box on every item a store
// suggests a variant for, and the bar under the list (a plain form without app.js: its buttons open the preview, which saves nothing).
// The quick match (the owner's request of 20 Oct 2026): a cell of a store that has no variant for the item has "Match…" as a fold
// with a search box. Its box and its button name a GET form of the picker (form=), drawn after the list: the list is inside the bulk
// form, and a form inside a form is no form. app.js shows the store's variants in the fold (data-options, by_product_options.php).
// As a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W12.7): one search and ONE Show row (what it shows), the number
// of rows (its links keep the place), Clear and Export at the right; the page's items as ONE group in their CW number order (its count), "Stores matched" the solid status cell.
?>
<?= $head(\CW\Ui\Words::MENU['by_product'], intro: 'by_product', look: $lookOnly) ?>
<?php if ($stores === []): ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['no_stores'], \CW\Ui\Words::BY_PRODUCT['no_stores_text']) ?>
<?php else: ?>
<form class="toolbar" method="get" action="/ui/review/products" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
<?php if ($per_page !== null): ?>
  <input type="hidden" name="n" value="<?= $e($per_page) ?>">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('BY_PRODUCT', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('FIND', 'items') ?>" title="<?= $word('BY_PRODUCT', 'search') ?>" aria-keyshortcuts="/" data-page-search></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('BY_PRODUCT', 'filters') ?></label>
    <select id="tb-show" name="cov">
<?php foreach ($pills as $p): ?>
      <option value="<?= $e($p['value']) ?>"<?php if ($p['current']): ?> selected<?php endif; ?>><?= $e($p['label']) ?> (<?= $n($p['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a>
<?php endif; ?>
    <nav class="rows-choice" aria-label="<?= $word('BY_ITEM_BULK', 'rows') ?>"><span class="rows-label"><?= $word('BY_PRODUCT', 'rows') ?></span><?php foreach ($sizes as $size): ?> <a href="<?= $e($size['href']) ?>"<?php if ($size['current']): ?> aria-current="true"<?php endif; ?>><?= $n($size['size']) ?></a><?php endforeach; ?></nav>
    <a class="btn ghost sm" href="<?= $e($csv_link) ?>" title="<?= $word('BY_PRODUCT', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
  </div>
</form>
<?php if ($text !== '' && $total > 0): ?>
<?= $partial('search_note', ['count' => $total, 'text' => $text, 'clear' => $clear_search]) ?>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['empty_filter'], \CW\Ui\Words::BY_PRODUCT['empty_filter_text'] . ($text !== '' ? ' ' . \CW\Ui\Words::FIND['try_items'] : ''), $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['empty'], \CW\Ui\Words::BY_PRODUCT['empty_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php $bulk = $bulkActions !== []; ?>
<?php if ($bulk): ?>
<form class="bulk-form" method="post" action="/ui/review/products/bulk" data-bulk data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php foreach ($bulkKeep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<div class="grp-block doc-group info">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-items"><?= $word('BY_PRODUCT', 'board') ?></button> <span class="grp-count"><?= $say('BY_PRODUCT', 'shown', $total, $shown) ?></span><?php if ($bulk): ?> <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link title="<?= $word('BY_ITEM_BULK', 'select_all') ?>"><?= $word('BULK', 'select_all') ?></a><?php endif; ?></h3>
  <div class="table-wrap doc-board" id="g-items">
  <table class="stack list board by-product<?php if ($bulk): ?> picks<?php endif; ?>">
    <thead>
      <tr>
<?php if ($bulk): ?>
        <th scope="col" class="c-pick"><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BY_ITEM_BULK', 'select_all') ?></span></label></th>
<?php endif; ?>
        <th scope="col" class="c-item"><?= $word('BY_PRODUCT', 'item') ?></th>
<?php foreach ($stores as $name): ?>
        <th scope="col" class="c-store"><?= $e($name) ?></th>
<?php endforeach; ?>
        <th scope="col" class="c-status"><?= $word('BY_PRODUCT', 'stores_matched') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr id="p-<?= $e($r['id']) ?>" class="<?= $e($r['tone']) ?><?php if ($r['here']): ?> here<?php endif; ?><?php if ($r['ticked']): ?> picked<?php endif; ?>">
<?php if ($bulk): ?>
        <td class="c-pick" data-label=""><?php if ($r['cells_field'] !== null): ?><label class="pick"><input type="checkbox" name="pick_<?= $e($r['id']) ?>" value="1" data-bulk-row<?php if ($r['manual']): ?> data-bulk-manual<?php endif; ?><?php if ($r['ticked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('BULK', 'pick_row', (string) ($r['name'] ?? $r['code'])) ?></span></label>
          <input type="hidden" name="cells_<?= $e($r['id']) ?>" value="<?= $e($r['cells_field']) ?>"><?php endif; ?></td>
<?php endif; ?>
        <th scope="row" class="c-head c-item">
          <a class="o-name" href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['name'] ?? $r['code']) ?></a>
          <span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span>
<?php if ($r['parent'] !== null): ?>
          <span class="o-sub parent"><?= $say('BY_PRODUCT', 'parent', $r['parent']) ?></span>
<?php endif; ?>
<?php if (($r['family'] ?? null) !== null): ?>
          <span class="o-sub family"><?= $say('FAMILY', 'of_item', $r['family']) ?></span>
<?php endif; ?>
<?php if ($bulk && $r['too_many'] !== null): ?>
          <span class="o-sub"><?= $say('BY_ITEM_BULK', 'row_too_many', $r['too_many']) ?></span>
<?php endif; ?>
<?php if ($r['here']): ?>
          <span class="o-sub here-note"><?= $word('BY_PRODUCT', 'back_here') ?><?php if ($r['pinned']): ?> <?= $word('BY_PRODUCT', 'pinned') ?><?php endif; ?></span>
<?php endif; ?>
        </th>
<?php foreach ($r['cells'] as $c): ?>
        <td class="c-store" data-label="<?= $e($c['store']) ?>">
          <?= $stateChip('PRODUCT_STORE', $c['chip']) ?>
<?php if ($c['title'] !== null): ?>
          <a class="o-line" href="<?= $e($c['link']) ?>"><?= $e($c['title']) ?></a>
<?php endif; ?>
<?php if ($c['band'] !== null): ?>
          <span class="o-line"><span class="tag <?= $e(\CW\Ui\Words::tone('BAND', $c['band'])) ?>"><?= $word('BAND', $c['band']) ?></span></span>
<?php endif; ?>
<?php if ($c['more'] === 1): ?>
          <span class="o-sub"><?= $word('BY_PRODUCT', 'more_one') ?></span>
<?php elseif ($c['more'] > 1): ?>
          <span class="o-sub"><?= $say('BY_PRODUCT', 'more_many', $c['more']) ?></span>
<?php endif; ?>
<?php if ($c['sale'] !== null): ?>
          <span class="o-sub"><?= $e($c['sale']) ?></span>
<?php endif; ?>
<?php if ($c['hold']): ?>
          <span class="o-sub"><?= $word('BY_PRODUCT', 'on_hold') ?></span>
<?php endif; ?>
<?php if ($c['also_waiting'] > 0): ?>
          <span class="o-sub"><?= $say('BY_PRODUCT', 'also_waiting', $c['also_waiting']) ?></span>
<?php endif; ?>
<?php if ($c['also_suggested'] > 0): ?>
          <span class="o-sub"><?= $say('BY_PRODUCT', 'also_suggested', $c['also_suggested']) ?></span>
<?php endif; ?>
<?php if ($c['quick'] !== null): ?>
          <details class="quick-pick" data-quick-pick data-options="<?= $e($c['quick']['options']) ?>" data-sku-id="<?= $e($r['id']) ?>" data-channel="<?= $e($c['channel']) ?>" data-loading="<?= $word('BY_PRODUCT', 'quick_loading') ?>" data-failed="<?= $word('BY_PRODUCT', 'quick_failed') ?>">
            <summary class="btn sm" title="<?= $say('BY_PRODUCT', 'map_named', $c['store'], (string) $r['code']) ?>"><?= $word('BY_PRODUCT', 'map') ?></summary>
            <span class="qp-search"><input type="search" name="q" form="<?= $e($c['quick']['form']) ?>" value="<?= $e($c['quick']['text']) ?>" maxlength="100" aria-label="<?= $e($c['quick']['label']) ?>" placeholder="<?= $word('FIND', 'quick') ?>" data-qp-q> <button type="submit" class="btn sm" form="<?= $e($c['quick']['form']) ?>"><?= $word('UI', 'search') ?></button></span>
            <div class="qp-results" data-qp-results aria-live="polite"></div>
            <p class="qp-page"><a href="<?= $e($c['map']) ?>"><?= $word('BY_PRODUCT', 'quick_page') ?></a></p>
          </details>
<?php elseif ($c['review'] !== null || $c['map'] !== null): ?>
          <span class="o-line cell-actions">
<?php if ($c['review'] !== null): ?>
            <a class="btn sm" href="<?= $e($c['review']) ?>"><?= $word('BY_PRODUCT', 'review') ?></a>
<?php endif; ?>
<?php if ($c['map'] !== null): ?>
            <a class="map-more" href="<?= $e($c['map']) ?>" title="<?= $say('BY_PRODUCT', 'map_named', $c['store'], (string) $r['code']) ?>"><?php if ($c['state'] === 'matched'): ?><?= $word('BY_PRODUCT', 'map_more') ?><?php else: ?><?= $word('BY_PRODUCT', 'map_other') ?><?php endif; ?></a>
<?php endif; ?>
          </span>
<?php endif; ?>
        </td>
<?php endforeach; ?>
        <td class="c-status doc-st <?= $e($r['tone']) ?>" data-label="<?= $word('BY_PRODUCT', 'stores_matched') ?>"><span class="chip"><?= $say('BY_PRODUCT', 'summary', $r['on'], count($stores)) ?></span></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php if ($bulk): ?>
<?= $partial('bulk_bar', ['actions' => $bulkActions, 'max' => 0, 'words' => $bulkWords, 'choice' => $bulkChoice]) ?>
</form>
<p class="muted bulk-note"><?= $say('BY_ITEM_BULK', 'limit', $bulkMax) ?> <?= $word('BY_ITEM_BULK', 'undo_note') ?><?php if ($manualNote): ?> <?= $word('BY_ITEM_BULK', 'manual_note') ?><?php endif; ?></p>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<?php foreach ($r['cells'] as $c): ?>
<?php if ($c['quick'] !== null): ?>
<form class="qp-form" method="get" id="<?= $e($c['quick']['form']) ?>" action="<?= $e($c['quick']['action']) ?>" data-leaves="1">
<?php foreach ($c['quick']['keep'] as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
</form>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
