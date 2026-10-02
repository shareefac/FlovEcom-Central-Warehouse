<?php $v = $typed ?? ($settings ?? []); ?>
<p class="crumbs"><a href="/ui/purchasing/reorder">Reorder list</a> <a href="<?= $u('/ui/items/' . $sku['id']) ?>">Item page</a></p>
<h1>Reorder: <?= $e($sku['code']) ?> <?= $e($sku['name']) ?></h1>
<p class="muted"><?= $e($sku['brand'] ?? 'no brand') ?><?php if ($sku['merged_into_sku_id'] !== null): ?> <span class="tag bad">merged into <?= $e($sku['merged_code']) ?>: never suggested</span><?php endif; ?></p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<section class="card box" aria-labelledby="line-h">
  <h2 id="line-h">The line</h2>
<?php if ($line === null): ?>
  <p class="note">This item has no demand row and no minimum stock: it is not on the reorder list (no linked listing sold in the loaded history).</p>
<?php else: ?>
  <p><?= $e($line['explain']) ?></p>
  <dl>
    <dt>Preferred supplier</dt> <dd><?php if ($line['supplier'] !== null): ?><?= $e($line['supplier']) ?> <?= $e($line['supplier_name']) ?> (<?= $e($line['supplier_status']) ?>)<?php if ($line['supplier_code'] !== null): ?>, code <?= $e($line['supplier_code']) ?><?php endif; ?>, <?= $e($line['purchase_unit']) ?> of <?= $n($line['upp']) ?>, MOQ <?= $n($line['moq']) ?>, multiple <?= $n($line['mult']) ?><?php else: ?>none: mark one of the item's supplier items as preferred<?php endif; ?></dd>
    <dt>Lead / review / safety</dt> <dd><?= $n($line['lead']) ?> / <?= $n($line['review']) ?> / <?= $n($line['safety']) ?> days</dd>
    <dt>Target / reorder point</dt> <dd><?= $n($line['target']) ?> / <?= $n($line['rop']) ?></dd>
    <dt>Available / on order / in drafts</dt> <dd><?= $n($line['available']) ?> / <?= $n($line['on_order']) ?> / <?= $n($line['in_drafts']) ?></dd>
    <dt>Need / packs / units</dt> <dd><?= $n($line['need']) ?> / <?= $n($line['packs']) ?> / <?= $n($line['units']) ?></dd>
  </dl>
<?php endif; ?>
</section>

<section class="card box" aria-labelledby="demand-h">
  <h2 id="demand-h">Demand (stored)</h2>
<?php if ($demand === null): ?>
  <p class="note">No demand row: the last recalculation found no sale of this item's listings.</p>
