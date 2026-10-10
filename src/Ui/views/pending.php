<div class="head-help">
  <h1><?= $word('PAGE_TITLE', 'pending') ?></h1>
  <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
</div>
<?= $intro('pending', $lookOnly) ?>
<?= $partial('store_seg', ['items' => $stores, 'countWords' => \CW\Ui\Words::BULK['store_count_pending']]) ?>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PENDING['none'], \CW\Ui\Words::PENDING['none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list pending">
  <thead>
    <tr>
      <th scope="col"><?= $word('PENDING', 'product') ?></th>
      <th scope="col"><?= $word('PENDING', 'does') ?></th>
      <th scope="col"><?= $word('PENDING', 'why') ?></th>
      <th scope="col"><?= $word('PENDING', 'by') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $word('UI', 'what_each_answer_does') ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ($error_decision === $r['id']): ?> class="differs"<?php endif; ?>>
      <th scope="row" class="c-head">
        <a class="o-name" href="/ui/review/listing/<?= $e($r['listing_id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        <span class="o-sub"><?= $e($r['channel']) ?> · <?= $say('QUEUE', 'option', (string) $r['variant']) ?></span>
      </th>
      <td data-label="<?= $word('PENDING', 'does') ?>">
        <?= $partial('pending_decision', ['d' => $r]) ?>
<?php if ($r['reason'] !== null): ?>
        <div class="muted"><?= $say('PENDING', 'note_line', $r['reason']) ?></div>
<?php endif; ?>
<?php foreach ($r['stale'] as $why): ?>
        <div class="error"><?= $e($why) ?> <?= $word('LISTING', 'stale_fix') ?></div>
<?php endforeach; ?>
      </td>
      <td data-label="<?= $word('PENDING', 'why') ?>">
<?php foreach ($r['needs'] as $need): ?>
        <span class="tag"><?= $word('NEEDS_SECOND', $need) ?></span>
<?php endforeach; ?>
<?php if ($r['bulk'] !== null): ?>
        <div class="muted"><a href="/ui/review/batches/<?= $e($r['bulk']) ?>"><?= $say('BULK', 'from_batch', $r['bulk']) ?></a></div>
<?php endif; ?>
      </td>
      <td data-label="<?= $word('PENDING', 'by') ?>"><?= $e($r['decider'] ?? '') ?><div class="muted"><?= $when($r['created_at']) ?></div></td>
      <td class="c-next"><?= $partial('pending_actions', ['id' => $r['id'], 'from' => 'pending', 'can_approve' => $r['can_approve'], 'can_withdraw' => $r['can_withdraw'], 'own' => $r['own'], 'decider' => $r['decider']]) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
