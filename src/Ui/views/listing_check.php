<?php
// The lists' Check & match fold (docs/decisions.md CM1-CM4; the owner's request of 20 Oct 2026: "add a match button here so no need to
// go inside the page"): ONE website variant found by the check on arrival next to the warehouse item its Likely suggestion names, as
// ReviewController::check draws it for app.js. Not a page: no statement, no layout. Its root says what it is (data-lc-fragment: app.js
// shows nothing else). Every fact and line is the variant page's own (ReviewController::foldFacts, whyOf). Only a clean likely match has
// the two answers: plain POST forms of the variant's decide route with what the page's forms carry (the version, the suggestion,
// `seen`), `from=check` and the way back to the list at this row. Anything else says why, and leads to the variant's page. Simple,
// stable markup that is easy to script: the root, each answer's form and button carry data-listing-id, data-sku-id and data-channel.
$skuId = $target['id'] ?? '';
?>
<div class="lc" data-lc-fragment data-listing-id="<?= $e($l['id']) ?>" data-sku-id="<?= $e($skuId) ?>" data-channel="<?= $e($l['channel']) ?>" data-clean="<?= $e($clean ? 'yes' : 'no') ?>">
  <div class="lc-rows" role="table" aria-label="<?= $word('CHECK', 'fold') ?>">
    <div class="lc-row lc-head" role="row"><span role="columnheader"><?= $word('CHECK', 'what') ?></span><span role="columnheader"><?= $word('CHECK', 'website') ?></span><span role="columnheader"><?= $word('CHECK', 'warehouse') ?></span></div>
    <div class="lc-row" role="row" data-field="name"><span class="lc-k" role="rowheader"><?= $word('CHECK', 'name') ?></span><span class="lc-w" role="cell"><?= $e($l['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?><?php if ($l['variant_title'] !== null): ?> <span class="lc-sub"><?= $e($l['variant_title']) ?></span><?php endif; ?></span><span class="lc-i" role="cell"><?php if ($target !== null): ?><a href="<?= $u('/ui/items/' . $target['id']) ?>"><?= $e($target['code']) ?></a> <?= $e($target['name']) ?><?php else: ?><span class="muted"><?= $word('CHECK', 'not_stated') ?></span><?php endif; ?></span></div>
<?php foreach ($compare as $r): ?>
    <div class="lc-row<?php if ($r['state'] === 'differs'): ?> differs<?php endif; ?>" role="row" data-field="<?= $e($r['field']) ?>" data-state="<?= $e($r['state']) ?>"><span class="lc-k" role="rowheader"><?= $e($r['label']) ?> <?= $stateChip('FIELD_STATE', $r['state']) ?></span><span class="lc-w" role="cell"><?php if ($r['listing'] === ''): ?><span class="muted"><?= $word('CHECK', 'not_stated') ?></span><?php else: ?><?= $e($r['listing']) ?><?php endif; ?></span><span class="lc-i" role="cell"><?php if ($r['item'] === ''): ?><span class="muted"><?= $word('CHECK', 'not_stated') ?></span><?php else: ?><?= $e($r['item']) ?><?php endif; ?></span></div>
<?php endforeach; ?>
<?php if ($l['price'] !== null): ?>
    <div class="lc-row" role="row" data-field="price"><span class="lc-k" role="rowheader"><?= $word('CHECK', 'price') ?></span><span class="lc-w" role="cell"><?php if (is_numeric($l['price'])): ?><?= $money($l['price']) ?><?php else: ?><?= $e($l['price']) ?><?php endif; ?></span><span class="lc-i" role="cell"></span></div>
<?php endif; ?>
    <div class="lc-row" role="row" data-field="units_30d"><span class="lc-k" role="rowheader"><?= $word('QUEUE', 'sold_30') ?></span><span class="lc-w" role="cell"><?= $n($l['units_30d']) ?></span><span class="lc-i" role="cell"></span></div>
    <div class="lc-row" role="row" data-field="units_365d"><span class="lc-k" role="rowheader"><?= $word('QUEUE', 'sold_365') ?></span><span class="lc-w" role="cell"><?= $n($l['units_365d']) ?></span><span class="lc-i" role="cell"></span></div>
  </div>
<?php if ($why !== null): ?>
  <dl class="lc-why">
    <dt><?= $word('CHECK', 'how_sure') ?></dt><dd><strong><?= $e($why['band']) ?></strong> – <?= $e($why['band_help']) ?></dd>
<?php if ($why['here'] !== null): ?>
    <dt><?= $word('CHECK', 'here') ?></dt><dd><?= $e($why['here']) ?></dd>
<?php endif; ?>
<?php if ($why['cannot'] !== [] || $why['watch'] !== [] || $list_watch !== []): ?>
    <dt><?= $word('CHECK', 'watch') ?></dt><dd><ul class="plain watch">
<?php foreach ($why['cannot'] as $c): ?>
      <li><span class="tag bad"><?= $e($c) ?></span></li>
<?php endforeach; ?>
<?php foreach ([...$why['watch'], ...$list_watch] as $w): ?>
      <li><span class="tag warn"><?= $e($w) ?></span></li>
<?php endforeach; ?>
    </ul></dd>
<?php endif; ?>
  </dl>
<?php endif; ?>
<?php if ($clean): ?>
  <p class="lc-clean"><?= $word('CHECK', 'clean') ?></p>
  <div class="lc-answers">
    <form class="lc-yes" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide" data-answer="yes" data-listing-id="<?= $e($l['id']) ?>" data-sku-id="<?= $e($skuId) ?>" data-channel="<?= $e($l['channel']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="link">
      <input type="hidden" name="sku_id" value="<?= $e($skuId) ?>">
      <input type="hidden" name="units_per_item" value="1">
      <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
      <input type="hidden" name="proposal_id" value="<?= $e($proposal_id) ?>">
      <input type="hidden" name="seen" value="<?= $e($seen) ?>">
<?php foreach ($fields as $key => $value): ?>
      <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
      <button type="submit" class="btn primary" data-answer="yes" data-listing-id="<?= $e($l['id']) ?>" data-sku-id="<?= $e($skuId) ?>" data-channel="<?= $e($l['channel']) ?>" title="<?= $e($yes_named) ?>"><?= $e($yes) ?></button>
    </form>
    <form class="lc-no" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide" data-answer="no" data-listing-id="<?= $e($l['id']) ?>" data-sku-id="<?= $e($skuId) ?>" data-channel="<?= $e($l['channel']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="sku_id" value="<?= $e($skuId) ?>">
      <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
      <input type="hidden" name="proposal_id" value="<?= $e($proposal_id) ?>">
      <input type="hidden" name="seen" value="<?= $e($seen) ?>">
<?php foreach ($fields as $key => $value): ?>
      <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
      <button type="submit" class="btn secondary" data-answer="no" data-listing-id="<?= $e($l['id']) ?>" data-sku-id="<?= $e($skuId) ?>" data-channel="<?= $e($l['channel']) ?>" title="<?= $say('CHECK', 'no_named', $l['title'] ?? \CW\Ui\Words::LISTING['no_title'], (string) ($target['code'] ?? '')) ?>"><?= $word('CHECK', 'no') ?></button>
    </form>
  </div>
  <p class="lc-open"><a href="<?= $e($open) ?>"><?= $word('CHECK', 'open_page') ?></a></p>
<?php else: ?>
  <div class="lc-not" role="note">
    <p><?= $word('CHECK', 'not_here') ?></p>
    <ul class="plain">
<?php foreach ($blocks as $b): ?>
      <li><?= $e($b) ?></li>
<?php endforeach; ?>
    </ul>
  </div>
  <p class="lc-open"><a class="btn primary" href="<?= $e($open) ?>" data-listing-id="<?= $e($l['id']) ?>"><?= $word('CHECK', 'open') ?></a></p>
<?php endif; ?>
</div>
