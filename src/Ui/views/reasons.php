<h1><?= $word('PAGE_TITLE', 'reasons') ?></h1>
<?= $intro('reasons') ?>
<p class="muted"><?= $word('SETTINGS_PAGE', 'reasons_text') ?></p>
<?php if ($lookOnly !== null): ?>
<p class="muted"><?= $say('UI', 'look_only', $lookOnly) ?></p>
<?php endif; ?>
<p><a href="/ui/reference/reasons.csv"><?= $word('SETTINGS_PAGE', 'download_reasons') ?></a></p>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<details class="fold add-reason" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('REASONS_EDIT', 'add_title') ?></summary>
  <form class="record" method="post" action="/ui/reference/reasons">
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
<?php foreach ($uses as $u): ?>
      <label class="choice"><input type="checkbox" name="use_<?= $e($u['code']) ?>" value="1"<?php if ($u['checked']): ?> checked<?php endif; ?>> <?= $e($u['name']) ?></label>
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
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?= $e($typed['reason']) ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="primary"><?= $word('REASONS_EDIT', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<div class="table-wrap">
<table class="stack list reasons">
  <thead>
    <tr>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'reason') ?></th>
      <th scope="col"><?= $word('REASONS_EDIT', 'status') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'code') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'used_for') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'direction') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'needs_note') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'gift') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'by_cw') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($reasons as $r): ?>
    <tr class="<?php if ($r['is_active']): ?>done<?php else: ?>inactive off<?php endif; ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $e($r['href']) ?>"><?= $e($r['label']) ?></a></th>
      <td class="c-status"><?php if ($r['is_active']): ?><?= $chip('done', \CW\Ui\Words::REASONS_EDIT['status_on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::REASONS_EDIT['status_off']) ?><?php endif; ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'code') ?>"><code><?= $e($r['code']) ?></code></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'used_for') ?>"><?= $e($r['used_for']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'direction') ?>"><?= $e($r['way']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'needs_note') ?>"><?= $word('SETTINGS_PAGE', $r['needs_note'] ? 'yes' : 'no') ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'gift') ?>"><?= $word('SETTINGS_PAGE', $r['is_gift'] ? 'yes' : 'no') ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'by_cw') ?>"><?= $word('SETTINGS_PAGE', $r['system_only'] ? 'yes' : 'no') ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
