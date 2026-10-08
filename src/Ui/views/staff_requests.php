<h1><?= $word('PAGE_TITLE', 'staff_requests') ?></h1>
<?= $intro('staff_requests') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($requests === []): ?>
<?= $empty(\CW\Ui\Words::STAFF_REQUESTS['none']) ?>
<?php else: ?>
<?php foreach ($requests as $r): ?>
<article class="card request" id="request-<?= $e($r['id']) ?>">
  <h2><?= $e($r['person']) ?></h2>
<?php if ($r['error'] !== null): ?>
  <p class="error" role="alert"><?= $e($r['error']) ?></p>
<?php endif; ?>
  <dl class="wide">
    <dt><?= $word('STAFF_REQUESTS', 'now') ?></dt><dd><?= $jobs($r['before']) ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'asked') ?></dt><dd><?= $jobs($r['after']) ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'by') ?></dt><dd><?= $e($r['requested_by_name'] ?? '') ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'on') ?></dt><dd><?= $when($r['requested_at']) ?></dd>
  </dl>
<?php if (!$r['can']): ?>
  <p class="note read-only"><?= $word('STAFF_REQUESTS', 'yours') ?></p>
<?php else: ?>
  <form class="inline" method="post" action="<?= $u('/ui/staff-requests/' . $r['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="primary"><?= $word('STAFF_REQUESTS', 'ok') ?></button>
  </form>
  <form class="record" method="post" action="<?= $u('/ui/staff-requests/' . $r['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('STAFF_REQUESTS', 'note') ?>
      <textarea name="note" rows="2" minlength="3" maxlength="500" required></textarea>
    </label>
    <p class="actions"><button type="submit"><?= $word('STAFF_REQUESTS', 'not_ok') ?></button></p>
  </form>
<?php endif; ?>
</article>
<?php endforeach; ?>
<?php endif; ?>
