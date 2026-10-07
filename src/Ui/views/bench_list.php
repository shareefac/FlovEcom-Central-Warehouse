<h1>Goods-in bench</h1>
<p class="muted">The deliveries keyed by the purchasing desk and not posted yet, the earliest first. Check each one on the tablet: the duty stamp on the outer
  retail pack (and that it seals it), its type, anything short, over, damaged, the wrong item or unstamped, the supplier and the paperwork; photograph what
  is wrong. The person who checks a delivery never reviews its receipt.</p>
<?php if ($rows === []): ?>
<p class="note">Nothing to check: no receipt is waiting.</p>
<?php else: ?>
<div class="scroll">
<table class="stack bench-list">
  <thead>
    <tr>
      <th scope="col">Delivery</th>
      <th scope="col">Supplier</th>
      <th scope="col">Invoice</th>
      <th scope="col">Received (UK)</th>
      <th scope="col" class="num">Lines</th>
      <th scope="col" class="num">Units</th>
      <th scope="col">Checked</th>
      <th scope="col">Keyed by</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a class="button" href="<?= $u('/ui/receiving/' . $r['id'] . '/bench') ?>">Check: <?= $e($r['supplier_code']) ?>, <?php if ($r['external_ref'] !== null): ?>invoice <?= $e($r['external_ref']) ?><?php else: ?>no invoice yet (#<?= $n($r['id']) ?>)<?php endif; ?></a></th>
      <td data-label="Supplier"><?= $e($r['supplier_code']) ?> <span class="muted"><?= $e($r['supplier_name']) ?></span><?php if ($r['po_number'] !== null): ?> <span class="muted">(<?= $e($r['po_number']) ?>)</span><?php endif; ?></td>
      <td data-label="Invoice"><?= $e($r['external_ref']) ?></td>
      <td data-label="Received"><?= $e($r['received_uk']) ?></td>
      <td class="num" data-label="Lines"><?= $n($r['lines']) ?></td>
      <td class="num" data-label="Units"><?= $n($r['units']) ?></td>
      <td data-label="Checked"><?= $n($r['checked_lines']) ?> of <?= $n($r['lines']) ?> lines counted<?php if ($r['checked_at'] !== null): ?>, paperwork <?php if ((int) $r['paperwork_ok'] === 1): ?><span class="tag ok">credible</span><?php else: ?><span class="tag bad">not credible</span><?php endif; ?> (<?= $e($r['checked_by_name']) ?>)<?php endif; ?></td>
      <td data-label="Keyed by"><?= $e($r['created_by_name']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
