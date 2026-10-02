<h1>Suppliers</h1>
<p class="muted">A new supplier is a draft until a second person approves it; only an active supplier can receive purchase orders. No bank details
  are kept in CW.</p>
<div class="crumbs">
  <form class="filters" method="get" action="/ui/purchasing/suppliers">
    <label>Status
      <select name="status">
        <option value="">Any</option>
<?php foreach ($statuses as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($filters['status'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Code, name, VAT number or ERPNext name <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100"></label>
    <label class="choice"><input type="checkbox" name="due" value="1"<?php if ($filters['due']): ?> checked<?php endif; ?>> Due-diligence review due within 30 days</label>
    <button type="submit">Filter</button>
  </form>
  <p class="actions">
<?php if ($canManage): ?>
    <a class="button" href="/ui/purchasing/suppliers/new">New supplier</a>
<?php endif; ?>
    <a href="<?= $u('/ui/purchasing/suppliers.csv', ['status' => $filters['status'], 'q' => $filters['q'], 'due' => $filters['due'] ? '1' : null]) ?>">Download (CSV)</a>
  </p>
</div>
<?php if ($rows === []): ?>
<p class="note">No supplier matches.</p>
<?php else: ?>
<table class="suppliers">
  <thead>
    <tr>
      <th scope="col">Code</th>
      <th scope="col">Name</th>
      <th scope="col">Status</th>
      <th scope="col">Next due-diligence review</th>
      <th scope="col" class="num">Items</th>
      <th scope="col">Approved (UTC)</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="<?= $u('/ui/purchasing/suppliers/' . $r['id']) ?>"><?= $e($r['code']) ?></a></th>
      <td><?= $e($r['name']) ?><?php if ((int) $r['is_overseas'] === 1): ?> <span class="tag">overseas</span><?php endif; ?></td>
      <td><span class="status status-<?= $e($r['status']) ?>"><?= $e($r['status_label']) ?></span></td>
      <td><?= $e($r['dd_next_review_on']) ?><?php if ($r['dd_overdue']): ?> <span class="tag bad">overdue</span><?php endif; ?></td>
      <td class="num"><?= $n($r['items']) ?></td>
      <td><?= $dt($r['approved_at']) ?><?php if ($r['approved_by_name'] !== null): ?> <span class="muted">by <?= $e($r['approved_by_name']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php if (count($rows) >= $limit): ?>
<p class="muted">The first <?= $n($limit) ?> are shown: narrow the filter, or download the CSV for every row.</p>
<?php endif; ?>
<?php endif; ?>
