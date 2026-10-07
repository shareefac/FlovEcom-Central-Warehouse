<div class="head-help">
  <h1><?= $word('MENU', 'bench') ?></h1>
  <?= $explain('duty_stamp', \CW\Ui\Words::BENCH['stamp_label']) ?>
</div>
<?= $intro('bench') ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::BENCH['none'], \CW\Ui\Words::BENCH['none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list bench-list">
  <thead>
    <tr>
      <th scope="col"><?= $word('BENCH', 'delivery') ?></th>
      <th scope="col"><?= $word('BENCH', 'status') ?></th>
      <th scope="col"><?= $word('BENCH', 'arrived') ?></th>
      <th scope="col"><?= $word('BENCH', 'size') ?></th>
      <th scope="col"><?= $word('BENCH', 'progress') ?></th>
      <th scope="col"><?= $word('BENCH', 'keyed_by') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $word('BENCH', 'open') ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('BENCH_STATE', $r['bench_state'])) ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/receiving/' . $r['id'] . '/bench') ?>"><?= $e($r['supplier_name']) ?></a><span class="o-no"><?php if ($r['external_ref'] !== null): ?><?= $say('BENCH', 'invoice', (string) $r['external_ref']) ?><?php else: ?><?= $word('BENCH', 'no_invoice') ?><?php endif; ?><?php if ($r['po_number'] !== null): ?> · <?= $say('BENCH', 'order', (string) $r['po_number']) ?><?php endif; ?></span></th>
      <td class="c-status"><?= $stateChip('BENCH_STATE', $r['bench_state']) ?></td>
      <td data-label="<?= $word('BENCH', 'arrived') ?>"><?= $when($r['received_at']) ?></td>
      <td data-label="<?= $word('BENCH', 'size') ?>"><?= $e($r['size_line']) ?></td>
      <td data-label="<?= $word('BENCH', 'progress') ?>"><?= $say('BENCH', 'lines_checked', (int) $r['checked_lines'], (int) $r['lines']) ?><?php if ($r['checked_at'] !== null): ?><span class="o-sub"><?php if ((int) $r['paperwork_ok'] === 1): ?><?= $say('BENCH', 'paperwork_ok', (string) $r['checked_by_name']) ?><?php else: ?><?= $say('BENCH', 'paperwork_not_ok', (string) $r['checked_by_name']) ?><?php endif; ?></span><?php endif; ?></td>
      <td data-label="<?= $word('BENCH', 'keyed_by') ?>"><?= $e($r['created_by_name']) ?></td>
      <td class="c-next"><a class="btn primary" href="<?= $u('/ui/receiving/' . $r['id'] . '/bench') ?>"><?= $word('BENCH', 'open') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
