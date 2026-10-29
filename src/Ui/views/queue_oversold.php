<?php
// Approvals › From the stores › Oversold (oversell_event; docs/decisions.md PF9): each paid order the warehouse did not have the stock
// for, as a card: how many short, the order and store, what happened, the notes so far; for the people who may, a note (kept, never
// changed), and "mark handled" with how it was sorted out.
?>
<?= $head(\CW\Ui\Words::SEGMENT['queue_oversold'], intro: 'queue_oversold', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('queue_filter', ['action' => $action, 'states' => $states, 'state' => $state, 'count' => count($rows), 'more' => $more, 'pageSize' => $pageSize]) ?>
<?php if ($rows === [] && $state === 'open'): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none_open'], \CW\Ui\Words::OVERSOLD['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none'], \CW\Ui\Words::QUEUES['none_text'], $action, \CW\Ui\Words::QUEUES['show_open']) ?>
<?php else: ?>
<ol class="incident-list queue-list">
<?php foreach ($rows as $r): ?>
  <li>
  <article class="incident card <?= $e(\CW\Ui\Words::tone('QUEUE_STATE', $r['status'])) ?>" id="oversold-<?= $e($r['id']) ?>">
    <div class="task-head">
      <h2><a href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['title']) ?></a></h2>
      <?= $stateChip('QUEUE_STATE', $r['status']) ?>
    </div>
    <p><?= $e($r['from']) ?> · <?= $e($r['order']) ?></p>
    <p><?= $e($r['what']) ?> <?= $e($r['available']) ?></p>
    <p class="hint"><?= $e($r['received']) ?></p>
<?php if ($r['note_lines'] !== []): ?>
    <h3><?= $word('OVERSOLD', 'notes') ?></h3>
    <ul class="plain">
<?php foreach ($r['note_lines'] as $line): ?>
      <li><?= $e($line) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($r['closed'] !== null): ?>
    <p class="hint"><?= $e($r['closed']) ?></p>
<?php endif; ?>
<?php if ($canAct): ?>
<?php if ($r['closed'] === null): ?>
    <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/handle') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <fieldset>
        <legend><?= $word('OVERSOLD', 'handle_legend') ?></legend>
        <label><?= $word('OVERSOLD', 'how') ?>
          <select name="resolution">
<?php foreach ($resolutions as $code): ?>
            <option value="<?= $e($code) ?>"><?= $word('OVERSELL_RESOLUTION', $code) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label><?= $word('QUEUES', 'note') ?> <input type="text" name="note" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
        <p class="actions"><button class="btn" type="submit"><?= $word('OVERSOLD', 'handle') ?></button></p>
      </fieldset>
    </form>
<?php endif; ?>
    <details class="fold">
      <summary><?= $word('OVERSOLD', 'note_legend') ?></summary>
      <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/note') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <label><?= $word('QUEUES', 'note') ?> <input type="text" name="note" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
        <p class="actions"><button class="btn" type="submit"><?= $word('OVERSOLD', 'add_note') ?></button></p>
      </form>
    </details>
<?php endif; ?>
  </article>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
