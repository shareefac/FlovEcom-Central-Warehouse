<p class="crumbs"><a href="/ui/people"><?= $word('MENU', 'people') ?></a></p>
<h1><?= $e($person['display_name']) ?></h1>
<?php if ($readOnly !== null): ?>
<p class="lede"><?= $e(\CW\Ui\Words::PAGE_INTRO['person'][0]) ?></p>
<?php else: ?>
<?= $intro('person') ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($clash !== null): ?>
<div class="alert needs person-clash" role="note">
  <p class="alert-title"><?= $e($clash) ?></p>
  <?= $explain('admin_off', \CW\Ui\Words::UI['off']) ?>
</div>
<?php endif; ?>
<?php if ($request !== null): ?>
<div class="alert waiting person-request" role="note">
  <p class="alert-title"><?= $say('STAFF', 'request_waiting', $jobs($request['after']), (string) ($request['requested_by_name'] ?? ''), \CW\Ui\Html::when($request['requested_at'])) ?></p>
<?php if ($canManage): ?>
  <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/request/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="request" value="<?= $e($request['id']) ?>">
    <button type="submit"><?= $word('STAFF', 'request_withdraw') ?></button>
  </form>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($setup !== null): ?>
<p class="note"><?php if ($setup['open']): ?><?= $say('STAFF', 'setup_open', \CW\Ui\Html::when($setup['until'])) ?><?php else: ?><?= $say('STAFF', 'setup_over', \CW\Ui\Html::when($setup['until'])) ?><?php endif; ?></p>
<?php endif; ?>
<dl class="wide">
  <dt><?= $word('STAFF', 'email') ?></dt><dd><?= $e($person['email'] ?? '') ?></dd>
  <dt><?= $word('STAFF', 'jobs') ?></dt><dd><?= $jobs($roles) ?></dd>
  <dt><?= $word('STAFF', 'can_sign_in') ?></dt><dd><?php if ($person['is_active']): ?><?= $chip('done', \CW\Ui\Words::STAFF['yes']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::STAFF['no']) ?><?php endif; ?></dd>
  <dt><?= $word('STAFF', 'last_signed_in') ?></dt><dd><?php if ($person['last_login_at'] === null): ?><span class="muted"><?= $word('STAFF', 'never') ?></span><?php else: ?><?= $when($person['last_login_at']) ?><?php endif; ?></dd>
  <dt><?= $word('STAFF', 'added_on') ?></dt><dd><?= $day($person['created_at']) ?></dd>
</dl>

<?php if ($readOnly !== null): ?>
<p class="note read-only"><?= $e($readOnly) ?></p>
<?php else: ?>
<section aria-labelledby="roles-h">
  <h2 id="roles-h"><?= $word('STAFF', 'jobs') ?></h2>
  <form class="roles" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/roles') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="roles_seen" value="<?= $e($rolesSeen) ?>">
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
    <div class="head-help">
      <p class="muted"><?= $say('STAFF', 'rule', $adminCompatible) ?></p>
<?php if ($clash === null): ?>
      <?= $explain('admin_off', \CW\Ui\Words::ROLE['admin']) ?>
<?php endif; ?>
    </div>
    <button type="submit" class="primary"><?= $word('STAFF', 'save') ?></button>
  </form>
</section>

<section aria-labelledby="account-h">
  <h2 id="account-h"><?= $word('STAFF', 'account') ?></h2>
<?php if (!$person['is_active'] && $placeholder): ?>
  <p class="note read-only"><?= $word('STAFF', 'test_off') ?></p>
<?php else: ?>
  <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/active') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($person['is_active']): ?>
    <input type="hidden" name="active" value="0">
    <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('STAFF', 'stop_confirm') ?></label>
    <button type="submit" class="danger"><?= $word('STAFF', 'stop') ?></button>
    <span class="muted"><?= $word('STAFF', 'stop_note') ?></span>
<?php else: ?>
    <input type="hidden" name="active" value="1">
    <button type="submit"><?= $word('STAFF', 'allow') ?></button>
    <span class="muted"><?= $word('STAFF', 'allow_note') ?></span>
<?php endif; ?>
  </form>
<?php endif; ?>
<?php if (!$placeholder): ?>
  <div class="head-help">
    <h3><?= $word('STAFF', 'new_code') ?></h3>
    <?= $explain('sign_up', \CW\Ui\Words::STAFF['new_code']) ?>
  </div>
  <p class="muted"><?= $word('STAFF', 'new_code_text') ?></p>
  <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/authenticator') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('STAFF', 'new_code_confirm') ?></label>
    <button type="submit" class="danger"><?= $word('STAFF', 'new_code') ?></button>
  </form>
  <h3><?= $word('STAFF', 'new_password') ?></h3>
  <p class="muted"><?= $word('STAFF', 'new_password_text') ?></p>
  <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/password') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('STAFF', 'new_password_confirm') ?></label>
    <button type="submit" class="danger"><?= $word('STAFF', 'new_password') ?></button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="devices-h">
  <h2 id="devices-h"><?= $word('STAFF', 'devices') ?></h2>
<?php if ($devices === []): ?>
  <p class="muted"><?= $word('STAFF', 'person_devices_none') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list devices">
    <thead>
      <tr>
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
        <th scope="row" class="c-head"><?= $when($d['created_at']) ?></th>
        <td data-label="<?= $word('STAFF', 'device_seen') ?>"><?= $when($d['last_seen_at']) ?></td>
        <td data-label="<?= $word('STAFF', 'device_from') ?>"><code><?= $e($d['ip'] ?? '') ?></code></td>
<?php if ($canManage): ?>
        <td class="c-next"><form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/sign-out') ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="session" value="<?= $e($d['handle']) ?>"><button type="submit"><?= $word('STAFF', 'sign_out_device') ?></button></form></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php if ($canManage): ?>
  <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/sign-out') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="session" value="all">
    <button type="submit" class="danger"><?= $word('STAFF', 'sign_out_all') ?></button>
    <span class="muted"><?= $word('STAFF', 'sign_out_all_note') ?></span>
  </form>
<?php endif; ?>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('STAFF', 'history') ?></h2>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('STAFF', 'no_history') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack history">
    <thead>
      <tr>
        <th scope="col"><?= $word('STAFF', 'job') ?></th>
        <th scope="col"><?= $word('STAFF', 'given') ?></th>
        <th scope="col"><?= $word('STAFF', 'given_by') ?></th>
        <th scope="col"><?= $word('STAFF', 'taken') ?></th>
        <th scope="col"><?= $word('STAFF', 'taken_by') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($history as $h): ?>
      <tr<?php if ($h['revoked_at'] !== null): ?> class="revoked off"<?php endif; ?>>
        <th scope="row" class="c-head"><?= $word('ROLE', $h['role']) ?></th>
        <td data-label="<?= $word('STAFF', 'given') ?>"><?= $when($h['granted_at']) ?></td>
        <td data-label="<?= $word('STAFF', 'given_by') ?>"><?= $e($h['granted_by']) ?></td>
        <td data-label="<?= $word('STAFF', 'taken') ?>"><?php if ($h['revoked_at'] === null): ?><span class="muted"><?= $word('STAFF', 'held_now') ?></span><?php else: ?><?= $when($h['revoked_at']) ?><?php endif; ?></td>
        <td data-label="<?= $word('STAFF', 'taken_by') ?>"><?= $e($h['revoked_by'] ?? '') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
