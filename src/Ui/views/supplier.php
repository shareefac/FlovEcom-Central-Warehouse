<p class="crumbs"><a href="/ui/purchasing/suppliers">Suppliers</a></p>
<h1><?= $e($s['code']) ?> <span class="muted"><?= $e($s['name']) ?></span> <span class="status status-<?= $e($s['status']) ?>"><?= $e($statusLabel) ?></span></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($errorMissing !== null): ?> <span class="muted">(missing: <?= $e($errorMissing) ?>)</span><?php endif; ?></p>
<?php endif; ?>
<?php if ($ddOverdue): ?>
<p class="note">Due diligence is overdue: the next review was due on <?= $e($s['dd_next_review_on']) ?>. Purchase orders show this warning.</p>
<?php endif; ?>
<?php if ($routeUnapproved && $s['status'] === 'active'): ?>
<p class="note">Overseas supplier: its import route (how and where UK duty stamps are applied) is not approved, so purchase orders are refused until a
  second person approves it.</p>
<?php endif; ?>
<?php if ($s['last_decision_note'] !== null): ?>
<p class="muted">Last decision note: <?= $e($s['last_decision_note']) ?></p>
<?php endif; ?>

<section aria-labelledby="activation-h" class="card box">
  <h2 id="activation-h">Activation</h2>
<?php if ($s['status'] === 'active'): ?>
  <p>Active since <?= $dt($s['approved_at']) ?> UTC, approved by <?= $e($people['approved']) ?>.</p>
<?php if ($canDeactivate): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/deactivate') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
    <label>Why it is deactivated (required) <input type="text" name="reason" minlength="3" maxlength="500" required></label>
    <button type="submit">Deactivate</button>
  </form>
  <p class="muted">A deactivated supplier gets no new purchase orders; orders already approved are not affected. Activating it again needs a second
    person's approval.</p>
<?php endif; ?>
<?php elseif ($s['status'] === 'pending_approval'): ?>
  <p><strong>Waiting for a second person</strong> to approve this supplier<?php if ($open['activation'] !== null): ?>
    (<?= $e(str_replace('_', ' ', $open['activation']['reason'])) ?>, asked by <?= $e($open['activation']['opened_by_name']) ?> on <?= $dt($open['activation']['opened_at']) ?> UTC,
    due <?= $dt($open['activation']['due_at']) ?> UTC<?php if ($open['activation']['overdue']): ?>, <span class="tag bad">overdue</span><?php endif; ?>)<?php endif; ?>.
    It cannot be changed while it waits.</p>
<?php else: ?>
  <p>This supplier is <?= $e($statusLabel) ?><?php if ($s['status'] === 'inactive'): ?> since <?= $dt($s['deactivated_at']) ?> UTC (<?= $e($people['deactivated']) ?>: <?= $e($s['deactivate_reason']) ?>)<?php endif; ?>.
    It is used for purchase orders only after a second person approves it.</p>
<?php if ($canRequest && $missing !== []): ?>
  <p class="note">Before asking for activation, fill in: <?= $e($missingText) ?>.</p>
<?php elseif ($canRequest): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/request-activation') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
    <button type="submit" class="primary"><?php if ($s['approved_at'] === null): ?>Ask a second person to activate it<?php else: ?>Ask a second person to activate it again<?php endif; ?></button>
  </form>
<?php endif; ?>
<?php endif; ?>
</section>

<?php foreach (['activation' => 'Activation approval (blocking)', 'route' => 'Import route approval (blocking)', 'review' => 'Change review (does not block orders)'] as $slot => $title): ?>
<?php if ($open[$slot] !== null): ?>
<section aria-labelledby="task-<?= $e($slot) ?>-h" class="card box waiting">
  <h2 id="task-<?= $e($slot) ?>-h"><?= $e($title) ?></h2>
<?php if ($slot === 'route' && (int) $s['is_overseas'] !== 1): ?>
  <p>This supplier was marked as no longer overseas on <?= $dt($open[$slot]['opened_at']) ?> UTC (<?= $e($open[$slot]['opened_by_name']) ?>): purchase orders
    are refused until a second person confirms it needs no import route. Rejecting deactivates the supplier.</p>
