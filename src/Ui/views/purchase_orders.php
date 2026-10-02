<h1>Purchase orders</h1>
<p class="muted">A purchase order is drafted by a buyer and approved by them: it is numbered then, and a second person reviews it within 7 days. An
  order above £<?= $n($approvalLimit) ?> net waits for a reviewer's approval first. A posted order is never changed: cancel or amend it.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($formKey !== null): ?>
<section class="card box" aria-labelledby="new-h" id="new">
  <h2 id="new-h">New purchase order</h2>
<?php if ($newSuppliers === []): ?>
  <p class="note">There is no supplier yet: <a href="/ui/purchasing/suppliers/new">add one</a> (a second person approves it before an order can be approved).</p>
<?php else: ?>
  <form class="inline" method="post" action="/ui/purchasing/orders">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label>Supplier
      <select name="supplier_id" required>
        <option value="">Choose a supplier</option>
<?php foreach ($newSuppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === (int) $s['id']): ?> selected<?php endif; ?>><?= $e($s['code']) ?> <?= $e($s['name']) ?><?php if ($s['status'] !== 'active'): ?> (<?= $e(str_replace('_', ' ', $s['status'])) ?>)<?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="primary">Start a draft</button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<div class="crumbs">
  <form class="filters" method="get" action="/ui/purchasing/orders">
    <label>State
      <select name="state">
        <option value="">Any</option>
<?php foreach ($states as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($filters['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Supplier
      <select name="supplier">
        <option value="">Any</option>
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === (int) $s['id']): ?> selected<?php endif; ?>><?= $e($s['code']) ?> <?= $e($s['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Number or supplier's reference <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100"></label>
    <label class="choice"><input type="checkbox" name="rejected" value="1"<?php if ($filters['rejected']): ?> checked<?php endif; ?>> Rejected at review</label>
    <label class="choice"><input type="checkbox" name="show" value="all"<?php if ($filters['all']): ?> checked<?php endif; ?>> Show the cancellation documents</label>
    <button type="submit">Filter</button>
  </form>
  <p class="actions">
    <a href="<?= $u('/ui/purchasing/orders.csv', ['state' => $filters['state'], 'supplier' => $filters['supplier'], 'q' => $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['all'] ? 'all' : null]) ?>">Download (CSV)</a>
  </p>
</div>
<?php if ($rows === []): ?>
<p class="note">No purchase order matches.</p>
<?php else: ?>
<table class="orders">
  <thead>
    <tr>
      <th scope="col">Order</th>
      <th scope="col">Supplier</th>
      <th scope="col">Order date</th>
      <th scope="col">Expected</th>
      <th scope="col">State</th>
      <th scope="col" class="num">Lines</th>
      <th scope="col" class="num">Units</th>
      <th scope="col" class="num">Net (GBP)</th>
      <th scope="col">Review</th>
      <th scope="col">Sent (UTC)</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="<?= $u($r['href']) ?>"><?= $e($r['label']) ?></a></th>
      <td><?= $e($r['supplier_code']) ?> <span class="muted"><?= $e($r['supplier_name']) ?></span></td>
      <td><?= $e($r['doc_date']) ?></td>
      <td><?= $e($r['expected_date']) ?></td>
      <td><span class="status"><?= $e($r['state_label']) ?></span></td>
      <td class="num"><?= $n($r['lines']) ?></td>
      <td class="num"><?= $n($r['units']) ?></td>
      <td class="num"><?= $dec($r['net_total']) ?></td>
      <td><?php if ($r['review_state'] === 'rejected'): ?><span class="tag bad">rejected</span><?php else: ?><?= $e($r['review_state'] === null ? '' : str_replace('_', ' ', $r['review_state'])) ?><?php endif; ?></td>
      <td><?= $dt($r['sent_at']) ?><?php if ($r['sent_via'] !== null): ?> <span class="muted"><?= $e(str_replace('_', ' ', $r['sent_via'])) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php if (count($rows) >= $limit): ?>
<p class="muted">The newest <?= $n($limit) ?> are shown: narrow the filter, or download the CSV for every row.</p>
<?php endif; ?>
<?php endif; ?>
