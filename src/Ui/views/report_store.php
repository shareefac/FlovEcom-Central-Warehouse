<?= $head(\CW\Ui\Words::MENU['stock_by_store'], intro: 'stock_by_store') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="store" value="<?= $e($f['store']) ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('REPORTS', 'show') ?>
        <select name="show">
          <option value="all"<?php if ($f['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_all') ?></option>
          <option value="in"<?php if ($f['show'] === 'in'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_in') ?></option>
          <option value="out"<?php if ($f['show'] === 'out'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'show_out') ?></option>
        </select>
      </label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('REPORTS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
<?php if ($store !== null): ?>
  <a class="btn ghost sm" href="<?= $e($csv_link) ?>"><?= $word('REPORTS', 'csv') ?></a>
<?php endif; ?>
</form>

<?php if ($stores === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_stores']) ?>
<?php else: ?>
<section class="home-tiles" aria-label="<?= $word('REPORTS', 'store') ?>">
  <div class="kpis-wrap"><ul class="kpis four">
<?php foreach ($stores as $s): ?>
    <li class="kpi<?php if ($store !== null && $s['id'] === $store['id']): ?> current<?php endif; ?>">
      <p class="kpi-label"><a href="<?= $u('/ui/reports/stock-by-store', ['store' => $s['id']]) ?>"><?= $e($s['name']) ?></a></p>
<?php if ($s['warehouse'] === null): ?>
      <p class="kpi-sub"><?= $word('REPORTS', 'no_warehouse') ?></p>
<?php else: ?>
      <p class="kpi-value"><?= $n($s['figures']['units']) ?></p>
      <p class="kpi-sub"><?= $word('REPORTS', 'can_sell') ?> · <?= $say('REPORTS', 'sells_from', $s['warehouse']) ?></p>
      <p class="kpi-sub"><?= $word('REPORTS', 'matched') ?>: <?= $n($s['figures']['matched']) ?> · <?= $word('REPORTS', 'in_stock') ?>: <?= $n($s['figures']['in']) ?> · <?= $word('REPORTS', 'out_of_stock') ?>: <?= $n($s['figures']['out']) ?></p>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul></div>
</section>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::REPORTS['none_filtered'], \CW\Ui\Words::REPORTS['none_filtered_text'], $clear_link, \CW\Ui\Words::REPORTS['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_matched'], \CW\Ui\Words::REPORTS['no_matched_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $e($store['name']) ?></h2><p class="board-note"><?php if ($pages > 1): ?><?= $say('REPORTS', 'page', $page_no, $pages) ?><?php endif; ?></p></div>
<div class="table-wrap">
<table class="stack list report-store">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'product') ?></th>
      <th scope="col" class="c-status"><?= $word('REPORTS', 'status') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'store_units') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?><?php if ($r['pack'] > 1): ?> · <?= $say('REPORTS', 'pack', $r['pack']) ?><?php endif; ?></span></th>
      <td class="c-status"><?php if ($r['policy'] === 'stopped'): ?><?= $chip('off', \CW\Ui\Words::REPORTS['stopped']) ?><?php elseif ($r['available'] > 0): ?><?= $chip('done', \CW\Ui\Words::REPORTS['in_stock']) ?><?php else: ?><?= $chip('blocked', \CW\Ui\Words::REPORTS['out_of_stock']) ?><?php endif; ?><?php if ($r['policy'] === 'legacy'): ?> <small class="muted"><?= $word('REPORTS', 'own_stock') ?></small><?php endif; ?></td>
      <td class="num c-key" data-label="<?= $word('REPORTS', 'store_units') ?>"><?= $n($r['available']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($prev_link !== null || $next_link !== null): ?>
<p class="pager"><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('REPORTS', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('REPORTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
