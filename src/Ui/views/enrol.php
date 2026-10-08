<section class="narrow login enrol">
  <h1><?= $word('PAGE_TITLE', 'enrol') ?></h1>
  <?= $intro('enrol') ?>
<?php if ($error !== null): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <form method="post" action="/ui/enrol" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('ENROL', 'email') ?>
      <input type="email" name="email" value="<?= $e($email) ?>" autocomplete="username" maxlength="320" required autofocus>
    </label>
    <label><?= $word('ENROL', 'setup_code') ?>
      <span class="hint"><?= $word('ENROL', 'setup_code_hint') ?></span>
      <input type="text" name="setup_code" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="<?= $e($codeLength + 8) ?>" required>
    </label>
    <label><?= $word('ENROL', 'code') ?>
      <span class="hint"><?= $word('ENROL', 'code_hint') ?></span>
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" required>
    </label>
    <button type="submit" class="primary"><?= $word('ENROL', 'next') ?></button>
  </form>
  <p class="hint"><?= $word('ENROL', 'what_next') ?></p>
  <p class="hint"><a href="/ui/login"><?= $word('ENROL', 'back') ?></a></p>
  <p class="hint"><?= $word('UI', 'login_stuck') ?></p>
</section>
