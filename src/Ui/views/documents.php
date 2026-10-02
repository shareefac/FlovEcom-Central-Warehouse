<h1>Documents</h1>
<?php if ($live === []): ?>
<p class="note">No document type is live yet. These screens, the review queue and the number series are ready; the documents themselves arrive with
  their phases:<?php foreach ($types as $i => $t): ?> <?= $e($t['name']) ?> (<?= $e($t['code']) ?>) in Phase <?= $e($t['phase']) ?><?php if ($i < count($types) - 1): ?>;<?php else: ?>.<?php endif; ?><?php endforeach; ?></p>
<?php else: ?>
<p class="note">Live document types:<?php foreach ($types as $t): ?><?php if (in_array($t['code'], $live, true)): ?> <?= $e($t['name']) ?> (<?= $e($t['code']) ?>)<?php if ($t['code'] === 'PO'): ?>, written in Purchasing<?php endif; ?>;<?php endif; ?><?php endforeach; ?>
  the others arrive with their phases:<?php foreach ($types as $t): ?><?php if (!in_array($t['code'], $live, true)): ?> <?= $e($t['name']) ?> (<?= $e($t['code']) ?>) in Phase <?= $e($t['phase']) ?>;<?php endif; ?><?php endforeach; ?></p>
<?php endif; ?>

<form class="filters" method="get" action="/ui/documents">
  <label>Type
    <select name="type">
      <option value="">All types</option>
<?php foreach ($types as $t): ?>
      <option value="<?= $e($t['code']) ?>"<?php if ($filters['type'] === $t['code']): ?> selected<?php endif; ?>><?= $e($t['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <option value="">Any status</option>
<?php foreach ($statuses as $s): ?>
      <option value="<?= $e($s) ?>"<?php if ($filters['status'] === $s): ?> selected<?php endif; ?>><?= $e(str_replace('_', ' ', $s)) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>Review
    <select name="review">
      <option value="">Any review state</option>
<?php foreach ($reviewStates as $s): ?>
      <option value="<?= $e($s) ?>"<?php if ($filters['review'] === $s): ?> selected<?php endif; ?>><?= $e(str_replace('_', ' ', $s)) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>Number or external reference
    <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100">
  </label>
  <button type="submit">Filter</button>
</form>

<p class="muted"><?= $n($total) ?> document<?php if ($total !== 1): ?>s<?php endif; ?>, newest first.</p>
<?php if ($rows === []): ?>
<p><?php if ($filters['type'] !== null || $filters['status'] !== null || $filters['review'] !== null || $filters['q'] !== ''): ?>No document matches these filters.<?php else: ?>No documents yet.<?php endif; ?></p>
<?php else: ?>
<table class="documents">
  <thead>
    <tr>
      <th scope="col">Document</th>
      <th scope="col">Type</th>
      <th scope="col">Status</th>
      <th scope="col">Review</th>
      <th scope="col">Date</th>
      <th scope="col">Warehouse</th>
      <th scope="col">External reference</th>
      <th scope="col">Created by</th>
      <th scope="col">Posted (UTC)</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="<?= $u('/ui/documents/' . $r['id']) ?>"><?= $e($r['label']) ?></a></th>
      <td><?= $e($r['doc_type']) ?></td>
      <td><span class="status"><?= $e(str_replace('_', ' ', $r['status'])) ?></span></td>
      <td><?= $e($r['review_state'] === null ? '' : str_replace('_', ' ', $r['review_state'])) ?></td>
      <td><?= $e($r['doc_date']) ?></td>
      <td><?= $e($r['warehouse']) ?></td>
      <td><?= $e($r['external_ref']) ?></td>
      <td><?= $e($r['created_by_name']) ?></td>
      <td><?= $dt($r['posted_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/documents', $filters + ['page' => $page - 1]) ?>">Newer</a>
<?php endif; ?>
  <span>Page <?= $n($page) ?> of <?= $n($pages) ?></span>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/documents', $filters + ['page' => $page + 1]) ?>">Older</a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
