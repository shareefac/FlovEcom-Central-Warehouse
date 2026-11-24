<?php
// Profit › Delivery bills › a Royal Mail bill checked, nothing stored (0078; docs/decisions.md DL79; the plan, section 6 step 3): what CW read
// (account, bill date, days, that its lines add up to its totals, its rows, net, VAT, total), the bill it replaces, where its money would go
// (as its page shows it once stored: on orders per shop, a shop's "not on an order", the days' lines, Hayati Vapes, before CW start per shop,
// held per reason), a red line when days wait for their labels, its flat days (labels, billed by every bill with it, priced, the day's line),
// what moves on the bills already live (DL80), its rows as read, and the Store button for who checked it (the bill as read is kept for it,
// never the file), or "in the profit already".
$W = \CW\Ui\Words::DELIVERY;
$gbp = static fn (int $p): string => \CW\Profit\DpdInvoiceFile::gbp($p);
$groupText = static function (array $w) use ($W): string {
    $reason = $w['reason'] === null ? '' : ($W['rm_held_' . $w['reason']] ?? (string) $w['reason']);
    return match ($w['group']) {
        'party' => sprintf($W['rm_group_party'], $w['name']),
        'held' => sprintf($W['rm_group_held'], $reason),
        default => sprintf($W['rm_group_' . $w['group']], $w['name']),
    };
};
$total = array_sum(array_column($where, 'pence'));
$refText = static function (array $r): string {
    return match ((string) $r['ref_kind']) {
        'manifest' => 'CLICK AND DROP M' . $r['ref_no'] . ($r['sales_order'] !== null ? ' (' . $r['sales_order'] . ')' : ''),
        'sales_order' => 'ADJ' . $r['ref_no'] . 'RCN',
        'week' => 'Week ' . $r['ref_no'],
        'preadvice' => (string) $r['ref_no'],
        default => '',
    };
};
$waiting = count(array_filter($days, static fn (array $d): bool => $d['waiting']));
?>
<?= $head(\CW\Ui\Words::say('DELIVERY', 'rm_check_title', (string) $bill['invoice_no']), lede: $W['rm_check_intro'],
    aside: $explain('delivery_bills', \CW\Ui\Words::MENU['profit_delivery']), back: ['/ui/profit/delivery', $W['back']]) ?>
<?php if ($check['same']): ?>
<p class="notice" role="status"><?= $word('DELIVERY', 'rm_check_same') ?></p>
<?php endif; ?>
<?php if ($check['replace'] !== null): ?>
<p class="lede"><a href="<?= $u('/ui/profit/delivery/' . (int) $check['replace']['id']) ?>"><?= $say('DELIVERY', 'rm_check_replaces', (string) $check['replace']['invoice_no'], \CW\Ui\Html::when((string) $check['replace']['uploaded_at'])) ?></a></p>
<?php endif; ?>

