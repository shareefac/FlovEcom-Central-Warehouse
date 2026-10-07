<?php $v = $typed ?? ($settings ?? []); ?>
<p class="crumbs"><a href="/ui/purchasing/reorder"><?= $word('MENU', 'reorder') ?></a> <a href="<?= $u('/ui/items/' . $sku['id']) ?>"><?= $word('REORDER_ITEM', 'product_page') ?></a></p>
<h1><?= $e($title) ?></h1>
<p class="eyebrow"><?php if ($sku['brand'] !== null): ?><?= $e($sku['brand']) ?><?php else: ?><?= $word('REORDER_ITEM', 'no_brand') ?><?php endif; ?><?php if ($sku['merged_into_sku_id'] !== null): ?> <?= $chip('blocked', \CW\Ui\Words::say('REORDER_ITEM', 'merged', (string) $sku['merged_code'])) ?><?php endif; ?></p>
<?= $intro('reorder_item', $canManage ? null : \CW\Ui\Words::whoCan('reorder.manage')) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<section class="card box" aria-labelledby="line-h">
  <h2 id="line-h"><?= $word('REORDER_ITEM', 'suggest') ?></h2>
<?php if ($line === null): ?>
  <?= $empty(\CW\Ui\Words::REORDER_ITEM['not_listed'], \CW\Ui\Words::REORDER_ITEM['not_listed_text']) ?>
<?php else: ?>
  <p><?php foreach ($line['why'] as $sentence): ?><?= $e($sentence) ?> <?php endforeach; ?></p>
<?php foreach ($line['flag_list'] as $fl): ?>
  <?= $chip($fl['tone'], $fl['word']) ?>
<?php endforeach; ?>
  <dl>
    <dt><?= $word('REORDER_ITEM', 'main_supplier') ?></dt>
    <dd><?php if ($line['supplier'] !== null): ?><?= $say('REORDER_ITEM', 'supplier_line', (string) ($line['supplier_name'] ?? $line['supplier']), \CW\Ui\Words::of('SUPPLIER_STATUS', (string) $line['supplier_status']), \CW\Ui\Controller\PurchaseOrdersController::pack((string) $line['purchase_unit'], (int) $line['upp'])) ?><?php if ($line['supplier_code'] !== null): ?>, <?= $say('REORDER_ITEM', 'their_code', (string) $line['supplier_code']) ?><?php endif; ?>, <?= $say('REORDER_ITEM', 'moq', $line['moq'], $line['mult']) ?><?php else: ?><?= $word('REORDER_ITEM', 'no_main') ?><?php endif; ?></dd>
    <dt><?= $word('REORDER_ITEM', 'delivery') ?></dt><dd><?= $say('REORDER_ITEM', 'n_days', $line['lead']) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'every') ?></dt><dd><?= $say('REORDER_ITEM', 'n_days', $line['review']) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'spare') ?></dt><dd><?= $say('REORDER_ITEM', 'n_days', $line['safety']) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'aim') ?></dt><dd><?= $n($line['target']) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'urgent_below') ?></dt><dd><?= $n($line['rop']) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'in_stock') ?></dt><dd><?= $say('REORDER_ITEM', 'in_stock_line', $line['available'], $line['on_order'], $line['in_drafts']) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'suggest_line') ?></dt><dd><?= $say('REORDER_ITEM', 'suggest_value', $line['upp'] > 1 ? \CW\Ui\Words::say('WHY', 'packs', $line['packs'], $line['upp'], $line['units']) : \CW\Ui\Words::say('WHY', 'single', $line['units']), $line['value'] === '' ? '£0.00' : $line['value']) ?></dd>
  </dl>
  <details class="why maths">
    <summary><?= $word('REORDER', 'maths') ?></summary>
    <p><?= $e($line['explain']) ?></p>
  </details>
<?php endif; ?>
</section>

<section class="card box" aria-labelledby="demand-h">
  <h2 id="demand-h"><?= $word('REORDER_ITEM', 'demand') ?></h2>
<?php if ($demand === null): ?>
  <p class="muted"><?= $word('REORDER_ITEM', 'no_demand') ?></p>
