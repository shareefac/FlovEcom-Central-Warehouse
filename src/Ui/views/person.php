<p class="crumbs"><a href="/ui/people">People and roles</a></p>
<h1><?= $e($person['display_name']) ?></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<dl class="wide">
  <dt>E-mail</dt><dd><?= $e($person['email'] ?? '') ?></dd>
  <dt>Roles now</dt><dd><?php foreach ($roles as $r): ?><span class="tag"><?= $e($r) ?></span><?php endforeach; ?><?php if ($roles === []): ?><span class="muted">no roles</span><?php endif; ?></dd>
  <dt>Account</dt><dd><?php if ($person['is_active']): ?>active<?php else: ?><span class="status">switched off</span><?php endif; ?></dd>
  <dt>Last sign-in (UTC)</dt><dd><?= $dt($person['last_login_at']) ?></dd>
  <dt>Created (UTC)</dt><dd><?= $dt($person['created_at']) ?></dd>
</dl>

<?php if ($readOnly !== null): ?>
<p class="note read-only"><?= $e($readOnly) ?></p>
<?php else: ?>
<section aria-labelledby="roles-h">
  <h2 id="roles-h">Roles</h2>
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
          <span class="role-name"><?= $e($r['role']) ?></span>
          <span class="role-desc"><?= $e($r['description']) ?></span>
        </label>
<?php endforeach; ?>
      </fieldset>
<?php endforeach; ?>
    </div>
    <p class="muted">admin can be combined only with <?= $e($adminCompatible) ?>: the person who manages people and roles never posts,
      reviews or decides. A role taken away stops working on the person's next page; every change is kept in the history below.</p>
    <button type="submit" class="primary">Save roles</button>
  </form>
</section>

<section aria-labelledby="account-h">
  <h2 id="account-h">Account</h2>
<?php if (!$person['is_active'] && $placeholder): ?>
  <p class="note read-only">This is a placeholder account (an e-mail under .invalid): it is never switched on or given a role from a
    browser, because it would be a working second identity that defeats the two-person rule. Create a real account on the server instead.</p>
<?php else: ?>
  <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/active') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($person['is_active']): ?>
    <input type="hidden" name="active" value="0">
    <button type="submit">Switch the account off</button>
    <span class="muted">The person can no longer sign in, and every session of theirs ends at once.</span>
<?php else: ?>
    <input type="hidden" name="active" value="1">
    <button type="submit">Switch the account on</button>
    <span class="muted">The person can sign in again with their current password and code.</span>
<?php endif; ?>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h">Role history</h2>
<?php if ($history === []): ?>
  <p class="muted">No role was ever given to this person.</p>
<?php else: ?>
  <table class="history">
    <thead>
      <tr>
        <th scope="col">Role</th>
        <th scope="col">Given (UTC)</th>
        <th scope="col">Given by</th>
        <th scope="col">Taken away (UTC)</th>
        <th scope="col">Taken away by</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($history as $h): ?>
      <tr<?php if ($h['revoked_at'] !== null): ?> class="revoked"<?php endif; ?>>
        <th scope="row"><?= $e($h['role']) ?></th>
        <td><?= $dt($h['granted_at']) ?></td>
        <td><?= $e($h['granted_by']) ?></td>
        <td><?php if ($h['revoked_at'] === null): ?><span class="muted">held now</span><?php else: ?><?= $dt($h['revoked_at']) ?><?php endif; ?></td>
        <td><?= $e($h['revoked_by'] ?? '') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
