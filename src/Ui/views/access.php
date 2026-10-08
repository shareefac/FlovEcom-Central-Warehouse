<p class="crumbs"><a href="/ui/reference/settings"><?= $word('MENU', 'settings') ?></a></p>
<h1><?= $word('PAGE_TITLE', 'access') ?></h1>
<?= $intro('access') ?>
<?php if ($canSeeStaff): ?>
<p class="see-also"><a href="/ui/people"><?= $word('ACCESS', 'people') ?></a> <a href="/ui/reference/approvals"><?= $word('MENU', 'approvals') ?></a></p>
<?php else: ?>
<p class="see-also"><a href="/ui/reference/approvals"><?= $word('MENU', 'approvals') ?></a></p>
<?php endif; ?>
<section class="card" aria-labelledby="rules-h">
  <h2 id="rules-h"><?= $word('ACCESS', 'rules') ?></h2>
  <ul>
    <li><?= $word('ACCESS', 'rule_own') ?></li>
    <li><?= $say('ACCESS', 'rule_admin', $adminCompatible) ?></li>
    <li><?= $word('ACCESS', 'rule_settings') ?></li>
    <li><?= $word('ACCESS', 'rule_approvals') ?></li>
  </ul>
</section>
<h2><?= $word('ACCESS', 'by_job') ?></h2>
<div class="jobs">
<?php foreach ($byJob as $j): ?>
  <section class="card job<?php if ($j['mine']): ?> mine<?php endif; ?>" aria-labelledby="job-<?= $e($j['role']) ?>">
    <h3 id="job-<?= $e($j['role']) ?>"><?= $e($j['name']) ?> <small class="role-code"><?= $say('STAFF', 'code', $j['role']) ?></small></h3>
    <p class="muted"><?= $e($j['help']) ?></p>
    <p><strong><?= $word('ACCESS', 'can') ?></strong></p>
    <ul>
<?php foreach ($j['can'] as $c): ?>
      <li><?= $e($c) ?></li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
</div>
<h2><?= $word('ACCESS', 'by_task') ?></h2>
<div class="table-wrap">
<table class="stack list tasks">
  <thead>
    <tr>
      <th scope="col"><?= $word('ACCESS', 'task') ?></th>
      <th scope="col"><?= $word('ACCESS', 'who') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($tasks as $t): ?>
    <tr>
      <th scope="row" class="c-head"><?= $e($t['what']) ?></th>
      <td data-label="<?= $word('ACCESS', 'who') ?>"><?= $e($t['who']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
