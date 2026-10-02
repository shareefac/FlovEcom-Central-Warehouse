<p class="crumbs"><a href="/ui/purchasing/orders">Purchase orders</a></p>
<h1><?= $e($doc->label()) ?> <span class="muted"><?= $e($supplier['code'] ?? '') ?> <?= $e($supplier['name'] ?? '') ?></span> <span class="status"><?= $e($stateText) ?></span></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rejected !== null): ?>
<p class="error">Rejected at review by <?= $e($rejected['decided_by_name']) ?> on <?= $dt($rejected['decided_at']) ?> UTC: <?= $e($rejected['decision_note']) ?>.
  The order stands until its buyer cancels or amends it.</p>
<?php endif; ?>
<?php if ($warnings !== []): ?>
<ul class="warnings">
<?php foreach ($warnings as $w): ?>
  <li><?= $e($w) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($doc->status === 'draft'): ?>
<p class="note read-only">A draft is changed only by the person who created it (<?= $e($people['created']) ?>).</p>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="order-h">
    <h2 id="order-h">Order</h2>
    <dl>
      <dt>Supplier</dt><dd><a href="<?= $u('/ui/purchasing/suppliers/' . ($supplier['id'] ?? 0)) ?>"><?= $e($supplier['code'] ?? '') ?></a> <?= $e($supplier['name'] ?? '') ?></dd>
      <dt>State</dt><dd><?= $e($stateText) ?></dd>
      <dt>Review</dt><dd><?php if ($doc->reviewState === null): ?><span class="muted">not approved yet</span><?php else: ?><?= $e(str_replace('_', ' ', $doc->reviewState)) ?><?php endif; ?></dd>
      <dt>Order date</dt><dd><?= $e($doc->docDate) ?></dd>
      <dt>Expected delivery</dt><dd><?= $e($po['expected_date'] ?? '') ?></dd>
      <dt>Supplier's quote reference</dt><dd><?= $e($doc->externalRef) ?></dd>
      <dt>Notes to supplier</dt><dd><?= $e($doc->note) ?></dd>
      <dt>Source</dt><dd><?= $e(str_replace('_', ' ', (string) ($po['source'] ?? ''))) ?></dd>
<?php if ($amends !== null): ?>
      <dt>Amends</dt><dd><a href="<?= $u('/ui/purchasing/orders/' . $amends['id']) ?>"><?= $e($amends['number']) ?></a></dd>
<?php endif; ?>
<?php foreach ($amendedBy as $a): ?>
      <dt>Amended by</dt><dd><a href="<?= $u('/ui/purchasing/orders/' . $a['id']) ?>"><?= $e($a['number'] ?? str_replace('_', ' ', $a['status']) . ' #' . $a['id']) ?></a></dd>
<?php endforeach; ?>
<?php if ($reversal !== null): ?>
      <dt>Cancelled by</dt><dd><?= $e($reversal->number ?? 'a cancellation waiting for approval') ?> on <?= $e($reversal->docDate) ?>: <?= $e($reversal->reasonCode) ?><?php if ($reversal->note !== null): ?> — <?= $e($reversal->note) ?><?php endif; ?>
        (<a href="<?= $u('/ui/purchasing/orders/' . $reversal->id . '/pdf') ?>">cancellation PDF</a>)</dd>
<?php endif; ?>
      <dt>Created (UTC)</dt><dd><?= $dt($doc->createdAt) ?> by <?= $e($people['created']) ?></dd>
<?php if ($doc->submittedAt !== null): ?>
      <dt>Approval asked (UTC)</dt><dd><?= $dt($doc->submittedAt) ?> by <?= $e($people['submitted']) ?></dd>
<?php endif; ?>
<?php if ($doc->postedAt !== null): ?>
      <dt>Approved (UTC)</dt><dd><?= $dt($doc->postedAt) ?> by <?= $e($people['posted']) ?></dd>
<?php endif; ?>
<?php if (($po['sent_at'] ?? null) !== null): ?>
      <dt>Sent (UTC)</dt><dd><?= $dt($po['sent_at']) ?> by <?= $e($people['sent']) ?>, <?= $e(str_replace('_', ' ', (string) $po['sent_via'])) ?><?php if ($po['sent_to'] !== null): ?> to <?= $e($po['sent_to']) ?><?php endif; ?></dd>