<?php else: ?>
  <dl>
    <dt><?= $word('REORDER_ITEM', 'rate') ?></dt><dd><?= $say('REORDER_ITEM', 'rate_line', \CW\Ui\Html::dec($demand['rate']), \CW\Ui\Html::dec($demand['rate_short']), \CW\Ui\Html::dec($demand['rate_long'])) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'plain') ?></dt><dd><?= $say('REORDER_ITEM', 'plain_line', \CW\Ui\Html::dec($demand['rate_raw_30'])) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'year') ?></dt><dd><?= $say('REORDER_ITEM', 'year_line', (int) $demand['units_365'], \CW\Ui\Html::day($demand['first_sale_date']), \CW\Ui\Html::day($demand['last_sale_date'])) ?></dd>
    <dt><?= $word('REORDER_ITEM', 'computed') ?></dt><dd><?= $when($demand['computed_at']) ?></dd>
  </dl>
<?php endif; ?>
<?php if ($monthly !== []): ?>
  <h3><?= $word('REORDER_ITEM', 'by_month') ?></h3>
  <div class="scroll">
  <table class="months">
    <thead><tr><?php foreach (array_keys($monthly) as $m): ?><th scope="col" class="num"><?= $e($m) ?></th><?php endforeach; ?></tr></thead>
    <tbody><tr><?php foreach ($monthly as $m => $units): ?><td class="num" data-label="<?= $e($m) ?>"><?= $n($units) ?></td><?php endforeach; ?></tr></tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section class="card box" aria-labelledby="days-h">
  <h2 id="days-h"><?= $word('REORDER_ITEM', 'days') ?></h2>
<?php if ($liveError !== null): ?>
  <p class="error"><?= $say('REORDER_ITEM', 'days_error', $liveError) ?></p>
<?php elseif ($live === null || $live['listings'] === []): ?>
  <p class="muted"><?= $word('REORDER_ITEM', 'no_days') ?></p>
<?php else: ?>
<?php foreach ($live['listings'] as $l): ?>
  <details>
    <summary><?= $say('REORDER_ITEM', 'days_summary', $channelNames[$l['channel']] ?? $l['channel'], (string) $l['variant'], $l['u'], \CW\Ui\Html::dec($l['rate']), $l['valid_long'], $params['long_window'], max(0, $params['long_window'] - $l['valid_long'])) ?><?php if ($l['capped'] > 0): ?> <?= $say('REORDER_ITEM', 'days_capped', $l['capped'], (int) $l['cap']) ?><?php endif; ?></summary>
    <div class="table-wrap">
    <table class="stack days">
      <thead><tr><th scope="col"><?= $word('REORDER_ITEM', 'day') ?></th><th scope="col" class="num"><?= $word('REORDER_ITEM', 'sold') ?></th><th scope="col"><?= $word('REORDER_ITEM', 'used') ?></th></tr></thead>
      <tbody>
<?php foreach ($l['days'] as $d): ?>
        <tr<?php if ($d['reason'] !== null): ?> class="excluded"<?php endif; ?>><th scope="row" class="c-head"><?= $day($d['date']) ?></th><td class="num" data-label="<?= $word('REORDER_ITEM', 'sold') ?>"><?= $n($d['q']) ?></td>
          <td data-label="<?= $word('REORDER_ITEM', 'used') ?>"><?php if ($d['reason'] === null): ?><?php if ($d['capped']): ?><?= $say('REORDER_ITEM', 'yes_lowered', (int) $l['cap']) ?><?php else: ?><?= $word('REORDER_ITEM', 'yes') ?><?php endif; ?><?php else: ?><?= $say('REORDER_ITEM', 'no', $d['reason'] === 'anomaly' ? (string) ($d['anomaly'] ?? \CW\Ui\Words::REORDER_ITEM['left_out']) : ($dayReason[$d['reason']] ?? \CW\Ui\Words::REORDER_ITEM['left_out'])) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
<?php endforeach; ?>
<?php endif; ?>
</section>

<section class="card box" aria-labelledby="settings-h">
  <h2 id="settings-h"><?= $word('REORDER_ITEM', 'settings') ?></h2>
  <p class="hint"><?= $say('REORDER_ITEM', 'settings_note', (int) ($brand['safety_days'] ?? $params['safety'])) ?><?php if ($brand !== null && $brand['demand_factor'] !== null): ?> <?= $say('REORDER_ITEM', 'settings_brand', \CW\Ui\Html::dec($brand['demand_factor'])) ?><?php endif; ?></p>
