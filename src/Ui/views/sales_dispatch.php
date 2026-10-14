<?php
// Stock › Sales › Dispatch (pack D, docs/decisions.md PD6): read only. The store selector (with each store's paid orders waiting to
// ship), five figures, the switch between the four lists, then the list chosen. No form here posts anything.
?>
<div class="head-help">
  <h1><?= $word('MENU', 'sales_dispatch') ?></h1>
  <?= $explain('sales_dispatch', \CW\Ui\Words::MENU['sales_dispatch']) ?>
</div>
<?= $intro('sales_dispatch') ?>
<?= $partial('store_seg', ['items' => $storeItems, 'countWords' => \CW\Ui\Words::DISPATCH['store_count']]) ?>

<section class="home-tiles" aria-label="<?= $word('DISPATCH', 'figures') ?>">
  <div class="kpis-wrap"><ul class="kpis">
    <li class="kpi"><p class="kpi-label"><?= $word('DISPATCH', 'tile_waiting') ?></p><p class="kpi-value"><?= $n($figures['waiting']) ?></p><p class="kpi-sub"><?= $say('DISPATCH', 'tile_waiting_sub', $figures['waiting_units']) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('DISPATCH', 'tile_late') ?></p><p class="kpi-value"><?= $n($figures['late']) ?></p><p class="kpi-sub"><?= $say('DISPATCH', 'tile_late_sub', $lateHours) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('DISPATCH', 'tile_today') ?></p><p class="kpi-value"><?= $n($figures['today']) ?></p><p class="kpi-sub"><?= $say('DISPATCH', 'tile_today_sub', $figures['today_units']) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('DISPATCH', 'tile_week') ?></p><p class="kpi-value"><?= $n($figures['week']) ?></p><p class="kpi-sub"><?= $say('DISPATCH', 'tile_week_sub', $figures['week_units']) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('DISPATCH', 'tile_undone') ?></p><p class="kpi-value"><?= $n($figures['undone']) ?></p><p class="kpi-sub"><?= $word('DISPATCH', 'tile_undone_sub') ?></p></li>
  </ul></div>
</section>
<?php if ($lateCapped !== null): ?>
<p class="note"><?= $e($lateCapped) ?></p>
<?php endif; ?>

<nav class="pills dispatch-pills" aria-label="<?= $word('DISPATCH', 'show') ?>">
<?php foreach ($shows as $s): ?>
  <a href="<?= $e($s['href']) ?>"<?php if ($s['current']): ?> aria-current="page"<?php endif; ?>><?= $e($s['label']) ?></a>
<?php endforeach; ?>
</nav>
<?php if ($extra !== null): ?>
<p class="muted"><?= $e($extra) ?></p>
<?php endif; ?>

<?php if ($rows === []): ?>
<?= $empty($none[0], $none[1]) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?php foreach ($shows as $s): ?><?php if ($s['current']): ?><?= $e($s['label']) ?><?php endif; ?><?php endforeach; ?></h2><p class="board-note"><?= $e($note) ?></p></div>
<div class="table-wrap">
<?php if ($show === 'waiting'): ?>
<table class="stack list board dispatch-waiting">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('DISPATCH', 'order') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'store') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'paid') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'waiting') ?></th>
      <th scope="col" class="c-status"><?= $word('DISPATCH', 'status') ?></th>
      <th scope="col" class="num"><?= $word('DISPATCH', 'units') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'note') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a></th>
      <td data-label="<?= $word('DISPATCH', 'store') ?>"><?= $e($r['store']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'paid') ?>"><?= $when($r['paid']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'waiting') ?>"><?= $e($r['waited']) ?></td>
      <td class="c-status"><?= $chip($r['tone'], $r['chipWord']) ?></td>
      <td class="num" data-label="<?= $word('DISPATCH', 'units') ?>"><?= $n($r['units']) ?></td>
      <td class="c-wide" data-label="<?= $word('DISPATCH', 'note') ?>"><?= $e($r['note']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php elseif ($show === 'undone'): ?>
<table class="stack list board dispatch-undone">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('DISPATCH', 'order') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'store') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'unit') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'undone_at') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'who') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'now') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><?php if ($r['href'] !== null): ?><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><?php else: ?><?= $e($r['ref']) ?><?php endif; ?></th>
      <td data-label="<?= $word('DISPATCH', 'store') ?>"><?= $e($r['store']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'unit') ?>"><?= $e($r['unit']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'undone_at') ?>"><?= $when($r['at']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'who') ?>"><?= $e($r['who']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'now') ?>"><?= $e($r['now']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<table class="stack list board dispatch-sent">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('DISPATCH', 'order') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'store') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'sent') ?></th>
      <th scope="col" class="num"><?= $word('DISPATCH', 'units') ?></th>
      <th scope="col"><?= $word('DISPATCH', 'now') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a></th>
      <td data-label="<?= $word('DISPATCH', 'store') ?>"><?= $e($r['store']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'sent') ?>"><?= $when($r['sent']) ?></td>
      <td class="num" data-label="<?= $word('DISPATCH', 'units') ?>"><?= $n($r['units']) ?></td>
      <td data-label="<?= $word('DISPATCH', 'now') ?>"><?= $e($r['now']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<p class="foot"><?= $word('DISPATCH', 'foot') ?></p>
<?php endif; ?>
<?php if ($next_link !== null || $start_link !== null): ?>
<p class="pager"><?php if ($start_link !== null): ?><a href="<?= $e($start_link) ?>"><?= $word('DISPATCH', 'back') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $e($next_word) ?></a><?php endif; ?></p>
<?php endif; ?>
