<?php
// Profit › Delivery bills › a Royal Mail bill checked, nothing stored (0078; docs/decisions.md DL79; the plan, section 6 step 3): what CW read
// (account, bill date, days, that its lines add up to its totals, its rows, net, VAT, total), the bill it replaces, where its money would go
// (as its page shows it once stored: on orders per shop, a shop's "not on an order", the days' lines, Hayati Vapes, before CW start per shop,
// held per reason), and the Store button for who checked it (the bill as read is kept for it, never the file), or "in the profit already".
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

<?php if ($check['token'] !== null): ?>
<form class="record delivery-store" method="post" action="/ui/profit/delivery/store">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="check" value="<?= $e((string) $check['token']) ?>">
  <input type="hidden" name="bill_sha256" value="<?= $e((string) $check['bill_sha256']) ?>">
  <p class="hint"><?= $say('DELIVERY', 'rm_check_time', $minutes) ?></p>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('DELIVERY', 'rm_check_store') ?></button> <a class="btn" href="<?= $u('/ui/profit/delivery') ?>"><?= $word('DELIVERY', 'rm_check_cancel') ?></a></p>
</form>
<?php endif; ?>
