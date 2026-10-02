<h1><?= $e($sku['code']) ?> <span class="muted"><?= $e($sku['name']) ?></span></h1>

<?php if ($merged_into !== null): ?>
<p class="note">This item was merged into <a href="/ui/items/<?= $e($merged_into['id']) ?>"><?= $e($merged_into['code']) ?></a> <?= $e($merged_into['name']) ?>. Its listings and stock moved there.</p>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="id-h">
    <h2 id="id-h">Identity</h2>
    <dl>
      <dt>Brand</dt><dd><?= $e($sku['brand']) ?></dd>
      <dt>Strength (mg)</dt><dd><?= $e($sku['strength_mg']) ?></dd>
      <dt>Nicotine type</dt><dd><?= $e($sku['nic_type']) ?></dd>
      <dt>Range / line</dt><dd><?= $e($sku['line']) ?></dd>
      <dt>Form</dt><dd><?= $e($sku['form']) ?></dd>
      <dt>Flavour</dt><dd><?= $e($sku['flavour']) ?></dd>
      <dt>Volume (ml)</dt><dd><?= $e($sku['volume_ml']) ?></dd>
      <dt>Puffs</dt><dd><?= $e($sku['puffs']) ?></dd>
      <dt>Pack units</dt><dd><?= $e($sku['pack_units']) ?></dd>
      <dt>Sell policy</dt><dd><?= $e($sku['policy']) ?><?php if ($sku['policy'] !== 'legacy'): ?> <span class="tag">protected</span><?php endif; ?></dd>
      <dt>Origin</dt><dd><?= $e($sku['origin']) ?><?php if ($sku['origin_listing_id'] !== null): ?> <a href="/ui/review/listing/<?= $e($sku['origin_listing_id']) ?>">listing #<?= $e($sku['origin_listing_id']) ?></a><?php endif; ?><?php if ($sku['cwp'] !== null): ?> <span class="muted">(<?= $e($sku['cwp']) ?> in the first-match files)</span><?php endif; ?></dd>
      <dt>Created</dt><dd><?= $dt($sku['created_at']) ?></dd>
      <dt>Last counted</dt><dd><?php if ($sku['counted_at'] === null): ?><span class="muted">never</span><?php else: ?><?= $dt($sku['counted_at']) ?><?php endif; ?></dd>
    </dl>
  </section>

  <section class="card" aria-labelledby="bc-h">
    <h2 id="bc-h">Barcodes</h2>
<?php if ($barcodes === []): ?>
    <p class="muted">No barcode is known for this item.</p>
<?php else: ?>
    <table>
      <thead><tr><th scope="col">Barcode</th><th scope="col" class="num">Units per scan</th><th scope="col">Source</th><th scope="col">Usable</th></tr></thead>
      <tbody>
<?php foreach ($barcodes as $b): ?>
        <tr>
          <td><?= $e($b['barcode']) ?></td>
          <td class="num"><?= $e($b['units']) ?></td>
          <td><?= $e($b['source']) ?></td>
          <td><?php if ($b['usable']): ?>yes<?php else: ?>no<?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>
</div>

<?php if ($suppliers !== null): ?>
<section aria-labelledby="suppliers-h">
  <h2 id="suppliers-h">Suppliers</h2>
<?php if ($suppliers === []): ?>
  <p class="muted">No supplier sells us this item yet.</p>
