<?php
// Stock › Sales › Returns (pack D, docs/decisions.md PD7): read only. The toolbar (Filter: returned, cancelled or both; Export), the
// store selector (with each store's units waiting for a recount), one figure, then the units newest order first. No form here posts.
?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
<?php if ($f['channel'] !== null): ?>
  <input type="hidden" name="channel" value="<?= $e($f['channel']) ?>">
<?php endif; ?>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('SALE_RETURNS', 'kind') ?>
        <select name="kind">
          <option value=""><?= $word('SALE_RETURNS', 'any_kind') ?></option>
<?php foreach ($kinds as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($f['kind'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="<?= $e($clear) ?>"><?= $word('SALE_RETURNS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
<?php if ($rows !== []): ?>
  <div class="tb-end"><a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('SALE_RETURNS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a></div>
<?php endif; ?>
</form>
<div class="head-help">
  <h1><?= $word('MENU', 'sales_returns') ?></h1>
</div>
<?= $intro('sales_returns') ?>
<?= $partial('store_seg', ['items' => $storeItems, 'countWords' => \CW\Ui\Words::SALE_RETURNS['store_count']]) ?>

<section class="home-tiles" aria-label="<?= $word('SALE_RETURNS', 'figures') ?>">
  <div class="kpis-wrap"><ul class="kpis">
    <li class="kpi"><p class="kpi-label"><?= $word('SALE_RETURNS', 'tile_verify') ?></p><p class="kpi-value"><?= $n($verifyUnits) ?></p><p class="kpi-sub"><?= $say('SALE_RETURNS', 'tile_verify_sub', $verifyName) ?></p></li>
  </ul></div>
</section>

<?php if ($rows === []): ?>
<?php if ($filtered || $newest_link !== null): ?>
<?= $empty(\CW\Ui\Words::SALE_RETURNS['none_filtered'], \CW\Ui\Words::SALE_RETURNS['none_filtered_text'], $clear, \CW\Ui\Words::SALE_RETURNS['clear']) ?>
<?php elseif ($f['channel'] !== null): ?>
<?= $empty(\CW\Ui\Words::SALE_RETURNS['none_store'], \CW\Ui\Words::SALE_RETURNS['none_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::SALE_RETURNS['none'], \CW\Ui\Words::SALE_RETURNS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('SALE_RETURNS', 'board') ?></h2><p class="board-note"><?= $say('SALE_RETURNS', 'board_note', $page_size) ?></p></div>
<div class="table-wrap">
<table class="stack list board sales-returns">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('SALE_RETURNS', 'item') ?></th>
      <th scope="col"><?= $word('SALE_RETURNS', 'order') ?></th>
      <th scope="col"><?= $word('SALE_RETURNS', 'store') ?></th>
      <th scope="col"><?= $word('SALE_RETURNS', 'unit') ?></th>
      <th scope="col"><?= $word('SALE_RETURNS', 'what') ?></th>
      <th scope="col"><?= $word('SALE_RETURNS', 'when') ?></th>
      <th scope="col" class="c-status"><?= $word('SALE_RETURNS', 'back_on_sale') ?></th>
      <th scope="col"><?= $word('SALE_RETURNS', 'stock_now') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><?php if ($r['sku_id'] !== null): ?><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><?php else: ?><?= $e($r['name']) ?><?php endif; ?><span class="o-sub"><?= $e($r['sub']) ?></span></th>
      <td data-label="<?= $word('SALE_RETURNS', 'order') ?>"><a href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a></td>
      <td data-label="<?= $word('SALE_RETURNS', 'store') ?>"><?= $e($r['store']) ?></td>
      <td data-label="<?= $word('SALE_RETURNS', 'unit') ?>"><?= $e($r['unit']) ?></td>
      <td data-label="<?= $word('SALE_RETURNS', 'what') ?>"><?= $e($r['what']) ?></td>
      <td data-label="<?= $word('SALE_RETURNS', 'when') ?>"><?= $when($r['when']) ?></td>
      <td class="c-status"><?= $chip($r['restockTone'], $r['restockWord']) ?></td>
      <td class="c-wide" data-label="<?= $word('SALE_RETURNS', 'stock_now') ?>"><?= $e($r['where']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="foot"><?= $word('SALE_RETURNS', 'foot') ?></p>
<?php endif; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('SALE_RETURNS', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('SALE_RETURNS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
