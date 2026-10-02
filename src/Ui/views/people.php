<h1>People and roles</h1>
<?php foreach ($warnings as $w): ?>
<p class="note" role="alert"><?= $e($w) ?></p>
<?php endforeach; ?>
<p class="muted">New people are created on the server with <code>bin/create_staff.php --email=&lt;address&gt; --roles=&lt;role&gt;,&lt;role&gt;</code>:
  their one-time password and sign-in code are printed there once and never shown in a browser (docs/ops.md, "Staff accounts").
  Open a person to change their roles or to switch their account off.</p>
<p><a href="/ui/people.csv">Download this list (CSV)</a></p>
<table class="people">
  <thead>
    <tr>
      <th scope="col">Name</th>
      <th scope="col">E-mail</th>
      <th scope="col">Roles</th>
      <th scope="col">Active</th>
      <th scope="col">Last sign-in (UTC)</th>
      <th scope="col">Created (UTC)</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($people as $p): ?>
    <tr<?php if (!$p['is_active']): ?> class="inactive"<?php endif; ?>>
      <th scope="row"><a href="<?= $u('/ui/people/' . $p['id']) ?>"><?= $e($p['display_name']) ?></a><?php if ($p['id'] === $meId): ?> <span class="muted">(you)</span><?php endif; ?></th>
      <td><?= $e($p['email'] ?? '') ?></td>
      <td><?php foreach ($p['roles'] as $r): ?><span class="tag"><?= $e($r) ?></span><?php endforeach; ?><?php if ($p['roles'] === []): ?><span class="muted">no roles</span><?php endif; ?></td>
      <td><?php if ($p['is_active']): ?>yes<?php else: ?><span class="status">switched off</span><?php endif; ?></td>
      <td><?= $dt($p['last_login_at']) ?></td>
      <td><?= $dt($p['created_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
