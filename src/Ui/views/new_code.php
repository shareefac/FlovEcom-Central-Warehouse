<section class="narrow login new-code">
  <h1><?= $word('PAGE_TITLE', 'new_code') ?></h1>
<?php if ($gone): ?>
  <div class="alert blocked" role="alert">
    <p class="alert-title"><?= $word('NEW_CODE', 'gone') ?></p>
  </div>
  <p><?= $word('NEW_CODE', 'gone_text') ?></p>
  <p class="hint"><a href="/ui/login"><?= $word('ENROL', 'back') ?></a></p>
  <p class="hint"><?= $word('UI', 'login_stuck') ?></p>
<?php else: ?>
  <?= $intro('new_code') ?>
<?php if ($error !== null): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <div class="alert blocked sheet-once" role="note">
    <p class="alert-title"><?= $say('NEW_CODE', 'by', \CW\Ui\Html::when($until)) ?></p>
  </div>
  <ol class="steps">
    <li><?= $word('NEW_CODE', 'step_scan') ?></li>
    <li><?= $word('NEW_CODE', 'step_old') ?></li>
    <li><?= $word('NEW_CODE', 'step_type') ?></li>
  </ol>
  <div class="qr" role="img" aria-label="<?= $word('SHEET', 'qr') ?>">
<?php foreach ($qr as $row): ?><span class="qr-row"><?php foreach ($row as $dark): ?><?php if ($dark): ?><i class="d"></i><?php else: ?><i></i><?php endif; ?><?php endforeach; ?></span>
<?php endforeach; ?>
  </div>
  <p><?= $word('SHEET', 'key') ?></p>
  <p class="setup-key"><code><?= $e($key) ?></code></p>
  <p class="muted"><?= $say('SHEET', 'account', $email) ?></p>
  <form method="post" action="/ui/new-code" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('NEW_CODE', 'code') ?>
      <span class="hint"><?= $word('ENROL', 'code_hint') ?></span>
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" required autofocus>
    </label>
<?php if ($route === 'enrol'): ?>
    <label><?= $say('ENROL', 'new', $min) ?>
      <input type="password" name="new" autocomplete="new-password" minlength="<?= $e($min) ?>" maxlength="200" required data-reveal data-show="<?= $word('SIGN_IN', 'reveal_show') ?>" data-hide="<?= $word('SIGN_IN', 'reveal_hide') ?>" data-show-label="<?= $word('SIGN_IN', 'reveal_show_label') ?>" data-hide-label="<?= $word('SIGN_IN', 'reveal_hide_label') ?>">
    </label>
    <label><?= $word('ENROL', 'again') ?>
      <input type="password" name="again" autocomplete="new-password" minlength="<?= $e($min) ?>" maxlength="200" required>
    </label>
    <button type="submit" class="primary"><?= $word('NEW_CODE', 'save_enrol') ?></button>
<?php else: ?>
    <button type="submit" class="primary"><?= $word('NEW_CODE', 'save_login') ?></button>
<?php endif; ?>
  </form>
<?php endif; ?>
</section>
