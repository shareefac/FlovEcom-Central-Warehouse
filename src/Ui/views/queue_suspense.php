<?php
// Approvals › From the stores › Lines to place (goods-in suspense; docs/decisions.md PF6): each relayed ERPNext line the warehouse
// records could not match to an item, as a card: what it is, where it came from, why it waits; for the people who may, the item's code
// with the units of one line unit and a note (book it, or for a correction send it to the corrections), or a note to close it. A refused
// answer comes back with what was typed in its row ($r['typed'], docs/decisions.md OV7).
?>
<?= $head(\CW\Ui\Words::SEGMENT['queue_suspense'], intro: 'queue_suspense', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('queue_filter', ['action' => $action, 'states' => $states, 'state' => $state, 'count' => count($rows), 'more' => $more, 'pageSize' => $pageSize]) ?>
<?php if ($rows === [] && $state === 'open'): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none_open'], \CW\Ui\Words::SUSPENSE['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none'], \CW\Ui\Words::QUEUES['none_text'], $action, \CW\Ui\Words::QUEUES['show_open']) ?>
<?php else: ?>
<ol class="incident-list queue-list">
<?php foreach ($rows as $r): ?>
  <li>
  <article class="incident card <?= $e(\CW\Ui\Words::tone('QUEUE_STATE', $r['status'])) ?>" id="suspense-<?= $e($r['id']) ?>">
    <div class="task-head">
      <h2><?= $e($r['title']) ?></h2>
      <?= $stateChip('QUEUE_STATE', $r['status']) ?>
    </div>
    <p><?= $e($r['from']) ?> · <?= $e($r['document']) ?></p>
<?php if ($r['names'] !== null): ?>
    <p class="hint"><?= $e($r['names']) ?></p>
<?php endif; ?>
    <p><strong><?= $e($r['why']) ?></strong></p>
    <p class="hint"><?= $e($r['received']) ?></p>
<?php if ($r['closed'] !== null): ?>
<?php if ($r['picked'] !== null): ?>
    <p><?= $e($r['picked']) ?></p>
<?php endif; ?>
    <p class="hint"><?= $e($r['closed']) ?></p>
<?php elseif ($canAct): ?>
    <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/pick') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <fieldset>
        <legend><?= $word('SUSPENSE', 'pick_legend') ?></legend>
        <label><?= $word('SUSPENSE', 'item') ?> <input type="text" name="item" value="<?= $e($r['typed'] !== null && $r['typed']['form'] === 'pick' ? $r['typed']['item'] : '') ?>" maxlength="16" required autocomplete="off" inputmode="text"></label>
        <p class="hint"><?= $word('SUSPENSE', 'item_hint') ?></p>
        <label><?= $word('SUSPENSE', 'units') ?> <input type="number" name="units" min="1" max="10000" step="1" value="<?= $e($r['typed'] !== null && $r['typed']['form'] === 'pick' ? $r['typed']['units'] : '1') ?>" required></label>
        <p class="hint"><?= $word('SUSPENSE', 'units_hint') ?></p>
        <label><?= $word('QUEUES', 'note') ?> <input type="text" name="note" value="<?= $e($r['typed'] !== null && $r['typed']['form'] === 'pick' ? $r['typed']['note'] : '') ?>" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
        <p class="actions"><button type="submit"><?php if ($r['correction']): ?><?= $word('SUSPENSE', 'to_corrections') ?><?php else: ?><?= $word('SUSPENSE', 'book') ?><?php endif; ?></button></p>
        <p class="hint"><?php if ($r['correction']): ?><?= $word('SUSPENSE', 'correction_hint') ?><?php else: ?><?= $word('SUSPENSE', 'book_hint') ?><?php endif; ?></p>
      </fieldset>
    </form>
    <details class="fold"<?php if ($r['typed'] !== null && $r['typed']['form'] === 'dismiss'): ?> open<?php endif; ?>>
      <summary><?= $word('SUSPENSE', 'dismiss_legend') ?></summary>
      <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/dismiss') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <label><?= $word('QUEUES', 'note') ?> <input type="text" name="note" value="<?= $e($r['typed'] !== null && $r['typed']['form'] === 'dismiss' ? $r['typed']['note'] : '') ?>" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
        <p class="actions"><button type="submit" class="danger"><?= $word('SUSPENSE', 'dismiss') ?></button></p>
      </form>
    </details>
<?php endif; ?>
  </article>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
