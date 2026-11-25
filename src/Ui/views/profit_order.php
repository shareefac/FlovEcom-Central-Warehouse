<?php
// Profit › one order (docs/decisions.md PR27): its money (goods sold ex VAT, buying cost, profit and margin, with its re-sends' cost when
// it has re-sends; delivery charged and refunded; the refunds not on a line; the shop's order total: what it asked for, PR147; no profit or
// margin while it is not paid), its lines as one row per product,
// cost of one unit, where the cost came from and state (PR84: their units counted; sale ex VAT, cost, profit and margin added up; cancelled,
// free, back in stock, refunded or no longer on the order said in words), its parcels and its re-sends. Read only.
// Delivery on its own table, never in the goods margin (0077, docs/decisions.md DL23): delivery charged (no VAT, less refunded), what its
// parcels' carrier bill lines and the collections on it cost, its re-sends' when it is a first order, and delivery after its cost; each
// parcel with its bill lines or "not billed yet".
// Its PayPal money (0080, docs/decisions.md PPF16): each PayPal row on it (a sale's fee, a refund's fee back, a chargeback's money and fees),
// the day it counts on and what it costs; none: no table.
$o = $order;
$sale = (int) $o['sale_ex'];
$cost = (int) $o['cost'];
$resendCost = (int) $o['resend_cost'];
$sourceWords = ['erp_at_sale' => \CW\Ui\Words::PROFIT['source_erp_at_sale'], 'cw_cost' => \CW\Ui\Words::PROFIT['source_cw_cost'],
    'estimate' => \CW\Ui\Words::PROFIT['source_estimate'], 'none' => \CW\Ui\Words::PROFIT['source_none']];
?>
<?= $head(\CW\Ui\Words::say('PROFIT', 'order_title', $o['order_no'] !== '' ? (string) $o['order_no'] : (string) $o['order_ref']), intro: 'profit_order',
    aside: $explain('profit_cost', \CW\Ui\Words::PROFIT['cost_from']), back: ['/ui/profit/orders', \CW\Ui\Words::PROFIT['back']]) ?>
<?php if ($o['paid_at'] === null): ?>
<p class="note" role="status"><?= $word('PROFIT', 'unpaid') ?></p>
<?php elseif ((int) $o['units_no_cost'] > 0 || $resends_incomplete): ?>
<p class="note" role="status"><?= $word('PROFIT', 'incomplete_note') ?></p>
<?php elseif ($own_incomplete): ?>
<p class="note" role="status"><?= $word('PROFIT', 'pending_note') ?></p>
<?php endif; ?>
<?php if ($o['parent_ref'] !== null && ((int) $clone_goods_inc !== 0 || (int) $o['delivery_charged_inc'] !== 0)): ?>
<p class="note" role="status"><?= $say('PROFIT', 'resend_not_counted', \CW\Ui\Html::money((int) $clone_goods_inc / 100),
    \CW\Ui\Html::money((int) $o['delivery_charged_inc'] / 100)) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table profit-order-money">
  <tbody>
    <tr><th scope="row"><?= $word('PROFIT', 'store') ?></th><td><?= $e($o['store']) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'status') ?></th><td><?= $e($o['status']) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'placed') ?></th><td><?= $when($o['placed_at']) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'paid') ?></th><td><?= $when($o['paid_at']) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gateway') ?></th><td><?= $e($gateway_words) ?></td></tr>
<?php if ($parent_href !== null): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'resends') ?></th><td><a href="<?= $e($parent_href) ?>"><?= $say('PROFIT', 'resend_of', (string) $o['parent_ref']) ?></a></td></tr>
<?php endif; ?>
<?php if ($sub_href !== null): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'order') ?></th><td><a href="<?= $e($sub_href) ?>"><?= $say('PROFIT', 'sub_of', (string) $o['sub_of']) ?></a></td></tr>
<?php endif; ?>
    <tr><th scope="row"><?= $word('PROFIT', 'goods') ?></th><td class="num"><?= $money($sale / 100) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'cost') ?></th><td class="num"><?= $money($cost / 100) ?></td></tr>
<?php if ($o['paid_at'] === null): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'profit') ?></th><td class="num"><?= $word('PROFIT', 'unpaid_profit') ?></td></tr>
<?php elseif ($own_incomplete): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'profit') ?></th><td class="num"><?= $word('PROFIT', 'unknown') ?> <span class="muted"><?= $word('PROFIT', 'incomplete') ?></span></td></tr>
<?php else: ?>
    <tr><th scope="row"><?= $word('PROFIT', 'profit') ?></th><td class="num"><?= $money(($sale - $cost) / 100) ?> (<?= $e(\CW\Profit\ProfitViews::pct(\CW\Profit\ProfitMath::marginBp($sale - $cost, $sale))) ?>)</td></tr>