<?php else: ?>
  <dl>
    <dt>Rate</dt> <dd><?= $dec($demand['rate']) ?> units a day (short window <?= $dec($demand['rate_short']) ?>, long window <?= $dec($demand['rate_long']) ?>)</dd>
    <dt>Plain 30-day average</dt> <dd><?= $dec($demand['rate_raw_30']) ?> units a day (no exclusions, as ERPNext's "Fetch Item")</dd>
    <dt>Units in 365 days</dt> <dd><?= $n($demand['units_365']) ?> (first sale <?= $e($demand['first_sale_date']) ?>, last <?= $e($demand['last_sale_date']) ?>)</dd>
    <dt>Computed</dt> <dd><?= $dt($demand['computed_at']) ?> UTC</dd>
  </dl>
<?php endif; ?>
<?php if ($monthly !== []): ?>
  <table class="months">
    <thead><tr><?php foreach (array_keys($monthly) as $m): ?><th scope="col" class="num"><?= $e($m) ?></th><?php endforeach; ?></tr></thead>
    <tbody><tr><?php foreach ($monthly as $units): ?><td class="num"><?= $n($units) ?></td><?php endforeach; ?></tr></tbody>
  </table>
<?php endif; ?>
</section>

<section class="card box" aria-labelledby="days-h">
  <h2 id="days-h">Day by day (computed now)</h2>
<?php if ($liveError !== null): ?>
  <p class="error"><?= $e($liveError) ?></p>
<?php elseif ($live === null || $live['listings'] === []): ?>
  <p class="note">No linked listing of this item sold in the loaded history.</p>
<?php else: ?>
<?php foreach ($live['listings'] as $l): ?>
  <details>
    <summary><?= $e($l['channel']) ?> variant <?= $e($l['variant']) ?> (× <?= $n($l['u']) ?>): <?= $dec($l['rate']) ?> a day, <?= $e($l['method']) ?>; <?= $n($l['valid_long']) ?> valid days of <?= $n($params['long_window']) ?>, <?= $n($l['valid_short']) ?> of the last <?= $n($params['short_window']) ?><?php if ($l['capped'] > 0): ?>; <?= $n($l['capped']) ?> capped at <?= $n($l['cap']) ?><?php endif; ?></summary>
    <table class="days">
      <thead><tr><th scope="col">Day</th><th scope="col" class="num">Units</th><th scope="col">Counted</th></tr></thead>
      <tbody>
<?php foreach ($l['days'] as $d): ?>
        <tr<?php if ($d['reason'] !== null): ?> class="excluded"<?php endif; ?>><td><?= $e($d['date']) ?></td><td class="num"><?= $n($d['q']) ?></td>
          <td><?php if ($d['reason'] === null): ?>yes<?php if ($d['capped']): ?> <span class="tag warn">capped at <?= $n($l['cap']) ?></span><?php endif; ?><?php else: ?>no: <?= $e($d['reason'] === 'anomaly' ? ($d['anomaly'] ?? 'anomaly') : ($reasonText[$d['reason']] ?? $d['reason'])) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </details>
<?php endforeach; ?>
<?php endif; ?>
</section>

<section class="card box" aria-labelledby="settings-h">
  <h2 id="settings-h">Settings of this item</h2>
  <p class="muted">Empty = the brand's or the default: safety <?= $n($brand['safety_days'] ?? $params['safety']) ?> days<?php if ($brand !== null && $brand['demand_factor'] !== null): ?>, brand factor <?= $dec($brand['demand_factor']) ?><?php endif; ?>, lead from the supplier item, the supplier or <?= $n($params['lead']) ?> days, packs rounded up.</p>
<?php if ($canManage): ?>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/reorder/items/' . $sku['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <fieldset>
      <legend>Demand and cover</legend>
      <label>Demand factor (0 to 5; 0.85 = 15% less) <input type="text" name="demand_factor" value="<?= $e($v['demand_factor'] ?? '') ?>" maxlength="4" class="short"></label>
      <label>Safety days (0 to 90) <input type="number" name="safety_days" value="<?= $e($v['safety_days'] ?? '') ?>" min="0" max="90" class="short"></label>
      <label>Lead days (overrides the supplier's; 0 to 120) <input type="number" name="lead_days_override" value="<?= $e($v['lead_days_override'] ?? '') ?>" min="0" max="120" class="short"></label>
      <label>Minimum stock <input type="number" name="min_stock" value="<?= $e($v['min_stock'] ?? '') ?>" min="0" class="short"></label>
      <label>Maximum stock <input type="number" name="max_stock" value="<?= $e($v['max_stock'] ?? '') ?>" min="0" class="short"></label>
      <label>Pack rounding
        <select name="pack_rounding">
          <option value="">Default (up)</option>
          <option value="up"<?php if (($v['pack_rounding'] ?? '') === 'up'): ?> selected<?php endif; ?>>Up to a whole pack</option>
          <option value="nearest"<?php if (($v['pack_rounding'] ?? '') === 'nearest'): ?> selected<?php endif; ?>>To the nearest pack</option>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="do_not_reorder" value="1"<?php if ((string) ($v['do_not_reorder'] ?? '0') === '1'): ?> checked<?php endif; ?>> Do not reorder (never suggested)</label>
      <label>Note <input type="text" name="note" value="<?= $e($v['note'] ?? '') ?>" maxlength="500"></label>
    </fieldset>
    <p><button type="submit" class="primary">Save the settings</button></p>
  </form>
<?php else: ?>
  <dl>
    <dt>Demand factor</dt> <dd><?= $e($settings['demand_factor'] ?? 'default') ?></dd>
    <dt>Safety days</dt> <dd><?= $e($settings['safety_days'] ?? 'default') ?></dd>
    <dt>Lead days</dt> <dd><?= $e($settings['lead_days_override'] ?? 'default') ?></dd>
    <dt>Minimum / maximum stock</dt> <dd><?= $e($settings['min_stock'] ?? '-') ?> / <?= $e($settings['max_stock'] ?? '-') ?></dd>
    <dt>Pack rounding</dt> <dd><?= $e($settings['pack_rounding'] ?? 'up') ?></dd>
    <dt>Do not reorder</dt> <dd><?= $e((int) ($settings['do_not_reorder'] ?? 0) === 1 ? 'yes' : 'no') ?></dd>
  </dl>
<?php endif; ?>
</section>
