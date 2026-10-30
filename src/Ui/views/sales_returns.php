<?php
// Stock › Sales › Returns (pack D, docs/decisions.md PD7), a board (the approved design of 29 Oct 2026, pattern 1; docs/decisions.md W10):
// read only. One Show row (returned, cancelled or both; and the store, with each store's units waiting for a recount: docs/decisions.md DC33),
// Clear and Export at the right; the figures strip; then the page's units grouped by what happened (returned, then cancelled), newest order first in each,
// every group a coloured heading with its count over a card with its colour on the left edge, the status cell (back on sale?) filled. A
// unit's item opens the item, its order the order's record page. No form here posts anything.
?>
<?= $head(\CW\Ui\Words::MENU['sales_returns'], intro: 'sales_returns') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('DOC_LIST', 'show') ?></label>
    <select id="tb-show" name="kind">
      <option value=""><?= $word('SALE_RETURNS', 'any_kind') ?></option>
<?php foreach ($kinds as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['kind'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-store"><?= $word('BULK', 'stores') ?></label>
    <select id="tb-store" name="channel" title="<?= $say('BULK', 'store_count_title', \CW\Ui\Words::SALE_RETURNS['store_count']) ?>">
<?php foreach ($storeItems as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="<?= $e($clear) ?>"><?= $word('SALE_RETURNS', 'clear') ?></a>
<?php endif; ?>
<?php if ($rows_n > 0): ?>
    <a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('SALE_RETURNS', 'download') ?>" download><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
<?php endif; ?>
  </div>
</form>

<section class="doc-progress rp-figs" aria-label="<?= $word('SALE_RETURNS', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('SALE_RETURNS', 'tile_verify') ?></p><p class="doc-step-big"><?= $n($verifyUnits) ?></p><p class="step-sub"><?= $say('SALE_RETURNS', 'tile_verify_sub', $verifyName) ?></p></div>
</section>

<?php if ($groups === []): ?>
<?php if ($filtered || $newest_link !== null): ?>
<?= $empty(\CW\Ui\Words::SALE_RETURNS['none_filtered'], \CW\Ui\Words::SALE_RETURNS['none_filtered_text'], $clear, \CW\Ui\Words::SALE_RETURNS['clear']) ?>
<?php elseif ($f['channel'] !== null): ?>
<?= $empty(\CW\Ui\Words::SALE_RETURNS['none_store'], \CW\Ui\Words::SALE_RETURNS['none_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::SALE_RETURNS['none'], \CW\Ui\Words::SALE_RETURNS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g => $grp): ?>
<div class="grp-block doc-group <?= $e($grp['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($grp['name']) ?></button> <span class="grp-count"><?php if (count($grp['rows']) === 1): ?><?= $word('RESV', 'group_one') ?><?php else: ?><?= $say('RESV', 'group_many', count($grp['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list sales-returns">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('SALE_RETURNS', 'item') ?></th>
        <th scope="col" class="c-status"><?= $word('SALE_RETURNS', 'back_on_sale') ?></th>
        <th scope="col"><?= $word('SALE_RETURNS', 'order') ?></th>
        <th scope="col"><?= $word('SALE_RETURNS', 'store') ?></th>
        <th scope="col"><?= $word('SALE_RETURNS', 'unit') ?></th>
        <th scope="col"><?= $word('SALE_RETURNS', 'when') ?></th>
        <th scope="col"><?= $word('SALE_RETURNS', 'stock_now') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><?php if ($r['sku_id'] !== null): ?><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><?php else: ?><?= $e($r['name']) ?><?php endif; ?><span class="o-sub"><?= $e($r['sub']) ?></span></th>
        <td class="c-status doc-st <?= $e($r['restockTone']) ?>"><?= $e($r['restockWord']) ?></td>
        <td data-label="<?= $word('SALE_RETURNS', 'order') ?>"><a href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a></td>
        <td data-label="<?= $word('SALE_RETURNS', 'store') ?>"><?= $e($r['store']) ?></td>
        <td data-label="<?= $word('SALE_RETURNS', 'unit') ?>"><?= $e($r['unit']) ?></td>
        <td data-label="<?= $word('SALE_RETURNS', 'when') ?>"><?= $when($r['when']) ?></td>
        <td class="c-wide" data-label="<?= $word('SALE_RETURNS', 'stock_now') ?>"><?= $e($r['where']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('SALE_RETURNS', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('SALE_RETURNS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
