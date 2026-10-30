<?php
// One reason, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.11): the list as the
// eyebrow (DC3), its name with its status; a card of what it is (a row each: its code, what it is used for, which way, its rules); for
// whoever changes reasons a card of its changes, each saved on its own with a reason (its name in its row; where it is offered, its
// stock-out rules and switching it off or on each under the row's own Change); then its History. CW's own reasons are never changed.
?>
<?= $head((string) $r['label'], intro: 'reason', more: $lookOnly, back: ['/ui/reference/reasons', \CW\Ui\Words::PAGE_TITLE['reasons']],
    chips: $r['is_active'] ? $chip('done', \CW\Ui\Words::REASONS_EDIT['status_on']) : $chip('off', \CW\Ui\Words::REASONS_EDIT['status_off'])) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<div class="set-list">
<section class="set-card reason-facts" aria-labelledby="facts-h">
  <h2 id="facts-h"><?= $word('REASONS_EDIT', 'facts') ?></h2>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTINGS_PAGE', 'code') ?></p></div><div class="set-v"><code><?= $e($r['code']) ?></code></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTINGS_PAGE', 'used_for') ?></p></div><div class="set-v"><?= $e($r['used_for']) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTINGS_PAGE', 'direction') ?></p></div><div class="set-v"><?= $e($r['way']) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTINGS_PAGE', 'needs_note') ?></p></div><div class="set-v"><?= $word('SETTINGS_PAGE', $r['needs_note'] ? 'yes' : 'no') ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('SETTINGS_PAGE', 'gift') ?></p></div><div class="set-v"><?= $word('SETTINGS_PAGE', $r['is_gift'] ? 'yes' : 'no') ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('REASONS_EDIT', 'given_to') ?></p></div><div class="set-v"><?= $word('SETTINGS_PAGE', $r['needs_given_to'] ? 'yes' : 'no') ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('REASONS_EDIT', 'below_zero') ?></p></div><div class="set-v"><?= $word('SETTINGS_PAGE', $r['below_zero'] ? 'yes' : 'no') ?></div></div>
</section>
<?php if ($r['system_only']): ?>
<p class="note read-only"><?= $word('REASONS_EDIT', 'locked') ?></p>
<?php elseif ($canEdit): ?>
<section class="set-card reason-changes" aria-labelledby="changes-h">
  <h2 id="changes-h"><?= $word('SETTINGS_PAGE', 'changes') ?></h2>
  <form class="set-row" method="post" action="/ui/reference/reasons/reason" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="code" value="<?= $e($r['code']) ?>">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <input type="hidden" name="do" value="rename">
    <div class="set-k"><p class="set-kt" id="rename-h"><?= $word('REASONS_EDIT', 'rename') ?></p></div>
    <div class="set-v">
      <label class="visually-hidden" for="rn-label"><?= $word('REASONS_EDIT', 'name') ?></label>
      <input id="rn-label" class="set-in wide" type="text" name="label" value="<?= $e($typed['label']) ?>" maxlength="100" required<?php if ($errorCode === 'bad_label'): ?> aria-invalid="true"<?php endif; ?>>
      <label class="visually-hidden" for="rn-why"><?= $word('SETTINGS_PAGE', 'why_label') ?></label>
      <input id="rn-why" class="set-why" type="text" name="reason" minlength="3" maxlength="500" required placeholder="<?= $word('SETTINGS_PAGE', 'why') ?>" value="<?php if ($typed['do'] === 'rename'): ?><?= $e($typed['reason']) ?><?php endif; ?>">
      <button type="submit" class="btn"><?= $word('REASONS_EDIT', 'rename_button') ?></button>
    </div>
  </form>
  <div class="set-row">
    <div class="set-k"><p class="set-kt"><?= $word('REASONS_EDIT', 'uses_title') ?></p><p class="set-kd"><?= $word('REASONS_EDIT', 'uses_text') ?></p></div>
    <details class="set-fold"<?php if ($typed['do'] === 'uses'): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('SETTINGS_PAGE', 'change') ?></summary>
      <form class="record" method="post" action="/ui/reference/reasons/reason" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="code" value="<?= $e($r['code']) ?>">
        <input type="hidden" name="seen" value="<?= $e($seen) ?>">
        <input type="hidden" name="do" value="uses">
        <fieldset>
          <legend><?= $word('REASONS_EDIT', 'uses') ?></legend>
<?php foreach ($uses as $use): ?>
          <label class="choice"><input type="checkbox" name="use_<?= $e($use['code']) ?>" value="1"<?php if ($use['checked']): ?> checked<?php endif; ?>> <?= $e($use['name']) ?></label>
<?php endforeach; ?>
        </fieldset>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'uses'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
        <p class="actions"><button type="submit" class="btn"><?= $word('REASONS_EDIT', 'uses_button') ?></button></p>
      </form>
    </details>
  </div>
  <div class="set-row">
    <div class="set-k"><p class="set-kt"><?= $word('REASONS_EDIT', 'rules_title') ?></p><p class="set-kd"><?= $word('REASONS_EDIT', 'rules_text') ?></p></div>
    <details class="set-fold"<?php if ($typed['do'] === 'rules'): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('SETTINGS_PAGE', 'change') ?></summary>
      <form class="record" method="post" action="/ui/reference/reasons/reason" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="code" value="<?= $e($r['code']) ?>">
        <input type="hidden" name="seen" value="<?= $e($seen) ?>">
        <input type="hidden" name="do" value="rules">
        <label class="choice"><input type="checkbox" name="needs_given_to" value="1"<?php if ($typed['needs_given_to']): ?> checked<?php endif; ?>> <?= $word('REASONS_EDIT', 'given_to') ?></label>
        <label class="choice"><input type="checkbox" name="below_zero" value="1"<?php if ($typed['below_zero']): ?> checked<?php endif; ?>> <?= $word('REASONS_EDIT', 'below_zero') ?></label>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'rules'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
        <p class="actions"><button type="submit" class="btn"><?= $word('REASONS_EDIT', 'rules_button') ?></button></p>
      </form>
    </details>
  </div>
  <div class="set-row">
    <div class="set-k"><p class="set-kt"><?= $word('REASONS_EDIT', 'switch') ?></p><p class="set-kd"><?= $word('REASONS_EDIT', 'switch_off_text') ?></p></div>
    <details class="set-fold"<?php if ($typed['do'] === 'on' || $typed['do'] === 'off'): ?> open<?php endif; ?>>
      <summary class="btn"><?php if ($r['is_active']): ?><?= $word('REASONS_EDIT', 'switch_off_button') ?><?php else: ?><?= $word('REASONS_EDIT', 'switch_on_button') ?><?php endif; ?></summary>
      <form class="record" method="post" action="/ui/reference/reasons/reason" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="code" value="<?= $e($r['code']) ?>">
        <input type="hidden" name="seen" value="<?= $e($seen) ?>">
        <input type="hidden" name="do" value="<?php if ($r['is_active']): ?>off<?php else: ?>on<?php endif; ?>">
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'on' || $typed['do'] === 'off'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
<?php if ($r['is_active']): ?>
        <p class="actions"><button type="submit" class="btn risky"><?= $word('REASONS_EDIT', 'switch_off_button') ?></button></p>
<?php else: ?>
        <p class="actions"><button class="btn" type="submit"><?= $word('REASONS_EDIT', 'switch_on_button') ?></button></p>
<?php endif; ?>
      </form>
    </details>
  </div>
</section>
<?php endif; ?>
<section class="set-card history" aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('CONFIG', 'history') ?></h2>
<?= $partial('config_history', ['rows' => $history]) ?>
</section>
</div>
