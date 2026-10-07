<h1><?= $word('PAGE_TITLE', 'search') ?></h1>
<?= $intro('search') ?>

<form class="filters" method="get" action="/ui/search" role="search">
  <label><?= $word('SEARCH', 'label') ?>
    <input type="search" name="q" value="<?= $e($text) ?>" maxlength="100" autofocus>
  </label>
  <button type="submit" class="primary"><?= $word('SEARCH', 'button') ?></button>
</form>

<?php if ($short): ?>
<p class="muted"><?= $word('SEARCH', 'short') ?></p>
<?php elseif ($text === ''): ?>
<p class="muted"><?= $word('SEARCH', 'hint') ?></p>
<?php else: ?>
<section aria-labelledby="items-h">
  <h2 id="items-h"><?= $word('SEARCH', 'items') ?> <span class="hint"><?= $word('SEARCH', 'items_hint') ?></span></h2>
<?php if ($skus === []): ?>
  <p class="muted"><?= $word('SEARCH', 'no_items') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list found-items">
    <thead><tr><th scope="col"><?= $word('SEARCH', 'product') ?></th><th scope="col"><?= $word('SEARCH', 'brand') ?></th><th scope="col"><?= $word('SEARCH', 'rule') ?></th><th scope="col"><?= $word('SEARCH', 'barcodes') ?></th></tr></thead>
    <tbody>
<?php foreach ($skus as $s): ?>
      <tr>
        <th scope="row" class="c-head"><a class="o-name" href="/ui/items/<?= $e($s['id']) ?>"><?= $e($s['name']) ?></a> <span class="o-sub"><?= $e($s['code']) ?></span></th>
        <td data-label="<?= $word('SEARCH', 'brand') ?>"><?= $e($s['brand']) ?></td>
        <td data-label="<?= $word('SEARCH', 'rule') ?>"><?= $stateChip('POLICY', $s['policy']) ?></td>
        <td data-label="<?= $word('SEARCH', 'barcodes') ?>" class="muted"><?= $e(implode(', ', $s['barcodes'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="listings-h">
  <h2 id="listings-h"><?= $word('SEARCH', 'listings') ?> <span class="hint"><?= $word('SEARCH', 'listings_hint') ?></span></h2>
<?php if ($listings === []): ?>
  <p class="muted"><?= $word('SEARCH', 'no_listings') ?><?php if ($skus !== []): ?> <?= $word('SEARCH', 'on_item_page') ?><?php endif; ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list found-listings">
    <thead><tr><th scope="col"><?= $word('SEARCH', 'product') ?></th><th scope="col"><?= $word('SEARCH', 'website') ?></th><th scope="col"><?= $word('SEARCH', 'matched') ?></th><th scope="col" class="num"><?= $word('SEARCH', 'sold_30') ?></th></tr></thead>
    <tbody>
<?php foreach ($listings as $r): ?>
      <tr>
        <th scope="row" class="c-head">
          <a class="o-name" href="/ui/review/listing/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
          <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        </th>
        <td data-label="<?= $word('SEARCH', 'website') ?>"><?= $e($r['channel']) ?> <span class="muted small"><?= $say('QUEUE', 'option', (string) $r['variant']) ?></span></td>
        <td data-label="<?= $word('SEARCH', 'matched') ?>"><?php if ($r['status'] === 'mapped' && $r['sku_id'] !== null): ?><?= $word('SEARCH', 'yes_to') ?> <a href="/ui/items/<?= $e($r['sku_id']) ?>"><?= $e($r['sku_code']) ?></a><?php elseif ($r['status'] === 'ignored' || $r['status'] === 'quarantined'): ?><?= $stateChip('LISTING_STATUS', $r['status']) ?><?php if ($r['sku_id'] !== null): ?> <a href="/ui/items/<?= $e($r['sku_id']) ?>"><?= $e($r['sku_code']) ?></a><?php endif; ?><?php else: ?><span class="muted"><?= $word('SEARCH', 'not_yet') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SEARCH', 'sold_30') ?>"><?= $n($r['units_30d'] ?? 0) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>
