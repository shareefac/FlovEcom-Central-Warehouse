<p class="crumbs"><a href="<?= $u('/ui/people/' . $id) ?>"><?= $e($name) ?></a></p>
<h1><?= $say('SHEET', 'title_' . $kind, $name) ?></h1>
<?= $intro('staff_sheet') ?>
<div class="alert blocked sheet-once" role="alert">
  <p class="alert-title"><?= $word('SHEET', 'once') ?></p>
</div>
<?php if ($waiting !== null): ?>
<p class="note"><?= $say('SHEET', 'requested', $waiting) ?></p>
<?php endif; ?>
<?php if ($addressMissing): ?>
<p class="note"><?= $word('SHEET', 'address_missing') ?></p>
<?php endif; ?>
<section class="card sign-up" aria-labelledby="steps-h">
  <h2 id="steps-h"><?= $say('SHEET', 'steps', $name) ?></h2>
  <ol class="steps">
<?php if ($kind === 'signup'): ?>
    <li><?= $word('SHEET', 'step_app') ?></li>
    <li><?= $word('SHEET', 'step_scan') ?></li>
    <li><?= $say('SHEET', 'step_open', $address) ?></li>
    <li><?= $word('SHEET', 'step_type') ?></li>
    <li><?= $say('SHEET', 'step_own', $minPassword) ?></li>
<?php elseif ($kind === 'code'): ?>
    <li><?= $word('SHEET', 'step_app') ?></li>
    <li><?= $word('SHEET', 'step_scan') ?></li>
    <li><?= $say('SHEET', 'step_signin', $address) ?></li>
    <li><?= $word('SHEET', 'step_signin_own') ?></li>
<?php else: ?>
    <li><?= $say('SHEET', 'step_open', $address) ?></li>
    <li><?= $word('SHEET', 'step_password_type') ?></li>
    <li><?= $say('SHEET', 'step_own', $minPassword) ?></li>
<?php endif; ?>
<?php if ($until !== null): ?>
    <li><?= $say('SHEET', 'step_by', \CW\Ui\Html::when($until), \CW\Ui\Words::ASK) ?></li>
<?php endif; ?>
  </ol>
<?php if ($qr !== null): ?>
  <div class="qr" role="img" aria-label="<?= $word('SHEET', 'qr') ?>">
<?php foreach ($qr as $row): ?><span class="qr-row"><?php foreach ($row as $dark): ?><?php if ($dark): ?><i class="d"></i><?php else: ?><i></i><?php endif; ?><?php endforeach; ?></span>
<?php endforeach; ?>
  </div>
  <p><?= $word('SHEET', 'key') ?></p>
  <p class="setup-key"><code><?= $e($key) ?></code></p>
<?php endif; ?>
<?php if ($setupCode !== null): ?>
  <p><?= $word('SHEET', 'setup_code') ?></p>
  <p class="setup-key setup-code"><code><?= $e($setupCode) ?></code></p>
<?php endif; ?>
<?php if ($kind === 'code'): ?>
  <p class="muted"><?= $word('SHEET', 'code_note') ?></p>
<?php endif; ?>
  <p class="muted"><?= $say('SHEET', 'account', $email) ?></p>
</section>
<p><a class="btn primary" href="<?= $u('/ui/people/' . $id) ?>"><?= $say('SHEET', 'done', $name) ?></a></p>
