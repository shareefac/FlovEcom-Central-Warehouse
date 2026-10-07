<h1><?= $word('MENU', 'people') ?></h1>
<?php if ($lookOnly !== null): ?>
<p class="lede"><?= $e(\CW\Ui\Words::PAGE_INTRO['people'][0]) ?> <?= $e($lookOnly) ?></p>
<?php else: ?>
<?= $intro('people') ?>
<?php endif; ?>
<?php foreach ($warnings as $warning): ?>
<p class="note" role="alert"><?= $e($warning) ?></p>
<?php endforeach; ?>
<?php if ($clash): ?>
<div class="head-help">
  <p class="muted"><?= $word('STAFF', 'off_note') ?></p>
  <?= $explain('admin_off', \CW\Ui\Words::UI['off']) ?>
</div>
<?php endif; ?>
<?php if ($lookOnly === null): ?>
<p class="muted"><?= $word('STAFF', 'add') ?></p>
<?php endif; ?>
<p><a href="/ui/people.csv"><?= $word('STAFF', 'download') ?></a></p>
<div class="table-wrap">
<table class="stack list people">
  <thead>
    <tr>
      <th scope="col"><?= $word('STAFF', 'name') ?></th>
      <th scope="col"><?= $word('STAFF', 'can_sign_in') ?></th>
      <th scope="col"><?= $word('STAFF', 'jobs') ?></th>
      <th scope="col"><?= $word('STAFF', 'email') ?></th>
      <th scope="col"><?= $word('STAFF', 'last_signed_in') ?></th>
      <th scope="col"><?= $word('STAFF', 'added_on') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($people as $p): ?>
    <tr class="<?php if (!$p['is_active']): ?>inactive off<?php elseif (\CW\Auth\Permissions::switchedOff($p['roles']) !== []): ?>needs<?php else: ?>done<?php endif; ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/people/' . $p['id']) ?>"><?= $e($p['display_name']) ?></a><?php if ($p['id'] === $meId): ?> <span class="muted"><?= $word('STAFF', 'you') ?></span><?php endif; ?></th>
      <td class="c-status"><?php if ($p['is_active']): ?><?= $chip('done', \CW\Ui\Words::STAFF['yes']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::STAFF['no']) ?><?php endif; ?></td>
      <td data-label="<?= $word('STAFF', 'jobs') ?>"><?= $jobs($p['roles']) ?></td>
      <td data-label="<?= $word('STAFF', 'email') ?>"><?= $e($p['email'] ?? '') ?></td>
      <td data-label="<?= $word('STAFF', 'last_signed_in') ?>"><?php if ($p['last_login_at'] === null): ?><span class="muted"><?= $word('STAFF', 'never') ?></span><?php else: ?><?= $when($p['last_login_at']) ?><?php endif; ?></td>
      <td data-label="<?= $word('STAFF', 'added_on') ?>"><?= $day($p['created_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