<?php endif; ?>
<?php if ((int) $o['resends'] > 0): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'resend_cost') ?></th><td class="num"><?= $money($resendCost / 100) ?></td></tr>
<?php if ($o['paid_at'] === null): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'with_resends') ?></th><td class="num"><?= $word('PROFIT', 'unpaid_profit') ?></td></tr>
<?php elseif ($own_incomplete || $resends_incomplete): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'with_resends') ?></th><td class="num"><?= $word('PROFIT', 'unknown') ?> <span class="muted"><?= $word('PROFIT', 'incomplete') ?></span></td></tr>
<?php else: ?>
    <tr><th scope="row"><?= $word('PROFIT', 'with_resends') ?></th><td class="num"><?= $money(($sale - $cost - $resendCost) / 100) ?> (<?= $e(\CW\Profit\ProfitViews::pct($o['margin_bp'] === null ? null : (int) $o['margin_bp'])) ?>)</td></tr>
<?php endif; ?>
<?php endif; ?>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery_method') ?></th><td><?= $e((string) ($o['delivery_method'] ?? '')) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery') ?></th><td class="num"><?= $money((int) $o['delivery_ex'] / 100) ?></td></tr>
<?php if ((int) $o['delivery_refund_inc'] > 0): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery_refund') ?></th><td class="num"><?= $money((int) $o['delivery_refund_inc'] / 100) ?></td></tr>
<?php endif; ?>
<?php if ((int) $o['order_refund_inc'] > 0): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'order_refund') ?></th><td class="num"><?= $money((int) $o['order_refund_inc'] / 100) ?></td></tr>
<?php endif; ?>
    <tr><th scope="row"><?= $word('PROFIT', 'grand') ?></th><td class="num"><?= $money((int) $o['grand_inc'] / 100) ?></td></tr>
  </tbody>
</table>
</div>

<h2><?= $word('PROFIT', 'delivery_table') ?></h2>
<?php if ($o['parent_ref'] !== null): ?>
<p class="note"><?= $word('PROFIT', 'delivery_resend_note') ?></p>
<?php endif; ?>
<?php if ($delivery['not_billed'] > 0): ?>
<p class="note"><?= $say('PROFIT', 'not_billed_note', (string) $delivery['not_billed']) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table profit-order-delivery">
  <tbody>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery_charged_ex') ?></th><td class="num"><?= $money((int) $o['delivery_ex'] / 100) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery_cost_own') ?></th><td class="num"><?= $money($delivery['cost'] / 100) ?></td></tr>
<?php if ((int) $o['resends'] > 0 || $delivery['resend_lines'] > 0): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery_cost_resends') ?></th><td class="num"><?= $money($delivery['resend_cost'] / 100) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery_after_resends') ?></th><td class="num"><?= $money(((int) $o['delivery_ex'] - $delivery['cost'] - $delivery['resend_cost']) / 100) ?></td></tr>
<?php else: ?>
    <tr><th scope="row"><?= $word('PROFIT', 'delivery_after') ?></th><td class="num"><?= $money(((int) $o['delivery_ex'] - $delivery['cost']) / 100) ?></td></tr>
<?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($paypal !== []): ?>

<h2><?= $word('PROFIT', 'paypal_table') ?></h2>
<p class="muted"><?= $word('PROFIT', 'paypal_table_note') ?></p>
<div class="table-wrap">
<table class="stack rp-table profit-order-paypal">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'paypal_day') ?></th>
      <th scope="col"><?= $word('PROFIT', 'paypal_what') ?></th>
      <th scope="col"><?= $word('PROFIT', 'paypal_counts_on') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'paypal_cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($paypal as $pp): ?>
    <tr>
      <td data-label="<?= $word('PROFIT', 'paypal_day') ?>"><?= $e(\CW\Ui\Html::day($pp['day'])) ?></td>
      <td data-label="<?= $word('PROFIT', 'paypal_what') ?>"><?= $e(\CW\Ui\Words::PROFIT['paypal_kind_' . $pp['kind']] ?? $pp['code']) ?> <span class="muted">(<?= $e($pp['code']) ?>)</span></td>
<?php if ($pp['counts_on'] === null): ?>
      <td data-label="<?= $word('PROFIT', 'paypal_counts_on') ?>"><?= $word('PROFIT', 'paypal_order_day') ?></td>
<?php else: ?>
      <td data-label="<?= $word('PROFIT', 'paypal_counts_on') ?>"><?= $e(\CW\Ui\Html::day($pp['counts_on'])) ?></td>
<?php endif; ?>
      <td class="num" data-label="<?= $word('PROFIT', 'paypal_cost') ?>"><?= $money($pp['pence'] / 100) ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="rp-total">
      <th scope="row" colspan="3"><?= $word('PROFIT', 'paypal_total') ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'paypal_total') ?>"><?= $money(array_sum(array_column($paypal, 'pence')) / 100) ?></td>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2><?= $word('PROFIT', 'lines') ?></h2>
