<?php
// One setting, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.9): Rules and numbers as
// the eyebrow (DC3), its name; a card of what it is (what it does, its value now, whether the owner agreed it, its last change); for whoever
// changes settings a card with its one form (the value, the agreed tick, the reason; Save), or where its rule is changed; then its History.
?>
<?= $head((string) $name, intro: 'setting', more: $lookOnly, back: ['/ui/reference/settings', \CW\Ui\Words::SETTINGS_PAGE['title']]) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'settings']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<div class="set-list">
<section class="set-card setting-facts" aria-labelledby="facts-h">
  <h2 id="facts-h"><?= $word('SETTINGS_PAGE', 'facts') ?></h2>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTING_EDIT', 'what') ?></p></div><div class="set-v"><?= $e($help) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTING_EDIT', 'now') ?></p></div><div class="set-v pre"><?php if ($shown === null): ?><span class="muted"><?= $word('CONFIG', 'not_set') ?></span><?php else: ?><?= $e($shown) ?><?php endif; ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTING_EDIT', 'status') ?></p></div><div class="set-v"><?php if ($agreed): ?><span class="tag done"><?= $word('SETTINGS_PAGE', 'agreed') ?></span><?php else: ?><span class="tag needs"><?= $word('SETTINGS_PAGE', 'not_agreed') ?></span><?php endif; ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTING_EDIT', 'last') ?></p></div><div class="set-v"><?= $e($changed) ?></div></div>
</section>
<?php if ($approval): ?>
<p class="note"><?= $word('SETTING_EDIT', 'approvals') ?> <a href="/ui/reference/approvals"><?= $word('MENU', 'approvals') ?></a></p>
<?php elseif ($canEdit): ?>
<section class="set-card setting-change" aria-labelledby="change-h">
  <h2 id="change-h"><?= $word('SETTINGS_PAGE', 'changes') ?></h2>
  <div class="set-body">
  <form class="record" method="post" action="/ui/reference/settings/setting" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="key" value="<?= $e($key) ?>">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
<?php if ($items !== null): ?>
    <fieldset>
      <legend><?= $word('SETTING_EDIT', 'value') ?></legend>
      <p class="hint"><?= $e($hint) ?></p>
<?php foreach ($items as $it): ?>
      <label class="choice"><input type="checkbox" name="<?= $e($it['name']) ?>" value="1"<?php if ($it['checked']): ?> checked<?php endif; ?>> <?= $e($it['label']) ?></label>
<?php endforeach; ?>
    </fieldset>
<?php elseif ($type === 'bool'): ?>
    <fieldset>
      <legend><?= $word('SETTING_EDIT', 'value') ?></legend>
      <label class="choice"><input type="radio" name="value" value="true"<?php if ($raw === 'true'): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'yes') ?></label>
      <label class="choice"><input type="radio" name="value" value="false"<?php if ($raw !== 'true'): ?> checked<?php endif; ?>> <?= $word('CONFIG', 'no') ?></label>
    </fieldset>
<?php elseif ($type === 'text'): ?>
    <label><?= $word('SETTING_EDIT', 'value') ?>
      <span class="hint"><?= $e($hint) ?></span>
      <textarea name="value" rows="6" maxlength="4000"<?php if ($errorCode === 'bad_value'): ?> aria-invalid="true"<?php endif; ?>><?= $e($raw) ?></textarea>
    </label>
<?php else: ?>
    <label><?= $word('SETTING_EDIT', 'value') ?>
      <span class="hint"><?= $e($hint) ?></span>
      <input type="<?php if ($type === 'date'): ?>date<?php else: ?>text<?php endif; ?>" name="value" value="<?= $e($raw) ?>" maxlength="255"<?php if ($type === 'int'): ?> inputmode="numeric"<?php elseif ($type === 'decimal'): ?> inputmode="decimal"<?php endif; ?><?php if ($errorCode === 'bad_value'): ?> aria-invalid="true"<?php endif; ?>>
    </label>
<?php endif; ?>
    <label class="choice"><input type="checkbox" name="agreed" value="1"<?php if ($agreedTick): ?> checked<?php endif; ?>> <?= $word('SETTING_EDIT', 'agreed') ?></label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required<?php if ($errorCode === 'bad_reason'): ?> aria-invalid="true"<?php endif; ?>><?= $e($reason) ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('SETTING_EDIT', 'save') ?></button></p>
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
