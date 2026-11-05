<?php
// Settings › APIs (the owner, 5 Nov 2026; docs/decisions.md AK1-AK12), on the settings shell: one card per API CW calls, today ERPNext (read
// only). Its facts, a row each: which key is in use, the address, the last change (who, when), the key's last 4 characters and that a
// secret is saved: NEVER the key or the secret. The answer of the last "Check the connection", whether copying from ERPNext is on. For
// whoever changes settings: the form (the address, the key, the secret; Save), "Check the connection", and Remove (a fold whose form needs
// its tick: the confirm step is in the page, no script). The secret's field is a password field the browser does not fill in (AK2).
?>
<?= $head(\CW\Ui\Words::APIS['title'], intro: 'apis', more: $lookOnly) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'apis']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?><?php if ($retype): ?> <?= $word('APIS', 'retype') ?><?php endif; ?></p>
<?php endif; ?>
<div class="set-list">
<section class="set-card api-card" id="erp" aria-labelledby="erp-h">
  <h2 id="erp-h"><?= $word('APIS', 'erp_title') ?></h2>
  <p class="set-kd set-intro"><?= $word('APIS', 'erp_what') ?></p>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('APIS', 'in_use') ?></p></div><div class="set-v"><?php if ($inUse === 'saved'): ?><?= $chip('done', \CW\Ui\Words::APIS['use_saved']) ?><?php elseif ($inUse === 'app_env'): ?><?= $word('APIS', 'use_env') ?><?php else: ?><?= $chip('blocked', \CW\Ui\Words::APIS['use_none']) ?><?php endif; ?></div></div>
<?php if ($saved): ?>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('APIS', 'address') ?></p></div><div class="set-v api-url"><?= $e($shownUrl) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('APIS', 'key_label') ?></p></div><div class="set-v"><span class="api-key-end"><?= $say('APIS', 'key_ends', (string) $keyLast4) ?></span></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('APIS', 'secret_label') ?></p></div><div class="set-v"><?= $word('APIS', 'secret_saved') ?></div></div>
<?php endif; ?>
<?php if ($changed !== null): ?>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('APIS', 'last_change') ?></p></div><div class="set-v"><?= $e($changed) ?></div></div>
<?php endif; ?>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('APIS', 'copy_label') ?></p></div><div class="set-v"><?php if ($copyOn): ?><?= $word('APIS', 'copy_on') ?><?php else: ?><?= $word('APIS', 'copy_off') ?><?php endif; ?><?php if ($canEdit): ?> <a href="<?= $e($copySetting) ?>"><?= $word('APIS', 'copy_setting') ?></a><?php endif; ?></div></div>
<?php if ($check !== null): ?>
  <p class="note api-check<?php if ($check['tone'] === 'done'): ?> done<?php endif; ?>" role="status" data-outcome="<?= $e($check['outcome']) ?>"><?= $e($check['text']) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
  <div class="set-row api-change">
    <div class="set-k">
      <p class="set-kt"><?= $word('APIS', 'change_title') ?></p>
      <form class="record api-form" method="post" action="/ui/system/apis" autocomplete="off" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($error !== null): ?> data-dirty="1"<?php endif; ?>>
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
        <input type="hidden" name="version" value="<?= $e($version) ?>">
        <label for="f-api-url"><?= $word('APIS', 'address') ?> <span class="hint"><?= $word('APIS', 'address_hint') ?></span></label>
        <input id="f-api-url" type="text" name="url" value="<?= $e($url) ?>" maxlength="200" required inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false"<?php if ($errorCode === 'api_bad_url'): ?> aria-invalid="true"<?php endif; ?>>
        <label for="f-api-key"><?= $word('APIS', 'key') ?> <span class="hint"><?php if ($saved): ?><?= $word('APIS', 'key_hint_saved') ?><?php else: ?><?= $word('APIS', 'key_hint_new') ?><?php endif; ?></span></label>
        <input id="f-api-key" type="text" name="api_key" value="" maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false"<?php if (!$saved): ?> required<?php endif; ?><?php if (in_array($errorCode, ['api_bad_key', 'api_need_key'], true)): ?> aria-invalid="true"<?php endif; ?>>
        <label for="f-api-secret"><?= $word('APIS', 'secret') ?> <span class="hint"><?php if ($saved): ?><?= $word('APIS', 'secret_hint_saved') ?><?php else: ?><?= $word('APIS', 'secret_hint_new') ?><?php endif; ?></span></label>
        <input id="f-api-secret" type="password" name="api_secret" value="" maxlength="64" autocomplete="new-password" autocapitalize="off" spellcheck="false"<?php if (!$saved): ?> required<?php endif; ?><?php if (in_array($errorCode, ['api_bad_secret', 'api_need_secret'], true)): ?> aria-invalid="true"<?php endif; ?>>
        <p class="actions"><button type="submit" class="btn primary"><?= $word('APIS', 'save') ?></button></p>
      </form>
    </div>
  </div>
<?php if ($inUse !== 'none'): ?>
  <div class="set-row api-test">
    <div class="set-k"><p class="set-kt"><?= $word('APIS', 'check') ?></p><p class="set-kd"><?= $word('APIS', 'check_text') ?></p></div>
    <div class="set-v">
      <form class="inline" method="post" action="/ui/system/apis/check">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn"><?= $word('APIS', 'check') ?></button>
      </form>
    </div>
  </div>
<?php endif; ?>
<?php if ($saved): ?>
  <div class="set-row api-remove">
    <div class="set-k"><p class="set-kt"><?= $word('APIS', 'remove_title') ?></p></div>
    <details class="set-fold"<?php if ($errorCode === 'api_confirm'): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('APIS', 'remove') ?></summary>
      <form class="record" method="post" action="/ui/system/apis/remove">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="form_key" value="<?= $e($removeKey) ?>">
        <input type="hidden" name="version" value="<?= $e($version) ?>">
        <p><?= $word('APIS', 'remove_what') ?></p>
        <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('APIS', 'remove_confirm') ?></label>
        <p class="actions"><button type="submit" class="btn risky"><?= $word('APIS', 'remove_button') ?></button></p>
      </form>
    </details>
  </div>
<?php endif; ?>
<?php endif; ?>
</section>
</div>
</div>
</div>
