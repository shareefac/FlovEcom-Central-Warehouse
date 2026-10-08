<div class="head-help">
  <h1><?= $word('MENU', 'approvals') ?></h1>
  <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
</div>
<?= $intro('approvals', $lookOnly) ?>
<?php if ($requests > 0): ?>
<p class="note"><?= $say('APPROVALS', 'requests', $requests) ?><?php if ($canDecideRequests): ?> <a href="/ui/staff-requests"><?= $word('APPROVALS', 'requests_link') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($nobodyCan): ?>
<p class="note" role="alert"><?= $word('APPROVALS', 'nobody_can') ?></p>
<?php endif; ?>
<p class="muted"><?= $word('APPROVALS', 'loosen_rule') ?> <?= $word('APPROVALS', 'not_release') ?></p>
<?php if ($loosenNote !== null): ?>
<p class="note"><?= $e($loosenNote) ?></p>
<?php endif; ?>
<?php foreach ($sections as $section): ?>
<section class="rules" aria-labelledby="sec-<?= $e($section['key']) ?>">
  <h2 id="sec-<?= $e($section['key']) ?>"><?= $e($section['title']) ?></h2>
<?php if ($section['key'] === 'records'): ?>
  <p class="muted"><?= $word('APPROVALS', 'records_text') ?></p>
<?php endif; ?>
<?php foreach ($section['cards'] as $c): ?>
  <article class="card rule" id="<?= $e($c['anchor']) ?>">
    <h3><?= $e($c['title']) ?>
<?php if ($c['kind'] === 'document'): ?>
      <?php if ($c['live']): ?><?= $chip('done', \CW\Ui\Words::APPROVALS['in_use']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::APPROVALS['later']) ?><?php endif; ?>
<?php elseif ($c['kind'] === 'switch'): ?>
      <?php if ($c['on']): ?><?= $chip('done', \CW\Ui\Words::CONFIG['on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::CONFIG['off']) ?><?php endif; ?>
<?php endif; ?>
<?php if ($c['kind'] !== 'document'): ?>
      <?php if ($c['agreed']): ?><?= $chip('done', \CW\Ui\Words::SETTINGS_PAGE['agreed']) ?><?php else: ?><?= $chip('needs', \CW\Ui\Words::SETTINGS_PAGE['not_agreed']) ?><?php endif; ?>
<?php endif; ?>
    </h3>
<?php if ($c['kind'] === 'document'): ?>
<?php foreach ($c['sentences'] as $line): ?>
    <p><?= $e($line) ?></p>
<?php endforeach; ?>
<?php else: ?>
    <p><?= $e($c['text']) ?></p>
<?php if ($c['note'] !== null): ?>
    <p class="muted"><?= $e($c['note']) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($c['error'] !== null): ?>
    <p class="error" role="alert" data-code="<?= $e($c['errorCode']) ?>"><?= $e($c['error']) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
    <details class="fold"<?php if ($c['error'] !== null): ?> open<?php endif; ?>>
      <summary><?= $word('APPROVALS', 'change_rule') ?></summary>
      <form class="record" method="post" action="/ui/reference/approvals">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="kind" value="<?= $e($c['kind']) ?>">
        <input type="hidden" name="key" value="<?= $e($c['key']) ?>">
        <input type="hidden" name="seen" value="<?= $e($c['seen']) ?>">
<?php if ($c['kind'] === 'document'): ?>
        <label><?= $word('APPROVALS', 'review') ?>
          <select name="review_rule">
            <option value="all"<?php if ($c['form']['review_rule'] === 'all'): ?> selected<?php endif; ?>><?= $word('APPROVALS', 'review_all') ?></option>
            <option value="over_limit"<?php if ($c['form']['review_rule'] === 'over_limit'): ?> selected<?php endif; ?>><?= $word('APPROVALS', 'review_over') ?></option>
            <option value="none"<?php if ($c['form']['review_rule'] === 'none'): ?> selected<?php endif; ?>><?= $word('APPROVALS', 'review_none') ?></option>
          </select>
        </label>
        <label><?= $word('APPROVALS', 'review_limit') ?>
          <input type="text" name="review_limit" inputmode="numeric" value="<?= $e($c['form']['review_limit']) ?>" maxlength="10">
        </label>
        <label><?= $word('APPROVALS', 'days') ?>
          <input type="text" name="review_due_days" inputmode="numeric" value="<?= $e($c['form']['review_due_days']) ?>" maxlength="3" required>
        </label>
<?php if ($c['hasApproval']): ?>
        <fieldset>
          <legend><?= $word('APPROVALS', 'ok_first') ?></legend>
          <label class="choice"><input type="radio" name="approval" value="1"<?php if ($c['form']['approval']): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'on') ?></label>
          <label class="choice"><input type="radio" name="approval" value="0"<?php if (!$c['form']['approval']): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'off') ?></label>
          <label><?= $e($c['okLabel']) ?>
            <input type="text" name="approval_limit" inputmode="numeric" value="<?= $e($c['form']['approval_limit']) ?>" maxlength="10">
          </label>
        </fieldset>
<?php endif; ?>
<?php if ($c['recordOnly']): ?>
        <input type="hidden" name="reject_action" value="record">
        <p class="muted"><?= $word('APPROVALS', 'reject_record_only') ?></p>
<?php else: ?>
        <label><?= $word('APPROVALS', 'reject') ?>
          <select name="reject_action">
            <option value="reverse"<?php if ($c['form']['reject_action'] === 'reverse'): ?> selected<?php endif; ?>><?= $word('APPROVALS', 'reject_reverse') ?></option>
            <option value="record"<?php if ($c['form']['reject_action'] === 'record'): ?> selected<?php endif; ?>><?= $word('APPROVALS', 'reject_record') ?></option>
          </select>
        </label>
<?php endif; ?>
<?php $reasonTyped = $c['form']['reason']; ?>
<?php elseif ($c['kind'] === 'switch'): ?>
        <fieldset>
          <legend><?= $e($c['title']) ?></legend>
          <label class="choice"><input type="radio" name="value" value="true"<?php if ($c['tick']): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'on') ?></label>
          <label class="choice"><input type="radio" name="value" value="false"<?php if (!$c['tick']): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'off') ?></label>
        </fieldset>
<?php $reasonTyped = $c['reason']; ?>
<?php else: ?>
        <label><?= $e($c['label']) ?>
          <input type="text" name="value" inputmode="numeric" value="<?= $e($c['typed']) ?>" maxlength="9" required>
        </label>
<?php $reasonTyped = $c['reason']; ?>
<?php endif; ?>
<?php if ($c['kind'] !== 'document'): ?>
        <label class="choice"><input type="checkbox" name="agreed" value="1"<?php if ($c['agreedTick']): ?> checked<?php endif; ?>> <?= $word('APPROVALS', 'agreed') ?></label>
<?php endif; ?>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?= $e($reasonTyped) ?></textarea>
        </label>
        <p class="actions"><button type="submit" class="primary"><?= $word('APPROVALS', 'save_rule') ?></button></p>
      </form>
    </details>
<?php endif; ?>
    <details class="fold">
      <summary><?= $word('APPROVALS', 'history') ?></summary>
<?= $partial('config_history', ['rows' => $c['history']]) ?>
    </details>
  </article>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<p class="muted"><?= $word('APPROVALS', 'always') ?></p>
<p class="muted"><?= $word('APPROVALS', 'others') ?></p>
