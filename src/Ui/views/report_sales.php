<?php
// Reports › Sales by Store (pack E, docs/decisions.md PE1), a report (the approved design of 29 Oct 2026, pattern 5, TP_Report;
// docs/decisions.md W10): read only. One Show row (the days, or days of one's own, and the store), the file at its right; the days and when
// the figures were brought up to date; the strip of the days' totals; then the two boards: each store with the total under it, and day by
// day. What a unit is: the page's "?".
?>
<?= $head(\CW\Ui\Words::MENU['sales_by_store'], intro: 'sales_by_store', aside: $explain('sales_by_store', \CW\Ui\Words::MENU['sales_by_store'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-period"><?= $word('REPORTS', 'period') ?></label>
    <select id="tb-period" name="period">
<?php foreach ($periods as $p): ?>
      <option value="<?= $e($p) ?>"<?php if ($period === $p): ?> selected<?php endif; ?>><?= $word('REPORTS', $p) ?></option>
<?php endforeach; ?>
      <option value="choose"<?php if ($period === 'choose'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'choose') ?></option>
    </select>
    <label class="tb-day"><?= $word('REPORTS', 'from') ?> <input type="date" name="from" value="<?= $e($from) ?>"></label>
    <label class="tb-day"><?= $word('REPORTS', 'to') ?> <input type="date" name="to" value="<?= $e($to) ?>"></label>
    <label class="visually-hidden" for="tb-store"><?= $word('REPORTS', 'store') ?></label>
    <select id="tb-store" name="store" title="<?= $word('REPORTS', 'store') ?>">
      <option value=""><?= $word('REPORTS', 'all_stores') ?></option>
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($storeId === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('REPORTS', 'apply') ?></button>
  </div>
  <div class="tb-end">
    <a class="btn ghost sm" href="<?= $e($csv_link) ?>" download><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('REPORTS', 'csv') ?></span></a>
  </div>
</form>
<p class="muted"><?= $say('REPORTS', 'period_says', $day($from), $day($to)) ?> <?= $e($asOf) ?></p>
<?php if ($warn !== null): ?>
<p class="note"><?= $e($warn) ?></p>
<?php endif; ?>

<?php if (!$any): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_sales'], \CW\Ui\Words::REPORTS['no_sales_text']) ?>
<?php else: ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('REPORTS', 'sales_figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'orders') ?></p><p class="doc-step-big"><?= $n($total['orders']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'units_sold') ?></p><p class="doc-step-big"><?= $n($total['units_sold']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'units_sent') ?></p><p class="doc-step-big"><?= $n($total['units_sent']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'units_cancelled') ?></p><p class="doc-step-big"><?= $n($total['units_cancelled']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REPORTS', 'units_returned') ?></p><p class="doc-step-big"><?= $n($total['units_returned']) ?></p></div>
</section>

<section class="doc-related" aria-labelledby="by-store-h">
  <div class="board-head"><h2 id="by-store-h" class="board-sub"><?= $word('REPORTS', 'by_store') ?></h2></div>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list report-sales">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('REPORTS', 'store') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'orders') ?></th>
        <th scope="col" class="num c-key"><?= $word('REPORTS', 'units_sold') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_sent') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_cancelled') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_returned') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($byStore as $s): ?>
      <tr>
        <th scope="row" class="c-head c-item"><?= $e($s['name']) ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($s['orders']) ?></td>
        <td class="num c-key" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($s['units_sold']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($s['units_sent']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($s['units_cancelled']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($s['units_returned']) ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="grp-foot">
        <th scope="row" class="c-head c-item"><?= $word('REPORTS', 'total') ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($total['orders']) ?></td>
        <td class="num c-key" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($total['units_sold']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($total['units_sent']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($total['units_cancelled']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($total['units_returned']) ?></td>
      </tr>
    </tbody>
  </table>
  </div>
</section>

<section class="doc-related" aria-labelledby="by-day-h">
  <div class="board-head"><h2 id="by-day-h" class="board-sub"><?= $word('REPORTS', 'by_day') ?></h2></div>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list report-days">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('REPORTS', 'day') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'orders') ?></th>
        <th scope="col" class="num c-key"><?= $word('REPORTS', 'units_sold') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_sent') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_cancelled') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_returned') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($days as $d): ?>
      <tr>
        <th scope="row" class="c-head c-item"><?= $day($d['day']) ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($d['orders']) ?></td>
        <td class="num c-key" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($d['units_sold']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($d['units_sent']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($d['units_cancelled']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($d['units_returned']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>
