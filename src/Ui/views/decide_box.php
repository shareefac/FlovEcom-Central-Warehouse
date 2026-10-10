<section class="card decide-box<?php if ($decide['refusal'] === null): ?> waiting<?php endif; ?>" aria-labelledby="decide-h">
  <div class="head-help">
    <h2 id="decide-h"><?php if ($decide['task']['kind'] === 'approval'): ?><?= $word('RECORD', 'approval_title') ?><?php else: ?><?= $word('RECORD', 'review_title') ?><?php endif; ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $e($decide['text']) ?><?php if ($decide['task']['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::CHECKS['late']) ?><?php endif; ?></p>
<?php if ($decide['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($decide['refusal']) ?></p>
<?php else: ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done">
        <dt><?= $e($decide['ok']) ?></dt>
        <dd><?= $e($decide['okDoes']) ?></dd>
      </div>
      <div class="answer blocked<?php if ($decide['safer']): ?> safe<?php endif; ?>">
        <dt><?= $word('RECORD', 'not_ok') ?><?php if ($decide['safer']): ?> <span class="safe-tag"><?= $word('UI', 'safer') ?></span><?php endif; ?></dt>
        <dd><?= $e($decide['notOkDoes']) ?></dd>
      </div>
    </dl>
  </div>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $decide['task']['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECORD', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary"><?= $e($decide['ok']) ?></button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $decide['task']['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECORD', 'why_not_ok') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit"><?= $word('RECORD', 'not_ok') ?></button>
  </form>
<?php endif; ?>
</section>
