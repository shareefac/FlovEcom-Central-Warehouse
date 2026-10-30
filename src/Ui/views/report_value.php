<?php
// Reports › Inventory valuation summary (the approved Reports artboard of 30 Oct 2026, the standard report layout; docs/decisions.md RD-C11;
// before it W13.3): "‹ All reports" and the title, Export (the CSV) at the right, what the value leaves out under its "?"; the options CW has
// (find a brand or an item, group by item, brand or warehouse, only the items without a cost) and Run report; then the report: its centred
// head (the company, the report's name, the day, how it is grouped, at average cost, when the costs were last brought up to date), a plain
// table (by item, paged; by brand or by warehouse each with its share of our value) and its Total row; "All reports" under it.
$help = \CW\Ui\Words::REPORTS['cost_note'] . ' ' . \CW\Ui\Words::REPORTS['below_zero'] . ' ' . \CW\Ui\Words::REPORTS['past_date'];
$bar = $partial('report_bar', ['href' => $csv_link]);
$line = implode(' · ', [\CW\Ui\Words::say('RD_REPORTS', 'as_of', \CW\Ui\Html::day($today)), \CW\Ui\Words::say('RD_REPORTS', 'grouped_by', mb_strtolower(\CW\Ui\Words::REPORTS['group_' . $f['by']])),
    \CW\Ui\Words::RD_REPORTS['at_cost']]);
$more = array_values(array_filter([
    implode(' · ', [\CW\Ui\Words::say('RD_REPORTS', 'items_n', \CW\Ui\Html::int($figures['items'])), \CW\Ui\Words::say('RD_REPORTS', 'not_sold', \CW\Ui\Html::money($notSold), $slowDays)]),
    $figures['not_costed'] > 0 ? \CW\Ui\Words::say('RD_REPORTS', 'no_cost', \CW\Ui\Html::int($figures['not_costed'])) : null,
    $figures['theirs'] > 0 ? \CW\Ui\Words::say('RD_REPORTS', 'theirs', \CW\Ui\Html::int($figures['theirs'])) : null,
    $asOf], static fn (?string $v): bool => $v !== null && $v !== ''));
?>
<div class="doc-head rp-head">
<?= $head(\CW\Ui\Words::RD_REPORTS['stock_value'], intro: 'stock_value', more: $help, side: $bar, back: ['#all-reports', \CW\Ui\Words::RD_REPORTS['all']]) ?>
</div>

<form class="card rp-options" method="get" aria-label="<?= $word('RD_REPORTS', 'options') ?>">
  <div class="rp-opt rp-find"><label for="rp-q"><?= $word('RD_REPORTS', 'find') ?></label><input id="rp-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find_value') ?>"></div>
  <div class="rp-opt"><label for="rp-by"><?= $word('REPORTS', 'group_by') ?></label>
    <select id="rp-by" name="by">
<?php foreach ($groupings as $by): ?>
      <option value="<?php if ($by !== 'product'): ?><?= $e($by) ?><?php endif; ?>"<?php if ($f['by'] === $by): ?> selected<?php endif; ?>><?= $word('REPORTS', 'group_' . $by) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <label class="rp-check"><input type="checkbox" name="nocost" value="1"<?php if ($f['nocost'] && $f['by'] === 'product'): ?> checked<?php endif; ?>> <?= $word('RD_REPORTS', 'only_no_cost') ?></label>
  <button type="submit" class="btn primary"><?= $word('RD_REPORTS', 'run') ?></button>
<?php if ($filtered): ?>
  <a class="rp-clear" href="<?= $e($all_link) ?>"><?= $word('RD_REPORTS', 'clear') ?></a>
<?php endif; ?>
</form>

<?php $none = $f['by'] === 'product' ? $rows === [] : ($f['by'] === 'brand' ? $brands === [] : $warehouses === []); ?>
<?php if ($none): ?>
<p class="note rp-asof" role="status"><?= $e($asOf) ?></p>
<?php endif; ?>
<?php if ($none && $filtered): ?>
<?= $empty(\CW\Ui\Words::REPORTS['none_filtered'], \CW\Ui\Words::REPORTS['none_filtered_text'], $all_link, \CW\Ui\Words::RD_REPORTS['clear']) ?>
<?php elseif ($none): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_value'], \CW\Ui\Words::REPORTS['no_value_text']) ?>
<?php else: ?>
<article class="card rp-paper" aria-label="<?= $word('RD_REPORTS', 'paper') ?>">
<?= $partial('report_paper_head', ['company' => $company, 'name' => \CW\Ui\Words::RD_REPORTS['stock_value'], 'line' => $line, 'more' => $more]) ?>
  <div class="table-wrap">
