<?php
// Reports › Sales by store (the approved Reports artboard of 30 Oct 2026, the standard report layout; docs/decisions.md RD-C11; before it W13.7):
// "‹ All reports" and the title, Export (the CSV) at the right, what a unit is under its "?"; the options CW has (the days, or days of one's
// own, and the store) and Run report; then the report: its centred head (the company, the report's name, the days and the store, when the
// figures were brought up to date), a plain table grouped by store and day by day, each group with its Total row; "All reports" under it.
$bar = $partial('report_bar', ['href' => $csv_link]);
$storeName = \CW\Ui\Words::REPORTS['all_stores'];
foreach ($stores as $s) {
    if ($storeId === $s['id']) {
        $storeName = (string) $s['name'];
    }
}
$line = implode(' · ', [\CW\Ui\Words::say('RD_REPORTS', 'period_line', \CW\Ui\Html::day($from), \CW\Ui\Html::day($to)), $storeName]);
?>
<div class="doc-head rp-head">
<?= $head(\CW\Ui\Words::RD_REPORTS['sales_by_store'], intro: 'sales_by_store', aside: $explain('sales_by_store', \CW\Ui\Words::RD_REPORTS['sales_by_store']), side: $bar, back: ['#all-reports', \CW\Ui\Words::RD_REPORTS['all']]) ?>
</div>
<form class="card rp-options" method="get" aria-label="<?= $word('RD_REPORTS', 'options') ?>">
  <div class="rp-opt"><label for="rp-period"><?= $word('REPORTS', 'period') ?></label>
    <select id="rp-period" name="period">
<?php foreach ($periods as $p): ?>
      <option value="<?= $e($p) ?>"<?php if ($period === $p): ?> selected<?php endif; ?>><?= $word('REPORTS', $p) ?></option>
<?php endforeach; ?>
      <option value="choose"<?php if ($period === 'choose'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'choose') ?></option>
    </select>
  </div>
  <div class="rp-opt"><label for="rp-from"><?= $word('REPORTS', 'from') ?></label><input id="rp-from" type="date" name="from" value="<?= $e($from) ?>"></div>
  <div class="rp-opt"><label for="rp-to"><?= $word('REPORTS', 'to') ?></label><input id="rp-to" type="date" name="to" value="<?= $e($to) ?>"></div>
  <div class="rp-opt"><label for="rp-store"><?= $word('REPORTS', 'store') ?></label>
    <select id="rp-store" name="store">
      <option value=""><?= $word('REPORTS', 'all_stores') ?></option>
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($storeId === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary"><?= $word('RD_REPORTS', 'run') ?></button>
</form>
<?php if ($warn !== null): ?>
<p class="note" role="status"><?= $e($warn) ?></p>
<?php endif; ?>

<?php if (!$any): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_sales'], \CW\Ui\Words::REPORTS['no_sales_text']) ?>
<?php else: ?>
<article class="card rp-paper" aria-label="<?= $word('RD_REPORTS', 'paper') ?>">
<?= $partial('report_paper_head', ['company' => $company, 'name' => \CW\Ui\Words::RD_REPORTS['sales_by_store'], 'line' => $line, 'more' => [$asOf]]) ?>
  <div class="table-wrap">
  <table class="stack rp-table report-sales">
    <thead>
      <tr>
        <th scope="col"><span class="visually-hidden"><?= $word('REPORTS', 'store') ?> / <?= $word('REPORTS', 'day') ?></span></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'orders') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_sold') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_sent') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_cancelled') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'units_returned') ?></th>
      </tr>
    </thead>
    <tbody>
      <tr class="rp-group"><th scope="rowgroup" colspan="6"><?= $word('REPORTS', 'by_store') ?></th></tr>
<?php foreach ($byStore as $s): ?>
      <tr>
        <th scope="row" class="rp-in"><?= $e($s['name']) ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($s['orders']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($s['units_sold']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($s['units_sent']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($s['units_cancelled']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($s['units_returned']) ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="rp-gtotal">
        <th scope="row" class="rp-in"><?= $word('REPORTS', 'total') ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($total['orders']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($total['units_sold']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($total['units_sent']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($total['units_cancelled']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($total['units_returned']) ?></td>
      </tr>
    </tbody>
    <tbody>
      <tr class="rp-group"><th scope="rowgroup" colspan="6"><?= $word('REPORTS', 'by_day') ?></th></tr>
<?php foreach ($days as $d): ?>
      <tr>
        <th scope="row" class="rp-in"><?= $day($d['day']) ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($d['orders']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($d['units_sold']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($d['units_sent']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($d['units_cancelled']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($d['units_returned']) ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="rp-total">
        <th scope="row"><?= $word('RD_REPORTS', 'total') ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($total['orders']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($total['units_sold']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($total['units_sent']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($total['units_cancelled']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($total['units_returned']) ?></td>
      </tr>
    </tbody>
  </table>
  </div>
</article>
<?php endif; ?>
<?= $partial('report_all', ['groups' => $allReports]) ?>
