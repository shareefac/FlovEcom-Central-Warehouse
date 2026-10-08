<section class="narrow login">
  <h1><?= $word('UI', 'brand') ?></h1>
  <?= $intro('login') ?>
<?php if (($lost ?? false) === true): ?>
  <div class="alert blocked lost" role="alert">
    <p class="alert-title"><?= $word('UI', 'lost') ?></p>
  </div>
<?php elseif (($signedOut ?? false) === true && $error === null): ?>
  <p class="note" role="status"><?= $word('UI', 'signed_out') ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
  <p class="error" role="alert"<?php if (($expired ?? false) === true): ?> data-code="csrf"<?php endif; ?>><?= $e($error) ?></p>
<?php endif; ?>
  <form method="post" action="/ui/login" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if (($back ?? null) !== null): ?>
    <input type="hidden" name="back" value="<?= $e($back) ?>">
<?php endif; ?>
    <label><?= $word('SIGN_IN', 'email_label') ?>
      <input type="email" name="email" value="<?= $e($email) ?>" autocomplete="username" maxlength="320" required autofocus>
    </label>
    <label><?= $word('SIGN_IN', 'password_label') ?>
      <input type="password" name="password" autocomplete="current-password" maxlength="1024" required data-reveal data-show="<?= $word('SIGN_IN', 'reveal_show') ?>" data-hide="<?= $word('SIGN_IN', 'reveal_hide') ?>" data-show-label="<?= $word('SIGN_IN', 'reveal_show_label') ?>" data-hide-label="<?= $word('SIGN_IN', 'reveal_hide_label') ?>">
    </label>
    <label><?= $word('SIGN_IN', 'code_label') ?>
      <span class="hint"><?= $word('SIGN_IN', 'code_hint') ?></span>
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" required>
    </label>
    <button type="submit" class="primary"><?= $word('SIGN_IN', 'sign_in') ?></button>
  </form>
  <p class="hint"><?= $word('UI', 'login_first_time') ?></p>
  <p class="hint"><a href="/ui/enrol"><?= $word('ENROL', 'link') ?></a></p>
  <p class="hint"><?= $word('UI', 'login_stuck') ?></p>
</section>
