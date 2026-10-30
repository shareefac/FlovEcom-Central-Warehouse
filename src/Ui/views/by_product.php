<?php
// Product matching › By item (the approved ByItem artboard of 30 Oct 2026; docs/decisions.md RD-C7-RD-C10; before it U113-U130, W12.7): the
// title and its line, the two tabs (By item, New on the stores with its count), the search and the filter CW has (the match status: on every
// store, missing on a store, a suggestion waiting) with Clear filters; then ONE white card, a row per warehouse item: its name (its page), its
// CW number, brand and on how many of the stores it is matched; at the right a chip per store: a green tick where it is matched, a dashed
// "+ Store" that opens the match for that store (the quick match's search in place for someone who may decide; the suggestion's page when the
// computer suggests a variant), an amber chip while a second OK is awaited. In the owner's words (U122) the row is an ITEM, what a store sells
// is a VARIANT. A person who may decide ticks items for the bar under the list (U123-U130: a plain form without app.js; its buttons open the
// preview, which saves nothing); the quick match's box and button name a GET form drawn after the list (a form inside a form is no form). The
// page's More holds every other matching page, the list's file and the number of rows.
$stateWord = static fn (string $code): string => \CW\Ui\Words::of('PRODUCT_STORE', $code);
$bar = $partial('by_product_bar', ['export' => $stores === [] ? null : $csv_link, 'sizes' => $sizes]);
?>
<?= $head(\CW\Ui\Words::RD_MATCH['title'], intro: 'by_product', look: $lookOnly, meta: $word('RD_MATCH', 'sub'), side: $bar) ?>
<?= $partial('by_product_tabs', ['tab' => 'items', 'count' => $freshCount]) ?>
<?php if ($stores === []): ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['no_stores'], \CW\Ui\Words::BY_PRODUCT['no_stores_text']) ?>
<?php else: ?>
<form class="bi-find" method="get" action="/ui/review/products" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
<?php if ($per_page !== null): ?>
  <input type="hidden" name="n" value="<?= $e($per_page) ?>">
<?php endif; ?>
  <label class="visually-hidden" for="bi-q"><?= $word('BY_PRODUCT', 'search') ?></label>
  <input id="bi-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('RD_MATCH', 'search_items') ?>" title="<?= $word('BY_PRODUCT', 'search') ?>" aria-keyshortcuts="/" data-page-search>
  <div class="bi-filters">
    <label class="visually-hidden" for="bi-cov"><?= $word('RD_MATCH', 'match_status') ?></label>
    <select id="bi-cov" name="cov" title="<?= $word('RD_MATCH', 'match_status') ?>">
