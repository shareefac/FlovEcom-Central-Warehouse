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
<?php if ($requests > 0): ?>
<p class="note"><?= $say('STAFF', 'requests_open', $requests) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canManage): ?>
<p class="muted"><?= $word('STAFF', 'add') ?></p>
<details class="fold add-person" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('STAFF', 'add_title') ?></summary>
  <div class="head-help">
    <p class="muted"><?= $word('STAFF', 'add_text') ?></p>
    <?= $explain('sign_up', \CW\Ui\Words::STAFF['add_title']) ?>
  </div>
  <form class="roles" method="post" action="/ui/people">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('STAFF', 'add_name') ?>
      <input type="text" name="name" value="<?= $e($typed['name']) ?>" maxlength="128" required<?php if ($errorCode === 'bad_name'): ?> aria-invalid="true"<?php endif; ?>>
    </label>
    <label><?= $word('STAFF', 'add_email') ?>
      <input type="email" name="email" value="<?= $e($typed['email']) ?>" maxlength="191" autocomplete="off" required<?php if (in_array($errorCode, ['bad_email', 'staff_exists'], true)): ?> aria-invalid="true"<?php endif; ?>>
    </label>
    <p><strong><?= $word('STAFF', 'add_jobs') ?></strong></p>
    <div class="role-grid">
<?php foreach ($groups as $g): ?>
      <fieldset class="role-group">
        <legend><?= $e($g['label']) ?></legend>
<?php foreach ($g['roles'] as $r): ?>
        <label class="role-choice">
          <input type="checkbox" name="role_<?= $e($r['role']) ?>" value="1"<?php if ($r['checked']): ?> checked<?php endif; ?>>
          <span class="role-name"><?= $e($r['name']) ?> <small class="role-code"><?= $say('STAFF', 'code', $r['role']) ?></small></span>
          <span class="role-desc"><?= $e($r['description']) ?></span>
        </label>
<?php endforeach; ?>
      </fieldset>
<?php endforeach; ?>
    </div>
    <button type="submit" class="primary"><?= $word('STAFF', 'add_button') ?></button>
  </form>
</details>
<?php endif; ?>
<p><a href="/ui/people.csv"><?= $word('STAFF', 'download') ?></a> <a href="/ui/reference/access"><?= $word('PAGE_TITLE', 'access') ?></a></p>
<div class="board-head"><h2 class="board-title"><?= $word('STAFF', 'board') ?></h2></div>
<div class="table-wrap">
<table class="stack list people board">
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
<?php foreach ([['key' => 'active', 'tone' => 'done', 'rows' => array_values(array_filter($people, static fn (array $x): bool => (bool) $x['is_active']))],
    ['key' => 'off', 'tone' => 'waiting', 'rows' => array_values(array_filter($people, static fn (array $x): bool => !$x['is_active']))]] as $g): ?>
<?php if ($g['rows'] !== [] || ($g['key'] === 'active' && $canManage)): ?>
  <tbody class="grp <?= $e($g['tone']) ?>" id="g-<?= $e($g['key']) ?>">
    <tr class="grp-head"><th colspan="6" scope="rowgroup"><span class="grp-title <?= $e($g['tone']) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $word('STAFF', 'group_' . $g['key']) ?></button><span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('STAFF', 'count_one') ?><?php else: ?><?= $say('STAFF', 'count_many', count($g['rows'])) ?><?php endif; ?></span></span></th></tr>
<?php foreach ($g['rows'] as $p): ?>
    <tr class="<?php if (!$p['is_active']): ?>inactive off<?php elseif (\CW\Auth\Permissions::switchedOff($p['roles']) !== []): ?>needs<?php else: ?>done<?php endif; ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/people/' . $p['id']) ?>"><?= $e($p['display_name']) ?></a><?php if ($p['id'] === $meId): ?> <span class="muted"><?= $word('STAFF', 'you') ?></span><?php endif; ?></th>
      <td class="c-status"><?php if ($p['is_active']): ?><?= $chip('done', \CW\Ui\Words::STAFF['yes']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::STAFF['no']) ?><?php endif; ?></td>
      <td data-label="<?= $word('STAFF', 'jobs') ?>"><?= $jobs($p['roles']) ?></td>
      <td data-label="<?= $word('STAFF', 'email') ?>"><?= $e($p['email'] ?? '') ?></td>
      <td data-label="<?= $word('STAFF', 'last_signed_in') ?>"><?php if ($p['last_login_at'] === null): ?><span class="muted"><?= $word('STAFF', 'never') ?></span><?php else: ?><?= $when($p['last_login_at']) ?><?php endif; ?></td>
      <td data-label="<?= $word('STAFF', 'added_on') ?>"><?= $day($p['created_at']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($g['key'] === 'active' && $canManage): ?>
    <tr class="grp-add"><td colspan="6" data-label=""><a class="add-item" href="#new"><?= $word('STAFF', 'add_row') ?></a></td></tr>
<?php endif; ?>
  </tbody>
<?php endif; ?>
<?php endforeach; ?>
</table>
</div>

<section aria-labelledby="devices-h">
  <h2 id="devices-h"><?= $word('STAFF', 'devices') ?></h2>
<?php if ($devices === []): ?>
  <p class="muted"><?= $word('STAFF', 'devices_none') ?></p>
<?php else: ?>
  <p class="muted"><?= $word('STAFF', 'devices_text') ?></p>
  <div class="table-wrap">
  <table class="stack list devices">
    <thead>
      <tr>
        <th scope="col"><?= $word('STAFF', 'device_person') ?></th>
        <th scope="col"><?= $word('STAFF', 'device_since') ?></th>
        <th scope="col"><?= $word('STAFF', 'device_seen') ?></th>
        <th scope="col"><?= $word('STAFF', 'device_from') ?></th>
<?php if ($canManage): ?>
        <th scope="col"><span class="visually-hidden"><?= $word('STAFF', 'sign_out_device') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($devices as $d): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/people/' . $d['staff_user_id']) ?>"><?= $e($d['name']) ?></a><?php if ($d['staff_user_id'] === $meId): ?> <span class="muted"><?= $word('STAFF', 'you') ?></span><?php endif; ?></th>
        <td data-label="<?= $word('STAFF', 'device_since') ?>"><?= $when($d['created_at']) ?></td>
        <td data-label="<?= $word('STAFF', 'device_seen') ?>"><?= $when($d['last_seen_at']) ?></td>
        <td data-label="<?= $word('STAFF', 'device_from') ?>"><code><?= $e($d['ip'] ?? '') ?></code></td>
<?php if ($canManage): ?>
        <td class="c-next"><?php if ($d['staff_user_id'] !== $meId): ?><form class="inline" method="post" action="<?= $u('/ui/people/' . $d['staff_user_id'] . '/sign-out') ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="session" value="<?= $e($d['handle']) ?>"><input type="hidden" name="back" value="list"><button type="submit"><?= $word('STAFF', 'sign_out_device') ?></button></form><?php endif; ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