<?php elseif ($slot === 'route'): ?>
  <p>The import route changed on <?= $dt($open[$slot]['opened_at']) ?> UTC (<?= $e($open[$slot]['opened_by_name']) ?>): purchase orders are refused until it is approved.</p>
<?php elseif ($slot === 'review'): ?>
  <p>Details that matter for approval changed on <?= $dt($open[$slot]['opened_at']) ?> UTC (<?= $e($open[$slot]['opened_by_name']) ?>). Approving acknowledges the
    change; rejecting deactivates the supplier.</p>
<?php endif; ?>
  <p class="muted">Due <?= $dt($open[$slot]['due_at']) ?> UTC<?php if ($open[$slot]['overdue']): ?> <span class="tag bad">overdue</span><?php endif; ?>.</p>
<?php if ($open[$slot]['may_decide']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $open[$slot]['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Note (optional) <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary">Approve</button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $open[$slot]['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Why it is rejected (required) <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit">Reject</button>
  </form>
<?php else: ?>
  <p class="note read-only"><?= $e($open[$slot]['refusal']) ?></p>
<?php endif; ?>
<?php if ($open[$slot]['may_withdraw']): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $open[$slot]['id'] . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit">Withdraw the request</button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($canEdit): ?>
<p class="actions"><a class="button" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/edit') ?>">Edit</a></p>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="details-h">
    <h2 id="details-h">Details</h2>
    <dl>
      <dt>Legal name</dt><dd><?= $e($s['legal_name']) ?></dd>
      <dt>Company number</dt><dd><?= $e($s['company_number']) ?></dd>
      <dt>VAT number</dt><dd><?= $e($s['vat_number']) ?></dd>
      <dt>Address</dt><dd><?= $e($s['address_line1']) ?><?php if ($s['address_line2'] !== null): ?>, <?= $e($s['address_line2']) ?><?php endif; ?><?php if ($s['city'] !== null): ?>, <?= $e($s['city']) ?><?php endif; ?> <?= $e($s['postcode']) ?> <?= $e($s['country']) ?></dd>
      <dt>Contact</dt><dd><?= $e($s['contact_name']) ?></dd>
      <dt>E-mail (orders)</dt><dd><?= $e($s['email']) ?></dd>
      <dt>Phone</dt><dd><?= $e($s['phone']) ?></dd>
      <dt>Other contacts</dt><dd><?= $e($s['contacts_note']) ?></dd>
      <dt>Payment terms</dt><dd><?= $e($s['payment_terms']) ?><?php if ($s['payment_terms_days'] !== null): ?> (<?= $n($s['payment_terms_days']) ?> days)<?php endif; ?></dd>
      <dt>Lead days</dt><dd><?= $e($s['default_lead_days']) ?></dd>
      <dt>Order cycle (days)</dt><dd><?= $e($s['review_days']) ?></dd>
      <dt>Minimum order (GBP)</dt><dd><?= $e($s['min_order_value']) ?></dd>
      <dt>Default VAT code</dt><dd><?= $e($s['default_vat_code']) ?></dd>
      <dt>Currency</dt><dd><?= $e($s['currency']) ?></dd>
      <dt>ERPNext name</dt><dd><?= $e($s['erp_name']) ?></dd>
      <dt>Notes</dt><dd><?= $e($s['notes']) ?></dd>
      <dt>Created (UTC)</dt><dd><?= $dt($s['created_at']) ?> by <?= $e($people['created']) ?></dd>
      <dt>Last changed (UTC)</dt><dd><?= $dt($s['updated_at']) ?> by <?= $e($people['updated']) ?></dd>
<?php if ($people['changed'] !== null): ?>
      <dt>Details last changed (UTC)</dt><dd><?= $dt($s['details_changed_at']) ?> by <?= $e($people['changed']) ?></dd>
<?php endif; ?>
    </dl>
  </section>

  <section class="card" aria-labelledby="dd-h">
    <h2 id="dd-h">Due diligence</h2>
    <dl>
      <dt>Checked on</dt><dd><?= $e($s['dd_checked_on']) ?></dd>
      <dt>Checked by</dt><dd><?= $e($people['dd']) ?></dd>
      <dt>Evidence</dt><dd><?= $e($s['dd_evidence']) ?></dd>
      <dt>Evidence file</dt><dd><?php if ($files['dd'] !== null): ?><a href="<?= $u('/ui/files/' . $files['dd']['id']) ?>"><?= $e($files['dd']['original_name']) ?></a> <span class="muted"><?= $e($files['dd']['mime']) ?>, <?= $n($files['dd']['size_bytes']) ?> bytes</span><?php else: ?><span class="muted">none</span><?php endif; ?></dd>
      <dt>Next review</dt><dd><?= $e($s['dd_next_review_on']) ?><?php if ($ddOverdue): ?> <span class="tag bad">overdue</span><?php endif; ?></dd>
    </dl>
    <h3>Import route</h3>
    <dl>
      <dt>Overseas</dt><dd><?php if ((int) $s['is_overseas'] === 1): ?>yes<?php else: ?>no (UK supplier)<?php endif; ?></dd>
<?php if ((int) $s['is_overseas'] === 1 || $s['import_route'] !== null): ?>
      <dt>Route</dt><dd><?= $e($s['import_route']) ?></dd>
      <dt>Route evidence</dt><dd><?php if ($files['import_route'] !== null): ?><a href="<?= $u('/ui/files/' . $files['import_route']['id']) ?>"><?= $e($files['import_route']['original_name']) ?></a><?php else: ?><span class="muted">none</span><?php endif; ?></dd>
      <dt>Approved</dt><dd><?php if ($s['import_route_approved_at'] !== null): ?><?= $dt($s['import_route_approved_at']) ?> UTC by <?= $e($people['route']) ?><?php else: ?><span class="tag warn">not approved</span><?php endif; ?></dd>
<?php endif; ?>
    </dl>
<?php if ($canEdit): ?>
    <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/evidence') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
      <label>Evidence of
        <select name="kind">
          <option value="dd">the due-diligence check</option>
          <option value="import_route">the import route</option>
        </select>
      </label>
      <label>File (PDF, image, CSV, text or XLSX; at most 2 MiB) <input type="file" name="file" required></label>
      <button type="submit">Upload</button>
    </form>
<?php endif; ?>
  </section>
</div>

<section aria-labelledby="items-h">
  <h2 id="items-h">Items</h2>
  <p><?= $n($items['active']) ?> active supplier items (<?= $n($items['n']) ?> in all), <?= $n($items['preferred']) ?> of them the preferred supply of their item<?php if ($items['no_price'] > 0): ?>,
    <?= $n($items['no_price']) ?> without a price<?php endif; ?>.
    <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>">Open the items</a><?php if ($canManage): ?> &middot;
    <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items/new') ?>">Add an item</a><?php endif; ?></p>
</section>

<?php if ($recentPos !== null): ?>
<section aria-labelledby="pos-h">
  <h2 id="pos-h">Recent purchase orders</h2>
<?php if ($recentPos === []): ?>
  <p class="muted">No purchase order yet.</p>
<?php else: ?>
  <table class="orders">
    <thead>
      <tr><th scope="col">Order</th><th scope="col">Order date</th><th scope="col">Expected</th><th scope="col">State</th><th scope="col" class="num">Net (GBP)</th><th scope="col">Review</th></tr>
    </thead>
    <tbody>
<?php foreach ($recentPos as $p): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/purchasing/orders/' . $p['id']) ?>"><?= $e($p['number'] ?? str_replace('_', ' ', $p['status']) . ' #' . $p['id']) ?></a></th>
        <td><?= $e($p['doc_date']) ?></td>
        <td><?= $e($p['expected_date']) ?></td>
        <td><?= $e($p['state'] === null ? str_replace('_', ' ', $p['status']) : str_replace('_', ' ', $p['state'])) ?></td>
        <td class="num"><?= $dec($p['net_total']) ?></td>
        <td><?= $e($p['review_state'] === null ? '' : str_replace('_', ' ', $p['review_state'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
  <p><a href="<?= $u('/ui/purchasing/orders', ['supplier' => $s['id']]) ?>">All of this supplier's orders</a><?php if ($canOrder): ?> &middot;
    <a href="<?= $u('/ui/purchasing/orders', ['supplier' => $s['id']]) ?>#new">New purchase order</a><?php endif; ?></p>
</section>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h">Approvals and reviews</h2>
<?php if ($tasks === []): ?>
  <p class="muted">Nothing was asked for yet.</p>
<?php else: ?>
  <table class="history">
    <thead>
      <tr>
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
