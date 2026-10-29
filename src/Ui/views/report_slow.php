<?= $head(\CW\Ui\Words::MENU['slow_stock'], intro: 'slow_stock') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="show" value="<?= $e($f['show']) ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <button type="submit" class="btn ghost sm"><?= $word('UI', 'apply') ?></button>
  <a class="btn ghost sm" href="<?= $e($csv_link) ?>"><?= $word('REPORTS', 'csv') ?></a>
</form>
<p class="note"><?= $say('REPORTS', 'slow_says', $slowDays, $deadDays) ?> <?= $word('REPORTS', 'sale_means') ?> <?= $e($asOf) ?></p>
<nav class="tabs show-tabs" aria-label="<?= $word('REPORTS', 'show') ?>">
  <a class="tab" href="<?= $u('/ui/reports/slow-stock', ['q' => $f['q']]) ?>"<?php if ($f['show'] === 'slow'): ?> aria-current="page"<?php endif; ?>><span><?= $word('REPORTS', 'show_slow') ?></span><span class="count"><?= $n($counts['slow']) ?></span></a>
  <a class="tab" href="<?= $u('/ui/reports/slow-stock', ['q' => $f['q'], 'show' => 'dead']) ?>"<?php if ($f['show'] === 'dead'): ?> aria-current="page"<?php endif; ?>><span><?= $word('REPORTS', 'show_dead') ?></span><span class="count"><?= $n($counts['dead']) ?></span></a>
</nav>

<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_slow'], \CW\Ui\Words::REPORTS['no_slow_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list report-slow">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('REPORTS', 'product') ?></th>
      <th scope="col" class="num"><?= $word('REPORTS', 'units') ?></th>
      <th scope="col"><?= $word('REPORTS', 'last_sale') ?></th>
      <th scope="col" class="num c-key"><?= $word('REPORTS', 'days_since') ?></th>
<?php if ($showValue): ?>
      <th scope="col" class="num"><?= $word('REPORTS', 'value') ?></th>
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
      <td class="num" data-label="<?= $word('REPORTS', 'value') ?>"><?= $money($r['value']) ?></td>
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