<?php if ($f['by'] === 'product'): ?>
  <table class="stack rp-table report-value">
    <thead>
      <tr>
        <th scope="col"><?= $word('REPORTS', 'item') ?></th>
        <th scope="col"><?= $word('RD_REPORTS', 'cw_number') ?></th>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'on_hand') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'avg_cost') ?></th>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'value') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a></th>
        <td class="muted" data-label="<?= $word('RD_REPORTS', 'cw_number') ?>"><?= $e($r['code']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'on_hand') ?>"><?= $n($r['units']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'avg_cost') ?>"><?php if ($r['avg'] === null): ?><span class="muted"><?= $word('REPORTS', 'no_cost') ?></span><?php else: ?><?= $money($r['avg']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'value') ?>"><?php if ($r['value'] !== null): ?><?= $money($r['value']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
<?php if (!$filtered): ?>
      <tr class="rp-total">
        <th scope="row" colspan="2"><?= $word('RD_REPORTS', 'total') ?></th>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'on_hand') ?>"><?= $n($figures['costed'] + $figures['not_costed']) ?></td>
        <td data-label="<?= $word('REPORTS', 'avg_cost') ?>"></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'value') ?>"><?= $money($figures['value']) ?></td>
      </tr>
<?php endif; ?>
    </tbody>
  </table>
<?php elseif ($f['by'] === 'brand'): ?>
  <table class="stack rp-table report-brands">
    <thead>
      <tr>
        <th scope="col"><?= $word('REPORTS', 'brand') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'items') ?></th>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'on_hand') ?></th>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'value') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'share') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($brands as $b): ?>
      <tr>
        <th scope="row"><?php if ($b['brand'] === null): ?><?= $word('REPORTS', 'no_brand') ?><?php else: ?><?= $e($b['brand']) ?><?php endif; ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'items') ?>"><?= $n($b['products']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'on_hand') ?>"><?= $n($b['units']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'value') ?>"><?= $money($b['value']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'share') ?>"><?php if ($b['share'] !== null): ?><?= $n($b['share']) ?>%<?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="rp-total">
        <th scope="row"><?= $word('RD_REPORTS', 'total') ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'items') ?>"><?= $n($sum['products']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'on_hand') ?>"><?= $n($sum['units']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'value') ?>"><?= $money($sum['value']) ?></td>
        <td data-label="<?= $word('REPORTS', 'share') ?>"></td>
      </tr>
    </tbody>
  </table>
<?php else: ?>
  <table class="stack rp-table report-warehouses">
    <thead>
      <tr>
        <th scope="col"><?= $word('REPORTS', 'warehouse') ?></th>
        <th scope="col"><?= $word('REPORTS', 'whose') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'items') ?></th>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'on_hand') ?></th>
        <th scope="col" class="num"><?= $word('RD_REPORTS', 'value') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'share') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($warehouses as $w): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>"><?= $e($w['name']) ?></a></th>
        <td data-label="<?= $word('REPORTS', 'whose') ?>"><?php if ($w['ours']): ?><?= $word('REPORTS', 'ours') ?><?php else: ?><?= $word('REPORTS', 'not_ours') ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'items') ?>"><?= $n($w['products']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'on_hand') ?>"><?= $n($w['units']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'value') ?>"><?= $money($w['value']) ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'share') ?>"><?php if ($w['share'] !== null): ?><?= $n($w['share']) ?>%<?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="rp-total">
        <th scope="row" colspan="2"><?= $word('REPORTS', 'ours_total') ?></th>
        <td data-label="<?= $word('REPORTS', 'items') ?>"></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'on_hand') ?>"><?= $n($sum['units']) ?></td>
        <td class="num" data-label="<?= $word('RD_REPORTS', 'value') ?>"><?= $money($sum['value']) ?></td>
        <td data-label="<?= $word('REPORTS', 'share') ?>"></td>
      </tr>
    </tbody>
  </table>
<?php endif; ?>
  </div>
</article>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('REPORTS', 'page', $page_no, $pages) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('REPORTS', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('REPORTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?= $partial('report_all', ['groups' => $allReports]) ?>
