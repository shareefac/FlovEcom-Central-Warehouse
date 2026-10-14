<?php
// Approvals › From the stores › ERPNext corrections (count_review, ERPNext's; docs/decisions.md PF7): each "set to this number" a store
// relayed, as a card: the figure, when ERPNext set it, what the warehouse held at that moment and what confirming books now; for the
// people who may, a note and the two answers (confirm: books the difference; close it: books nothing).
?>
<div class="head-help">
  <h1><?= $word('SEGMENT', 'queue_corrections') ?></h1>
</div>
<?= $intro('queue_corrections', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('queue_filter', ['action' => $action, 'states' => $states, 'state' => $state, 'count' => count($rows), 'more' => $more, 'pageSize' => $pageSize]) ?>
<?php if ($rows === [] && $state === 'open'): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none_open'], \CW\Ui\Words::CORRECTIONS['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none'], \CW\Ui\Words::QUEUES['none_text'], $action, \CW\Ui\Words::QUEUES['show_open']) ?>
<?php else: ?>
<ol class="incident-list queue-list">
<?php foreach ($rows as $r): ?>
  <li>
  <article class="incident card <?= $e(\CW\Ui\Words::tone('QUEUE_STATE', $r['status'])) ?>" id="correction-<?= $e($r['id']) ?>">
    <div class="task-head">
      <h2><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['title']) ?></a></h2>
      <?= $stateChip('QUEUE_STATE', $r['status']) ?>
    </div>
    <p><?= $e($r['from']) ?> · <?= $e($r['document']) ?></p>
    <p><?= $e($r['set_at']) ?></p>
<?php foreach ($r['effect_lines'] as $line): ?>
    <p><?= $e($line) ?></p>
<?php endforeach; ?>
    <p class="hint"><?= $e($r['received']) ?></p>
<?php if ($r['closed'] !== null): ?>
    <p class="hint"><?= $e($r['closed']) ?></p>
<?php elseif ($canAct): ?>
    <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/confirm') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <fieldset>
        <legend><?= $word('CORRECTIONS', 'answer_legend') ?></legend>
        <label><?= $word('QUEUES', 'note') ?> <input type="text" name="note" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
        <p class="actions">
          <button type="submit"><?= $word('CORRECTIONS', 'confirm') ?></button>
          <button type="submit" class="danger" formaction="<?= $u($action . '/' . $r['id'] . '/dismiss') ?>"><?= $word('CORRECTIONS', 'dismiss') ?></button>
        </p>
        <p class="hint"><?= $word('CORRECTIONS', 'not_count') ?></p>
      </fieldset>
    </form>
<?php endif; ?>
  </article>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
