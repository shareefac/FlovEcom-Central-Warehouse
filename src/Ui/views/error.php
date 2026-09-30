<section class="narrow">
  <h1>Error <?= $e($status) ?></h1>
  <p class="error" role="alert"><?= $e($message) ?></p>
  <p class="muted">Code <code><?= $e($code) ?></code> &middot; request <code><?= $e($rid) ?></code></p>
  <p><a href="/ui/">Back to the dashboard</a></p>
</section>
