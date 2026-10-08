<p class="crumbs"><a href="/ui/reference/reasons"><?= $word('PAGE_TITLE', 'reasons') ?></a></p>
<h1><?= $e($r['label']) ?> <?php if ($r['is_active']): ?><?= $chip('done', \CW\Ui\Words::REASONS_EDIT['status_on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::REASONS_EDIT['status_off']) ?><?php endif; ?></h1>
<?php if ($lookOnly !== null): ?>
<p class="lede"><?= $e(\CW\Ui\Words::PAGE_INTRO['reason'][0]) ?> <?= $e($lookOnly) ?></p>
<?php else: ?>
<?= $intro('reason') ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<dl class="wide">
  <dt><?= $word('SETTINGS_PAGE', 'code') ?></dt><dd><code><?= $e($r['code']) ?></code></dd>
  <dt><?= $word('SETTINGS_PAGE', 'used_for') ?></dt><dd><?= $e($r['used_for']) ?></dd>
  <dt><?= $word('SETTINGS_PAGE', 'direction') ?></dt><dd><?= $e($r['way']) ?></dd>
  <dt><?= $word('SETTINGS_PAGE', 'needs_note') ?></dt><dd><?= $word('SETTINGS_PAGE', $r['needs_note'] ? 'yes' : 'no') ?></dd>
  <dt><?= $word('SETTINGS_PAGE', 'gift') ?></dt><dd><?= $word('SETTINGS_PAGE', $r['is_gift'] ? 'yes' : 'no') ?></dd>
</dl>
<?php if ($r['system_only']): ?>
<p class="note read-only"><?= $word('REASONS_EDIT', 'locked') ?></p>
<?php elseif ($canEdit): ?>
<section aria-labelledby="rename-h">
  <h2 id="rename-h"><?= $word('REASONS_EDIT', 'rename') ?></h2>
  <form class="record" method="post" action="/ui/reference/reasons/reason">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="code" value="<?= $e($r['code']) ?>">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <input type="hidden" name="do" value="rename">
    <label><?= $word('REASONS_EDIT', 'name') ?>
      <input type="text" name="label" value="<?= $e($typed['label']) ?>" maxlength="100" required<?php if ($errorCode === 'bad_label'): ?> aria-invalid="true"<?php endif; ?>>
    </label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'rename'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="primary"><?= $word('REASONS_EDIT', 'rename_button') ?></button></p>
  </form>
</section>
<section aria-labelledby="switch-h">
  <h2 id="switch-h"><?= $word('REASONS_EDIT', 'switch') ?></h2>
  <p class="muted"><?= $word('REASONS_EDIT', 'switch_off_text') ?></p>
  <form class="record" method="post" action="/ui/reference/reasons/reason">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="code" value="<?= $e($r['code']) ?>">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <input type="hidden" name="do" value="<?php if ($r['is_active']): ?>off<?php else: ?>on<?php endif; ?>">
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'on' || $typed['do'] === 'off'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
    </label>
<?php if ($r['is_active']): ?>
    <p class="actions"><button type="submit" class="danger"><?= $word('REASONS_EDIT', 'switch_off_button') ?></button></p>
<?php else: ?>
    <p class="actions"><button type="submit"><?= $word('REASONS_EDIT', 'switch_on_button') ?></button></p>
<?php endif; ?>
  </form>
</section>
<?php endif; ?>
<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('CONFIG', 'history') ?></h2>
<?= $partial('config_history', ['rows' => $history]) ?>
</section>
