<?php
// Reports › Inventory aging (Slow stock; the approved Reports artboard of 30 Oct 2026, the standard report layout; docs/decisions.md RD-C11;
// before it W13.6): "‹ All reports" and the title, Export (the CSV) at the right, what a sale is under its "?"; the options CW has (find an
// item, slow or dead with their counts) and Run report; then the report: its centred head (the company, the report's name, the day, the slow
// and dead days and how many each holds, when the sales were last brought up to date), a plain table, the longest without a sale first (its
// value for whoever may see money), and its Total row (how many items); "All reports" under it.
$bar = $partial('report_bar', ['href' => $csv_link]);
$line = implode(' · ', [\CW\Ui\Words::say('RD_REPORTS', 'as_of', \CW\Ui\Html::day($today)), $f['show'] === 'dead' ? \CW\Ui\Words::REPORTS['show_dead'] : \CW\Ui\Words::REPORTS['show_slow']]);
$more = [\CW\Ui\Words::say('RD_REPORTS', 'slow_line', \CW\Ui\Html::int($counts['slow']), $slowDays, \CW\Ui\Html::int($counts['dead']), $deadDays), $asOf];
?>
<div class="doc-head rp-head">
<?= $head(\CW\Ui\Words::RD_REPORTS['slow_stock'], intro: 'slow_stock', more: \CW\Ui\Words::REPORTS['sale_means'], side: $bar, back: ['#all-reports', \CW\Ui\Words::RD_REPORTS['all']]) ?>
</div>

<form class="card rp-options" method="get" aria-label="<?= $word('RD_REPORTS', 'options') ?>">
  <div class="rp-opt rp-find"><label for="rp-q"><?= $word('RD_REPORTS', 'find') ?></label><input id="rp-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find') ?>"></div>
  <div class="rp-opt"><label for="rp-show"><?= $word('RD_REPORTS', 'show') ?></label>
    <select id="rp-show" name="show">
      <option value=""<?php if ($f['show'] === 'slow'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_slow_n', $counts['slow']) ?></option>
      <option value="dead"<?php if ($f['show'] === 'dead'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_dead_n', $counts['dead']) ?></option>
    </select>
  </div>
  <button type="submit" class="btn primary"><?= $word('RD_REPORTS', 'run') ?></button>
</form>

<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_slow'], \CW\Ui\Words::REPORTS['no_slow_text']) ?>
<?php else: ?>
<article class="card rp-paper" aria-label="<?= $word('RD_REPORTS', 'paper') ?>">
<?= $partial('report_paper_head', ['company' => $company, 'name' => \CW\Ui\Words::RD_REPORTS['slow_stock'], 'line' => $line, 'more' => $more]) ?>
  <div class="table-wrap">
  <table class="stack rp-table report-slow">
    <thead>
      <tr>
        <th scope="col"><?= $word('REPORTS', 'item') ?></th>
        <th scope="col"><?= $word('RD_REPORTS', 'cw_number') ?></th>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'on_hand') ?></th>
        <th scope="col"><?= $word('REPORTS', 'last_sale') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'days_since') ?></th>
<?php if ($showValue): ?>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'value') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a></th>
        <td class="muted" data-label="<?= $word('RD_REPORTS', 'cw_number') ?>"><?= $e($r['code']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'on_hand') ?>"><?= $n($r['units']) ?></td>
        <td data-label="<?= $word('REPORTS', 'last_sale') ?>"><?php if ($r['last_sale'] === null): ?><span class="muted"><?= $word('REPORTS', 'never_sold') ?></span><?php if ($r['since'] !== null): ?> · <?= $word('REPORTS', 'in_stock_since') ?> <?= $day($r['since']) ?><?php endif; ?><?php else: ?><?= $day($r['last_sale']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'days_since') ?>"><?= $n($r['days']) ?></td>
<?php if ($showValue): ?>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'value') ?>"><?= $money($r['value']) ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
      <tr class="rp-total">
        <th scope="row" colspan="2"><?= $word('RD_REPORTS', 'total') ?></th>
        <td data-label="" class="num" colspan="<?php if ($showValue): ?>4<?php else: ?>3<?php endif; ?>"><?= $say('RD_REPORTS', 'items_n', $total) ?></td>
      </tr>
    </tbody>
  </table>
  </div>
</article>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('REPORTS', 'page', $page_no, $pages) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('REPORTS', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('REPORTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?= $partial('report_all', ['groups' => $allReports]) ?>
