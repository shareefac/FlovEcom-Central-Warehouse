<?php
// By Item's quick match (the owner's request of 20 Oct 2026: "directly add dropdown with full details"): the variants of ONE store
// for ONE item, as the picker lists them (ByProductController::options), which app.js draws into the item's cell. Not a page: no
// statement, no layout. Its root says what it is (data-qp-fragment: app.js shows nothing else). Simple, stable markup that is easy to
// script: one <li> per variant, it and its button carrying data-listing-id, data-sku-id and data-channel. "Match to …" is a plain
// POST of the variant's own decide route with the way back to this row of the list: a refusal lands on the variant's page with its
// words. A variant decided one at a time, or one only a matching lead decides, has the lists' line and "Choose" (its page) instead.
?>
<div class="qp" data-qp-fragment data-sku-id="<?= $e($sku['id']) ?>" data-channel="<?= $e($store['code']) ?>">
<?php foreach ($lists as $list): ?>
<?php if ($list['title'] !== null): ?>
  <p class="qp-h"><?= $e($list['title']) ?></p>
<?php endif; ?>
<?php foreach ($list['notes'] as $note): ?>
  <p class="qp-note muted"><?= $e($note) ?></p>
<?php endforeach; ?>
<?php if ($list['rows'] !== []): ?>
  <ul class="qp-list qp-<?= $e($list['id']) ?>">
<?php foreach ($list['rows'] as $r): ?>
    <li class="qp-row <?= $e(\CW\Ui\Words::tone('STORE_STATE', $r['state'])) ?>" data-listing-id="<?= $e($r['listing_id']) ?>" data-sku-id="<?= $e($sku['id']) ?>" data-channel="<?= $e($store['code']) ?>">
      <p class="qp-name"><a class="o-name" href="<?= $e($r['choose']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a><?php if ($r['variant_title'] !== null): ?> <span class="o-sub"><?= $e($r['variant_title']) ?></span><?php endif; ?></p>
      <p class="qp-chips"><?= $stateChip('STORE_STATE', $r['state']) ?><?php if ($r['band'] !== null): ?> <?= $chip(\CW\Ui\Words::tone('BAND', $r['band']), \CW\Ui\Words::of('BAND', $r['band'])) ?><?php endif; ?> <?php if ($r['barcode'] === 'unknown'): ?><span class="muted"><?= $word('BY_PRODUCT', 'pick_barcode_unknown') ?></span><?php else: ?><?= $chip(\CW\Ui\Words::tone('FIELD_STATE', $r['barcode']), \CW\Ui\Words::BY_PRODUCT['pick_barcode_' . $r['barcode']]) ?><?php endif; ?></p>
      <dl class="qp-facts">
<?php if ($r['brand'] !== null): ?>
        <dt><?= $word('FIELD', 'brand') ?></dt><dd><?= $e($r['brand']) ?></dd>
<?php endif; ?>
        <dt><?= $word('LISTING', 'option') ?></dt><dd><?= $e($r['variant']) ?></dd>
<?php foreach ($r['facts'] as $field => $value): ?>
        <dt><?= $word('FIELD', $field) ?></dt><dd><?= $e($value) ?></dd>
<?php endforeach; ?>
<?php if ($r['barcodes'] !== []): ?>
        <dt><?= $word('FIELD', 'barcodes') ?></dt><dd><?= $e(implode(', ', $r['barcodes'])) ?></dd>
<?php endif; ?>
<?php if ($r['price'] !== null): ?>
        <dt><?= $word('BY_PRODUCT', 'pick_price') ?></dt><dd><?php if (is_numeric($r['price'])): ?><?= $money($r['price']) ?><?php else: ?><?= $e($r['price']) ?><?php endif; ?></dd>
<?php endif; ?>
        <dt><?= $word('QUEUE', 'sold_30') ?></dt><dd><?= $n($r['units_30d'] ?? 0) ?></dd>
        <dt><?= $word('QUEUE', 'sold_365') ?></dt><dd><?= $n($r['units_365d'] ?? 0) ?></dd>
      </dl>
<?php if ($r['why'] !== null): ?>
      <p class="qp-why"><?= $e($r['why']['text']) ?><?php if ($r['why']['link'] !== null): ?> <a href="<?= $e($r['why']['link'][0]) ?>"><?= $e($r['why']['link'][1]) ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($r['match']): ?>
      <form class="qp-match" method="post" action="/ui/review/listing/<?= $e($r['listing_id']) ?>/decide" data-listing-id="<?= $e($r['listing_id']) ?>" data-sku-id="<?= $e($sku['id']) ?>" data-channel="<?= $e($store['code']) ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="link">
        <input type="hidden" name="sku_id" value="<?= $e($sku['id']) ?>">
        <input type="hidden" name="expected_map_version" value="<?= $e($r['map_version']) ?>">
<?php if ($r['proposal_id'] !== null): ?>
        <input type="hidden" name="proposal_id" value="<?= $e($r['proposal_id']) ?>">
<?php endif; ?>
<?php foreach ($back as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
        <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
        <label class="qp-units"><span><?= $word('BY_PRODUCT', 'quick_units') ?></span> <input type="number" name="units_per_item" min="1" max="<?= $e($max_units) ?>" inputmode="numeric" value="<?= $e($r['units']) ?>" required></label>
        <button type="submit" class="btn sm" data-listing-id="<?= $e($r['listing_id']) ?>" data-sku-id="<?= $e($sku['id']) ?>" data-channel="<?= $e($store['code']) ?>" title="<?= $say('BY_PRODUCT', 'quick_match_named', $r['title'] ?? \CW\Ui\Words::LISTING['no_title'], $sku['code']) ?>"><?= $say('BY_PRODUCT', 'quick_match', $sku['code']) ?></button>
      </form>
<?php else: ?>
      <p class="qp-open"><a class="btn sm" href="<?= $e($r['choose']) ?>"><?= $word('BY_PRODUCT', 'pick_choose') ?></a></p>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($more !== null): ?>
  <p class="qp-more"><a href="<?= $e($more) ?>"><?= $word('BY_PRODUCT', 'quick_more') ?></a></p>
<?php endif; ?>
  <p class="hint"><?= $word('BY_PRODUCT', 'quick_hint') ?></p>
</div>
