<?php
// Profit › Card fees › one invoice (CW-1174; docs/decisions.md GPF10): the invoice as printed, how it was spread (the card orders, CW's card
// takings, on orders, on no order) or that it is still being applied, every change with its reason; for the Expenses job, change its
// figures (with a reason) or delete it (with a reason: its month goes back to estimates).
$P = \CW\Ui\Words::PROFIT;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
$t = $typed;
$deleted = $inv['deleted_at'] !== null;
$acctName = $P['gp_account_' . $inv['account']] ?? (string) $inv['account'];
?>
<?= $head(\CW\Ui\Words::say('PROFIT', 'gp_one_title', $acctName, (string) $inv['month']), intro: 'profit_card_fee', back: ['/ui/profit/card-fees', $P['gp_back']]) ?>
<?php if ($notice !== null): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($deleted): ?>
<p class="note" role="status"><?= $say('PROFIT', 'gp_deleted_note', (string) $inv['deleted_reason']) ?></p>
<?php elseif ($inv['spread_state'] === 'due'): ?>
<p class="note" role="status"><?= $word('PROFIT', 'gp_due_note') ?></p>
<?php else: ?>
<p><?= $say('PROFIT', 'gp_spread_line', (string) (int) $inv['spread_orders'], $gbp((int) $inv['cw_takings_pence']), $gbp((int) $inv['spread_pence']), $gbp((int) $inv['other_pence'])) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table card-fee">
  <tbody>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_f_merchant') ?></th><td><?= $e((string) $inv['merchant_no']) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_f_date') ?></th><td><?= $e(\CW\Ui\Html::day((string) $inv['invoice_date'])) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_f_sales') ?></th><td class="num"><?= $e($gbp((int) $inv['sales_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_cw_takings') ?></th><td class="num"><?= $e($gbp((int) $cw)) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_f_net') ?></th><td class="num"><?= $e($gbp((int) $inv['net_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_f_vat') ?></th><td class="num"><?= $e($gbp((int) $inv['vat_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_f_total') ?></th><td class="num"><?= $e($gbp((int) $inv['total_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_rate_col') ?></th><td class="num"><?= $e(\CW\Profit\ProfitMath::ratePercent((int) $inv['rate_e4'])) ?>%</td></tr>
<?php if ($inv['note'] !== null): ?>
    <tr><th scope="row"><?= $word('PROFIT', 'gp_f_note') ?></th><td><?= $e((string) $inv['note']) ?></td></tr>
<?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($canEdit && !$deleted): ?>

<h2><?= $word('PROFIT', 'gp_change') ?></h2>
<form class="record gp-invoice-change" method="post" action="<?= $e('/ui/profit/card-fees/' . (int) $inv['id'] . '/change') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="seen" value="<?= $e((string) (int) $seen) ?>">
  <div class="ex-fields">
    <div class="ex-row">
      <div class="field"><label for="gpc-merchant"><?= $word('PROFIT', 'gp_f_merchant') ?></label><input id="gpc-merchant" type="text" name="merchant_no" value="<?= $e($t['merchant_no']) ?>" required></div>
      <div class="field"><label for="gpc-date"><?= $word('PROFIT', 'gp_f_date') ?></label><input id="gpc-date" type="date" name="invoice_date" value="<?= $e($t['invoice_date']) ?>" required></div>
    </div>
    <div class="ex-row">
      <div class="field"><label for="gpc-sales"><?= $word('PROFIT', 'gp_f_sales') ?></label><input id="gpc-sales" type="text" name="sales" inputmode="decimal" value="<?= $e($t['sales']) ?>" required></div>
      <div class="field"><label for="gpc-net"><?= $word('PROFIT', 'gp_f_net') ?></label><input id="gpc-net" type="text" name="net" inputmode="decimal" value="<?= $e($t['net']) ?>" required></div>
    </div>
    <div class="ex-row">
      <div class="field"><label for="gpc-vat"><?= $word('PROFIT', 'gp_f_vat') ?></label><input id="gpc-vat" type="text" name="vat" inputmode="decimal" value="<?= $e($t['vat']) ?>" required></div>
      <div class="field"><label for="gpc-total"><?= $word('PROFIT', 'gp_f_total') ?></label><input id="gpc-total" type="text" name="total" inputmode="decimal" value="<?= $e($t['total']) ?>" required></div>
    </div>
    <div class="field"><label for="gpc-note"><?= $word('PROFIT', 'gp_f_note') ?></label><input id="gpc-note" type="text" name="note" value="<?= $e($t['note']) ?>" maxlength="500"></div>
    <div class="field"><label for="gpc-reason"><?= $word('PROFIT', 'gp_reason') ?></label><input id="gpc-reason" type="text" name="reason" maxlength="500" required<?php if ($errorField === 'reason'): ?> aria-invalid="true"<?php endif; ?>></div>
  </div>
  <label class="choice"><input type="checkbox" name="use_rate" value="1"> <?= $word('PROFIT', 'gp_f_use_rate') ?></label>
  <label class="choice"><input type="checkbox" name="gap_ok" value="1"> <?= $word('PROFIT', 'gp_f_gap_ok') ?></label>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('PROFIT', 'gp_change') ?></button></p>
</form>

<h2><?= $word('PROFIT', 'gp_delete') ?></h2>
<form class="record gp-invoice-delete" method="post" action="<?= $e('/ui/profit/card-fees/' . (int) $inv['id'] . '/delete') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="seen" value="<?= $e((string) (int) $inv['version']) ?>">
  <p class="muted"><?= $word('PROFIT', 'gp_delete_note') ?></p>
  <div class="field"><label for="gpd-reason"><?= $word('PROFIT', 'gp_reason') ?></label><input id="gpd-reason" type="text" name="reason" maxlength="500" required></div>
  <label class="choice"><input type="checkbox" name="sure" value="1" required> <?= $word('PROFIT', 'gp_sure') ?></label>
  <p class="actions"><button type="submit" class="btn danger"><?= $word('PROFIT', 'gp_delete') ?></button></p>
</form>
<?php endif; ?>

<h2><?= $word('PROFIT', 'gp_history') ?></h2>
<div class="table-wrap">
<table class="stack rp-table card-fee-history">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'gp_h_when') ?></th>
      <th scope="col"><?= $word('PROFIT', 'gp_h_what') ?></th>
      <th scope="col"><?= $word('PROFIT', 'gp_h_who') ?></th>
      <th scope="col"><?= $word('PROFIT', 'gp_reason') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($history as $h): ?>
    <tr>
      <td data-label="<?= $word('PROFIT', 'gp_h_when') ?>"><?= $e(\CW\Ui\Html::when((string) $h['at'])) ?></td>
      <td data-label="<?= $word('PROFIT', 'gp_h_what') ?>"><?= $word('PROFIT', 'gp_h_' . $h['action']) ?></td>
      <td data-label="<?= $word('PROFIT', 'gp_h_who') ?>"><?= $e((string) $h['actor']) ?></td>
      <td data-label="<?= $word('PROFIT', 'gp_reason') ?>"><?= $e((string) ($h['reason'] ?? '')) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
