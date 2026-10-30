<?php
// One task's jobs, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.14): Roles and
// permissions as the eyebrow (DC3), the task; a card of what it is (who can now, what kind, whether CW set it); for whoever changes it a card
// with its one form (the jobs ticked, the rules, the reason; Save), or why it never changes here; then its History.
?>
<?= $head($what, intro: 'permission', more: $lookOnly, back: ['/ui/reference/access', \CW\Ui\Words::PAGE_TITLE['access']]) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'people']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<div class="set-list">
<section class="set-card permission-facts" aria-labelledby="facts-h">
  <h2 id="facts-h"><?= $word('SETTINGS_PAGE', 'facts') ?></h2>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('ROLES_EDIT', 'now') ?></p></div><div class="set-v"><?= $e($who) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTING_EDIT', 'what') ?></p></div><div class="set-v"><?= $e($kind) ?></div></div>
<?php if ($fromCode): ?>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTING_EDIT', 'last') ?></p></div><div class="set-v"><?= $word('ROLES_EDIT', 'from_code') ?></div></div>
<?php endif; ?>
</section>
<?php if ($fixed): ?>
<p class="note"><span class="tag info"><?= $word('ACCESS', 'fixed') ?></span> <?= $word('ACCESS', 'fixed_why') ?></p>
<?php elseif ($canChange): ?>
<section class="set-card permission-change" aria-labelledby="change-h">
  <h2 id="change-h"><?= $word('SETTINGS_PAGE', 'changes') ?></h2>
  <div class="set-body">
  <form class="record" method="post" action="/ui/reference/access" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="perm" value="<?= $e($perm) ?>">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <fieldset>
      <legend><?= $word('ROLES_EDIT', 'jobs') ?></legend>
      <p class="hint"><?= $word('ROLES_EDIT', 'jobs_hint') ?></p>
<?php foreach ($boxes as $b): ?>
<?php if ($b['always']): ?>
      <input type="hidden" name="job_<?= $e($b['role']) ?>" value="1">
<?php endif; ?>
      <label class="choice"><input type="checkbox" name="job_<?= $e($b['role']) ?>" value="1"<?php if ($b['checked']): ?> checked<?php endif; ?><?php if ($b['disabled']): ?> disabled<?php endif; ?>> <?= $e($b['name']) ?><?php if ($b['why'] !== null): ?> <small class="muted"><?= $e($b['why']) ?></small><?php endif; ?></label>
<?php endforeach; ?>
    </fieldset>
    <p class="hint"><?= $word('ROLES_EDIT', 'give_rule') ?> <?= $word('ROLES_EDIT', 'take_rule') ?></p>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required<?php if ($errorCode === 'bad_reason'): ?> aria-invalid="true"<?php endif; ?>><?= $e($reason) ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('ROLES_EDIT', 'save') ?></button></p>
  </form>
  </div>
</section>
<?php endif; ?>
<section class="set-card history" aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('CONFIG', 'history') ?></h2>
<?= $partial('config_history', ['rows' => $history]) ?>
</section>
</div>
</div>
</div>