<?php foreach ($pills as $p): ?>
      <option value="<?= $e($p['value']) ?>"<?php if ($p['current']): ?> selected<?php endif; ?>><?= $e($p['label']) ?> (<?= $n($p['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
<?php if ($filtered): ?>
    <a class="bi-clear" href="<?= $e($clear_link) ?>"><?= $word('RD_MATCH', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>
<?php if ($text !== '' && $total > 0): ?>
<?= $partial('search_note', ['count' => $total, 'text' => $text, 'clear' => $clear_search]) ?>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['empty_filter'], \CW\Ui\Words::BY_PRODUCT['empty_filter_text'] . ($text !== '' ? ' ' . \CW\Ui\Words::FIND['try_items'] : ''), $clear_link, \CW\Ui\Words::RD_MATCH['clear']) ?>
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
<section class="card bi-list<?php if ($bulk): ?> picks<?php endif; ?>" aria-label="<?= $say('BY_PRODUCT', 'shown', $total, $shown) ?>">
<?php if ($bulk): ?>
  <div class="bi-pickall"><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BY_ITEM_BULK', 'select_all') ?></span></label> <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link title="<?= $word('BY_ITEM_BULK', 'select_all') ?>"><?= $word('BULK', 'select_all') ?></a></div>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
  <div class="bi-row<?php if ($r['here']): ?> here<?php endif; ?><?php if ($r['ticked']): ?> picked<?php endif; ?>" id="p-<?= $e($r['id']) ?>">
<?php if ($bulk): ?>
    <div class="bi-pick"><?php if ($r['cells_field'] !== null): ?><label class="pick"><input type="checkbox" name="pick_<?= $e($r['id']) ?>" value="1" data-bulk-row<?php if ($r['manual']): ?> data-bulk-manual<?php endif; ?><?php if ($r['ticked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('BULK', 'pick_row', (string) ($r['name'] ?? $r['code'])) ?></span></label>
      <input type="hidden" name="cells_<?= $e($r['id']) ?>" value="<?= $e($r['cells_field']) ?>"><?php endif; ?></div>
<?php endif; ?>
    <div class="bi-item">
      <a class="o-name" href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['name'] ?? $r['code']) ?></a>
      <span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?> · <span class="bi-on"><?= $say('RD_MATCH', 'stores_n', $r['on'], count($stores)) ?></span></span>
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
    </div>
    <div class="bi-stores">
<?php foreach ($r['cells'] as $c): ?>
<?php $extra = implode(' ', array_filter([$c['title'], $c['band'] !== null ? \CW\Ui\Words::of('BAND', $c['band']) : null,
    $c['more'] === 1 ? \CW\Ui\Words::BY_PRODUCT['more_one'] : ($c['more'] > 1 ? \CW\Ui\Words::say('BY_PRODUCT', 'more_many', $c['more']) : null), $c['sale'],
    $c['hold'] ? \CW\Ui\Words::BY_PRODUCT['on_hold'] : null, $c['also_waiting'] > 0 ? \CW\Ui\Words::say('BY_PRODUCT', 'also_waiting', $c['also_waiting']) : null,
    $c['also_suggested'] > 0 ? \CW\Ui\Words::say('BY_PRODUCT', 'also_suggested', $c['also_suggested']) : null], static fn (?string $v): bool => $v !== null && $v !== '')); ?>
<?php if ($c['state'] === 'matched'): ?>
      <a class="bi-chip on<?php if ($c['hold']): ?> hold<?php endif; ?>" data-label="<?= $e($c['store']) ?>" href="<?= $e($c['map'] ?? $c['link']) ?>" data-state="matched" title="<?= $say('RD_MATCH', 'matched_on', $c['store']) ?><?php if ($extra !== ''): ?>: <?= $e($extra) ?><?php endif; ?>"><span class="ico ico-check" aria-hidden="true"></span><?= $e($c['store']) ?><span class="visually-hidden"> (<?= $e($stateWord($c['chip'])) ?><?php if ($extra !== ''): ?> <?= $e($extra) ?><?php endif; ?>)</span></a>
<?php elseif ($c['state'] === 'waiting'): ?>
      <a class="bi-chip wait" data-label="<?= $e($c['store']) ?>" href="<?= $e($c['link']) ?>" data-state="waiting" title="<?= $say('RD_MATCH', 'waiting_on', $c['store']) ?><?php if ($extra !== ''): ?>: <?= $e($extra) ?><?php endif; ?>"><?= $e($c['store']) ?><span class="visually-hidden"> (<?= $e($stateWord($c['chip'])) ?><?php if ($extra !== ''): ?> <?= $e($extra) ?><?php endif; ?>)</span></a>
<?php elseif ($c['state'] === 'suggested'): ?>
      <a class="bi-chip open suggested" data-label="<?= $e($c['store']) ?>" href="<?= $e($c['review'] ?? $c['link']) ?>" data-state="<?= $e($c['chip']) ?>" title="<?= $say('RD_MATCH', 'suggested_on', $c['store']) ?><?php if ($extra !== ''): ?>: <?= $e($extra) ?><?php endif; ?>">+ <?= $e($c['store']) ?><span class="visually-hidden"> (<?= $e($stateWord($c['chip'])) ?><?php if ($extra !== ''): ?> <?= $e($extra) ?><?php endif; ?>)</span></a>
<?php elseif ($c['quick'] !== null): ?>
      <details class="quick-pick bi-quick" data-label="<?= $e($c['store']) ?>" data-quick-pick data-options="<?= $e($c['quick']['options']) ?>" data-sku-id="<?= $e($r['id']) ?>" data-channel="<?= $e($c['channel']) ?>" data-loading="<?= $word('BY_PRODUCT', 'quick_loading') ?>" data-failed="<?= $word('BY_PRODUCT', 'quick_failed') ?>">
        <summary class="bi-chip open" data-state="none" title="<?= $say('BY_PRODUCT', 'map_named', $c['store'], (string) $r['code']) ?>" aria-label="<?= $say('RD_MATCH', 'match_on', $c['store']) ?>">+ <?= $e($c['store']) ?><span class="visually-hidden"> (<?= $e($stateWord($c['chip'])) ?>)</span></summary>
        <span class="qp-search"><input type="search" name="q" form="<?= $e($c['quick']['form']) ?>" value="<?= $e($c['quick']['text']) ?>" maxlength="100" aria-label="<?= $e($c['quick']['label']) ?>" placeholder="<?= $word('FIND', 'quick') ?>" data-qp-q> <button type="submit" class="btn sm" form="<?= $e($c['quick']['form']) ?>"><?= $word('UI', 'search') ?></button></span>
        <div class="qp-results" data-qp-results aria-live="polite"></div>
        <p class="qp-page"><a href="<?= $e($c['map']) ?>"><?= $word('BY_PRODUCT', 'quick_page') ?></a></p>
      </details>
<?php else: ?>
      <span class="bi-chip open off" data-label="<?= $e($c['store']) ?>" data-state="none"><?= $e($c['store']) ?><span class="visually-hidden"> (<?= $e($stateWord($c['chip'])) ?>)</span></span>
<?php endif; ?>
<?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
</section>
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
