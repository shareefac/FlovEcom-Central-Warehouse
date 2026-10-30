<?php
// Settings › Users › Roles and permissions, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md
// W13.14): the heading (its "?": the rules that always apply); a card of who may do each thing, a row a task: what it is, the jobs that may,
// and at the right "Change who can" (its own page, saved on its own with its History), its History for whoever only looks, or "Fixed"; then
// a card of what each job may do, a row a job.
$help = \CW\Ui\Words::ACCESS['rule_own'] . ' ' . \CW\Ui\Words::say('ACCESS', 'rule_admin', $adminCompatible) . ' ' . \CW\Ui\Words::ACCESS['rule_settings'] . ' '
    . \CW\Ui\Words::ACCESS['rule_approvals'] . ' ' . \CW\Ui\Words::ACCESS['rule_give'];
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['access'], intro: 'access', more: $help) ?>
<div class="set-list">
<section class="set-card tasks" aria-labelledby="tasks-h">
  <h2 id="tasks-h"><?= $word('ACCESS', 'by_task') ?></h2>
<?php foreach ($tasks as $t): ?>
  <div class="set-row task">
    <div class="set-k">
      <p class="set-kt"><?= $e($t['what']) ?></p>
      <p class="set-kd"><?= $e($t['who']) ?></p>
    </div>
    <div class="set-v"><?php if ($t['fixed']): ?><span class="tag info"><?= $word('ACCESS', 'fixed') ?></span><?php elseif ($canChange): ?><a class="btn" href="<?= $u($t['href']) ?>"><?= $word('ACCESS', 'change_who') ?></a><?php else: ?><a class="btn ghost" href="<?= $u($t['href']) ?>"><?= $word('CONFIG', 'history') ?></a><?php endif; ?></div>
  </div>
<?php endforeach; ?>
</section>
<section class="set-card jobs" aria-labelledby="jobs-h">
  <h2 id="jobs-h"><?= $word('ACCESS', 'by_job') ?></h2>
<?php foreach ($byJob as $j): ?>
  <div class="set-row job<?php if ($j['mine']): ?> mine<?php endif; ?>" id="job-<?= $e($j['role']) ?>">
    <div class="set-k">
      <p class="set-kt"><?= $e($j['name']) ?> <small class="role-code"><?= $say('STAFF', 'code', $j['role']) ?></small></p>
      <p class="set-kd"><?= $e($j['help']) ?></p>
      <p class="set-kh"><?= $word('ACCESS', 'can') ?> <?= $e(implode(' · ', $j['can'])) ?></p>
    </div>
  </div>
<?php endforeach; ?>
</section>
</div>
