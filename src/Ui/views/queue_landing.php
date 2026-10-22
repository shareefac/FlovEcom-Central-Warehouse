<?php
// Approvals › From the stores › Website bugs (landing_order; docs/decisions.md LP8): each order a store took for a landing page, which
// is not a product, as a card: the order and the store, the landing page and its option number, how many, paid or not; no stock moved
// for it. For the people who may, "mark it handled" with how it was sorted out and a note.
?>
<?= $head(\CW\Ui\Words::SEGMENT['queue_landing'], intro: 'queue_landing', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('queue_filter', ['action' => $action, 'states' => $states, 'state' => $state, 'count' => count($rows), 'more' => $more, 'pageSize' => $pageSize]) ?>
<?php if ($rows === [] && $state === 'open'): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none_open'], \CW\Ui\Words::WEBSITE_BUG['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none'], \CW\Ui\Words::QUEUES['none_text'], $action, \CW\Ui\Words::QUEUES['show_open']) ?>
<?php else: ?>
<ol class="incident-list queue-list">
<?php foreach ($rows as $r): ?>
  <li>
  <article class="incident card <?= $e(\CW\Ui\Words::tone('QUEUE_STATE', $r['status'])) ?>" id="landing-<?= $e($r['id']) ?>">
    <div class="task-head">
      <h2><?php if ($canOpen): ?><a href="<?= $u($r['link']) ?>"><?= $e($r['title']) ?></a><?php else: ?><?= $e($r['title']) ?><?php endif; ?></h2>
      <?= $stateChip('QUEUE_STATE', $r['status']) ?>
    </div>
    <p><?= $e($r['from']) ?> · <?= $e($r['option']) ?></p>
    <p><?= $e($r['units_line']) ?> <?= $e($r['paid_line']) ?> <?= $word('WEBSITE_BUG', 'no_stock') ?></p>
<?php if ($r['still'] !== null): ?>
    <p class="hint"><?= $e($r['still']) ?></p>
<?php endif; ?>
    <p class="hint"><?= $e($r['received']) ?></p>
<?php if ($r['closed'] !== null): ?>
    <p class="hint"><?= $e($r['closed']) ?></p>
<?php endif; ?>
<?php if ($canAct && $r['closed'] === null): ?>
    <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/handle') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="paid" value="<?= $e((int) $r['paid']) ?>">
      <fieldset>
        <legend><?= $word('WEBSITE_BUG', 'handle_legend') ?></legend>
        <label><?= $word('WEBSITE_BUG', 'how') ?>
          <select name="resolution">
<?php foreach ($resolutions as $code): ?>
            <option value="<?= $e($code) ?>"><?= $word('LANDING_RESOLUTION', $code) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label><?= $word('QUEUES', 'note') ?> <input type="text" name="note" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
        <p class="actions"><button type="submit"><?= $word('WEBSITE_BUG', 'handle') ?></button></p>
      </fieldset>
    </form>
<?php endif; ?>
  </article>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
