<?php
// Settings › Approval rules, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.13): the
// heading (its "?": who may loosen a rule, what switching one off does not let through, what always needs a second OK, the one rule per kind
// of record) with a second OK's own "?"; the staff requests waiting and the "nobody else can say OK" warning; then a card per part, a row per
// rule: its name and state (in use, on or off, agreed), the rule in sentences, and at the right its Change (for whoever changes rules: the
// rule's own form, saved on its own with a reason) or its History, which is under the Change too.
$help = \CW\Ui\Words::APPROVALS['loosen_rule'] . ' ' . \CW\Ui\Words::APPROVALS['not_release'] . ' ' . \CW\Ui\Words::APPROVALS['records_text'] . ' '
    . \CW\Ui\Words::APPROVALS['always'] . ' ' . \CW\Ui\Words::APPROVALS['others'] . ($lookOnly !== null ? ' ' . \CW\Ui\Words::say('UI', 'look_only', $lookOnly) : '');
?>
<?= $head(\CW\Ui\Words::MENU['approvals'], intro: 'approvals', more: $help, aside: $explain('second_ok', \CW\Ui\Words::THING['second'])) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'approvals']) ?>
<div class="set-main">
<?php if ($requests > 0): ?>
<p class="note"><?= $say('APPROVALS', 'requests', $requests) ?><?php if ($canDecideRequests): ?> <a href="/ui/staff-requests"><?= $word('APPROVALS', 'requests_link') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($nobodyCan): ?>
<p class="note" role="alert"><?= $word('APPROVALS', 'nobody_can') ?></p>
<?php endif; ?>
<?php if ($loosenNote !== null): ?>
<p class="note"><?= $e($loosenNote) ?></p>
<?php endif; ?>
<div class="set-list">
<?php foreach ($sections as $section): ?>
<section class="set-card rules" aria-labelledby="sec-<?= $e($section['key']) ?>">
  <div class="set-head"><h2 id="sec-<?= $e($section['key']) ?>"><?= $e($section['title']) ?></h2> <span class="muted"><?php if (count($section['cards']) === 1): ?><?= $word('APPROVALS', 'count_one') ?><?php else: ?><?= $say('APPROVALS', 'count_many', count($section['cards'])) ?><?php endif; ?></span></div>
<?php foreach ($section['cards'] as $c): ?>
  <article class="set-row rule" id="<?= $e($c['anchor']) ?>">
    <div class="set-k">
      <h3 class="set-kt"><?= $e($c['title']) ?>
<?php if ($c['kind'] === 'document'): ?>
<?php if ($c['live']): ?><span class="tag done"><?= $word('APPROVALS', 'in_use') ?></span><?php else: ?><span class="tag off"><?= $word('APPROVALS', 'later') ?></span><?php endif; ?>
<?php elseif ($c['kind'] === 'switch'): ?>
<?php if ($c['on']): ?><span class="tag done"><?= $word('CONFIG', 'on') ?></span><?php else: ?><span class="tag off"><?= $word('CONFIG', 'off') ?></span><?php endif; ?>
<?php endif; ?>
<?php if ($c['kind'] !== 'document'): ?>
<?php if ($c['agreed']): ?><span class="tag done"><?= $word('SETTINGS_PAGE', 'agreed') ?></span><?php else: ?><span class="tag needs"><?= $word('SETTINGS_PAGE', 'not_agreed') ?></span><?php endif; ?>
<?php endif; ?>
      </h3>
<?php if ($c['kind'] === 'document'): ?>
<?php foreach ($c['sentences'] as $line): ?>
      <p class="set-kd"><?= $e($line) ?></p>
<?php endforeach; ?>
<?php else: ?>
      <p class="set-kd"><?= $e($c['text']) ?></p>
<?php if ($c['note'] !== null): ?>
      <p class="set-kd"><?= $e($c['note']) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($c['error'] !== null): ?>
      <p class="error" role="alert" data-code="<?= $e($c['errorCode']) ?>"><?= $e($c['error']) ?></p>
<?php endif; ?>
    </div>
    <details class="set-fold"<?php if ($c['error'] !== null): ?> open<?php endif; ?>>
      <summary class="btn"><?php if ($canEdit): ?><?= $word('SETTINGS_PAGE', 'change') ?><?php else: ?><?= $word('SETTINGS_PAGE', 'history_short') ?><?php endif; ?></summary>
<?php if ($canEdit): ?>
          <form class="record" method="post" action="/ui/reference/approvals" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
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
<?php if ($c['hasSize']): ?>
            <fieldset>
              <legend><?= $word('APPROVALS', 'size') ?></legend>
              <label class="choice"><input type="radio" name="size" value="1"<?php if ($c['form']['size']): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'on') ?></label>
              <label class="choice"><input type="radio" name="size" value="0"<?php if (!$c['form']['size']): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'off') ?></label>
              <label><?= $word('APPROVALS', 'size_units') ?>
                <input type="text" name="size_units" inputmode="numeric" value="<?= $e($c['form']['size_units']) ?>" maxlength="10">
              </label>
              <label><?= $word('APPROVALS', 'size_value') ?>
                <input type="text" name="size_value" inputmode="numeric" value="<?= $e($c['form']['size_value']) ?>" maxlength="10">
              </label>
            </fieldset>
<?php endif; ?>
<?php if ($c['recordOnly']): ?>
            <input type="hidden" name="reject_action" value="record">
            <p class="muted"><?= $e($c['recordOnlyText']) ?></p>
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
            <p class="actions"><button type="submit" class="btn"><?= $word('APPROVALS', 'save_rule') ?></button></p>
          </form>
<?php endif; ?>
      <div class="set-fold-body">
        <p class="set-kt"><?= $word('APPROVALS', 'history') ?></p>
<?= $partial('config_history', ['rows' => $c['history']]) ?>
      </div>
    </details>
  </article>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
</div>
</div>
</div>