<?php if ($canManage): ?>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/reorder/items/' . $sku['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <fieldset>
      <legend class="visually-hidden"><?= $word('REORDER_ITEM', 'settings') ?></legend>
      <label><?= $word('REORDER_ITEM', 'factor') ?> <span class="hint"><?= $word('REORDER_ITEM', 'factor_hint') ?></span> <input type="text" name="demand_factor" value="<?= $e($v['demand_factor'] ?? '') ?>" maxlength="4" class="short"></label>
      <label><?= $word('REORDER_ITEM', 'safety') ?> <span class="hint"><?= $word('REORDER_ITEM', 'safety_hint') ?></span> <input type="number" name="safety_days" value="<?= $e($v['safety_days'] ?? '') ?>" min="0" max="90" class="short"></label>
      <label><?= $word('REORDER_ITEM', 'lead') ?> <span class="hint"><?= $word('REORDER_ITEM', 'lead_hint') ?></span> <input type="number" name="lead_days_override" value="<?= $e($v['lead_days_override'] ?? '') ?>" min="0" max="120" class="short"></label>
      <label><?= $word('REORDER_ITEM', 'min') ?> <input type="number" name="min_stock" value="<?= $e($v['min_stock'] ?? '') ?>" min="0" class="short"></label>
      <label><?= $word('REORDER_ITEM', 'max') ?> <input type="number" name="max_stock" value="<?= $e($v['max_stock'] ?? '') ?>" min="0" class="short"></label>
      <label><?= $word('REORDER_ITEM', 'rounding') ?>
        <select name="pack_rounding">
          <option value=""><?= $word('REORDER_ITEM', 'rounding_default') ?></option>
          <option value="up"<?php if (($v['pack_rounding'] ?? '') === 'up'): ?> selected<?php endif; ?>><?= $word('REORDER_ITEM', 'rounding_up') ?></option>
          <option value="nearest"<?php if (($v['pack_rounding'] ?? '') === 'nearest'): ?> selected<?php endif; ?>><?= $word('REORDER_ITEM', 'rounding_nearest') ?></option>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="do_not_reorder" value="1"<?php if ((string) ($v['do_not_reorder'] ?? '0') === '1'): ?> checked<?php endif; ?>> <?= $word('REORDER_ITEM', 'never') ?></label>
      <label><?= $word('REORDER_ITEM', 'note') ?> <input type="text" name="note" value="<?= $e($v['note'] ?? '') ?>" maxlength="500"></label>
    </fieldset>
    <p class="actions"><button type="submit" class="primary"><?= $word('REORDER_ITEM', 'save') ?></button></p>
  </form>
<?php elseif ($settings === null): ?>
  <p><?= $word('REORDER_ITEM', 'normal') ?></p>
<?php else: ?>
  <ul class="plain">
<?php if ($settings['demand_factor'] !== null): ?>
    <li><?= $say('REORDER_ITEM', 'set_factor', \CW\Ui\Html::dec($settings['demand_factor'])) ?></li>
<?php endif; ?>
<?php if ($settings['safety_days'] !== null): ?>
    <li><?= $say('REORDER_ITEM', 'set_safety', (int) $settings['safety_days']) ?></li>
<?php endif; ?>
<?php if ($settings['lead_days_override'] !== null): ?>
    <li><?= $say('REORDER_ITEM', 'set_lead', (int) $settings['lead_days_override']) ?></li>
<?php endif; ?>
<?php if ($settings['min_stock'] !== null): ?>
    <li><?= $say('REORDER_ITEM', 'set_min', (int) $settings['min_stock']) ?></li>
<?php endif; ?>
<?php if ($settings['max_stock'] !== null): ?>
    <li><?= $say('REORDER_ITEM', 'set_max', (int) $settings['max_stock']) ?></li>
<?php endif; ?>
<?php if (($settings['pack_rounding'] ?? null) === 'nearest'): ?>
    <li><?= $word('REORDER_ITEM', 'set_rounding') ?></li>
<?php endif; ?>
<?php if ((int) ($settings['do_not_reorder'] ?? 0) === 1): ?>
    <li><?= $word('REORDER_ITEM', 'set_never') ?></li>
<?php endif; ?>
<?php if (($settings['note'] ?? null) !== null && $settings['note'] !== ''): ?>
    <li><?= $say('REORDER_ITEM', 'set_note', (string) $settings['note']) ?></li>
<?php endif; ?>
  </ul>
<?php endif; ?>
</section>
