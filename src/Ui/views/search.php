<h1>Search</h1>

<form class="filters" method="get" action="/ui/search">
  <label>Item name, barcode, CW code, or a site variant id
    <input type="search" name="q" value="<?= $e($text) ?>" maxlength="100" autofocus>
  </label>
  <button type="submit" class="primary">Search</button>
</form>

<?php if ($short): ?>
<p class="muted">Type at least two characters.</p>
<?php elseif ($text !== ''): ?>
<section aria-labelledby="items-h">
  <h2 id="items-h">Items</h2>
<?php if ($skus === []): ?>
  <p class="muted">No item matches.</p>
<?php else: ?>
  <table>
    <thead><tr><th scope="col">Item</th><th scope="col">Brand</th><th scope="col">Sell policy</th><th scope="col">Barcodes</th></tr></thead>
    <tbody>
<?php foreach ($skus as $s): ?>
      <tr>
        <td><a href="/ui/items/<?= $e($s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?></td>
        <td><?= $e($s['brand']) ?></td>
        <td><?= $e($s['policy']) ?></td>
        <td class="muted"><?= $e(implode(', ', $s['barcodes'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section aria-labelledby="listings-h">
  <h2 id="listings-h">Listings</h2>
<?php if ($listings === []): ?>
  <p class="muted">No listing matches.</p>
<?php else: ?>
  <table>
    <thead><tr><th scope="col">Listing</th><th scope="col">Site</th><th scope="col">Status</th><th scope="col">Linked to</th><th scope="col" class="num">30 days</th></tr></thead>
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
        <td><span class="status status-<?= $e($r['status']) ?>"><?= $e($r['status']) ?></span></td>
        <td><?php if ($r['sku_id'] !== null): ?><a href="/ui/items/<?= $e($r['sku_id']) ?>"><?= $e($r['sku_code']) ?></a><?php else: ?><span class="muted">not linked</span><?php endif; ?></td>
        <td class="num"><?= $n($r['units_30d'] ?? 0) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php endif; ?>
