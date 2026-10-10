<?php
// By Item's picker (docs/decisions.md U118): the variants of ONE store to choose from for ONE warehouse item. Plain links and one
// GET form: it works without app.js. "Choose" opens the variant's own page with this item picked; the match is made there. The
// search box is filled in, but on a plain opening it has run only when nothing is suggested ($ran): else the person presses Search.
$lists = [['id' => 'suggested', 'rows' => $suggested], ['id' => 'found', 'rows' => $found]];
?>
<p class="crumbs"><a href="<?= $e($back) ?>"><?= $word('MENU', 'by_product') ?></a></p>
<h1><?= $say('BY_PRODUCT', 'pick_title', (string) $sku['code'], $store['name']) ?></h1>
<?= $intro('by_product_map') ?>

<section class="card" aria-labelledby="facts-h">
  <h2 id="facts-h"><?= $word('BY_PRODUCT', 'pick_facts') ?></h2>
  <p class="title"><a href="<?= $u('/ui/items/' . $sku['id']) ?>"><?= $e($sku['code']) ?></a> <?= $e($sku['name']) ?></p>
  <dl>
<?php if ($sku['brand'] !== null): ?>
    <dt><?= $word('FIELD', 'brand') ?></dt><dd><?= $e($sku['brand']) ?></dd>
<?php endif; ?>
<?php if ($sku['line'] !== null): ?>
    <dt><?= $word('FIELD', 'line') ?></dt><dd><?= $e($sku['line']) ?></dd>
<?php endif; ?>
<?php if ($sku['flavour'] !== null): ?>
    <dt><?= $word('FIELD', 'flavour') ?></dt><dd><?= $e($sku['flavour']) ?></dd>
<?php endif; ?>
<?php if ($sku['strength'] !== null): ?>
    <dt><?= $word('FIELD', 'strength_mg') ?></dt><dd><?= $e($sku['strength']) ?></dd>
<?php endif; ?>
<?php if ($sku['size'] !== null): ?>
    <dt><?= $word('FIELD', 'volume_ml') ?></dt><dd><?= $e($sku['size']) ?></dd>
<?php endif; ?>
    <dt><?= $word('FIELD', 'barcodes') ?></dt><dd><?php if ($barcodes === []): ?><span class="muted"><?= $word('BY_PRODUCT', 'pick_none') ?></span><?php else: ?><?= $e(implode(', ', $barcodes)) ?><?php endif; ?></dd>
    <dt><?= $say('BY_PRODUCT', 'pick_now', $store['name']) ?></dt>
    <dd><?php if ($now['state'] === 'none'): ?><?= $word('BY_PRODUCT', 'pick_now_none') ?><?php else: ?><?= $stateChip('PRODUCT_STORE', $now['state']) ?> <a href="<?= $e($now['link']) ?>"><?= $e($now['title']) ?></a><?php if ($now['more'] === 1): ?> <span class="muted"><?= $word('BY_PRODUCT', 'more_one') ?></span><?php elseif ($now['more'] > 1): ?> <span class="muted"><?= $say('BY_PRODUCT', 'more_many', $now['more']) ?></span><?php endif; ?><?php if ($now['state'] === 'matched'): ?> <span class="hint"><?= $word('BY_PRODUCT', 'pick_now_more') ?></span><?php endif; ?><?php endif; ?></dd>
  </dl>
</section>

<div class="alert info" role="note"><p><?= $word('BY_PRODUCT', 'pick_next_step') ?></p></div>

<?php foreach ($lists as $list): ?>
<?php if ($list['id'] === 'suggested'): ?>
<h2 id="suggested-h"><?= $word('BY_PRODUCT', 'pick_suggested') ?></h2>
<?php if ($list['rows'] === []): ?>
<p class="muted"><?= $word('BY_PRODUCT', 'pick_suggested_none') ?></p>
<?php endif; ?>
<?php else: ?>
<h2 id="found-h"><?= $say('BY_PRODUCT', 'pick_search', $store['name']) ?></h2>
<form class="filters" method="get" action="<?= $e($action) ?>#found-h">
<?php foreach ($keep as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
  <label><?= $word('BY_PRODUCT', 'pick_search_label') ?>
    <input type="search" name="q" value="<?= $e($text) ?>" maxlength="100">
  </label>
  <button type="submit"><?= $word('UI', 'search') ?></button>
</form>
<p class="hint"><?= $word('BY_PRODUCT', 'pick_search_hint') ?></p>
<?php if (!$ran): ?>
<p><?= $word('BY_PRODUCT', 'pick_search_first') ?></p>
<?php elseif ($list['rows'] === []): ?>
<p class="muted"><?php if ($suggested === []): ?><?= $word('BY_PRODUCT', 'pick_found_none') ?><?php else: ?><?= $word('BY_PRODUCT', 'pick_found_no_more') ?><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($list['rows'] !== []): ?>
<div class="table-wrap">
<table class="stack list board row-strips pick-list pick-<?= $e($list['id']) ?>">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('BY_PRODUCT', 'pick_variant') ?></th>
      <th scope="col" class="c-status"><?= $word('BY_PRODUCT', 'pick_state') ?></th>
      <th scope="col"><?= $word('BY_PRODUCT', 'pick_barcode') ?></th>
      <th scope="col" class="num"><?= $word('BY_PRODUCT', 'pick_price') ?></th>
      <th scope="col" class="num"><?= $word('QUEUE', 'sold_30') ?></th>
      <th scope="col" class="num"><?= $word('QUEUE', 'sold_365') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $word('BY_PRODUCT', 'pick_choose') ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($list['rows'] as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('STORE_STATE', $r['state'])) ?>">
      <th scope="row" class="c-head">
        <a class="o-name" href="<?= $e($r['choose']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        <span class="o-sub"><?php if ($r['brand'] !== null): ?><?= $e($r['brand']) ?> · <?php endif; ?><?= $say('QUEUE', 'option', (string) $r['variant']) ?></span>
      </th>
      <td class="c-status"><?php if ($r['band'] !== null): ?><?= $chip(\CW\Ui\Words::tone('BAND', $r['band']), \CW\Ui\Words::of('BAND', $r['band'])) ?><?php else: ?><?= $stateChip('STORE_STATE', $r['state']) ?><?php endif; ?></td>
      <td data-label="<?= $word('BY_PRODUCT', 'pick_barcode') ?>"><?php if ($r['barcode'] === 'unknown'): ?><span class="muted"><?= $word('BY_PRODUCT', 'pick_barcode_unknown') ?></span><?php else: ?><?= $chip(\CW\Ui\Words::tone('FIELD_STATE', $r['barcode']), \CW\Ui\Words::BY_PRODUCT['pick_barcode_' . $r['barcode']]) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('BY_PRODUCT', 'pick_price') ?>"><?php if ($r['price'] !== null && is_numeric($r['price'])): ?><?= $money($r['price']) ?><?php else: ?><?= $e($r['price']) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('QUEUE', 'sold_30') ?>"><?= $n($r['units_30d'] ?? 0) ?></td>
      <td class="num" data-label="<?= $word('QUEUE', 'sold_365') ?>"><?= $n($r['units_365d'] ?? 0) ?></td>
      <td class="c-next"><a class="btn sm primary" href="<?= $e($r['choose']) ?>" aria-label="<?= $say('BY_PRODUCT', 'pick_choose_named', $r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?>"><?= $word('BY_PRODUCT', 'pick_choose') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($prev_link !== null || $next_link !== null): ?>
<nav class="pager" aria-label="<?= $say('BY_PRODUCT', 'pick_page', $page_no) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('BY_PRODUCT', 'pick_page', $page_no) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
