<?= $head(\CW\Ui\Words::MENU['stock_value'], intro: 'stock_value') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
<?php if ($f['by'] === 'product'): ?>
<?php if ($f['nocost']): ?>
  <input type="hidden" name="nocost" value="1">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <button type="submit" class="btn ghost sm"><?= $word('UI', 'apply') ?></button>
<?php else: ?>
  <input type="hidden" name="by" value="<?= $e($f['by']) ?>">
<?php endif; ?>
  <a class="btn ghost sm" href="<?= $e($csv_link) ?>"><?= $word('REPORTS', 'csv') ?></a>
</form>

<section class="home-tiles" aria-label="<?= $word('MENU', 'stock_value') ?>">
  <div class="kpis-wrap"><ul class="kpis four">
    <li class="kpi"><p class="kpi-label"><?= $word('REPORTS', 'our_value') ?></p><p class="kpi-value"><?= $money($figures['value']) ?></p><p class="kpi-sub"><?= $word('REPORTS', 'our_value_sub') ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('REPORTS', 'units_costed') ?></p><p class="kpi-value"><?= $n($figures['costed']) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('REPORTS', 'units_not_costed') ?></p><p class="kpi-value"><?= $n($figures['not_costed']) ?></p><p class="kpi-sub"><a href="<?= $e($nocost_link) ?>"><?= $word('REPORTS', 'no_cost_list') ?></a></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('REPORTS', 'theirs') ?></p><p class="kpi-value"><?= $n($figures['theirs']) ?></p><p class="kpi-sub"><?= $word('REPORTS', 'theirs_sub') ?></p></li>
  </ul></div>
</section>
<p class="note"><?= $word('REPORTS', 'cost_note') ?> <?= $word('REPORTS', 'below_zero') ?> <?= $word('REPORTS', 'past_date') ?> <?= $e($asOf) ?></p>
<nav class="tabs show-tabs" aria-label="<?= $word('REPORTS', 'show') ?>">
<?php foreach ($tabs as $t): ?>
  <a class="tab"<?php if ($t['current']): ?> aria-current="page"<?php else: ?> href="<?= $e($t['href']) ?>"<?php endif; ?>><span><?= $word('REPORTS', 'by_' . $t['by']) ?></span></a>
<?php endforeach; ?>
</nav>

<?php if ($f['by'] === 'product'): ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_value'], \CW\Ui\Words::REPORTS['no_value_text']) ?>
<?php else: ?>
<?php if ($f['nocost']): ?>
<p class="note"><?= $word('REPORTS', 'no_cost_list') ?> <a href="<?= $e($all_link) ?>"><?= $word('REPORTS', 'clear') ?></a></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack list report-value">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'product') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'avg_cost') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'value') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($r['units']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'avg_cost') ?>"><?php if ($r['avg'] === null): ?><span class="muted"><?= $word('REPORTS', 'no_cost') ?></span><?php else: ?><?= $money($r['avg']) ?><?php endif; ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value') ?>"><?php if ($r['value'] !== null): ?><?= $money($r['value']) ?><?php endif; ?></td>
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
<?php if ($brands === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_value'], \CW\Ui\Words::REPORTS['no_value_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list report-brands">
  <thead>
    <tr>
      <th scope="col"><?= $word('REPORTS', 'brand') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'products') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'value') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'no_cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($brands as $b): ?>
    <tr>
      <th scope="row" class="c-head"><?php if ($b['brand'] === null): ?><?= $word('REPORTS', 'no_brand') ?><?php else: ?><?= $e($b['brand']) ?><?php endif; ?></th>
      <td class="num" data-label="<?= $word('REPORTS', 'products') ?>"><?= $n($b['products']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($b['units']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value') ?>"><?= $money($b['value']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'no_cost') ?>"><?= $n($b['not_costed']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php else: ?>
<?php if ($warehouses === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_value'], \CW\Ui\Words::REPORTS['no_value_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list report-warehouses">
  <thead>
    <tr>
      <th scope="col"><?= $word('REPORTS', 'warehouse') ?></th>
      <th scope="col"><?= $word('REPORTS', 'whose') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'products') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'value') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'no_cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($warehouses as $w): ?>
    <tr>
      <th scope="row" class="c-head"><a href="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>"><?= $e($w['name']) ?></a></th>
      <td data-label="<?= $word('REPORTS', 'whose') ?>"><?php if ($w['ours']): ?><?= $word('REPORTS', 'ours') ?><?php else: ?><?= $word('REPORTS', 'not_ours') ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'products') ?>"><?= $n($w['products']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($w['units']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'value') ?>"><?= $money($w['value']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'no_cost') ?>"><?= $n($w['not_costed']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
