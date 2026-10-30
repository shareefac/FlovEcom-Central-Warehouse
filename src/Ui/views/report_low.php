<?php
// Reports › Low stock (the approved Reports artboard of 30 Oct 2026, the standard report layout; docs/decisions.md RD-C11; before it W13.5):
// "‹ All reports" and the title, Export (the CSV) at the right; the options CW has (find an item, low or none left with their counts) and Run
// report; then the report: its centred head (the company, the report's name, the day, how many are low and none left, the level an item is
// low at), a plain table, the fewest first (the units the stores can sell, the low level, the reorder page for the buyers), and its Total row
// (how many items); "All reports" under it.
$bar = $partial('report_bar', ['href' => $csv_link]);
$line = implode(' · ', [\CW\Ui\Words::say('RD_REPORTS', 'as_of', \CW\Ui\Html::day($today)), $f['show'] === 'out' ? \CW\Ui\Words::REPORTS['show_none'] : \CW\Ui\Words::REPORTS['show_low']]);
$more = [\CW\Ui\Words::say('RD_REPORTS', 'low_line', \CW\Ui\Html::int($counts['low']), \CW\Ui\Html::int($counts['out']), \CW\Ui\Html::int($default))];
?>
<div class="doc-head rp-head">
<?= $head(\CW\Ui\Words::RD_REPORTS['low_stock'], intro: 'low_stock', side: $bar, back: ['#all-reports', \CW\Ui\Words::RD_REPORTS['all']]) ?>
</div>

<form class="card rp-options" method="get" aria-label="<?= $word('RD_REPORTS', 'options') ?>">
  <div class="rp-opt rp-find"><label for="rp-q"><?= $word('RD_REPORTS', 'find') ?></label><input id="rp-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find') ?>"></div>
  <div class="rp-opt"><label for="rp-show"><?= $word('RD_REPORTS', 'show') ?></label>
    <select id="rp-show" name="show">
      <option value=""<?php if ($f['show'] === 'low'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_low_n', $counts['low']) ?></option>
      <option value="out"<?php if ($f['show'] === 'out'): ?> selected<?php endif; ?>><?= $say('REPORTS', 'show_none_n', $counts['out']) ?></option>
    </select>
  </div>
  <button type="submit" class="btn primary"><?= $word('RD_REPORTS', 'run') ?></button>
</form>

<?php if ($rows === []): ?>
<?php if ($f['show'] === 'out'): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_out'], \CW\Ui\Words::REPORTS['no_out_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_low'], \CW\Ui\Words::REPORTS['no_low_text']) ?>
<?php endif; ?>
<?php else: ?>
<article class="card rp-paper" aria-label="<?= $word('RD_REPORTS', 'paper') ?>">
<?= $partial('report_paper_head', ['company' => $company, 'name' => \CW\Ui\Words::RD_REPORTS['low_stock'], 'line' => $line, 'more' => $more]) ?>
  <div class="table-wrap">
  <table class="stack rp-table report-low">
    <thead>
      <tr>
        <th scope="col"><?= $word('REPORTS', 'item') ?></th>
        <th scope="col"><?= $word('RD_REPORTS', 'cw_number') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'store_units') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'low_at') ?></th>
<?php if ($canReorder): ?>
        <th scope="col"><span class="visually-hidden"><?= $word('REPORTS', 'reorder') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a></th>
        <td class="muted" data-label="<?= $word('RD_REPORTS', 'cw_number') ?>"><?= $e($r['code']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'store_units') ?>"><?= $n($r['available']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'low_at') ?>"><?= $n($r['low_at']) ?><?php if ($r['own']): ?> <span class="tag info"><?= $word('REPORTS', 'own_level') ?></span><?php endif; ?></td>
<?php if ($canReorder): ?>
        <td data-label="<?= $word('REPORTS', 'reorder') ?>"><a href="<?= $u('/ui/purchasing/reorder/items/' . $r['sku_id']) ?>"><?= $word('REPORTS', 'reorder') ?></a></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
      <tr class="rp-total">
        <th scope="row" colspan="2"><?= $word('RD_REPORTS', 'total') ?></th>
        <td data-label="" class="num" colspan="<?php if ($canReorder): ?>3<?php else: ?>2<?php endif; ?>"><?= $say('RD_REPORTS', 'items_n', $total) ?></td>
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
