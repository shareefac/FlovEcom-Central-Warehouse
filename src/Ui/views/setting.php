<p class="crumbs"><a href="/ui/reference/settings"><?= $word('MENU', 'settings') ?></a></p>
<h1><?= $e($name) ?></h1>
<?php if ($lookOnly !== null): ?>
<p class="lede"><?= $e(\CW\Ui\Words::PAGE_INTRO['setting'][0]) ?> <?= $e($lookOnly) ?></p>
<?php else: ?>
<?= $intro('setting') ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<dl class="wide">
  <dt><?= $word('SETTING_EDIT', 'what') ?></dt><dd><?= $e($help) ?></dd>
  <dt><?= $word('SETTING_EDIT', 'now') ?></dt><dd class="pre"><?php if ($shown === null): ?><span class="muted"><?= $word('CONFIG', 'not_set') ?></span><?php else: ?><?= $e($shown) ?><?php endif; ?></dd>
  <dt><?= $word('SETTING_EDIT', 'status') ?></dt><dd><?php if ($agreed): ?><?= $chip('done', \CW\Ui\Words::SETTINGS_PAGE['agreed']) ?><?php else: ?><?= $chip('needs', \CW\Ui\Words::SETTINGS_PAGE['not_agreed']) ?><?php endif; ?></dd>
  <dt><?= $word('SETTING_EDIT', 'last') ?></dt><dd><?= $e($changed) ?></dd>
</dl>
<?php if ($approval): ?>
<p class="note"><?= $word('SETTING_EDIT', 'approvals') ?> <a href="/ui/reference/approvals"><?= $word('MENU', 'approvals') ?></a></p>
<?php elseif ($canEdit): ?>
<form class="record" method="post" action="/ui/reference/settings/setting">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="key" value="<?= $e($key) ?>">
  <input type="hidden" name="seen" value="<?= $e($seen) ?>">
<?php if ($type === 'bool'): ?>
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
  <p class="actions"><button type="submit" class="primary"><?= $word('SETTING_EDIT', 'save') ?></button></p>
</form>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('CONFIG', 'history') ?></h2>
<?= $partial('config_history', ['rows' => $history]) ?>
</section>
