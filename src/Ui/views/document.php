<p class="crumbs"><a href="/ui/documents">Documents</a></p>
<h1><?= $e($type['name']) ?> <?= $e($doc->label()) ?></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if (!$handler): ?>
<p class="note"><?= $e($type['name']) ?> screens arrive in Phase <?= $e($type['phase']) ?>.</p>
<?php endif; ?>
<?php if ($poHref !== null): ?>
<p class="actions"><a class="button" href="<?= $u($poHref) ?>">Open in Purchasing</a> <span class="muted">(the order's own page: lines, sending, cancelling, the PDF)</span></p>
<?php endif; ?>

<dl class="wide">
  <dt>Status</dt><dd><span class="status"><?= $e($statusText) ?></span></dd>
  <dt>Review</dt><dd><?php if ($doc->reviewState === null): ?><span class="muted">not posted</span><?php else: ?><?= $e(str_replace('_', ' ', $doc->reviewState)) ?><?php endif; ?></dd>
  <dt>Date</dt><dd><?= $e($doc->docDate) ?></dd>
  <dt>Warehouse</dt><dd><?= $e($warehouse) ?></dd>
  <dt>External reference</dt><dd><?= $e($doc->externalRef) ?></dd>
  <dt>Reason</dt><dd><?= $e($doc->reasonCode) ?></dd>
  <dt>Note</dt><dd><?= $e($doc->note) ?></dd>
  <dt>Created (UTC)</dt><dd><?= $dt($doc->createdAt) ?> by <?= $e($people['created']) ?></dd>
<?php if ($doc->submittedAt !== null): ?>
  <dt>Approval asked (UTC)</dt><dd><?= $dt($doc->submittedAt) ?> by <?= $e($people['submitted']) ?></dd>
<?php endif; ?>
<?php if ($doc->postedAt !== null): ?>
  <dt>Posted (UTC)</dt><dd><?= $dt($doc->postedAt) ?> by <?= $e($people['posted']) ?></dd>
<?php endif; ?>
<?php if ($doc->cancelledAt !== null): ?>
  <dt>Cancelled (UTC)</dt><dd><?= $dt($doc->cancelledAt) ?> by <?= $e($people['cancelled']) ?>: <?= $e($doc->cancelReason) ?></dd>
<?php endif; ?>
<?php if ($reverses !== null): ?>
  <dt>Reverses</dt><dd><a href="<?= $u('/ui/documents/' . $reverses['id']) ?>"><?= $e($reverses['number']) ?></a></dd>
<?php endif; ?>
<?php if ($reversedBy !== null): ?>
  <dt><?php if ($reversedBy['number'] === null): ?>Reversal requested<?php else: ?>Reversed by<?php endif; ?></dt>
  <dd><a href="<?= $u('/ui/documents/' . $reversedBy['id']) ?>"><?= $e($reversedBy['number'] ?? 'reversal #' . $reversedBy['id'] . ', waiting for approval') ?></a></dd>
<?php endif; ?>
<?php if ($doc->postedHash !== null): ?>
  <dt>Fingerprint (sha256)</dt><dd><code><?= $e($doc->postedHash) ?></code></dd>
<?php endif; ?>
</dl>
<p><a href="<?= $u('/ui/documents/' . $doc->id . '/pdf') ?>">Download as PDF</a></p>

<?php if ($decide !== null): ?>
<section aria-labelledby="decide-h">
  <h2 id="decide-h"><?php if ($decide['task']['kind'] === 'approval'): ?>Approval (blocking)<?php else: ?>Review<?php endif; ?></h2>
  <p><?php if ($decide['task']['kind'] === 'approval'): ?>This document is held back until a second person approves it<?php else: ?>This document is posted and waits for a second person's review<?php endif; ?>
    (<?= $e(str_replace('_', ' ', $decide['task']['reason'])) ?><?php if ($decide['task']['units'] !== null): ?>, <?= $n($decide['task']['units']) ?> units<?php endif; ?>; due <?= $dt($decide['task']['due_at']) ?> UTC<?php if ($decide['task']['overdue']): ?>, <span class="tag bad">overdue</span><?php endif; ?>).</p>
<?php if ($decide['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($decide['refusal']) ?></p>
<?php else: ?>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $decide['task']['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Note (optional) <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary">Approve</button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $decide['task']['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Why it is rejected (required) <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit"><?php if ($decide['task']['kind'] === 'approval'): ?>Reject the request<?php elseif ($doc->isReversal() || $rejectRecords): ?>Reject<?php else: ?>Reject and reverse<?php endif; ?></button>
  </form>
<?php if ($decide['task']['kind'] === 'approval' && $doc->isReversal()): ?>
  <p class="muted">Approving posts this reversal now, as the requester's posting: the original's stock is booked back. Rejecting cancels the request: nothing is booked.</p>
<?php elseif ($decide['task']['kind'] === 'approval'): ?>
  <p class="muted">Rejecting cancels the request: nothing is booked.</p>
<?php elseif ($doc->isReversal()): ?>
  <p class="muted">A reversal is never reversed: rejecting records that this reversal was wrong and books nothing. If the original was right,
    its poster posts it again as a new document.</p>
<?php elseif ($rejectRecords): ?>
  <p class="muted">Rejecting records the rejection and changes nothing else: the document stands (a purchase order may already be with the supplier).
    Its poster then cancels or amends it.</p>
<?php else: ?>
  <p class="muted">Rejecting posts the document's reversal now (reason: rejected at review).</p>
<?php endif; ?>
<?php endif; ?>
</section>
<?php elseif ($waiting): ?>
<p class="muted">Waiting for a reviewer.</p>
<?php endif; ?>

<?php if ($reverse !== null): ?>
<section aria-labelledby="reverse-h">
  <h2 id="reverse-h">Reverse this document</h2>
  <p class="muted">A posted document is never changed. A reversal is a new document in the same series with every line negated: it books the
    stock back exactly, and it is reviewed like any other document of this type. A reversal that puts more units back on hand than the
    approval limit (stock without a supplier document) waits for a reviewer's approval before it is posted.</p>
  <form class="inline" method="post" action="<?= $u('/ui/documents/' . $doc->id . '/reverse') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Reason
      <select name="reason_code" required>
        <option value="">Choose a reason</option>
<?php foreach ($reverse['reasons'] as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Note <input type="text" name="note" maxlength="1000"></label>
    <button type="submit">Post the reversal</button>
  </form>
</section>
<?php endif; ?>

<section aria-labelledby="lines-h">
  <h2 id="lines-h">Lines</h2>
<?php if ($lines === []): ?>
  <p class="muted">No lines.</p>
<?php else: ?>
  <table class="lines">
    <thead>
      <tr>
        <th scope="col" class="num">#</th>
        <th scope="col">Item</th>
        <th scope="col">Warehouse</th>
        <th scope="col" class="num">Qty</th>
        <th scope="col" class="num">Unit cost (GBP)</th>
        <th scope="col" class="num">Amount (GBP)</th>
        <th scope="col">Reason</th>
        <th scope="col">Description</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <td class="num"><?= $n($l['line_no']) ?></td>
        <td><?php if ($l['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_code'] ?? '#' . $l['sku_id']) ?></a> <?= $e($l['sku_name']) ?><?php endif; ?></td>
        <td><?= $e($l['warehouse']) ?></td>
        <td class="num"><?= $n($l['qty']) ?></td>
        <td class="num"><?= $dec($l['unit_cost']) ?></td>
        <td class="num"><?= $dec($l['amount']) ?></td>
        <td><?= $e($l['reason_code']) ?></td>
        <td><?= $e($l['description']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section aria-labelledby="files-h">
  <h2 id="files-h">Files</h2>
<?php if ($files === []): ?>
  <p class="muted">No files attached.</p>
<?php else: ?>
  <table class="files">
    <thead>
      <tr>
        <th scope="col">Role</th>
        <th scope="col">File</th>
        <th scope="col">Type</th>
        <th scope="col" class="num">Bytes</th>
        <th scope="col">sha256</th>
        <th scope="col">Attached (UTC)</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($files as $f): ?>
      <tr>
        <td><?= $e($f['role']) ?></td>
        <td><a href="<?= $u('/ui/files/' . $f['id']) ?>"><?= $e($f['original_name']) ?></a></td>
        <td><?= $e($f['mime']) ?></td>
        <td class="num"><?= $n($f['size_bytes']) ?></td>
        <td><code><?= $e($f['sha256']) ?></code></td>
        <td><?= $dt($f['attached_at']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <h2 id="history-h">Review history</h2>
<?php if ($tasks === []): ?>
  <p class="muted">No review or approval was asked for.</p>
<?php else: ?>
  <table class="history">
    <thead>
      <tr>
        <th scope="col">Kind</th>
        <th scope="col">Why</th>
        <th scope="col" class="num">Units</th>
        <th scope="col">Opened (UTC)</th>
        <th scope="col">By</th>
        <th scope="col">Due (UTC)</th>
        <th scope="col">Outcome</th>
        <th scope="col">Decided (UTC)</th>
        <th scope="col">By</th>
        <th scope="col">Note</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($tasks as $t): ?>
      <tr>
        <td><?= $e($t['kind']) ?></td>
        <td><?= $e(str_replace('_', ' ', $t['reason'])) ?></td>
        <td class="num"><?= $n($t['units']) ?></td>
        <td><?= $dt($t['opened_at']) ?></td>
        <td><?= $e($t['opened_by_name']) ?></td>
        <td><?= $dt($t['due_at']) ?><?php if ($t['overdue']): ?> <span class="tag bad">overdue</span><?php endif; ?></td>
        <td><?= $e($t['state']) ?></td>
        <td><?= $dt($t['decided_at']) ?></td>
        <td><?= $e($t['decided_by_name']) ?></td>
        <td><?= $e($t['decision_note']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
