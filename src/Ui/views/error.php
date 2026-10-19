<section class="narrow error-page" data-code="<?= $e($code) ?>">
  <?= $head($heading, eyebrow: \CW\Ui\Words::say('UI', 'error_eyebrow', (string) $status)) ?>
  <p class="error" role="alert"><?= $e($message) ?></p>
  <p class="muted rid"><?= $word('UI', 'quote_rid') ?> <code><?= $e($rid) ?></code></p>
<?php if ($who !== null): ?>
  <p class="actions"><?php if (($back ?? null) !== null): ?><a class="btn primary" href="<?= $u($back[0]) ?>"><?= $e($back[1]) ?></a> <?php endif; ?><a class="btn secondary" href="/ui/"><?= $word('UI', 'back_home') ?></a></p>
<?php else: ?>
  <p><a class="btn secondary" href="/ui/login"><?= $word('UI', 'back_sign_in') ?></a></p>
<?php endif; ?>
</section>
