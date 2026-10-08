<p class="crumbs"><a href="<?= $u('/ui/people/' . $id) ?>"><?= $e($name) ?></a></p>
<h1><?= $say('SHEET', 'title', $name) ?></h1>
<?= $intro('staff_sheet') ?>
<div class="alert blocked sheet-once" role="alert">
  <p class="alert-title"><?= $word('SHEET', 'once') ?></p>
</div>
<?php if ($waiting !== null): ?>
<p class="note"><?= $say('SHEET', 'requested', $waiting) ?></p>
<?php endif; ?>
<section class="card sign-up" aria-labelledby="steps-h">
  <h2 id="steps-h"><?= $say('SHEET', 'steps', $name) ?></h2>
  <ol class="steps">
    <li><?= $word('SHEET', 'step_app') ?></li>
    <li><?= $word('SHEET', 'step_scan') ?></li>
<?php if ($until !== null): ?>
    <li><?= $say('SHEET', 'step_open', $address) ?></li>
    <li><?= $say('SHEET', 'step_type', $minPassword) ?></li>
    <li><?= $say('SHEET', 'step_by', \CW\Ui\Html::when($until), \CW\Ui\Words::ASK) ?></li>
<?php else: ?>
    <li><?= $word('SHEET', 'step_signin') ?></li>
<?php endif; ?>
  </ol>
  <div class="qr" role="img" aria-label="<?= $word('SHEET', 'qr') ?>">
<?php foreach ($qr as $row): ?><span class="qr-row"><?php foreach ($row as $dark): ?><?php if ($dark): ?><i class="d"></i><?php else: ?><i></i><?php endif; ?><?php endforeach; ?></span>
<?php endforeach; ?>
  </div>
  <p><?= $word('SHEET', 'key') ?></p>
  <p class="setup-key"><code><?= $e($key) ?></code></p>
  <p class="muted"><?= $say('SHEET', 'account', $email) ?></p>
</section>
<p><a class="btn primary" href="<?= $u('/ui/people/' . $id) ?>"><?= $say('SHEET', 'done', $name) ?></a></p>
