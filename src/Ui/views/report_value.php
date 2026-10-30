<?php
// Reports › Stock value, a report (the approved TP_Report of 29 Oct 2026, pattern 5; docs/decisions.md W13.3): the heading with "At average
// cost" and when the costs were last brought up to date under it, Download (Excel) at its right, what the value leaves out under its "?";
// the figures strip (value at cost, units on the shelf, items with stock, not sold in the slow days); one search (a brand or an item) and
// the Group by choice (item, brand, warehouse) with its Show button, the list of products without a cost and Clear under the toolbar's More;
// then the list as one board with its blue edge: by item (paged), by brand or by warehouse each with its share of our value and the total.
$help = \CW\Ui\Words::REPORTS['cost_note'] . ' ' . \CW\Ui\Words::REPORTS['below_zero'] . ' ' . \CW\Ui\Words::REPORTS['past_date'];
$bar = $partial('report_bar', ['href' => $csv_link]);
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::MENU['stock_value'], intro: 'stock_value', more: $help, meta: $e(\CW\Ui\Words::REPORTS['at_cost'] . ' ' . $asOf), side: $bar) ?>
</div>

<section class="doc-progress rp-figs" aria-label="<?= $word('REPORTS', 'value_figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'fig_value') ?></p><p class="doc-step-big"><?= $money($figures['value']) ?></p><?php if ($figures['theirs'] > 0): ?><p class="step-sub"><?= $say('REPORTS', 'fig_theirs_sub', $figures['theirs']) ?></p><?php endif; ?></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'fig_units') ?></p><p class="doc-step-big"><?= $n($figures['costed'] + $figures['not_costed']) ?></p><?php if ($figures['not_costed'] > 0): ?><p class="step-sub"><?= $say('REPORTS', 'fig_no_cost_sub', $figures['not_costed']) ?></p><?php endif; ?></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'fig_items') ?></p><p class="doc-step-big"><?= $n($figures['items']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $say('REPORTS', 'fig_not_sold', $slowDays) ?></p><p class="doc-step-big"><?= $money($notSold) ?></p></div>
</section>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find_value') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find_value') ?>"></div>
  <div class="tb-state">
    <label for="tb-by"><?= $word('REPORTS', 'group_by') ?></label>
    <select id="tb-by" name="by">
<?php foreach ($groupings as $by): ?>
      <option value="<?php if ($by !== 'product'): ?><?= $e($by) ?><?php endif; ?>"<?php if ($f['by'] === $by): ?> selected<?php endif; ?>><?= $word('REPORTS', 'group_' . $by) ?></option>
<?php endforeach; ?>
    </select>
<?php if ($f['nocost'] && $f['by'] === 'product'): ?>
    <input type="hidden" name="nocost" value="1">
<?php endif; ?>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
<?php if (!$f['nocost'] || $f['by'] !== 'product'): ?>
        <a class="menu-item" href="<?= $e($nocost_link) ?>"><?= $word('REPORTS', 'no_cost_list') ?></a>
<?php endif; ?>
<?php if ($filtered): ?>
        <a class="menu-item" href="<?= $e($all_link) ?>"><?= $word('REPORTS', 'clear') ?></a>
<?php endif; ?>
      </div>
    </details>
  </div>
</form>

<?php if ($f['by'] === 'product'): ?>
<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REPORTS['none_filtered'], \CW\Ui\Words::REPORTS['none_filtered_text'], $all_link, \CW\Ui\Words::REPORTS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_value'], \CW\Ui\Words::REPORTS['no_value_text']) ?>
<?php else: ?>
<div class="table-wrap doc-board info">
<table class="stack list board doc-list report-value">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'item') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'avg_cost') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'value_at_cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($r['units']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'avg_cost') ?>"><?php if ($r['avg'] === null): ?><span class="muted"><?= $word('REPORTS', 'no_cost') ?></span><?php else: ?><?= $money($r['avg']) ?><?php endif; ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value_at_cost') ?>"><?php if ($r['value'] !== null): ?><?= $money($r['value']) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('REPORTS', 'page', $page_no, $pages) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('REPORTS', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('REPORTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?php elseif ($f['by'] === 'brand'): ?>
<?php if ($brands === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REPORTS['none_filtered'], \CW\Ui\Words::REPORTS['none_filtered_text'], $all_link, \CW\Ui\Words::REPORTS['clear']) ?>
<?php elseif ($brands === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_value'], \CW\Ui\Words::REPORTS['no_value_text']) ?>
<?php else: ?>
<div class="table-wrap doc-board info">
<table class="stack list board doc-list report-brands">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'brand') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'items') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'value_at_cost') ?></th>
      <th scope="col" class="c-share"><?= $word('REPORTS', 'share') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($brands as $b): ?>
    <tr>
      <th scope="row" class="c-head c-item"><?php if ($b['brand'] === null): ?><?= $word('REPORTS', 'no_brand') ?><?php else: ?><?= $e($b['brand']) ?><?php endif; ?></th>
      <td class="num" data-label="<?= $word('REPORTS', 'items') ?>"><?= $n($b['products']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($b['units']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value_at_cost') ?>"><?= $money($b['value']) ?></td>
      <td class="c-share" data-label="<?= $word('REPORTS', 'share') ?>"><?php if ($b['share'] !== null): ?><meter class="rp-share" min="0" max="100" value="<?= $e($b['share']) ?>"></meter> <span class="muted"><?= $n($b['share']) ?>%</span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="grp-foot">
      <th scope="row" class="c-head c-item"><?= $word('REPORTS', 'all_total') ?></th>
      <td class="num" data-label="<?= $word('REPORTS', 'items') ?>"><?= $n($sum['products']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($sum['units']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value_at_cost') ?>"><?= $money($sum['value']) ?></td>
      <td class="c-share" data-label=""></td>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php else: ?>
<?php if ($warehouses === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REPORTS['none_filtered'], \CW\Ui\Words::REPORTS['none_filtered_text'], $all_link, \CW\Ui\Words::REPORTS['clear']) ?>
<?php elseif ($warehouses === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_value'], \CW\Ui\Words::REPORTS['no_value_text']) ?>
<?php else: ?>
<div class="table-wrap doc-board info">
<table class="stack list board doc-list report-warehouses">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'warehouse') ?></th>
      <th scope="col"><?= $word('REPORTS', 'whose') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'items') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'value_at_cost') ?></th>
      <th scope="col" class="c-share"><?= $word('REPORTS', 'share') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($warehouses as $w): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>"><?= $e($w['name']) ?></a></th>
      <td data-label="<?= $word('REPORTS', 'whose') ?>"><?php if ($w['ours']): ?><?= $word('REPORTS', 'ours') ?><?php else: ?><?= $word('REPORTS', 'not_ours') ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'items') ?>"><?= $n($w['products']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($w['units']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value_at_cost') ?>"><?= $money($w['value']) ?></td>
      <td class="c-share" data-label="<?= $word('REPORTS', 'share') ?>"><?php if ($w['share'] !== null): ?><meter class="rp-share" min="0" max="100" value="<?= $e($w['share']) ?>"></meter> <span class="muted"><?= $n($w['share']) ?>%</span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="grp-foot">
      <th scope="row" class="c-head c-item"><?= $word('REPORTS', 'ours_total') ?></th>
      <td data-label=""></td>
      <td class="num" data-label=""></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($sum['units']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value_at_cost') ?>"><?= $money($sum['value']) ?></td>
      <td class="c-share" data-label=""></td>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
