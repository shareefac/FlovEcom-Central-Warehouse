<h1><?= $word('SEGMENT', 'timed') ?></h1>
<?= $intro('timed') ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ARRIVALS['timed_none'], \CW\Ui\Words::ARRIVALS['timed_none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list board">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('ARRIVALS', 'invoice') ?></th>
      <th scope="col"><?= $word('ARRIVALS', 'supplier') ?></th>
      <th scope="col"><?= $word('ARRIVALS', 'timed_at') ?></th>
      <th scope="col"><?= $word('ARRIVALS', 'timed_by') ?></th>
      <th scope="col" class="c-status"><?= $word('ARRIVALS', 'timed_state') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ($r['failed']): ?> class="needs"<?php endif; ?>>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['external_ref'] ?? \CW\Ui\Words::STOCK_OPS['no_number']) ?></a><span class="o-sub"><?= $e(\CW\Ui\Words::RECEIVE_MODE[$r['receive_mode']] ?? '') ?></span></th>
      <td data-label="<?= $word('ARRIVALS', 'supplier') ?>"><?= $e($r['supplier_name']) ?></td>
      <td data-label="<?= $word('ARRIVALS', 'timed_at') ?>"><?= $e($r['at']) ?></td>
      <td data-label="<?= $word('ARRIVALS', 'timed_by') ?>"><?= $e($r['submit_by_name'] ?? '') ?></td>
      <td class="c-status"><?php if ($r['failed']): ?><?= $chip('blocked', \CW\Ui\Words::ARRIVALS['could_not']) ?><span class="o-sub"><?= $e($r['submit_error'] ?? '') ?></span><?php else: ?><?= $chip('waiting', \CW\Ui\Words::ARRIVALS['waiting']) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
