<h1>Reorder list</h1>
<p class="muted">A suggestion per item from its sales on the sites (stockpiling, promotion and out-of-stock days left out), its lead, review and safety
  days, the stock and what is on order. It is advice: tick the lines, adjust the packs, create the draft orders and check them before approving.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($created !== null): ?>
<section class="card box" aria-labelledby="created-h">
  <h2 id="created-h">New draft orders</h2>
<?php if ($created['drafts'] === []): ?>
  <p class="note">No draft order is shown (it may have been cancelled since).</p>
<?php else: ?>
  <ul class="plain">
<?php foreach ($created['drafts'] as $d): ?>
    <li><a href="<?= $u('/ui/purchasing/orders/' . $d['id']) ?>"><?= $e($d['number'] ?? 'draft #' . $d['id']) ?></a> <?= $e($d['supplier']) ?> <span class="muted"><?= $e($d['supplier_name']) ?></span>:
      <?= $n($d['lines']) ?> lines, £<?= $dec($d['net_total']) ?> net<?php if ($d['below_minimum']): ?> <span class="tag warn">below the supplier's minimum order of £<?= $dec($d['min_order_value']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($created['skipped'] !== []): ?>
  <h3>Not ordered</h3>
  <ul class="plain">
<?php foreach ($created['skipped'] as $s): ?>
    <li><a href="<?= $u('/ui/purchasing/reorder/items/' . $s['sku_id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?>: <?= $e($s['reason']) ?></li>
<?php endforeach; ?>
<?php if ($created['more'] > 0): ?>
    <li class="muted">and <?= $n($created['more']) ?> more</li>
<?php endif; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="card box" aria-labelledby="history-h">
  <h2 id="history-h">History and demand</h2>
<?php if ($history['channels'] === []): ?>
  <p class="note">No sales history is loaded yet: the list is empty until it is (<a href="/ui/purchasing/sales-history">Sales history</a>).</p>
<?php else: ?>
  <ul class="plain">
<?php foreach ($history['channels'] as $c): ?>
    <li><?= $e($c['code']) ?>: sales history <?= $e($c['from']) ?> to <?= $e($c['to']) ?><?php if ($c['stale']): ?> <span class="tag warn">ends <?= $n($c['days_ago']) ?> days ago: load the newer days</span><?php endif; ?></li>
<?php endforeach; ?>
    <li>Demand computed <?= $dt($history['computed_at']) ?> (UTC)<?php if ($history['demand_stale']): ?> <span class="tag warn">older than the last import: recalculate</span><?php endif; ?></li>
  </ul>
<?php endif; ?>
  <p class="actions">
<?php if ($canManage): ?>
    <form class="inline" method="post" action="/ui/purchasing/reorder/recalculate">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button type="submit">Recalculate the demand</button>
    </form>
<?php endif; ?>
    <a href="/ui/purchasing/reorder/brands">Brand factors and safety days</a>
    <a href="/ui/purchasing/reorder/anomalies">Anomaly windows</a>
    <a href="/ui/purchasing/sales-history">Sales history</a>
  </p>
</section>

<div class="crumbs">
  <form class="filters" method="get" action="/ui/purchasing/reorder">
    <label>Brand
      <select name="brand">
        <option value="">Any</option>
