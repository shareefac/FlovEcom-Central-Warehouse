<?php
// Settings › Users, a board (the approved board of 29 Oct 2026, pattern 1, GI_List; docs/decisions.md W13.16): the heading with "+ New user"
// at its right for whoever adds people (its form opens here, `#new`); the warnings (no reviewer, no admin, a test account, jobs Admin
// switches off), the staff requests waiting; one search (a name or an e-mail) and one Show (everyone, can sign in, switched off), the
// spreadsheet file, Roles and permissions and Clear under the toolbar's More; then the users in two groups, can sign in and switched off,
// each a coloured heading with its count over a card with its colour on the left edge, the status cell filled with it; a row opens the
// person; the quiet "+ Add a staff member" ends the first group. Under it, everyone signed in now, as a related board.
$groupsShown = [['key' => 'active', 'tone' => 'done', 'rows' => array_values(array_filter($people, static fn (array $x): bool => (bool) $x['is_active']))],
    ['key' => 'off', 'tone' => 'off', 'rows' => array_values(array_filter($people, static fn (array $x): bool => !$x['is_active']))]];
?>
<?= $head(\CW\Ui\Words::MENU['people'], intro: 'people', more: $lookOnly) ?>
<?php foreach ($warnings as $warning): ?>
<p class="note" role="alert"><?= $e($warning) ?></p>
<?php endforeach; ?>
<?php if ($clash): ?>
<p class="note"><?= $word('STAFF', 'off_note') ?> <?= $explain('admin_off', \CW\Ui\Words::UI['off']) ?></p>
<?php endif; ?>
<?php if ($requests > 0): ?>
<p class="note"><?= $say('STAFF', 'requests_open', $requests) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<form class="toolbar" method="get" action="/ui/people" aria-label="<?= $word('UI', 'filter') ?>"<?php if ($canManage): ?> data-leaves="1"<?php endif; ?>>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STAFF', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('STAFF', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('STAFF', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value=""><?= $word('STAFF', 'show_all') ?></option>
      <option value="on"<?php if ($show === 'on'): ?> selected<?php endif; ?>><?= $word('STAFF', 'group_active') ?></option>
      <option value="off"<?php if ($show === 'off'): ?> selected<?php endif; ?>><?= $word('STAFF', 'group_off') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
        <a class="menu-item" href="/ui/people.csv" download><?= $word('STAFF', 'download') ?></a>
        <a class="menu-item" href="/ui/reference/access"><?= $word('PAGE_TITLE', 'access') ?></a>
<?php if ($filtered): ?>
        <a class="menu-item" href="/ui/people"><?= $word('STAFF', 'clear') ?></a>
<?php endif; ?>
      </div>
    </details>
  </div>
</form>
<?php if ($canManage): ?>
<details class="fold add-person drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('STAFF', 'add_title') ?></summary>
  <p class="hint"><?= $word('STAFF', 'add_text') ?> <?= $explain('sign_up', \CW\Ui\Words::STAFF['add_title']) ?></p>
  <form class="roles" method="post" action="/ui/people" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
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
    <button type="submit" class="btn primary"><?= $word('STAFF', 'add_button') ?></button>
  </form>
</details>
<?php endif; ?>

<?php if ($people === []): ?>
<?= $empty(\CW\Ui\Words::STAFF['none_filtered'], \CW\Ui\Words::STAFF['none_filtered_text'], '/ui/people', \CW\Ui\Words::STAFF['clear']) ?>
<?php endif; ?>
<?php foreach ($groupsShown as $g): ?>
<?php if ($g['rows'] !== [] || ($g['key'] === 'active' && $canManage && !$filtered)): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $word('STAFF', 'group_' . $g['key']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('STAFF', 'count_one') ?><?php else: ?><?= $say('STAFF', 'count_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list people">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STAFF', 'name') ?></th>
        <th scope="col" class="c-status"><?= $word('STAFF', 'can_sign_in') ?></th>
        <th scope="col"><?= $word('STAFF', 'jobs') ?></th>
        <th scope="col"><?= $word('STAFF', 'email') ?></th>
        <th scope="col"><?= $word('STAFF', 'last_signed_in') ?></th>
        <th scope="col"><?= $word('STAFF', 'added_on') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $p): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/people/' . $p['id']) ?>"><?= $e($p['display_name']) ?></a><?php if ($p['id'] === $meId): ?> <span class="muted"><?= $word('STAFF', 'you') ?></span><?php endif; ?></th>
        <td data-label="<?= $word('STAFF', 'can_sign_in') ?>" class="c-status doc-st <?php if (!$p['is_active']): ?>off<?php elseif (\CW\Auth\Permissions::switchedOff($p['roles']) !== []): ?>needs<?php else: ?>done<?php endif; ?>"><?php if ($p['is_active']): ?><?= $word('STAFF', 'yes') ?><?php else: ?><?= $word('STAFF', 'no') ?><?php endif; ?></td>
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
  </table>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<section class="doc-related" aria-labelledby="devices-h">
  <div class="board-head"><h2 id="devices-h" class="board-sub"><?= $word('STAFF', 'devices') ?></h2></div>
<?php if ($devices === []): ?>
  <div class="table-wrap doc-board"><p class="muted"><?= $word('STAFF', 'devices_none') ?></p></div>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list devices">
    <caption class="visually-hidden"><?= $word('STAFF', 'devices_text') ?></caption>
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STAFF', 'device_person') ?></th>
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
        <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/people/' . $d['staff_user_id']) ?>"><?= $e($d['name']) ?></a><?php if ($d['staff_user_id'] === $meId): ?> <span class="muted"><?= $word('STAFF', 'you') ?></span><?php endif; ?></th>
        <td data-label="<?= $word('STAFF', 'device_since') ?>"><?= $when($d['created_at']) ?></td>
        <td data-label="<?= $word('STAFF', 'device_seen') ?>"><?= $when($d['last_seen_at']) ?></td>
        <td data-label="<?= $word('STAFF', 'device_from') ?>"><code><?= $e($d['ip'] ?? '') ?></code></td>
<?php if ($canManage): ?>
        <td class="c-next"><?php if ($d['staff_user_id'] !== $meId): ?><form class="inline" method="post" action="<?= $u('/ui/people/' . $d['staff_user_id'] . '/sign-out') ?>" data-leaves="1"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="session" value="<?= $e($d['handle']) ?>"><input type="hidden" name="back" value="list"><button class="btn sm" type="submit"><?= $word('STAFF', 'sign_out_device') ?></button></form><?php endif; ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