<?php else: ?>
  <table class="item-suppliers">
    <thead>
      <tr>
        <th scope="col">Supplier</th>
        <th scope="col">Supplier's code</th>
        <th scope="col">Pack</th>
        <th scope="col">Preferred</th>
        <th scope="col" class="num">Last price (per pack)</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($suppliers as $r): ?>
      <tr<?php if (!$r['active']): ?> class="inactive"<?php endif; ?>>
        <td><a href="<?= $u('/ui/purchasing/supplier-items/' . $r['id']) ?>"><?= $e($r['supplier']) ?></a> <?= $e($r['name']) ?><?php if ($r['status'] !== 'active'): ?> <span class="tag"><?= $e(str_replace('_', ' ', $r['status'])) ?></span><?php endif; ?><?php if (!$r['active']): ?> <span class="tag">not used</span><?php endif; ?></td>
        <td><?= $e($r['code']) ?></td>
        <td><?= $e($r['pack']) ?></td>
        <td><?php if ($r['preferred']): ?><span class="tag ok">preferred</span><?php endif; ?></td>
        <td class="num"><?= $e($r['price']) ?><?php if ($r['price_on'] !== null): ?> <span class="muted"><?= $e($r['price_on']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="listings-h">
  <h2 id="listings-h">Listings on the sites</h2>
<?php if ($listings === []): ?>
  <p class="muted">No listing is linked to this item, and none ever was.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th scope="col">Listing</th>
        <th scope="col">Site</th>
        <th scope="col">Status</th>
        <th scope="col" class="num">Per item</th>
        <th scope="col" class="num">30 days</th>
        <th scope="col" class="num">365 days</th>
        <th scope="col">Link history</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($listings as $r): ?>
      <tr>
        <td>
          <a href="/ui/review/listing/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? '(no title)') ?></a>
<?php if ($r['variant_title'] !== null): ?>
          <span class="muted">&middot; <?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        </td>
        <td><?= $e($r['channel']) ?> <span class="muted"><?= $e($r['variant']) ?></span></td>
        <td>
          <span class="status status-<?= $e($r['status']) ?>"><?= $e($r['status']) ?></span>
<?php if ($r['linked']): ?>
          <span class="tag">linked now</span>
<?php elseif ($r['elsewhere']): ?>
          <span class="tag">linked elsewhere now</span>
<?php else: ?>
          <span class="tag">not linked now</span>
<?php endif; ?>
        </td>
        <td class="num"><?= $e($r['units_per_item']) ?></td>
        <td class="num"><?= $n($r['units_30d'] ?? 0) ?></td>
        <td class="num"><?= $n($r['units_365d'] ?? 0) ?></td>
        <td>
<?php if ($r['periods'] === []): ?>
          <span class="muted">none</span>
<?php else: ?>
          <ul class="plain">
<?php foreach ($r['periods'] as $p): ?>
            <li><?= $dt($p['from']) ?> to <?php if ($p['to'] === null): ?>now<?php else: ?><?= $dt($p['to']) ?><?php endif; ?>:
              <?php if ($p['this_item']): ?>this item<?php else: ?><?= $e($p['sku_code']) ?><?php endif; ?>
              x<?= $e($p['units']) ?>, <?= $e($p['action']) ?> by <?= $e($p['decider']) ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section aria-labelledby="stock-h">
  <h2 id="stock-h">Stock</h2>
<?php if ($stock === []): ?>
  <p class="muted">No stock has ever been recorded for this item.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th scope="col">Warehouse</th>
        <th scope="col" class="num">On hand</th>
        <th scope="col" class="num">Allocated</th>
        <th scope="col" class="num">Held</th>
        <th scope="col" class="num">Available</th>
        <th scope="col">Last counted</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($stock as $b): ?>
      <tr>
        <th scope="row"><?= $e($b['code']) ?> <span class="muted"><?= $e($b['name']) ?></span><?php if (!$b['sellable']): ?> <span class="tag">not sellable</span><?php endif; ?></th>
        <td class="num"><?= $n($b['on_hand']) ?></td>
        <td class="num"><?= $n($b['allocated']) ?></td>
        <td class="num"><?= $n($b['held']) ?></td>
        <td class="num"><?= $n($b['available']) ?></td>
        <td><?php if ($b['counted_at'] === null): ?><span class="muted">never</span><?php else: ?><?= $dt($b['counted_at']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="sub">
        <th scope="row">Sellable warehouses</th>
        <td class="num"><?= $n($totals['on_hand']) ?></td>
        <td class="num"><?= $n($totals['allocated']) ?></td>
        <td class="num"><?= $n($totals['held']) ?></td>
        <td class="num"><?= $n($totals['available']) ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section aria-labelledby="ledger-h">
  <h2 id="ledger-h">Recent stock movements</h2>
<?php if ($ledger === []): ?>
  <p class="muted">No movements yet.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th scope="col">When</th>
        <th scope="col">Warehouse</th>
        <th scope="col">Bucket</th>
        <th scope="col" class="num">Change</th>
        <th scope="col" class="num">Balance</th>
        <th scope="col">Type</th>
        <th scope="col">Reference</th>
        <th scope="col">Who</th>
        <th scope="col">Note</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($ledger as $r): ?>
      <tr>
        <td><?= $dt($r['at']) ?></td>
        <td><?= $e($r['warehouse']) ?></td>
        <td><?= $e($r['bucket']) ?></td>
        <td class="num"><?= $e($r['delta']) ?></td>
        <td class="num"><?= $e($r['after']) ?></td>
        <td><?= $e($r['type']) ?></td>
        <td><?= $e($r['ref']) ?></td>
        <td><?= $e($r['actor']) ?></td>
        <td><?= $e($r['note']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
