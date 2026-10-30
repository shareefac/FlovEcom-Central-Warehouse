<?php
// Product matching › New on the stores (the approved ByItem artboard of 30 Oct 2026; docs/decisions.md RD-C7): the stores' products first seen
// in New variants' period that are still to match, from New variants' own reads. "Match all new" at the right (New variants of the store shown,
// whose bar matches or creates many at once; for whoever may decide); one search and the store choice, each store with its count; then ONE white
// card, a row per store product: its name, its store and when it was added, its likely match, and Match (its own page: is it the same item?).
$f = $fresh;
$bar = $partial('by_product_bar', ['main' => $matchAll === null ? null : ['href' => $matchAll, 'label' => \CW\Ui\Words::RD_MATCH['match_all']]]);
?>
<?= $head(\CW\Ui\Words::RD_MATCH['title'], intro: 'by_product', look: $lookOnly, meta: $word('RD_MATCH', 'sub'), side: $bar) ?>
<?= $partial('by_product_tabs', ['tab' => 'new', 'count' => $f['count']]) ?>
<?php if ($f['store'] === null): ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['no_stores'], \CW\Ui\Words::BY_PRODUCT['no_stores_text']) ?>
<?php else: ?>
<form class="bi-find bi-new" method="get" action="/ui/review/products" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <input type="hidden" name="tab" value="new">
  <label class="visually-hidden" for="nw-q"><?= $word('RD_MATCH', 'search_new') ?></label>
  <input id="nw-q" type="search" name="q" value="<?= $e($f['text']) ?>" maxlength="100" placeholder="<?= $word('RD_MATCH', 'search_new') ?>" aria-keyshortcuts="/" data-page-search>
  <div class="bi-filters">
    <label class="visually-hidden" for="nw-store"><?= $word('RD_MATCH', 'store') ?></label>
    <select id="nw-store" name="channel" title="<?= $word('RD_MATCH', 'store') ?>">
<?php foreach ($f['stores'] as $s): ?>
      <option value="<?= $e($s['code']) ?>"<?php if ($s['current']): ?> selected<?php endif; ?>><?= $say('RD_MATCH', 'store_n', $s['name'], $s['count']) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
<?php if ($f['text'] !== ''): ?>
    <a class="bi-clear" href="<?= $e($f['clear_link']) ?>"><?= $word('RD_MATCH', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>
<?php if ($f['rows'] === []): ?>
<?php if ($f['text'] !== ''): ?>
<?= $empty(\CW\Ui\Words::NEW_VARIANTS['empty_search'], \CW\Ui\Words::NEW_VARIANTS['empty_search_text'], $f['clear_link'], \CW\Ui\Words::RD_MATCH['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::say('RD_MATCH', 'none_new', (string) ($f['store']['name'] ?? '')), \CW\Ui\Words::say('RD_MATCH', 'none_new_text', $f['days'])) ?>
<?php endif; ?>
<?php else: ?>
<section class="card bi-list bi-fresh" aria-label="<?= $word('RD_MATCH', 'tab_new') ?>">
<?php foreach ($f['rows'] as $r): ?>
  <div class="bi-row" id="l-<?= $e($r['listing_id']) ?>">
    <div class="bi-item">
      <span class="o-name"><?= $e($r['name']) ?></span>
      <span class="o-sub"><?= $e($r['store']) ?> · <?= $say('RD_MATCH', 'added', \CW\Ui\Html::day($r['seen'])) ?></span>
    </div>
    <span class="chip <?php if ($r['likely']): ?>needs bi-hint likely<?php else: ?>waiting bi-hint<?php endif; ?>"><?= $e($r['hint']) ?></span>
    <a class="btn" href="<?= $e($r['link']) ?>"><?= $word('RD_MATCH', 'match') ?></a>
  </div>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($f['pages'] > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $f['page_no'], $f['pages']) ?>">
<?php if ($f['prev_link'] !== null): ?><a href="<?= $e($f['prev_link']) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $f['page_no'], $f['pages']) ?></span>
<?php if ($f['next_link'] !== null): ?><a href="<?= $e($f['next_link']) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