<?php endif; ?>
<?php if (($po['closed_at'] ?? null) !== null): ?>
      <dt>Closed (UTC)</dt><dd><?= $dt($po['closed_at']) ?> by <?= $e($people['closed']) ?>: <?= $e($po['close_reason']) ?></dd>
<?php endif; ?>
<?php if ($doc->cancelledAt !== null): ?>
      <dt>Cancelled (UTC)</dt><dd><?= $dt($doc->cancelledAt) ?> by <?= $e($people['cancelled']) ?>: <?= $e($doc->cancelReason) ?></dd>
<?php endif; ?>
    </dl>
  </section>
  <section class="card" aria-labelledby="totals-h">
    <h2 id="totals-h">Totals</h2>
    <dl>
      <dt>Lines</dt><dd><?= $n(count($lines)) ?>, <?= $n($units) ?> units<?php if ($received > 0): ?>, <?= $n($received) ?> received<?php endif; ?></dd>
      <dt>Net</dt><dd><?= $e($totals['net']) ?></dd>
<?php foreach ($totals['by_code'] as $code => $v): ?>
      <dt>VAT <?= $e($code) ?> <?= $e($v['rate']) ?>%</dt><dd><?= $e($v['vat']) ?></dd>
<?php endforeach; ?>
      <dt>Total</dt><dd><strong><?= $e($totals['gross']) ?></strong></dd>
    </dl>
    <p><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/pdf') ?>">PDF</a> &middot;
      <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>">Lines (XLSX)</a> &middot;
      <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.csv') ?>">(CSV)</a> &middot;
      <a href="<?= $u('/ui/documents/' . $doc->id) ?>">The document</a></p>
  </section>
</div>

<?php foreach ($decide as $d): ?>
<section aria-labelledby="decide-<?= $e($d['task']['id']) ?>-h" class="card box waiting">
  <h2 id="decide-<?= $e($d['task']['id']) ?>-h"><?php if ($d['task']['kind'] === 'approval'): ?>Approval (blocking)<?php else: ?>Review of <?= $e($d['of']) ?><?php endif; ?></h2>
  <p><?php if ($d['task']['kind'] === 'approval'): ?>This order is above the approval limit (<?= $e(str_replace('_', ' ', $d['task']['reason'])) ?>: £<?= $n($d['task']['units']) ?>, limit £<?= $n($limit) ?>): nothing is ordered until a second person approves it<?php elseif ($d['reversal']): ?>The cancellation is posted and waits for a second person's review<?php else: ?>The order is approved and waits for a second person's review<?php endif; ?>
    (due <?= $dt($d['task']['due_at']) ?> UTC<?php if ($d['task']['overdue']): ?>, <span class="tag bad">overdue</span><?php endif; ?>).</p>
