<?php
// Reports › Stock summary by store (the approved Reports artboard of 30 Oct 2026, the standard report layout; docs/decisions.md RD-C11; before
// it W13.4): "‹ All reports" and the title, Export (the store's CSV) at the right; the options CW has (find an item, the store, all / in stock /
// out of stock) and Run report; then the report: its centred head (the company, the report's name, the store and the warehouse it sells from,
// its figures), a plain table of the store's items with their status pill and the units it can sell, and its Total row; "All reports" under it.
$fig = null;
foreach ($stores as $s) {
    if ($store !== null && $s['id'] === $store['id']) {
        $fig = $s;
    }
}
$bar = $store !== null ? $partial('report_bar', ['href' => $csv_link]) : '';
$line = $fig === null ? '' : implode(' · ', array_filter([\CW\Ui\Words::say('RD_REPORTS', 'as_of', \CW\Ui\Html::day($today)), (string) $fig['name'],
    $fig['warehouse'] === null ? \CW\Ui\Words::REPORTS['no_warehouse'] : \CW\Ui\Words::say('REPORTS', 'sells_from', (string) $fig['warehouse']),
    \CW\Ui\Words::REPORTS['show_' . $f['show']]], static fn (string $v): bool => $v !== ''));
$more = $fig === null || $fig['warehouse'] === null ? [] : [\CW\Ui\Words::say('RD_REPORTS', 'store_line', \CW\Ui\Html::int($fig['figures']['matched']),
    \CW\Ui\Html::int($fig['figures']['in']), \CW\Ui\Html::int($fig['figures']['out']))];
?>
<div class="doc-head rp-head">
<?= $head(\CW\Ui\Words::RD_REPORTS['stock_by_store'], intro: 'stock_by_store', side: $bar, back: ['#all-reports', \CW\Ui\Words::RD_REPORTS['all']]) ?>
</div>
<?php if ($stores === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_stores']) ?>
<?php else: ?>
<form class="card rp-options" method="get" aria-label="<?= $word('RD_REPORTS', 'options') ?>">
  <div class="rp-opt rp-find"><label for="rp-q"><?= $word('RD_REPORTS', 'find') ?></label><input id="rp-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPORTS', 'find') ?>"></div>
  <div class="rp-opt"><label for="rp-store"><?= $word('REPORTS', 'store') ?></label>
    <select id="rp-store" name="store">
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($store !== null && $s['id'] === $store['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="rp-opt"><label for="rp-show"><?= $word('RD_REPORTS', 'show') ?></label>
    <select id="rp-show" name="show">
      <option value="all"<?php if ($f['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_all') ?></option>
      <option value="in"<?php if ($f['show'] === 'in'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_in') ?></option>
      <option value="out"<?php if ($f['show'] === 'out'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_out') ?></option>
    </select>
  </div>
  <button type="submit" class="btn primary"><?= $word('RD_REPORTS', 'run') ?></button>
<?php if ($filtered): ?>
  <a class="rp-clear" href="<?= $e($clear_link) ?>"><?= $word('RD_REPORTS', 'clear') ?></a>
<?php endif; ?>
</form>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::REPORTS['none_filtered'], \CW\Ui\Words::REPORTS['none_filtered_text'], $clear_link, \CW\Ui\Words::RD_REPORTS['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_matched'], \CW\Ui\Words::REPORTS['no_matched_text']) ?>
<?php endif; ?>
<?php else: ?>
<article class="card rp-paper" aria-label="<?= $word('RD_REPORTS', 'paper') ?>">
<?= $partial('report_paper_head', ['company' => $company, 'name' => \CW\Ui\Words::RD_REPORTS['stock_by_store'], 'line' => $line, 'more' => $more]) ?>
  <div class="table-wrap">
  <table class="stack rp-table report-store">
    <thead>
      <tr>
        <th scope="col"><?= $word('REPORTS', 'item') ?></th>
        <th scope="col"><?= $word('RD_REPORTS', 'cw_number') ?></th>
        <th scope="col"><?= $word('REPORTS', 'status') ?></th>
        <th scope="col" class="num"><?= $word('REPORTS', 'store_units') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
<?php $tone = $r['policy'] === 'stopped' ? 'off' : ($r['available'] > 0 ? 'done' : 'blocked'); ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><?php if ($r['pack'] > 1 || $r['policy'] === 'legacy'): ?><span class="o-sub"><?php if ($r['pack'] > 1): ?><?= $say('REPORTS', 'pack', $r['pack']) ?><?php endif; ?><?php if ($r['pack'] > 1 && $r['policy'] === 'legacy'): ?> · <?php endif; ?><?php if ($r['policy'] === 'legacy'): ?><?= $word('REPORTS', 'own_stock') ?><?php endif; ?></span><?php endif; ?></th>
        <td class="muted" data-label="<?= $word('RD_REPORTS', 'cw_number') ?>"><?= $e($r['code']) ?></td>
        <td data-label="<?= $word('REPORTS', 'status') ?>"><?php if ($r['policy'] === 'stopped'): ?><?= $chip($tone, \CW\Ui\Words::REPORTS['stopped']) ?><?php elseif ($r['available'] > 0): ?><?= $chip($tone, \CW\Ui\Words::REPORTS['in_stock']) ?><?php else: ?><?= $chip($tone, \CW\Ui\Words::REPORTS['out_of_stock']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('REPORTS', 'store_units') ?>"><?= $n($r['available']) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($fig !== null && $fig['warehouse'] !== null && !$filtered): ?>
      <tr class="rp-total">
        <th scope="row" colspan="3"><?= $word('RD_REPORTS', 'total') ?></th>
        <td class="num" data-label="<?= $word('REPORTS', 'store_units') ?>"><?= $n($fig['figures']['units']) ?></td>
      </tr>
<?php endif; ?>
    </tbody>
  </table>
  </div>
</article>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><span><?= $say('REPORTS', 'page', $page_no, $pages) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('REPORTS', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('REPORTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?= $partial('report_all', ['groups' => $allReports]) ?>