<?php foreach ($brands as $b): ?>
        <option value="<?= $e($b) ?>"<?php if ($filters['brand'] !== null && mb_strtolower($filters['brand']) === mb_strtolower($b)): ?> selected<?php endif; ?>><?= $e($b) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Preferred supplier
      <select name="supplier">
        <option value="">Any</option>
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === $s['id']): ?> selected<?php endif; ?>><?= $e($s['code']) ?> <?= $e($s['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Item <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="CW code or words"></label>
    <label>Show
      <select name="show">
        <option value="need"<?php if ($filters['show'] === 'need'): ?> selected<?php endif; ?>>Lines to order</option>
        <option value="all"<?php if ($filters['show'] === 'all'): ?> selected<?php endif; ?>>Every item</option>
      </select>
    </label>
    <label>Stock
      <select name="stock">
        <option value="cw"<?php if ($filters['stock'] === 'cw'): ?> selected<?php endif; ?>>CW stock</option>
        <option value="site"<?php if ($filters['stock'] === 'site'): ?> selected<?php endif; ?>>Vape and Go's own stock (while the sites are not live)</option>
      </select>
    </label>
    <label class="choice"><input type="checkbox" name="urgent" value="1"<?php if ($filters['urgent']): ?> checked<?php endif; ?>> Urgent only</label>
    <button type="submit">Filter</button>
  </form>
  <p class="actions">
    <a href="<?= $u('/ui/purchasing/reorder.csv', $query) ?>">Download (CSV, every line)</a>
  </p>
</div>

<?php if ($rows === []): ?>
<p class="note">No line matches<?php if ($filters['show'] === 'need'): ?> (nothing to order: choose "Every item" to see them all)<?php endif; ?>.</p>
<?php else: ?>
<p class="muted"><?= $n($total) ?> lines<?php if ($pages > 1): ?>, page <?= $n($page) ?> of <?= $n($pages) ?><?php endif; ?>. Demand is in central units a day; cover = lead + review + safety days.</p>
<?php if ($canDraft): ?>
<form class="reorder" method="post" action="/ui/purchasing/reorder/draft">
  <input type="hidden" name="row_count" value="<?= $e(count($rows)) ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="stock" value="<?= $e($filters['stock']) ?>">
  <input type="hidden" name="brand" value="<?= $e($filters['brand']) ?>">
  <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
  <input type="hidden" name="q" value="<?= $e($filters['q']) ?>">
  <input type="hidden" name="show" value="<?= $e($filters['show']) ?>">
  <input type="hidden" name="urgent" value="<?= $e($filters['urgent'] ? '1' : '') ?>">
  <input type="hidden" name="page" value="<?= $e($page) ?>">
<?php endif; ?>
<table class="reorder">
  <thead>
    <tr>
<?php if ($canDraft): ?>
      <th scope="col">Order</th>
<?php endif; ?>
      <th scope="col">Item</th>
      <th scope="col">Supplier</th>
      <th scope="col" class="num">Demand / day</th>
      <th scope="col" class="num">Cover days</th>
      <th scope="col" class="num">Target</th>
      <th scope="col" class="num"><?= $e($filters['stock'] === 'site' ? 'Site stock' : 'Available') ?></th>
      <th scope="col" class="num">On order</th>
      <th scope="col" class="num">In drafts</th>
      <th scope="col" class="num">Need</th>
      <th scope="col" class="num">Packs</th>
      <th scope="col" class="num">Units</th>
      <th scope="col" class="num">Pack price</th>
      <th scope="col" class="num">Value</th>
      <th scope="col" class="num">Cover now</th>
      <th scope="col">Flags</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ($r['urgent']): ?> class="urgent"<?php endif; ?>>
<?php if ($canDraft): ?>
      <td><label class="choice"><input type="checkbox" name="pick_<?= $e($r['sku_id']) ?>" value="1"<?php if ($r['packs'] > 0 && (int) $r['in_drafts'] === 0): ?> checked<?php endif; ?>> <span class="muted">tick</span></label></td>
<?php endif; ?>
      <th scope="row"><a href="<?= $u('/ui/purchasing/reorder/items/' . $r['sku_id']) ?>"><?= $e($r['code']) ?></a> <?= $e($r['name']) ?><?php if ($r['brand'] !== null): ?> <span class="muted"><?= $e($r['brand']) ?></span><?php endif; ?>
        <details class="why"><summary>Why</summary><p><?= $e($r['explain']) ?></p></details></th>
      <td><?php if ($r['supplier'] !== null): ?><?= $e($r['supplier']) ?><?php if ($r['supplier_code'] !== null): ?> <span class="muted"><?= $e($r['supplier_code']) ?></span><?php endif; ?><?php else: ?><span class="muted">none</span><?php endif; ?></td>
      <td class="num"><?= $e($r['show_per_day']) ?></td>
      <td class="num"><?= $n($r['cover_days']) ?></td>
      <td class="num"><?= $n($r['target']) ?></td>
      <td class="num"><?= $n($r['available']) ?></td>
      <td class="num"><?= $n($r['on_order']) ?></td>
      <td class="num"><?= $n($r['in_drafts']) ?></td>
      <td class="num"><?= $n($r['need']) ?></td>
      <td class="num"><?php if ($canDraft): ?><input class="packs" type="number" name="packs_<?= $e($r['sku_id']) ?>" value="<?= $e($r['packs']) ?>" min="0" max="1000000" step="1" aria-label="Packs of <?= $e($r['code']) ?>"><?php else: ?><?= $n($r['packs']) ?><?php endif; ?>
        <span class="muted"><?= $e($r['upp'] > 1 ? '× ' . $r['purchase_unit'] . ' of ' . $r['upp'] : '') ?></span></td>
      <td class="num"><?= $n($r['units']) ?></td>
      <td class="num"><?= $dec($r['pack_price']) ?></td>
      <td class="num"><?= $e($r['show_value']) ?></td>
      <td class="num"><?= $e($r['show_cover']) ?></td>
      <td><?php foreach ($r['flags'] as $fl): ?><span class="tag<?= $e(in_array($fl, ['urgent', 'supplier_inactive', 'merged', 'card_blocked'], true) ? ' bad' : (in_array($fl, ['no_supplier', 'supplier_draft', 'supplier_pending_approval', 'no_price', 'site_stock_unreliable', 'in_draft', 'card_warning', 'discontinued'], true) ? ' warn' : '')) ?>"><?= $e($flagText[$fl] ?? $fl) ?><?php if (($r['flag_rules'][$fl] ?? '') !== ''): ?>: <?= $e($r['flag_rules'][$fl]) ?><?php endif; ?></span><?php endforeach; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php if ($canDraft): ?>
  <p class="actions">
    <button type="submit" class="primary">Create draft orders from the ticked lines</button>
    <span class="muted">One draft per preferred supplier, at the last price; the suggestion is kept on each line. A line already in a draft order is
      not ticked (its suggestion does not count the draft): tick it only to order more.</span>
  </p>
</form>
<?php endif; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/purchasing/reorder', $query + ['page' => $page - 1]) ?>">Previous page</a>
<?php endif; ?>
  <span>Page <?= $n($page) ?> of <?= $n($pages) ?></span>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/purchasing/reorder', $query + ['page' => $page + 1]) ?>">Next page</a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
