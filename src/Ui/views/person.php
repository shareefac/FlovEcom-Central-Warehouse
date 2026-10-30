<?php
// One user, a record page (the approved record page of 29 Oct 2026, PO_View; docs/decisions.md W13.17): Users as the eyebrow (DC3), their
// name with whether they can sign in, and for whoever manages staff the bar at the head's right (person_bar: under More, the account's switch,
// a new sign-up sheet or sign-in code or password, signing them out everywhere); what waits (a request, a reset, the set-up's time); the
// figures strip (jobs, last signed in, added, signed in now); the paper card (their e-mail beside the facts); then the related parts: their
// jobs (the form, for whoever manages staff; Save the jobs), where they are signed in now, and the history of their jobs.
$bar = $readOnly === null ? $partial('person_bar', ['person' => $person, 'placeholder' => $placeholder, 'setup' => $setup, 'addressMissing' => $addressMissing,
    'formKeys' => $formKeys, 'canManage' => $canManage, 'devices' => $devices]) : '';
$setUp = $setup['finished'] === null ? \CW\Ui\Words::STAFF[$setup['state'] === 'signup' ? 'set_up_not_yet' : 'set_up_server']
    : \CW\Ui\Words::say('STAFF', 'set_up_at', \CW\Ui\Html::when($setup['finished']['at']), (string) ($setup['finished']['ip'] ?? ''));
$facts = [['label' => \CW\Ui\Words::STAFF['jobs'], 'value' => \CW\Ui\Words::roles(array_values(array_map('strval', $roles)))],
    ['label' => \CW\Ui\Words::STAFF['can_sign_in'], 'value' => \CW\Ui\Words::STAFF[$person['is_active'] ? 'yes' : 'no']],
    ['label' => \CW\Ui\Words::STAFF['set_up'], 'value' => $setUp]];
?>
<div class="doc-head">
<?= $head((string) $person['display_name'], intro: 'person', more: $readOnly !== null ? '' : null, back: ['/ui/people', \CW\Ui\Words::MENU['people']], side: $bar,
    chips: $person['is_active'] ? $chip('done', \CW\Ui\Words::STAFF['group_active']) : $chip('off', \CW\Ui\Words::STAFF['group_off'])) ?>
</div>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'people']) ?>
<div class="set-main">
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
<?php if ($request['kind'] === 'roles'): ?>
  <p class="alert-title"><?= $say('STAFF', 'request_waiting', $jobs($request['after']), (string) ($request['requested_by_name'] ?? ''), \CW\Ui\Html::when($request['requested_at'])) ?></p>
<?php else: ?>
  <p class="alert-title"><?= $say('STAFF', 'reset_waiting', \CW\Ui\Words::of('RESET_KIND', $request['kind']), (string) ($request['requested_by_name'] ?? ''), \CW\Ui\Html::when($request['requested_at'])) ?></p>
<?php endif; ?>
<?php if ($canManage): ?>
  <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/request/withdraw') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="request" value="<?= $e($request['id']) ?>">
    <button class="btn" type="submit"><?= $word('STAFF', 'request_withdraw') ?></button>
  </form>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($approvedReset !== null): ?>
