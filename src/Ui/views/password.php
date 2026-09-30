<section class="narrow">
  <h1>Change password</h1>
<?php if ($who !== null && $who->mustChangePassword): ?>
  <p class="note">You have to choose a new password before you can use the screens.</p>
<?php endif; ?>
<?php if ($error !== null): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <form method="post" action="/ui/password" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Current password
      <input type="password" name="current" autocomplete="current-password" maxlength="1024" required>
    </label>
    <label>New password (12 characters or more)
      <input type="password" name="new" autocomplete="new-password" minlength="12" maxlength="200" required>
    </label>
    <label>New password again
      <input type="password" name="again" autocomplete="new-password" minlength="12" maxlength="200" required>
    </label>
    <button type="submit" class="primary">Change password</button>
  </form>
  <p class="muted">Changing it signs you out everywhere else, and this browser continues on a new session.</p>
</section>
