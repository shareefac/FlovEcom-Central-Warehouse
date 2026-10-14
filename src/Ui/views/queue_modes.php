<?php
// Approvals › From the stores › Mode changes (policy_review, ERPNext's; docs/decisions.md PF8): each selling-mode change a store
// relayed that differs from what the warehouse holds as its mode, as a card: the item, the store and variant, the mode and low-stock level,
// what CW has now and what accepting does; for the people who may, a note and the two answers.
?>
<div class="head-help">
  <h1><?= $word('SEGMENT', 'queue_modes') ?></h1>
</div>
<?= $intro('queue_modes', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('queue_filter', ['action' => $action, 'states' => $states, 'state' => $state, 'count' => count($rows), 'more' => $more, 'pageSize' => $pageSize]) ?>
<?php if ($rows === [] && $state === 'open'): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none_open'], \CW\Ui\Words::MODES['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none'], \CW\Ui\Words::QUEUES['none_text'], $action, \CW\Ui\Words::QUEUES['show_open']) ?>
<?php else: ?>
<ol class="incident-list queue-list">
<?php foreach ($rows as $r): ?>
  <li>
  <article class="incident card <?= $e(\CW\Ui\Words::tone('QUEUE_STATE', $r['status'])) ?>" id="mode-<?= $e($r['id']) ?>">
    <div class="task-head">
      <h2><?php if ($r['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['title']) ?></a><?php else: ?><?= $e($r['title']) ?><?php endif; ?></h2>
      <?= $stateChip('QUEUE_STATE', $r['status']) ?>
    </div>
    <p><?= $e($r['from']) ?><?php if ($r['variant'] !== null): ?> · <?= $e($r['variant']) ?><?php endif; ?></p>
<?php if ($r['low'] !== null): ?>
    <p><?= $e($r['low']) ?></p>
<?php endif; ?>
    <p><?= $e($r['cw']) ?></p>
    <p class="hint"><?= $e($r['received']) ?></p>
<?php if ($r['closed'] !== null): ?>
    <p class="hint"><?= $e($r['closed']) ?></p>
<?php elseif ($canAct): ?>
    <p><strong><?= $e($r['will']) ?></strong></p>
    <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/accept') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <fieldset>
        <legend><?= $word('MODES', 'answer_legend') ?></legend>
        <label><?= $word('QUEUES', 'note') ?> <input type="text" name="note" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
        <p class="actions">
          <button type="submit"><?= $word('MODES', 'accept') ?></button>
          <button type="submit" class="danger" formaction="<?= $u($action . '/' . $r['id'] . '/dismiss') ?>"><?= $word('MODES', 'dismiss') ?></button>
        </p>
      </fieldset>
    </form>
<?php else: ?>
    <p><?= $e($r['will']) ?></p>
<?php endif; ?>
  </article>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
