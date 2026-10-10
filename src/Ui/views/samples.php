<div class="head-help">
  <h1><?= $word('PAGE_TITLE', 'samples') ?></h1>
  <?= $explain('spot_check', \CW\Ui\Words::MENU['samples']) ?>
</div>
<?= $intro('samples') ?>
<?= $partial('store_seg', ['items' => $stores, 'countWords' => \CW\Ui\Words::BULK['store_count_samples']]) ?>

<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::SAMPLE['none'], \CW\Ui\Words::SAMPLE['none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list samples">
  <thead>
    <tr>
      <th scope="col"><?= $word('SAMPLE', 'name') ?></th>
      <th scope="col"><?= $word('SAMPLE', 'result') ?></th>
      <th scope="col" class="num"><?= $word('SAMPLE', 'checked') ?></th>
      <th scope="col" class="num"><?= $word('SAMPLE', 'picked_from') ?></th>
      <th scope="col"><?= $word('SAMPLE', 'started_by') ?></th>
      <th scope="col" class="num"><?= $word('SAMPLE', 'together') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('SAMPLE_RESULT', $r['result'])) ?>">
      <th scope="row" class="c-head"><a class="o-name" href="/ui/review/samples/<?= $e($r['id']) ?>"><?= $say('SAMPLE', 'title', $r['name']) ?></a></th>
      <td class="c-status"><?= $stateChip('SAMPLE_RESULT', $r['result']) ?></td>
      <td class="num" data-label="<?= $word('SAMPLE', 'checked') ?>"><?= $say('SAMPLE', 'of', $r['decided'], $r['size']) ?></td>
      <td class="num" data-label="<?= $word('SAMPLE', 'picked_from') ?>"><?= $n($r['population']) ?></td>
      <td data-label="<?= $word('SAMPLE', 'started_by') ?>"><?= $e($r['created_by'] ?? '') ?> <span class="muted"><?= $day($r['created_at']) ?></span></td>
      <td class="num" data-label="<?= $word('SAMPLE', 'together') ?>"><?= $n($r['bulk_linked']) ?><?php if ($r['bulk_undone'] > 0): ?> <span class="muted"><?= $say('SAMPLE', 'undone', $r['bulk_undone']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