<div class="table-wrap">
<table class="stack rp-table profit-order-lines">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'item') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'units') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'goods') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'unit_cost') ?></th>
      <th scope="col"><?= $word('PROFIT', 'cost_from') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'cost') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'profit') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'margin') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($lines as $l): ?>
    <tr>
      <th scope="row"><?php if ($l['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . (int) $l['sku_id']) ?>"><?= $e((string) ($l['sku_name'] ?? '')) ?></a> <span class="muted"><?= $e((string) ($l['sku_code'] ?? '')) ?></span><?php else: ?><?= $say('PROFIT', 'variant', (string) $l['variant_id']) ?><?php endif; ?><?php if ((int) $l['cancelled'] === 1): ?> <span class="muted"><?= $word('PROFIT', 'cancelled') ?></span><?php endif; ?><?php if ((int) $l['is_free'] === 1): ?> <span class="muted"><?= $word('PROFIT', 'free') ?></span><?php endif; ?><?php if ((int) $l['restocked'] === 1): ?> <span class="muted"><?= $word('PROFIT', 'restocked') ?></span><?php endif; ?><?php if ((int) $l['refund_inc'] > 0): ?> <span class="muted"><?= $word('PROFIT', 'refunded') ?></span><?php endif; ?><?php if ($l['removed_at'] !== null): ?> <span class="muted"><?= $word('PROFIT', 'removed') ?></span><?php endif; ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'units') ?>"><?= $n($l['units']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'goods') ?>"><?= $money((int) $l['sale_ex'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'unit_cost') ?>"><?php if ($l['unit_cost_e4'] !== null): ?><?= $e(\CW\Profit\ProfitViews::price((int) $l['unit_cost_e4'])) ?><?php endif; ?></td>
      <td data-label="<?= $word('PROFIT', 'cost_from') ?>"><?= $e($l['cost_source'] === null ? \CW\Ui\Words::PROFIT['source_open'] : $sourceWords[(string) $l['cost_source']]) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money((int) $l['cost'] / 100) ?></td>
<?php if ($o['paid_at'] === null): ?>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $word('PROFIT', 'unpaid_profit') ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $word('PROFIT', 'unpaid_profit') ?></td>
<?php elseif ((int) $l['units'] > 0 && (int) $l['restocked'] === 0 && ($l['cost_source'] === null || $l['cost_source'] === 'none')): ?>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $word('PROFIT', 'unknown') ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $word('PROFIT', 'unknown') ?></td>
<?php else: ?>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $money($l['profit'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($l['margin_bp'])) ?></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>

<h2><?= $word('PROFIT', 'parcels') ?></h2>
<?php if ($parcels === []): ?>
<p class="muted"><?= $word('PROFIT', 'no_parcels') ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table profit-order-parcels">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'tracking') ?></th>
      <th scope="col"><?= $word('PROFIT', 'carrier') ?></th>
      <th scope="col"><?= $word('PROFIT', 'service') ?></th>
      <th scope="col"><?= $word('PROFIT', 'status') ?></th>
      <th scope="col"><?= $word('PROFIT', 'label_at') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'bill') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($parcels as $p): ?>
    <tr>
      <th scope="row"><?= $e((string) ($p['tracking_no'] ?? '')) ?><?php if ($p['removed_at'] !== null): ?> <span class="muted"><?= $word('PROFIT', 'removed') ?></span><?php endif; ?></th>
      <td data-label="<?= $word('PROFIT', 'carrier') ?>"><?= $e($p['carrier']) ?></td>
      <td data-label="<?= $word('PROFIT', 'service') ?>"><?= $e($p['service']) ?></td>
      <td data-label="<?= $word('PROFIT', 'status') ?>"><?= $e($p['status']) ?></td>
      <td data-label="<?= $word('PROFIT', 'label_at') ?>"><?= $when($p['label_at']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'bill') ?>"><?php if ($p['not_billed']): ?><span class="muted"><?= $word('PROFIT', 'not_billed') ?></span><?php elseif ($p['bill_lines'] === [] && ($p['pooled'] ?? null) === null): ?><span class="muted">-</span><?php else: ?><?= $say('PROFIT', 'bill_line', \CW\Ui\Html::money($p['bill_cost'] / 100), implode(', ', $p['bills'])) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($delivery['collections'] !== []): ?>
<h2><?= $word('PROFIT', 'collections') ?></h2>
<div class="table-wrap">
<table class="stack rp-table profit-order-collections">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'collection_date') ?></th>
      <th scope="col"><?= $word('PROFIT', 'collection_bill') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'collection_cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($delivery['collections'] as $c): ?>
    <tr>
      <th scope="row"><?= $day($c['line_date']) ?></th>
      <td data-label="<?= $word('PROFIT', 'collection_bill') ?>"><?= $e(strtoupper((string) $c['carrier']) . ' ' . (string) $c['invoice_no']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'collection_cost') ?>"><?= $money((int) $c['cost'] / 100) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($resends !== []): ?>
<h2><?= $word('PROFIT', 'resends_list') ?></h2>
<div class="table-wrap">
<table class="stack rp-table profit-order-resends">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'order') ?></th>
      <th scope="col"><?= $word('PROFIT', 'paid') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'units') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($resends as $i => $r): ?>
    <tr>
      <th scope="row"><a href="<?= $e($resend_href[$i]) ?>"><?= $e((string) ($r['order_no'] !== '' ? $r['order_no'] : $r['order_ref'])) ?></a></th>
      <td data-label="<?= $word('PROFIT', 'paid') ?>"><?= $when($r['paid_at']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'units') ?>"><?= $n($r['units']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money((int) $r['cost'] / 100) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