<?php if ($d['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($d['refusal']) ?></p>
<?php else: ?>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Note (optional) <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary">Approve</button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Why it is rejected (required) <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit"><?php if ($d['task']['kind'] === 'approval'): ?>Reject the request<?php else: ?>Reject<?php endif; ?></button>
  </form>
  <p class="muted"><?php if ($d['task']['kind'] === 'approval'): ?>Approving posts the order now, as the requester's; rejecting cancels the request.<?php elseif ($d['reversal']): ?>A cancellation is never undone: rejecting records that it was wrong.<?php else: ?>Rejecting records the rejection and cancels nothing (the order may already be with the supplier): the buyer then cancels or amends it.<?php endif; ?></p>
<?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($can['approve'] || $can['withdraw'] || $can['send'] || $can['close'] || $can['cancel'] || $can['amend'] || $can['copy']): ?>
<section aria-labelledby="actions-h">
  <h2 id="actions-h">Actions</h2>
<?php if ($can['approve']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit" class="primary">Approve the order</button>
  </form>
<?php endif; ?>
<?php if ($can['withdraw']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit">Withdraw the approval request (back to draft)</button>
  </form>
<?php endif; ?>
<?php if ($can['send']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/send') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label>Sent by
      <select name="via">
<?php foreach ($sendVia as $code => $label): ?>
        <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>To <input type="text" name="to" maxlength="191" value="<?= $e($supplier['email'] ?? '') ?>"></label>
<?php if ($sendWarnings !== []): ?>
    <span class="warnings"><?php foreach ($sendWarnings as $w): ?><?= $e($w) ?> <?php endforeach; ?></span>
    <label class="choice"><input type="checkbox" name="send_anyway" value="1" required> Send anyway</label>
<?php endif; ?>
    <button type="submit" class="primary"><?php if (($po['state'] ?? '') === 'sent'): ?>Sent again<?php else: ?>Mark as sent<?php endif; ?></button>
  </form>
<?php endif; ?>
<?php if ($can['close']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/close') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label>Why the rest is not expected (required) <input type="text" name="reason" minlength="3" maxlength="500" required></label>
    <button type="submit">Close the order</button>
  </form>
<?php endif; ?>
<?php if ($can['cancel']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label>Cancel because
      <select name="reason_code" required>
<?php foreach ($cancelReasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Note <input type="text" name="note" maxlength="400"></label>
    <button type="submit">Cancel the order</button>
  </form>
<?php endif; ?>
<?php if ($can['amend']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/amend') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label>Amend because
      <select name="reason_code" required>
<?php foreach ($amendReasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Note <input type="text" name="note" maxlength="400"></label>
    <button type="submit">Amend (cancel and copy into a new draft)</button>
  </form>
<?php endif; ?>
<?php if ($can['copy']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/copy') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <button type="submit">Copy into a new draft</button>
  </form>
<?php endif; ?>
  <p class="muted">An approved order is never changed: cancelling posts a cancellation in the PO series (refused once goods were received: close
    it instead); amending cancels it and copies it into a new draft in one step.</p>
</section>
<?php endif; ?>

<section aria-labelledby="lines-h">
  <h2 id="lines-h">Lines</h2>
<?php if ($lines === []): ?>
  <p class="muted">No lines.</p>
<?php else: ?>
  <table class="po-lines">
    <thead>
      <tr>
        <th scope="col" class="num">#</th>
        <th scope="col">Item</th>
        <th scope="col">Supplier code</th>
        <th scope="col">Pack</th>
        <th scope="col" class="num">Packs</th>
        <th scope="col" class="num">Units</th>
        <th scope="col" class="num">Received</th>
        <th scope="col" class="num">Pack price (GBP)</th>
        <th scope="col">VAT</th>
        <th scope="col" class="num">Net</th>
        <th scope="col">Note</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <td class="num"><?= $n($l['line_no']) ?></td>
<?php if ($l['kind'] === 'charge'): ?>
        <td colspan="3"><span class="tag">charge</span></td>
        <td></td><td></td><td></td>
<?php else: ?>
        <td><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_code']) ?></a> <?= $e($l['sku_name']) ?></td>
        <td><?= $e($l['code']) ?></td>
        <td><?= $e($l['pack_label']) ?></td>
        <td class="num"><?= $n($l['packs']) ?></td>
        <td class="num"><?= $n($l['qty']) ?></td>
        <td class="num"><?= $n($l['received_units']) ?></td>
<?php endif; ?>
        <td class="num"><?= $e($l['price']) ?></td>
        <td><?= $e($l['vat_code']) ?></td>
        <td class="num"><?= $e($l['net']) ?></td>
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
  <p class="muted">No files<?php if ($doc->status === 'posted'): ?> (the PDF as sent is kept here when the server has a file store)<?php endif; ?>.</p>
<?php else: ?>
  <ul class="plain">
<?php foreach ($files as $f): ?>
    <li><a href="<?= $u('/ui/files/' . $f['id']) ?>"><?= $e($f['original_name']) ?></a> <span class="muted"><?= $e($f['role']) ?>, <?= $n($f['size_bytes']) ?> bytes, <?= $dt($f['attached_at']) ?> UTC</span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <h2 id="history-h">Approvals and reviews</h2>
<?php if ($tasks === []): ?>
  <p class="muted">Nothing was asked for yet.</p>
<?php else: ?>
  <table class="history">
    <thead>
      <tr>
        <th scope="col">Of</th>
        <th scope="col">Kind</th>
        <th scope="col">Why</th>
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
        <td><?= $e($t['of']) ?></td>
        <td><?= $e($t['kind']) ?></td>
        <td><?= $e(str_replace('_', ' ', $t['reason'])) ?></td>
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
