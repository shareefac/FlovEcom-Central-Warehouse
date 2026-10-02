<h1>Document reviews</h1>
<p class="muted">Documents are posted first and reviewed by a second person afterwards; only a few requests wait for an approval before
  anything is booked (a positive adjustment without a supplier document above the limit; from Phase I-2 a new supplier). Nobody decides a
  document they created, asked for or posted. Rejecting a posted document posts its reversal; rejecting a request cancels it.</p>
<?php if ($approvals === [] && $reviews === []): ?>
<p class="note">Nothing is waiting for review.</p>
<?php else: ?>
<?php foreach ([['id' => 'approvals', 'title' => 'Waiting for approval (blocking)', 'rows' => $approvals], ['id' => 'reviews', 'title' => 'Posted, waiting for review', 'rows' => $reviews]] as $list): ?>
<section aria-labelledby="<?= $e($list['id']) ?>-h">
  <h2 id="<?= $e($list['id']) ?>-h"><?= $e($list['title']) ?></h2>
<?php if ($list['rows'] === []): ?>
  <p class="muted">None.</p>
<?php else: ?>
  <table class="reviews">
    <thead>
      <tr>
        <th scope="col">Document</th>
        <th scope="col">Type</th>
        <th scope="col">Why</th>
        <th scope="col" class="num">Units</th>
        <th scope="col">From</th>
        <th scope="col">Since (UTC)</th>
        <th scope="col">Due (UTC)</th>
        <th scope="col">You</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list['rows'] as $r): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/documents/' . $r['document_id']) ?>"><?= $e($r['label']) ?></a></th>
        <td><?= $e($r['type']) ?></td>
        <td><?= $e(str_replace('_', ' ', $r['reason'])) ?></td>
        <td class="num"><?= $n($r['units']) ?></td>
        <td><?= $e($r['opened_by']) ?></td>
        <td><?= $dt($r['opened_at']) ?></td>
        <td><?= $dt($r['due_at']) ?><?php if ($r['overdue']): ?> <span class="tag bad">overdue</span><?php endif; ?></td>
        <td><?php if ($r['refusal'] === null): ?><a href="<?= $u('/ui/documents/' . $r['document_id']) ?>">Open to decide</a><?php else: ?><span class="muted"><?= $e($r['refusal']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
