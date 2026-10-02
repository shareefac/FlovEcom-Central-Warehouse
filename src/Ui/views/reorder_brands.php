<?php $v = $typed ?? ($current ?? []); ?>
<p class="crumbs"><a href="/ui/purchasing/reorder">Reorder list</a></p>
<h1>Reorder: brand factors and safety days</h1>
<p class="muted">A brand's demand factor scales the demand of all its items (0.85 = 15% less: for example the drop expected after the duty), its safety
  days replace the default <?= $n($defaultSafety) ?>. An item's own settings come first.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canManage && $brand !== null): ?>
<section class="card box" aria-labelledby="edit-h">
  <h2 id="edit-h"><?= $e($brand) ?></h2>
  <form class="record" method="post" action="/ui/purchasing/reorder/brands">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="brand" value="<?= $e($brand) ?>">
    <fieldset>
      <legend>Settings</legend>
      <label>Demand factor (0 to 5; empty = 1.00) <input type="text" name="demand_factor" value="<?= $e($v['demand_factor'] ?? '') ?>" maxlength="4" class="short"></label>
      <label>Safety days (0 to 90; empty = the default) <input type="number" name="safety_days" value="<?= $e($v['safety_days'] ?? '') ?>" min="0" max="90" class="short"></label>
      <label>Note <input type="text" name="note" value="<?= $e($v['note'] ?? '') ?>" maxlength="500"></label>
    </fieldset>
    <p><button type="submit" class="primary">Save</button></p>
  </form>
</section>
<?php endif; ?>
<?php if ($rows === []): ?>
<p class="note">No brand is on the reorder list yet.</p>
<?php else: ?>
<table class="brands">
  <thead>
    <tr>
      <th scope="col">Brand</th>
      <th scope="col" class="num">Items on the list</th>
      <th scope="col" class="num">Demand / day</th>
      <th scope="col" class="num">Factor</th>
      <th scope="col" class="num">Safety days</th>
      <th scope="col">Note</th>
      <th scope="col">Changed (UTC)</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?php if ($canManage): ?><a href="<?= $u('/ui/purchasing/reorder/brands', ['brand' => $r['brand']]) ?>"><?= $e($r['brand']) ?></a><?php else: ?><?= $e($r['brand']) ?><?php endif; ?></th>
      <td class="num"><?= $n($r['items']) ?></td>
      <td class="num"><?= $dec($r['rate']) ?></td>
      <td class="num"><?= $e($r['demand_factor'] ?? '1.00') ?></td>
      <td class="num"><?= $e($r['safety_days'] ?? 'default') ?></td>
      <td><?= $e($r['note']) ?></td>
      <td><?= $dt($r['updated_at']) ?><?php if ($r['updated_by_name'] !== null): ?> <span class="muted">by <?= $e($r['updated_by_name']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
