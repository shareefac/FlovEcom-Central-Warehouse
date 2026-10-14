<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="show" value="<?= $e($f['show']) ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPORTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <button type="submit" class="btn ghost sm"><?= $word('UI', 'apply') ?></button>
  <a class="btn ghost sm" href="<?= $e($csv_link) ?>"><?= $word('REPORTS', 'csv') ?></a>
</form>
<h1><?= $word('MENU', 'low_stock') ?></h1>
<?= $intro('low_stock') ?>
<p class="note"><?= $say('REPORTS', 'default_level', $default) ?></p>
<nav class="seg" aria-label="<?= $word('REPORTS', 'show') ?>">
  <a class="seg-item" href="<?= $u('/ui/reports/low-stock', ['q' => $f['q']]) ?>"<?php if ($f['show'] === 'low'): ?> aria-current="page"<?php endif; ?>><span><?= $word('REPORTS', 'show_low') ?></span><span class="count"><?= $n($counts['low']) ?></span></a>
  <a class="seg-item" href="<?= $u('/ui/reports/low-stock', ['q' => $f['q'], 'show' => 'out']) ?>"<?php if ($f['show'] === 'out'): ?> aria-current="page"<?php endif; ?>><span><?= $word('REPORTS', 'show_none') ?></span><span class="count"><?= $n($counts['out']) ?></span></a>
</nav>

<?php if ($rows === []): ?>
<?php if ($f['show'] === 'out'): ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_out'], \CW\Ui\Words::REPORTS['no_out_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::REPORTS['no_low'], \CW\Ui\Words::REPORTS['no_low_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list report-low">
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
      <td class="num" data-label="<?= $word('REPORTS', 'low_at') ?>"><?= $n($r['low_at']) ?><?php if ($r['own']): ?> <small class="muted"><?= $word('REPORTS', 'own_level') ?></small><?php endif; ?></td>
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
