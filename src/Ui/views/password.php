<section class="narrow">
  <h1><?= $word('PAGE_TITLE', 'password') ?></h1>
<?php $forced = $who !== null && $who->mustChangePassword; ?>
<?php if ($forced): ?>
  <p class="note"><?= $word('SIGN_IN', 'welcome') ?></p>
<?php else: ?>
  <?= $intro('password') ?>
<?php endif; ?>
<?php if ($error !== null): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <form method="post" action="/ui/password" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?php if ($forced): ?><?= $word('SIGN_IN', 'one_time') ?><?php else: ?><?= $word('SIGN_IN', 'current') ?><?php endif; ?>
      <input type="password" name="current" autocomplete="current-password" maxlength="1024" required data-reveal data-show="<?= $word('SIGN_IN', 'reveal_show') ?>" data-hide="<?= $word('SIGN_IN', 'reveal_hide') ?>" data-show-label="<?= $word('SIGN_IN', 'reveal_show_label') ?>" data-hide-label="<?= $word('SIGN_IN', 'reveal_hide_label') ?>">
    </label>
    <label><?= $say('SIGN_IN', 'new', 12) ?>
      <input type="password" name="new" autocomplete="new-password" minlength="12" maxlength="200" required data-reveal data-show="<?= $word('SIGN_IN', 'reveal_show') ?>" data-hide="<?= $word('SIGN_IN', 'reveal_hide') ?>" data-show-label="<?= $word('SIGN_IN', 'reveal_show_label') ?>" data-hide-label="<?= $word('SIGN_IN', 'reveal_hide_label') ?>">
    </label>
    <label><?= $word('SIGN_IN', 'again') ?>
      <input type="password" name="again" autocomplete="new-password" minlength="12" maxlength="200" required>
    </label>
    <button type="submit" class="primary"><?= $word('SIGN_IN', 'change') ?></button>
  </form>
  <p class="hint"><?= $word('SIGN_IN', 'after') ?></p>
</section>
