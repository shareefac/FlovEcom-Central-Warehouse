<?php
// Products › By Item (docs/decisions.md U113-U122): one row per warehouse item, one cell per store (the stores come from the
// channel table: the columns are whatever it holds). A cell is the store's name on a phone (data-label), where each row is a card.
// In the owner's words (U122) the row is an ITEM, what a store sells is a VARIANT, and "Product:" names the variant's parent page.
?>
<h1><?= $word('MENU', 'by_product') ?></h1>
<?= $intro('by_product', $lookOnly) ?>
<?php if ($stores === []): ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['no_stores'], \CW\Ui\Words::BY_PRODUCT['no_stores_text']) ?>
<?php else: ?>
<form class="toolbar" method="get" action="/ui/review/products" aria-label="<?= $word('UI', 'filter') ?>">
<?php if ($coverage !== null): ?>
  <input type="hidden" name="cov" value="<?= $e($coverage) ?>">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="bp-q"><?= $word('BY_PRODUCT', 'search') ?></label><input id="bp-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>" title="<?= $word('BY_PRODUCT', 'search') ?>"></div>
  <div class="tb-end">
    <a class="btn ghost sm" href="<?= $e($csv_link) ?>" title="<?= $word('BY_PRODUCT', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
  </div>
</form>

<nav class="pills state-pills" aria-label="<?= $word('BY_PRODUCT', 'filters') ?>">
<?php foreach ($pills as $p): ?>
  <a href="<?= $e($p['href']) ?>"<?php if ($p['current']): ?> aria-current="page"<?php endif; ?>><?php if ($p['tone'] !== null): ?><span class="dot <?= $e($p['tone']) ?>" aria-hidden="true"></span><?php endif; ?><?= $e($p['label']) ?> <strong class="tab-count"><?= $n($p['count']) ?></strong></a>
<?php endforeach; ?>
</nav>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['empty_filter'], \CW\Ui\Words::BY_PRODUCT['empty_filter_text'], $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::BY_PRODUCT['empty'], \CW\Ui\Words::BY_PRODUCT['empty_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('BY_PRODUCT', 'board') ?></h2><p class="board-note"><?= $say('BY_PRODUCT', 'shown', $total, $shown) ?><?php if ($filtered): ?> · <a href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a><?php endif; ?></p></div>
<div class="table-wrap">
<table class="stack list board row-strips by-product">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('BY_PRODUCT', 'item') ?></th>
<?php foreach ($stores as $name): ?>
      <th scope="col" class="c-store"><?= $e($name) ?></th>
<?php endforeach; ?>
      <th scope="col"><?= $word('BY_PRODUCT', 'stores_matched') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr id="p-<?= $e($r['id']) ?>" class="<?= $e($r['tone']) ?><?php if ($r['here']): ?> picked<?php endif; ?>">
      <th scope="row" class="c-head">
        <a class="o-name" href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['name'] ?? $r['code']) ?></a>
        <span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span>
<?php if ($r['parent'] !== null): ?>
        <span class="o-sub parent"><?= $say('BY_PRODUCT', 'parent', $r['parent']) ?></span>
<?php endif; ?>
<?php if ($r['here']): ?>
        <span class="o-sub here-note"><?= $word('BY_PRODUCT', 'back_here') ?><?php if ($r['pinned']): ?> <?= $word('BY_PRODUCT', 'pinned') ?><?php endif; ?></span>
<?php endif; ?>
      </th>
<?php foreach ($r['cells'] as $c): ?>
      <td class="c-store" data-label="<?= $e($c['store']) ?>">
        <?= $stateChip('PRODUCT_STORE', $c['state']) ?>
<?php if ($c['title'] !== null): ?>
        <a class="o-line" href="<?= $e($c['link']) ?>"><?= $e($c['title']) ?></a>
<?php endif; ?>
<?php if ($c['band'] !== null): ?>
        <span class="o-line"><span class="tag band <?= $e(\CW\Ui\Words::tone('BAND', $c['band'])) ?>"><?= $word('BAND', $c['band']) ?></span></span>
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
<?php if ($c['review'] !== null || $c['map'] !== null): ?>
        <span class="o-line cell-actions">
<?php if ($c['review'] !== null): ?>
          <a class="btn sm primary" href="<?= $e($c['review']) ?>"><?= $word('BY_PRODUCT', 'review') ?></a>
<?php endif; ?>
<?php if ($c['map'] !== null && $c['state'] === 'none'): ?>
          <a class="btn sm secondary" href="<?= $e($c['map']) ?>" title="<?= $say('BY_PRODUCT', 'map_named', $c['store'], (string) $r['code']) ?>"><?= $word('BY_PRODUCT', 'map') ?></a>
<?php elseif ($c['map'] !== null): ?>
          <a class="map-more" href="<?= $e($c['map']) ?>" title="<?= $say('BY_PRODUCT', 'map_named', $c['store'], (string) $r['code']) ?>"><?php if ($c['state'] === 'matched'): ?><?= $word('BY_PRODUCT', 'map_more') ?><?php else: ?><?= $word('BY_PRODUCT', 'map_other') ?><?php endif; ?></a>
<?php endif; ?>
        </span>
<?php endif; ?>
      </td>
<?php endforeach; ?>
      <td class="num" data-label="<?= $word('BY_PRODUCT', 'stores_matched') ?>"><?= $say('BY_PRODUCT', 'summary', $r['on'], count($stores)) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<p class="muted"><?= $word('BY_PRODUCT', 'note') ?></p>
<?php endif; ?>
