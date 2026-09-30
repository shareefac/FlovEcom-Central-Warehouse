<section class="narrow login">
  <h1>Central Warehouse</h1>
  <p class="muted">Staff sign-in</p>
<?php if ($error !== null): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <form method="post" action="/ui/login" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>E-mail address
      <input type="email" name="email" value="<?= $e($email) ?>" autocomplete="username" maxlength="320" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" maxlength="1024" required>
    </label>
    <label>6-digit code from your authenticator app
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" required>
    </label>
    <button type="submit" class="primary">Sign in</button>
  </form>
</section>
