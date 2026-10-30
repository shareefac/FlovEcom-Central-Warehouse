<?php
// Reports › Slow stock, a report (the approved TP_Report of 29 Oct 2026, pattern 5; docs/decisions.md W13.6): the heading with when the
// sales were last brought up to date under it, the list's file (Download (Excel)) at its right and what a sale is under its "?"; the figures
// strip (slow, dead, each with its days); one search (a product) and the Show row (slow or dead, each with its count) with its Show button;
// then the list as one board with its blue edge, the longest without a sale first (with its value for whoever may see money).
$bar = $partial('report_bar', ['href' => $csv_link]);
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::MENU['slow_stock'], intro: 'slow_stock', more: \CW\Ui\Words::REPORTS['sale_means'], meta: $e($asOf), side: $bar) ?>
</div>

<section class="doc-progress rp-figs" aria-label="<?= $word('REPORTS', 'slow_figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'show_slow') ?></p><p class="doc-step-big"><?= $n($counts['slow']) ?></p><p class="step-sub"><?= $say('REPORTS', 'fig_days_sub', $slowDays) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'show_dead') ?></p><p class="doc-step-big"><?= $n($counts['dead']) ?></p><p class="step-sub"><?= $say('REPORTS', 'fig_days_sub', $deadDays) ?></p></div>
</section>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('REPORTS', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value=""<?php if ($f['show'] === 'slow'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_slow_n', $counts['slow']) ?></option>
      <option value="dead"<?php if ($f['show'] === 'dead'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_dead_n', $counts['dead']) ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>

<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_slow'], \CW\Ui\Words::REPORTS['no_slow_text']) ?>
<?php else: ?>
<div class="table-wrap doc-board info">
<table class="stack list board doc-list report-slow">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'product') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col"><?= $word('REPORTS', 'last_sale') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'days_since') ?></th>
<?php if ($showValue): ?>
      <th scope="col" class="num"><?= $word('REPORTS', 'value_at_cost') ?></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
      <td class="num" data-label="<?= $word('REPORTS', 'units') ?>"><?= $n($r['units']) ?></td>
      <td data-label="<?= $word('REPORTS', 'last_sale') ?>"><?php if ($r['last_sale'] === null): ?><span class="muted"><?= $word('REPORTS', 'never_sold') ?></span><?php if ($r['since'] !== null): ?> · <?= $word('REPORTS', 'in_stock_since') ?> <?= $day($r['since']) ?><?php endif; ?><?php else: ?><?= $day($r['last_sale']) ?><?php endif; ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'days_since') ?>"><?= $n($r['days']) ?></td>
<?php if ($showValue): ?>
      <td class="num" data-label="<?= $word('REPORTS', 'value_at_cost') ?>"><?= $money($r['value']) ?></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('REPORTS', 'page', $page_no, $pages) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('REPORTS', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('REPORTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
