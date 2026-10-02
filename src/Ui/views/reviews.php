<h1>Document reviews</h1>
<p class="muted">Documents are posted first and reviewed by a second person afterwards; only a few requests wait for an approval before
  anything is booked (a positive adjustment without a supplier document above the limit) or used (a new or re-activated supplier, an overseas
  supplier's import route, a purchase order above the value limit). Nobody decides a document they created, asked for or posted, or a supplier they
  created, asked for or last changed. Rejecting a posted document posts its reversal, except a purchase order, whose rejection is recorded (its buyer
  cancels or amends it); rejecting a request cancels it. Supplier tasks are decided on the supplier's page, purchase orders on the order's page.</p>
<form class="filters" method="get" action="/ui/documents/reviews">
  <label>Type
    <select name="type">
      <option value="">Everything</option>
<?php foreach ($types as $code => $name): ?>
      <option value="<?= $e($code) ?>"<?php if ($type === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Filter</button>
</form>
<?php if ($approvals === [] && $reviews === []): ?>
<p class="note"><?php if ($type !== null): ?>Nothing of this type is waiting for review.<?php else: ?>Nothing is waiting for review.<?php endif; ?></p>
<?php else: ?>
<?php foreach ([['id' => 'approvals', 'title' => 'Waiting for approval (blocking)', 'rows' => $approvals], ['id' => 'reviews', 'title' => 'Posted, waiting for review (and changed suppliers)', 'rows' => $reviews]] as $list): ?>
<section aria-labelledby="<?= $e($list['id']) ?>-h">
  <h2 id="<?= $e($list['id']) ?>-h"><?= $e($list['title']) ?></h2>
<?php if ($list['rows'] === []): ?>
  <p class="muted">None.</p>
<?php else: ?>
  <table class="reviews">
    <thead>
      <tr>
        <th scope="col">Document or supplier</th>
        <th scope="col">Type</th>
        <th scope="col">Why</th>
        <th scope="col" class="num">Units or value</th>
        <th scope="col">From</th>
        <th scope="col">Since (UTC)</th>
        <th scope="col">Due (UTC)</th>
        <th scope="col">You</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list['rows'] as $r): ?>
      <tr>
        <th scope="row"><a href="<?= $u($r['href']) ?>"><?= $e($r['label']) ?></a></th>
        <td><?= $e($r['type']) ?></td>
        <td><?= $e(str_replace('_', ' ', $r['reason'])) ?></td>
        <td class="num"><?php if ($r['money']): ?><?= $e('£') . $n($r['units']) ?><?php else: ?><?= $n($r['units']) ?><?php endif; ?></td>
        <td><?= $e($r['opened_by']) ?></td>
        <td><?= $dt($r['opened_at']) ?></td>
        <td><?= $dt($r['due_at']) ?><?php if ($r['overdue']): ?> <span class="tag bad">overdue</span><?php endif; ?></td>
        <td><?php if ($r['refusal'] === null): ?><a href="<?= $u($r['href']) ?>">Open to decide</a><?php else: ?><span class="muted"><?= $e($r['refusal']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
