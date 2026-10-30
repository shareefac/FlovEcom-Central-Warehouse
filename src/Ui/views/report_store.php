<?php
// Reports › Stock by store, a report (the approved TP_Report of 29 Oct 2026, pattern 5; docs/decisions.md W13.4): the heading with the
// store's file (Download (Excel)) at its right; the figures strip of the store shown (the units it can sell and the warehouse it sells from,
// the products matched, in stock, out of stock); one search (a product) and the Show row (the store, all / in stock / out of stock) with its
// Show button, Clear under the toolbar's More; then the store's products as one board with its blue edge, each with its status filled solid.
$fig = null;
foreach ($stores as $s) {
    if ($store !== null && $s['id'] === $store['id']) {
        $fig = $s;
    }
}
$bar = $store !== null ? $partial('report_bar', ['href' => $csv_link]) : '';
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::MENU['stock_by_store'], intro: 'stock_by_store', side: $bar) ?>
</div>
<?php if ($stores === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_stores']) ?>
<?php else: ?>
<?php if ($fig !== null): ?>
<section class="doc-progress rp-figs" aria-label="<?= $e($fig['name']) ?>">
<?php if ($fig['warehouse'] === null): ?>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'can_sell') ?></p><p class="doc-step-big">0</p><p class="step-sub"><?= $word('REPORTS', 'no_warehouse') ?></p></div>
<?php else: ?>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'can_sell') ?></p><p class="doc-step-big"><?= $n($fig['figures']['units']) ?></p><p class="step-sub"><?= $say('REPORTS', 'sells_from', $fig['warehouse']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'matched') ?></p><p class="doc-step-big"><?= $n($fig['figures']['matched']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'in_stock') ?></p><p class="doc-step-big"><?= $n($fig['figures']['in']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'out_of_stock') ?></p><p class="doc-step-big"><?= $n($fig['figures']['out']) ?></p></div>
<?php endif; ?>
</section>
<?php endif; ?>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find') ?>"></div>
  <div class="tb-state">
    <label for="tb-store"><?= $word('REPORTS', 'store') ?></label>
    <select id="tb-store" name="store">
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($store !== null && $s['id'] === $store['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label for="tb-show"><?= $word('REPORTS', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value="all"<?php if ($f['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_all') ?></option>
      <option value="in"<?php if ($f['show'] === 'in'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_in') ?></option>
      <option value="out"<?php if ($f['show'] === 'out'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_out') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
        <a class="menu-item" href="<?= $e($clear_link) ?>"><?= $word('REPORTS', 'clear') ?></a>
      </div>
    </details>
  </div>
<?php endif; ?>
</form>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::REPORTS['none_filtered'], \CW\Ui\Words::REPORTS['none_filtered_text'], $clear_link, \CW\Ui\Words::REPORTS['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_matched'], \CW\Ui\Words::REPORTS['no_matched_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap doc-board info">
<table class="stack list board doc-list report-store">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'product') ?></th>
      <th scope="col" class="c-status"><?= $word('REPORTS', 'status') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'store_units') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
<?php $tone = $r['policy'] === 'stopped' ? 'off' : ($r['available'] > 0 ? 'done' : 'blocked'); ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?><?php if ($r['pack'] > 1): ?> · <?= $say('REPORTS', 'pack', $r['pack']) ?><?php endif; ?><?php if ($r['policy'] === 'legacy'): ?> · <?= $word('REPORTS', 'own_stock') ?><?php endif; ?></span></th>
      <td class="c-status doc-st <?= $e($tone) ?>"><span class="chip"><?php if ($r['policy'] === 'stopped'): ?><?= $word('REPORTS', 'stopped') ?><?php elseif ($r['available'] > 0): ?><?= $word('REPORTS', 'in_stock') ?><?php else: ?><?= $word('REPORTS', 'out_of_stock') ?><?php endif; ?></span></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'store_units') ?>"><?= $n($r['available']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('REPORTS', 'page', $page_no, $pages) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('REPORTS', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('REPORTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
