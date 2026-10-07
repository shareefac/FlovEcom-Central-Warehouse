<h1>Receive + invoice</h1>
<p class="muted">One receipt per supplier invoice: the purchasing desk keys it (copy the order down, import the supplier's sheet, or scan), attaches the
  invoice and posts it after the goods-in bench has checked the delivery (duty stamps, damage, short or over). Posting books the stock at once; a second
  person reviews every receipt within 3 days.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($formKey !== null): ?>
<section class="card box" aria-labelledby="new-h" id="new">
  <h2 id="new-h">New delivery</h2>
<?php if ($newSuppliers === []): ?>
  <p class="note">There is no supplier yet: a buyer adds one and a second person approves it before anything can be received from it.</p>
<?php else: ?>
  <form class="record receive-new" method="post" action="/ui/receiving">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label>Against a purchase order (optional)
      <select name="po_id" data-ticks="copy">
        <option value="">No purchase order</option>
<?php foreach ($orders as $o): ?>
        <option value="<?= $e($o['id']) ?>"<?php if (($typed['po_id'] ?? '') === (string) $o['id']): ?> selected<?php endif; ?>><?= $e($o['number']) ?> &middot; <?= $e($o['supplier_code']) ?> <?= $e($o['supplier_name']) ?> &middot; <?= $n($o['outstanding']) ?> units to come (<?= $e(str_replace('_', ' ', $o['state'])) ?>)</option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Supplier (not needed with a purchase order)
      <select name="supplier_id">
        <option value="">The order's supplier</option>
<?php foreach ($newSuppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if (($typed['supplier_id'] ?? '') === (string) $s['id']): ?> selected<?php endif; ?>><?= $e($s['code']) ?> <?= $e($s['name']) ?><?php if ($s['status'] !== 'active'): ?> (<?= $e(str_replace('_', ' ', $s['status'])) ?>)<?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Supplier invoice number <input type="text" name="invoice_number" maxlength="64" value="<?= $e($typed['invoice_number'] ?? '') ?>" autocomplete="off"></label>
    <label class="choice"><input type="checkbox" name="copy" value="1" id="copy"<?php if (($typed['copy'] ?? (($typed['po_id'] ?? '') !== '' ? '1' : '')) === '1'): ?> checked<?php endif; ?>> Receive all as ordered (with a purchase order: copy its outstanding lines, then edit)</label>
    <p><button type="submit" class="primary">Start the receipt</button></p>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<div class="crumbs">
  <form class="filters" method="get" action="/ui/receiving">
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
    <label>Number, invoice or order <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100"></label>
    <button type="submit">Filter</button>
  </form>
  <p class="actions"><a href="/ui/receiving/template.csv">A sample supplier sheet (CSV)</a></p>
</div>
<?php if ($rows === []): ?>
<p class="note">No receipt matches.</p>
<?php else: ?>
<div class="scroll">
<table class="stack receipts">
  <thead>
    <tr>
      <th scope="col">Receipt</th>
      <th scope="col">Supplier</th>
      <th scope="col">Invoice</th>
      <th scope="col">Order</th>
      <th scope="col">Received (UK)</th>
      <th scope="col" class="num">Lines</th>
      <th scope="col" class="num">Units</th>
      <th scope="col">State</th>
      <th scope="col">Bench</th>
      <th scope="col">Incidents</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="<?= $u('/ui/receiving/' . $r['id']) ?>"><?= $e($r['label']) ?></a></th>
      <td data-label="Supplier"><?= $e($r['supplier_code']) ?> <span class="muted"><?= $e($r['supplier_name']) ?></span></td>
      <td data-label="Invoice"><?= $e($r['external_ref']) ?></td>
      <td data-label="Order"><?= $e($r['po_number']) ?></td>
      <td data-label="Received"><?= $e($r['received_uk']) ?><?php if ((int) $r['paper_sheet'] === 1): ?> <span class="tag warn">paper sheet</span><?php endif; ?></td>
      <td class="num" data-label="Lines"><?= $n($r['lines']) ?></td>
      <td class="num" data-label="Units"><?= $n($r['units']) ?></td>
      <td data-label="State"><span class="status"><?= $e(str_replace('_', ' ', $r['status'])) ?></span><?php if ($r['review_state'] === 'rejected'): ?> <span class="tag bad">rejected</span><?php elseif ($r['review_state'] === 'pending'): ?> <span class="tag">review due</span><?php endif; ?></td>
      <td data-label="Bench"><?php if ($r['checked_at'] !== null): ?><span class="tag ok">checked</span><?php elseif ($r['status'] === 'draft'): ?><span class="tag warn">to check</span><?php endif; ?></td>
      <td data-label="Incidents"><?php if ((int) $r['open_incidents'] > 0): ?><span class="tag warn"><?= $n($r['open_incidents']) ?> open</span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted">The newest <?= $n($limit) ?> are shown: narrow the filter.</p>
<?php endif; ?>
<?php endif; ?>