<div class="table-wrap">
<table class="stack rp-table delivery-rm-check-facts">
  <tbody>
    <tr><th scope="row"><?= $word('DELIVERY', 'account') ?></th><td><?= $e((string) $bill['account']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'rm_bill_date') ?></th><td><?= $day($bill['invoice_date']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'days') ?></th><td><?= $day($bill['period_from']) ?> - <?= $day($bill['period_to']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'rm_check_ties') ?></th><td><?= $word('DELIVERY', 'rm_yes') ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'rm_rows_title') ?></th><td class="num"><?= $n((int) $bill['rows']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'net') ?></th><td class="num"><?= $e($gbp((int) $bill['net'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'vat') ?></th><td class="num"><?= $e($gbp((int) $bill['vat'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'gross') ?></th><td class="num"><?= $e($gbp((int) $bill['gross'])) ?></td></tr>
  </tbody>
</table>
</div>

<h2><?= $word('DELIVERY', 'rm_check_where') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-check-where">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'state') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($where as $w): ?>
    <tr>
      <th scope="row"><?= $e($groupText($w)) ?></th>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp($w['pence'])) ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="rp-total">
      <th scope="row"><?= $word('DELIVERY', 'tie_total') ?> <span class="muted"><?= $word('DELIVERY', $total === (int) $bill['net'] ? 'adds_up' : 'not_adding_up') ?></span></th>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp((int) $bill['net'])) ?></td>
    </tr>
  </tbody>
</table>
</div>

<?php if ($waiting > 0): ?>
<p class="error" role="alert"><?= $say('DELIVERY', 'rm_check_waiting', $waiting) ?></p>
<?php endif; ?>

<?php if ($days !== []): ?>
<h2><?= $word('DELIVERY', 'rm_days_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-check-days">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'rm_day') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_product') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_labels') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_billed_with') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_priced') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_line') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'status') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($days as $d): ?>
    <tr>
      <th scope="row"><?= $day($d['day']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'rm_product') ?>"><?= $e($d['product']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_labels') ?>"><?= $n($d['labels']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_billed_with') ?>"><?= $n($d['billed']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_priced') ?>"><?= $n($d['priced']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_line') ?>"><?= $e($gbp($d['line'])) ?></td>
      <td data-label="<?= $word('DELIVERY', 'status') ?>"><?php if ($d['removed']): ?><?= $word('DELIVERY', 'rm_check_removed') ?> <?php endif; ?><?php if ($d['waiting']): ?><?= $word('DELIVERY', 'rm_check_waiting_day') ?><?php else: ?><?= $word('DELIVERY', ['open' => 'rm_open', 'closed' => 'rm_closed', 'look' => 'rm_look'][$d['status']]) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($check['replace'] !== null && $gone !== []): ?>
<h2><?= $word('DELIVERY', 'rm_check_gone_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-check-gone">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'rm_day') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'state') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_moves_out') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($gone as $w): ?>
    <tr>
      <th scope="row"><?= $day($w['day']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'state') ?>"><?= $e($groupText($w)) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_moves_out') ?>"><?= $e($gbp($w['pence'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2><?= $word('DELIVERY', 'rm_check_others') ?></h2>
<?php if ($others === []): ?>
<p class="hint delivery-rm-check-others-none"><?= $word('DELIVERY', 'rm_check_others_none') ?></p>
<?php else: ?>
<p class="hint"><?= $word('DELIVERY', 'rm_check_others_hint') ?></p>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-check-others">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'bill') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_day') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'state') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_moves_in') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_moves_out') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($others as $w): ?>
    <tr>
      <th scope="row"><?= $e($w['invoice_no']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'rm_day') ?>"><?= $day($w['day']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'state') ?>"><?= $e($groupText($w)) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_moves_in') ?>"><?= $e($gbp($w['in'])) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_moves_out') ?>"><?= $e($gbp($w['out'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2><?= $word('DELIVERY', 'rm_rows_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-check-rows">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'rm_row') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_day') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_product') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_qty') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_ref') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'net') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?= $n($r['row_no']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'rm_day') ?>"><?= $day($r['service_date']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_product') ?>"><?= $e($r['product_code']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_qty') ?>"><?= $n($r['qty']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_ref') ?>"><?= $e($refText($r)) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'net') ?>"><?= $e($gbp($r['net'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($check['token'] !== null): ?>
<form class="record delivery-store" method="post" action="/ui/profit/delivery/store">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="check" value="<?= $e((string) $check['token']) ?>">
  <input type="hidden" name="bill_sha256" value="<?= $e((string) $check['bill_sha256']) ?>">
  <p class="hint"><?= $say('DELIVERY', 'rm_check_time', $minutes) ?></p>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('DELIVERY', 'rm_check_store') ?></button> <a class="btn" href="<?= $u('/ui/profit/delivery') ?>"><?= $word('DELIVERY', 'rm_check_cancel') ?></a></p>
</form>
<?php endif; ?>
