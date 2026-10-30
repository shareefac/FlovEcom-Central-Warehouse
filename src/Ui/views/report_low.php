<?php
// Reports › Low stock, a report (the approved TP_Report of 29 Oct 2026, pattern 5; docs/decisions.md W13.5): the heading with the list's
// file (Download (Excel)) at its right; the figures strip (how many are low, how many have none left, the level a product is low at); one
// search (a product) and the Show row (low or none left, each with its count) with its Show button; then the list as one board with its
// blue edge, the fewest first, each with the units the stores can sell, its low level and (for the buyers) its reorder page.
$bar = $partial('report_bar', ['href' => $csv_link]);
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::MENU['low_stock'], intro: 'low_stock', side: $bar) ?>
</div>

<section class="doc-progress rp-figs" aria-label="<?= $word('REPORTS', 'low_figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'show_low') ?></p><p class="doc-step-big"><?= $n($counts['low']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'show_none') ?></p><p class="doc-step-big"><?= $n($counts['out']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'low_at') ?></p><p class="doc-step-big"><?= $say('REPORTS', 'fig_level', $default) ?></p><p class="step-sub"><?= $say('REPORTS', 'default_level', $default) ?></p></div>
</section>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('REPORTS', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value=""<?php if ($f['show'] === 'low'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_low_n', $counts['low']) ?></option>
      <option value="out"<?php if ($f['show'] === 'out'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_none_n', $counts['out']) ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>

<?php if ($rows === []): ?>
<?php if ($f['show'] === 'out'): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_out'], \CW\Ui\Words::REPORTS['no_out_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_low'], \CW\Ui\Words::REPORTS['no_low_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap doc-board info">
<table class="stack list board doc-list report-low">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'product') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'store_units') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'low_at') ?></th>
<?php if ($canReorder): ?>
      <th scope="col"><?= $word('REPORTS', 'reorder') ?></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'store_units') ?>"><?= $n($r['available']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'low_at') ?>"><?= $n($r['low_at']) ?><?php if ($r['own']): ?> <span class="tag info"><?= $word('REPORTS', 'own_level') ?></span><?php endif; ?></td>
<?php if ($canReorder): ?>
      <td data-label="<?= $word('REPORTS', 'reorder') ?>"><a href="<?= $u('/ui/purchasing/reorder/items/' . $r['sku_id']) ?>"><?= $word('REPORTS', 'reorder') ?></a></td>
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
