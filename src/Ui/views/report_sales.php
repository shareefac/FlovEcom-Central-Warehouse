<?= $head(\CW\Ui\Words::MENU['sales_by_store'], intro: 'sales_by_store') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <label><?= $word('REPORTS', 'period') ?>
    <select name="period">
<?php foreach ($periods as $p): ?>
      <option value="<?= $e($p) ?>"<?php if ($period === $p): ?> selected<?php endif; ?>><?= $word('REPORTS', $p) ?></option>
<?php endforeach; ?>
      <option value="choose"<?php if ($period === 'choose'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'choose') ?></option>
    </select>
  </label>
  <label><?= $word('REPORTS', 'from') ?> <input type="date" name="from" value="<?= $e($from) ?>"></label>
  <label><?= $word('REPORTS', 'to') ?> <input type="date" name="to" value="<?= $e($to) ?>"></label>
  <label><?= $word('REPORTS', 'store') ?>
    <select name="store">
      <option value=""><?= $word('REPORTS', 'all_stores') ?></option>
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($storeId === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="btn sm"><?= $word('REPORTS', 'apply') ?></button>
  <a class="btn ghost sm" href="<?= $e($csv_link) ?>"><?= $word('REPORTS', 'csv') ?></a>
</form>
<p class="note"><?= $say('REPORTS', 'period_says', $day($from), $day($to)) ?> <?= $e($asOf) ?></p>
<?php if ($warn !== null): ?>
<p class="note"><?= $e($warn) ?></p>
<?php endif; ?>

<?php if (!$any): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_sales'], \CW\Ui\Words::REPORTS['no_sales_text']) ?>
<?php else: ?>
<h2><?= $word('REPORTS', 'by_store') ?></h2>
<div class="table-wrap">
<table class="stack list report-sales">
  <thead>
    <tr>
      <th scope="col"><?= $word('REPORTS', 'store') ?></th>
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
      <th scope="row" class="c-head"><?= $e($s['name']) ?></th>
      <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($s['orders']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($s['units_sold']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($s['units_sent']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($s['units_cancelled']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($s['units_returned']) ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="total">
      <th scope="row" class="c-head"><?= $word('REPORTS', 'total') ?></th>
      <td class="num" data-label="<?= $word('REPORTS', 'orders') ?>"><?= $n($total['orders']) ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'units_sold') ?>"><?= $n($total['units_sold']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units_sent') ?>"><?= $n($total['units_sent']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units_cancelled') ?>"><?= $n($total['units_cancelled']) ?></td>
      <td class="num" data-label="<?= $word('REPORTS', 'units_returned') ?>"><?= $n($total['units_returned']) ?></td>
    </tr>
  </tbody>
</table>
</div>
<p class="muted"><?= $word('REPORTS', 'sales_note') ?></p>

<h2><?= $word('REPORTS', 'by_day') ?></h2>
<div class="table-wrap">
<table class="stack list report-days">
  <thead>
    <tr>
      <th scope="col"><?= $word('REPORTS', 'day') ?></th>
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
      <th scope="row" class="c-head"><?= $day($d['day']) ?></th>
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
<?php endif; ?>