<p class="note"><?= $say('STAFF', 'reset_ok', \CW\Ui\Words::of('RESET_KIND', $approvedReset['kind']), (string) ($approvedReset['decided_by_name'] ?? ''), \CW\Ui\Html::when($approvedReset['decided_at'])) ?></p>
<?php endif; ?>
<?php if ($setup['closed_at'] !== null): ?>
<p class="note" role="alert"><?= $say('STAFF', 'setup_closed', \CW\Ui\Html::when($setup['closed_at'])) ?></p>
<?php elseif ($setup['pending']): ?>
<p class="note"><?= $word('STAFF', 'setup_pending') ?></p>
<?php elseif ($setup['until'] !== null && $setup['open']): ?>
<p class="note"><?= $say('STAFF', $setup['state'] === 'signup' ? 'setup_open' : 'password_open', \CW\Ui\Html::when($setup['until'])) ?></p>
<?php elseif ($setup['state'] === 'signup'): ?>
<p class="note"><?php if ($setup['until'] !== null): ?><?= $say('STAFF', 'setup_over', \CW\Ui\Html::when($setup['until'])) ?><?php else: ?><?= $word('STAFF', 'setup_not_done') ?><?php endif; ?></p>
<?php elseif ($setup['state'] === 'reset'): ?>
<p class="note"><?= $word('STAFF', 'code_waiting') ?></p>
<?php endif; ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('STAFF', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('STAFF', 'jobs') ?></p><p class="doc-step-big"><?= $n(count($roles)) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STAFF', 'last_signed_in') ?></p><p class="doc-step-big"><?php if ($person['last_login_at'] === null): ?><?= $word('STAFF', 'never') ?><?php else: ?><?= $day($person['last_login_at']) ?><?php endif; ?></p><?php if ($person['last_login_at'] !== null): ?><p class="step-sub"><?= $when($person['last_login_at']) ?></p><?php endif; ?></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STAFF', 'added_on') ?></p><p class="doc-step-big"><?= $day($person['created_at']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STAFF', 'devices') ?></p><p class="doc-step-big"><?= $n(count($devices)) ?></p></div>
</section>

<article class="doc-view" aria-label="<?= $e($person['display_name']) ?>">
<?= $partial('doc_party', ['party' => ['label' => \CW\Ui\Words::STAFF['email'], 'name' => (string) ($person['email'] ?? ''), 'lines' => []], 'facts' => $facts]) ?>
</article>

<?php if ($readOnly !== null): ?>
<p class="note read-only"><?= $e($readOnly) ?></p>
<?php else: ?>
<section class="doc-related" aria-labelledby="roles-h">
  <div class="board-head"><h2 id="roles-h" class="board-sub"><?= $word('STAFF', 'jobs') ?></h2></div>
  <form class="roles" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/roles') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
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
    <p class="hint"><?= $say('STAFF', 'rule', $adminCompatible) ?><?php if ($clash === null): ?> <?= $explain('admin_off', \CW\Ui\Words::ROLE['admin']) ?><?php endif; ?></p>
    <button type="submit" class="btn primary"><?= $word('STAFF', 'save') ?></button>
  </form>
</section>
<?php endif; ?>

<section class="doc-related" aria-labelledby="devices-h">
  <div class="board-head"><h2 id="devices-h" class="board-sub"><?= $word('STAFF', 'devices') ?></h2></div>
<?php if ($devices === []): ?>
  <div class="table-wrap doc-board"><p class="muted"><?= $word('STAFF', 'person_devices_none') ?></p></div>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list devices">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STAFF', 'device_since') ?></th>
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
        <th scope="row" class="c-head c-item"><?= $when($d['created_at']) ?></th>
        <td data-label="<?= $word('STAFF', 'device_seen') ?>"><?= $when($d['last_seen_at']) ?></td>
        <td data-label="<?= $word('STAFF', 'device_from') ?>"><code><?= $e($d['ip'] ?? '') ?></code></td>
<?php if ($canManage): ?>
        <td class="c-next"><form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/sign-out') ?>" data-leaves="1"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="session" value="<?= $e($d['handle']) ?>"><button class="btn sm" type="submit"><?= $word('STAFF', 'sign_out_device') ?></button></form></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section class="doc-related" aria-labelledby="history-h">
  <div class="board-head"><h2 id="history-h" class="board-sub"><?= $word('STAFF', 'history') ?></h2></div>
<?php if ($history === []): ?>
  <div class="table-wrap doc-board"><p class="muted"><?= $word('STAFF', 'no_history') ?></p></div>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list history">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STAFF', 'job') ?></th>
        <th scope="col"><?= $word('STAFF', 'given') ?></th>
        <th scope="col"><?= $word('STAFF', 'given_by') ?></th>
        <th scope="col"><?= $word('STAFF', 'taken') ?></th>
        <th scope="col"><?= $word('STAFF', 'taken_by') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($history as $h): ?>
      <tr<?php if ($h['revoked_at'] !== null): ?> class="revoked off"<?php endif; ?>>
        <th scope="row" class="c-head c-item"><?= $word('ROLE', $h['role']) ?></th>
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
</div>
</div>
