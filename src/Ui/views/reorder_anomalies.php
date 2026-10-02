<?php $v = $typed ?? []; ?>
<p class="crumbs"><a href="/ui/purchasing/reorder">Reorder list</a></p>
<h1>Reorder: anomaly windows</h1>
<p class="muted">Days whose sales are not trusted as demand (stockpiling before a duty, a one-off event): they are left out of every item's demand,
  on one site or all, for one brand or all. A window covers at most <?= $n($maxDays) ?> days. Changes count at the next recalculation.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canManage): ?>
<section class="card box" aria-labelledby="add-h">
  <h2 id="add-h">Add a window</h2>
  <form class="record" method="post" action="/ui/purchasing/reorder/anomalies">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset>
      <legend>Window</legend>
      <label>First day <input type="date" name="date_from" value="<?= $e($v['date_from'] ?? '') ?>" required></label>
      <label>Last day <input type="date" name="date_to" value="<?= $e($v['date_to'] ?? '') ?>" required></label>
      <label>Site
        <select name="channel_id">
          <option value="">Every site</option>
<?php foreach ($channels as $c): ?>
          <option value="<?= $e($c['id']) ?>"<?php if ((string) ($v['channel_id'] ?? '') === (string) $c['id']): ?> selected<?php endif; ?>><?= $e($c['code']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label>Brand (empty = every brand) <input type="text" name="brand" value="<?= $e($v['brand'] ?? '') ?>" maxlength="128"></label>
      <label>What happened (3 to 200 characters; shown in every "Why") <input type="text" name="label" value="<?= $e($v['label'] ?? '') ?>" maxlength="200" required></label>
    </fieldset>
    <p><button type="submit" class="primary">Add the window</button></p>
  </form>
</section>
<?php endif; ?>
<?php if ($rows === []): ?>
<p class="note">No window.</p>
<?php else: ?>
<table class="anomalies">
  <thead>
    <tr>
      <th scope="col">Days</th>
      <th scope="col">Site</th>
      <th scope="col">Brand</th>
      <th scope="col">What happened</th>
      <th scope="col">State</th>
      <th scope="col">Added (UTC)</th>
<?php if ($canManage): ?>
      <th scope="col"></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ((int) $r['is_active'] !== 1): ?> class="inactive"<?php endif; ?>>
      <th scope="row"><?= $e($r['date_from']) ?> to <?= $e($r['date_to']) ?></th>
      <td><?= $e($r['channel_code'] ?? 'every site') ?></td>
      <td><?= $e($r['brand'] ?? 'every brand') ?></td>
      <td><?= $e($r['label']) ?></td>
      <td><?php if ((int) $r['is_active'] === 1): ?>active<?php else: ?>ended <?= $dt($r['ended_at']) ?><?php if ($r['ended_by_name'] !== null): ?> by <?= $e($r['ended_by_name']) ?><?php endif; ?><?php endif; ?></td>
      <td><?= $dt($r['created_at']) ?> <span class="muted"><?= $e($r['created_by_name'] ?? $r['created_actor']) ?></span></td>
<?php if ($canManage): ?>
      <td><?php if ((int) $r['is_active'] === 1): ?>
        <form class="inline" method="post" action="<?= $u('/ui/purchasing/reorder/anomalies/' . $r['id'] . '/end') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <button type="submit">End</button>
        </form><?php endif; ?></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
