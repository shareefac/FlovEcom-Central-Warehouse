<div class="signin">
  <aside class="signin-brand" aria-label="<?= $word('SIGN_IN', 'brand_label') ?>">
    <span class="sb-shape one" aria-hidden="true"></span><span class="sb-shape two" aria-hidden="true"></span>
    <div class="sb-body">
      <div class="sb-logo" aria-hidden="true"><span class="brand-mark">CW</span></div>
      <p class="sb-eyebrow"><?= $word('SIGN_IN', 'brand_eyebrow') ?></p>
      <p class="sb-headline"><?= $word('SIGN_IN', 'brand_headline') ?></p>
      <p class="sb-line"><?= $word('SIGN_IN', 'brand_line') ?></p>
      <ul class="sb-bars" aria-hidden="true"><li></li><li></li><li></li><li></li></ul>
      <p class="sb-powered"><?= $word('SIGN_IN', 'powered_by') ?></p>
    </div>
  </aside>
  <section class="signin-form login">
    <div class="sf-inner">
      <p class="sf-logo"><span class="brand-mark" aria-hidden="true">CW</span><span><?= $word('UI', 'brand') ?></span></p>
      <?= $head(\CW\Ui\Words::PAGE_TITLE['login'], intro: ($codeOffUntil ?? null) === null ? 'login' : 'login_no_code', eyebrow: '') ?>
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
<?php if (($codeOffUntil ?? null) !== null): ?>
      <p class="note code-off" role="status"><?= $say('SIGN_IN', 'code_off', \CW\Ui\Html::when($codeOffUntil)) ?></p>
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
<?php if (($codeOffUntil ?? null) === null): ?>
        <label><?= $word('SIGN_IN', 'code_label') ?>
          <span class="hint"><?= $word('SIGN_IN', 'code_hint') ?></span>
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" required>
        </label>
<?php endif; ?>
        <button type="submit" class="btn primary"><?= $word('SIGN_IN', 'sign_in') ?></button>
      </form>
      <p class="hint"><?= $word('UI', 'login_stuck') ?></p>
      <p class="sf-or"><?= $word('SIGN_IN', 'or') ?></p>
      <a class="btn" href="/ui/enrol"><?= $word('SIGN_IN', 'set_up') ?></a>
      <p class="hint"><?= $word('UI', 'login_first_time') ?></p>
      <p class="hint"><?= $word('SIGN_IN', 'no_account') ?></p>
    </div>
  </section>
</div>
