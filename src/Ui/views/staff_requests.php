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
    <dt><?= $word('STAFF_REQUESTS', 'what') ?></dt><dd><?= $word('RESET_KIND', $r['kind']) ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'email') ?></dt><dd><?= $e($r['email'] ?? '') ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'made') ?></dt><dd><?php if ($r['person_created_at'] !== null): ?><?= $when($r['person_created_at']) ?><?php endif; ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'set_up') ?></dt><dd><?php if ($r['setup']['finished'] === null): ?><span class="muted"><?= $word('STAFF_REQUESTS', $r['setup']['state'] === 'signup' ? 'set_up_not_yet' : 'set_up_server') ?></span><?php else: ?><?= $say('STAFF_REQUESTS', 'set_up_at', \CW\Ui\Html::when($r['setup']['finished']['at']), (string) ($r['setup']['finished']['ip'] ?? '')) ?><?php endif; ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'now') ?></dt><dd><?= $jobs($r['before']) ?></dd>
<?php if ($r['kind'] === 'roles'): ?>
    <dt><?= $word('STAFF_REQUESTS', 'asked') ?></dt><dd><?= $jobs($r['after']) ?></dd>
<?php endif; ?>
    <dt><?= $word('STAFF_REQUESTS', 'by') ?></dt><dd><?= $e($r['requested_by_name'] ?? '') ?></dd>
    <dt><?= $word('STAFF_REQUESTS', 'on') ?></dt><dd><?= $when($r['requested_at']) ?></dd>
  </dl>
<?php if ($r['setup']['finished'] === null && $r['setup']['state'] === 'signup'): ?>
  <p class="note"><?= $word('STAFF_REQUESTS', 'not_set_up_note') ?></p>
<?php endif; ?>
<?php if (!$r['can']): ?>
  <p class="note read-only"><?= $word('STAFF_REQUESTS', 'yours') ?></p>
<?php else: ?>
  <form class="inline" method="post" action="<?= $u('/ui/staff-requests/' . $r['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="primary"><?= $word('STAFF_REQUESTS', $r['kind'] === 'roles' ? 'ok' : 'ok_reset') ?></button>
  </form>
  <form class="record" method="post" action="<?= $u('/ui/staff-requests/' . $r['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('STAFF_REQUESTS', 'note') ?>
      <textarea name="note" rows="2" minlength="3" maxlength="500" required></textarea>
    </label>
    <p class="actions"><button type="submit"><?= $word('STAFF_REQUESTS', $r['kind'] === 'roles' ? 'not_ok' : 'not_ok_reset') ?></button></p>
  </form>
<?php endif; ?>
</article>
<?php endforeach; ?>
<?php endif; ?>
