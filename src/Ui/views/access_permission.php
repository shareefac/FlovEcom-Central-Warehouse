<p class="crumbs"><a href="/ui/reference/access"><?= $word('ROLES_EDIT', 'back') ?></a></p>
<h1><?= $e($what) ?></h1>
<?php if ($lookOnly !== null): ?>
<p class="lede"><?= $e(\CW\Ui\Words::PAGE_INTRO['permission'][0]) ?> <?= $e($lookOnly) ?></p>
<?php else: ?>
<?= $intro('permission') ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<dl class="wide">
  <dt><?= $word('ROLES_EDIT', 'now') ?></dt><dd><?= $e($who) ?></dd>
  <dt><?= $word('SETTING_EDIT', 'what') ?></dt><dd><?= $e($kind) ?></dd>
<?php if ($fromCode): ?>
  <dt><?= $word('SETTING_EDIT', 'last') ?></dt><dd><?= $word('ROLES_EDIT', 'from_code') ?></dd>
<?php endif; ?>
</dl>
<?php if ($fixed): ?>
<p class="note"><?= $chip('info', \CW\Ui\Words::ACCESS['fixed']) ?> <?= $word('ACCESS', 'fixed_why') ?></p>
<?php elseif ($canChange): ?>
<form class="record" method="post" action="/ui/reference/access">
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
  <p class="note"><?= $word('ROLES_EDIT', 'give_rule') ?> <?= $word('ROLES_EDIT', 'take_rule') ?></p>
  <label><?= $word('CONFIG', 'reason') ?>
    <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
    <textarea name="reason" rows="2" minlength="3" maxlength="500" required<?php if ($errorCode === 'bad_reason'): ?> aria-invalid="true"<?php endif; ?>><?= $e($reason) ?></textarea>
  </label>
  <p class="actions"><button type="submit" class="primary"><?= $word('ROLES_EDIT', 'save') ?></button></p>
</form>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('CONFIG', 'history') ?></h2>
<?= $partial('config_history', ['rows' => $history]) ?>
</section>
