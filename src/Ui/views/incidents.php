<h1>Incidents</h1>
<p class="muted">What a posted receipt found: units short, over, damaged, the wrong item, or unstamped (quarantined in UNSTAMPED or refused at the door).
  Close one with what was done (a credit asked for, the goods returned, the supplier re-delivered) or why nothing is needed. Closing moves no stock: units
  leave VERIFY and UNSTAMPED with a stock control document (coming in Phase I-4).</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="filters" method="get" action="/ui/receiving/incidents">
  <label>Status
    <select name="status">
<?php foreach (['open' => 'open', 'resolved' => 'resolved', 'dismissed' => 'dismissed', 'all' => 'any'] as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($status === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>Kind
    <select name="kind">
      <option value="">Any</option>
<?php foreach ($kinds as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($kind === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Filter</button>
</form>
<?php if ($rows === []): ?>
<p class="note">No incident matches.</p>
<?php else: ?>
<?php foreach ($rows as $r): ?>
<article class="incident card<?php if ($r['status'] === 'open'): ?> waiting<?php endif; ?>">
  <h2><?= $e($kinds[$r['kind']] ?? $r['kind']) ?>: <?= $n($r['units']) ?> units of <?= $e($r['sku_code']) ?> <span class="muted"><?= $e($r['sku_name']) ?></span></h2>
  <p><a href="<?= $u('/ui/receiving/' . $r['document_id']) ?>"><?= $e($r['number']) ?></a> line <?= $n($r['line_no']) ?> &middot; invoice <?= $e($r['external_ref']) ?>
    &middot; <?= $e($r['supplier_code']) ?> <?= $e($r['supplier_name']) ?> &middot; <?= $e($dispositions[$r['disposition']] ?? $r['disposition']) ?>
    &middot; opened <?= $dt($r['opened_at']) ?> UTC by <?= $e($r['opened_by_name']) ?> <span class="status"><?= $e($r['status']) ?></span></p>
<?php if ($r['status'] !== 'open'): ?>
  <p class="muted"><?= $e($r['status']) ?> <?= $dt($r['resolved_at']) ?> UTC by <?= $e($r['resolved_by_name'] ?? $r['resolved_actor']) ?>: <?= $e($r['resolution']) ?></p>
<?php elseif ($canResolve): ?>
  <form class="inline" method="post" action="<?= $u('/ui/receiving/incidents/' . $r['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>
      <select name="status">
        <option value="resolved">Resolved</option>
        <option value="dismissed">Dismissed (nothing needed)</option>
      </select>
    </label>
    <label>What was done <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit">Close</button>
  </form>
<?php endif; ?>
</article>
<?php endforeach; ?>
<?php endif; ?>
