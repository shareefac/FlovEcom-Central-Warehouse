<?php
// Settings › Reasons, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.11): the heading
// (its "?": what a reason is, and who changes them) with "+ New reason" at its right for whoever changes them (its form opens here, `#new`);
// then a card of the reasons in use (the spreadsheet file under its More) and one of those switched off, a row each: its name (its page:
// rename it, where it is offered, its stock-out rules, switch it off, its History), what it is used for and which way the stock goes, its
// rules, and at the right its short code.
$help = \CW\Ui\Words::SETTINGS_PAGE['reasons_text'] . ($lookOnly !== null ? ' ' . \CW\Ui\Words::say('UI', 'look_only', $lookOnly) : '');
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['reasons'], intro: 'reasons', more: $help) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'reasons']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<details class="fold add-reason drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('REASONS_EDIT', 'add_title') ?></summary>
  <form class="record" method="post" action="/ui/reference/reasons" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('REASONS_EDIT', 'name') ?>
      <input type="text" name="label" value="<?= $e($typed['label']) ?>" maxlength="100" required>
    </label>
    <label><?= $word('REASONS_EDIT', 'code') ?>
      <span class="hint"><?= $word('REASONS_EDIT', 'code_hint') ?></span>
      <input type="text" name="code" value="<?= $e($typed['code']) ?>" maxlength="32" autocapitalize="none" spellcheck="false" required<?php if (in_array($errorCode, ['bad_code', 'reason_exists'], true)): ?> aria-invalid="true"<?php endif; ?>>
    </label>
    <fieldset>
      <legend><?= $word('REASONS_EDIT', 'uses') ?></legend>
<?php foreach ($uses as $use): ?>
      <label class="choice"><input type="checkbox" name="use_<?= $e($use['code']) ?>" value="1"<?php if ($use['checked']): ?> checked<?php endif; ?>> <?= $e($use['name']) ?></label>
<?php endforeach; ?>
    </fieldset>
    <fieldset>
      <legend><?= $word('REASONS_EDIT', 'direction') ?></legend>
<?php foreach ($directions as $d): ?>
      <label class="choice"><input type="radio" name="direction" value="<?= $e($d['code']) ?>"<?php if ($typed['direction'] === $d['code']): ?> checked<?php endif; ?>> <?= $e($d['name']) ?></label>
<?php endforeach; ?>
    </fieldset>
    <label class="choice"><input type="checkbox" name="needs_note" value="1"<?php if ($typed['needs_note']): ?> checked<?php endif; ?>> <?= $word('REASONS_EDIT', 'needs_note') ?></label>
    <label class="choice"><input type="checkbox" name="is_gift" value="1"<?php if ($typed['is_gift']): ?> checked<?php endif; ?>> <?= $word('REASONS_EDIT', 'gift') ?></label>
    <label class="choice"><input type="checkbox" name="needs_given_to" value="1"<?php if ($typed['needs_given_to']): ?> checked<?php endif; ?>> <?= $word('REASONS_EDIT', 'given_to') ?></label>
    <label class="choice"><input type="checkbox" name="below_zero" value="1"<?php if ($typed['below_zero']): ?> checked<?php endif; ?>> <?= $word('REASONS_EDIT', 'below_zero') ?></label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?= $e($typed['reason']) ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('REASONS_EDIT', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<div class="set-list">
<?php foreach ([true, false] as $on): ?>
<?php if ($on || array_filter($reasons, static fn (array $x): bool => !$x['is_active']) !== []): ?>
<section class="set-card reasons" aria-labelledby="reasons-<?php if ($on): ?>on<?php else: ?>off<?php endif; ?>-h">
  <div class="set-head">
    <h2 id="reasons-<?php if ($on): ?>on<?php else: ?>off<?php endif; ?>-h"><?php if ($on): ?><?= $word('REASONS_EDIT', 'status_on') ?><?php else: ?><?= $word('REASONS_EDIT', 'status_off') ?><?php endif; ?></h2>
<?php if ($on): ?>
    <div class="set-head-end">
      <details class="more">
        <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
        <div class="more-menu">
          <a class="menu-item" href="/ui/reference/reasons.csv" download><?= $word('SETTINGS_PAGE', 'download_reasons') ?></a>
        </div>
      </details>
    </div>
<?php endif; ?>
  </div>
<?php foreach ($reasons as $r): ?>
<?php if ((bool) $r['is_active'] === $on): ?>
  <div class="set-row<?php if (!$on): ?> unused<?php endif; ?>" data-code="<?= $e($r['code']) ?>">
    <div class="set-k">
      <a class="set-kt" href="<?= $e($r['href']) ?>"><?= $e($r['label']) ?></a>
      <p class="set-kd"><?= $word('SETTINGS_PAGE', 'used_for') ?>: <?= $e($r['used_for']) ?> · <?= $e($r['way']) ?></p>
<?php if ($r['needs_note'] || $r['is_gift'] || $r['needs_given_to'] || $r['below_zero'] || $r['system_only']): ?>
      <p class="set-kd"><?php if ($r['needs_note']): ?><span class="tag info"><?= $word('SETTINGS_PAGE', 'needs_note') ?></span> <?php endif; ?><?php if ($r['is_gift']): ?><span class="tag needs"><?= $word('SETTINGS_PAGE', 'gift') ?></span> <?php endif; ?><?php if ($r['needs_given_to']): ?><span class="tag info"><?= $word('REASONS_EDIT', 'given_to_short') ?></span> <?php endif; ?><?php if ($r['below_zero']): ?><span class="tag needs"><?= $word('REASONS_EDIT', 'below_zero_short') ?></span> <?php endif; ?><?php if ($r['system_only']): ?><span class="tag off"><?= $word('SETTINGS_PAGE', 'by_cw') ?></span><?php endif; ?></p>
<?php endif; ?>
    </div>
    <div class="set-v"><code class="set-val"><?= $e($r['code']) ?></code></div>
  </div>
<?php endif; ?>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php endforeach; ?>
</div>
</div>
</div>
