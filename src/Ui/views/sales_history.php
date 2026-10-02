<h1>Sales history</h1>
<p class="muted">Daily sales per site variant, exported read-only from the sites (tools/sales_history/export.php) and loaded with
  bin/import_sales_history.php. Sales are mapped to items when the demand is computed, so a variant linked later counts from then on.</p>
<?php if ($channels === []): ?>
<p class="note">No sales history is loaded yet (docs/ops.md, "Sales history").</p>
<?php endif; ?>
<?php foreach ($channels as $c): ?>
<section class="card box" aria-labelledby="ch-<?= $e($c['code']) ?>">
  <h2 id="ch-<?= $e($c['code']) ?>"><?= $e($c['code']) ?> <span class="muted"><?= $e($c['name']) ?></span></h2>
  <dl>
    <dt>Coverage</dt> <dd><?= $e($c['s']) ?> to <?= $e($c['e']) ?></dd>
    <dt>Stock snapshot days</dt> <dd><?php if ($c['snapshot_days'] === 0): ?>none (out-of-stock days cannot be told apart)<?php else: ?><?= $n($c['snapshot_days']) ?> (<?= $e($c['snapshot_first']) ?> to <?= $e($c['snapshot_last']) ?>)<?php endif; ?></dd>
    <dt>Site stock (latest)</dt> <dd><?php if ($c['latest_date'] === null): ?>none<?php else: ?><?= $n($c['latest_rows']) ?> variants on <?= $e($c['latest_date']) ?><?php endif; ?></dd>
  </dl>
  <h3>Selling but counting for no item: the top <?= $n($top) ?> in the last <?= $n($days) ?> days</h3>
<?php if ($c['top'] === []): ?>
  <p class="muted">Every variant that sold is linked to an item.</p>
<?php else: ?>
  <table class="unlinked">
    <thead>
      <tr>
        <th scope="col">Variant</th>
        <th scope="col" class="num">Units</th>
        <th scope="col">Last sale</th>
        <th scope="col">Mapping</th>
        <th scope="col">Site title</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($c['top'] as $t): ?>
      <tr>
        <th scope="row"><?php if ($canLink && $t['listing_id'] !== null): ?><a href="<?= $u('/ui/review/listing/' . $t['listing_id']) ?>"><?= $e($t['variant']) ?></a><?php else: ?><?= $e($t['variant']) ?><?php endif; ?></th>
        <td class="num"><?= $n($t['units']) ?></td>
        <td><?= $e($t['last_sale']) ?></td>
        <td><?= $e($t['mapping']) ?></td>
        <td><?= $e($t['product_title']) ?><?php if ($t['variant_title'] !== null): ?> <span class="muted"><?= $e($t['variant_title']) ?></span><?php endif; ?><?php if ($t['brand'] !== null): ?> <span class="tag"><?= $e($t['brand']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
  <p class="actions"><a href="<?= $u('/ui/purchasing/sales-history/unlinked.csv', ['channel' => $c['code']]) ?>">Download every unknown or unlinked variant (CSV)</a></p>
</section>
<?php endforeach; ?>
<?php if ($batches !== []): ?>
<h2>Import batches</h2>
<table class="batches">
  <thead>
    <tr>
      <th scope="col">Batch</th>
      <th scope="col">Site</th>
      <th scope="col">Days</th>
      <th scope="col">State</th>
      <th scope="col" class="num">Rows</th>
      <th scope="col" class="num">Units</th>
      <th scope="col" class="num">Unknown units</th>
      <th scope="col" class="num">Unlinked units</th>
      <th scope="col" class="num">Stock days</th>
      <th scope="col">Exported / loaded (UTC)</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($batches as $b): ?>
    <tr>
      <th scope="row"><?= $n($b['id']) ?> <span class="muted"><?= $e($b['sales_file']) ?></span></th>
      <td><?= $e($b['channel']) ?> <span class="muted"><?= $e($b['source']) ?></span></td>
      <td><?= $e($b['date_from']) ?> to <?= $e($b['date_to']) ?></td>
      <td><?= $e($b['status']) ?><?php if ($b['error'] !== null): ?> <span class="tag bad"><?= $e($b['error']) ?></span><?php endif; ?></td>
      <td class="num"><?= $n($b['rows_loaded']) ?></td>
      <td class="num"><?= $n($b['units_loaded']) ?></td>
      <td class="num"><?= $n($b['unknown_units']) ?> <span class="muted"><?= $pct((int) $b['unknown_units'], (int) $b['units_loaded']) ?></span></td>
      <td class="num"><?= $n($b['unlinked_units']) ?> <span class="muted"><?= $pct((int) $b['unlinked_units'], (int) $b['units_loaded']) ?></span></td>
      <td class="num"><?= $n($b['stock_rows']) ?></td>
      <td><?= $dt($b['exported_at']) ?> / <?= $dt($b['finished_at']) ?> <span class="muted"><?= $e($b['actor']) ?></span></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
