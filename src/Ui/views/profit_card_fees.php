<?php
// Profit › Card fees (CW-1174; docs/decisions.md GPF10-GPF12): Global Payments' card fees in plain words (how the estimate works, why an
// invoice goes on the calendar month, that a fixed charge of an account with no card payments is a regular cost), then per account its
// settings and its months (CW's card takings, the fees counted, estimated or from the invoice, the invoice entered), and for the Expenses job
// the form to enter a month's invoice. On a phone each row is a card.
$P = \CW\Ui\Words::PROFIT;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
$t = $typed;
$today = \CW\Profit\ProfitViews::today();
?>
<?= $head(\CW\Ui\Words::MENU['profit_card_fees'], intro: 'profit_card_fees') ?>
<?php if ($notice !== null): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<p><?= $word('PROFIT', 'gp_how') ?></p>
<p class="muted"><?= $word('PROFIT', 'gp_period_note') ?></p>
<p class="muted"><?= $word('PROFIT', 'gp_fixed_note') ?> <a href="/ui/profit/regular"><?= $word('PROFIT', 'gp_regular_link') ?></a></p>
<?php foreach ($accounts as $a): ?>

<h2><?= $e($P['gp_account_' . $a['account']] ?? $a['account']) ?></h2>
<?php if (!$a['on']): ?>
<p class="muted"><?= $word('PROFIT', 'gp_off') ?></p>
<?php else: ?>
<p class="muted"><?= $say('PROFIT', 'gp_on', \CW\Ui\Html::day((string) $a['from']), implode(', ', $a['shops']), (string) $a['rate']) ?><?php if ($a['until'] !== null): ?> <?= $say('PROFIT', 'gp_until', \CW\Ui\Html::day((string) $a['until'])) ?><?php endif; ?></p>
<?php if (!$a['merchant']): ?>
<p class="note" role="status"><?= $word('PROFIT', 'gp_merchant_missing') ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($a['months'] !== []): ?>
<div class="table-wrap">
<table class="stack rp-table card-fees" data-account="<?= $e($a['account']) ?>">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'gp_month') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'gp_cw_takings') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'gp_fees_counted') ?></th>
      <th scope="col"><?= $word('PROFIT', 'gp_invoice_col') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($a['months'] as $m): ?>
    <tr>
      <th scope="row"><?= $e($m['month']) ?></th>
      <td class="num" data-label="<?= $word('PROFIT', 'gp_cw_takings') ?>"><?= $e($gbp($m['takings'])) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'gp_fees_counted') ?>"><?= $e($gbp($m['fees'] + ($m['invoice']['other'] ?? 0))) ?><?php if ($m['est'] > 0 || ($m['invoice']['due'] ?? false)): ?> <?= $chip('waiting', $P['real_gp_estimated']) ?><?php endif; ?></td>
      <td data-label="<?= $word('PROFIT', 'gp_invoice_col') ?>"><?php if ($m['invoice'] === null): ?><span class="muted"><?= $word('PROFIT', 'gp_none') ?></span><?php else: ?><a href="<?= $e('/ui/profit/card-fees/' . $m['invoice']['id']) ?>"><?= $e(\CW\Ui\Words::say('PROFIT', 'gp_invoice_figures', $gbp($m['invoice']['net']), $gbp($m['invoice']['sales']), $m['invoice']['rate'])) ?></a><?php if ($m['invoice']['due']): ?> <?= $chip('waiting', $P['gp_state_due']) ?><?php endif; ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($canEdit): ?>

<h2><?= $word('PROFIT', 'gp_add') ?></h2>
<form class="record gp-invoice-form" method="post" action="/ui/profit/card-fees/new" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <fieldset>
    <legend><?= $word('PROFIT', 'gp_f_account') ?></legend>
<?php foreach ($accounts as $a): ?>
<?php if ($a['on']): ?>
    <label class="choice"><input type="radio" name="account" value="<?= $e($a['account']) ?>" required<?php if ($t['account'] === $a['account']): ?> checked<?php endif; ?><?php if ($errorField === 'account'): ?> aria-invalid="true"<?php endif; ?>> <?= $e($P['gp_account_' . $a['account']] ?? $a['account']) ?></label>
<?php endif; ?>
<?php endforeach; ?>
  </fieldset>
  <div class="ex-fields">
    <div class="ex-row">
      <div class="field">
        <label for="gp-month"><?= $word('PROFIT', 'gp_f_month') ?></label>
        <input id="gp-month" type="month" name="month" value="<?= $e($t['month']) ?>" required<?php if ($errorField === 'month'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="gp-merchant"><?= $word('PROFIT', 'gp_f_merchant') ?></label>
        <input id="gp-merchant" type="text" name="merchant_no" inputmode="numeric" value="<?= $e($t['merchant_no']) ?>" maxlength="20" required<?php if ($errorField === 'merchant_no'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
    </div>
    <div class="ex-row">
      <div class="field">
        <label for="gp-date"><?= $word('PROFIT', 'gp_f_date') ?></label>
        <input id="gp-date" type="date" name="invoice_date" value="<?= $e($t['invoice_date']) ?>" required<?php if ($errorField === 'invoice_date'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="gp-sales"><?= $word('PROFIT', 'gp_f_sales') ?></label>
        <input id="gp-sales" type="text" name="sales" inputmode="decimal" value="<?= $e($t['sales']) ?>" required<?php if ($errorField === 'sales'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
    </div>
    <div class="ex-row">
      <div class="field">
        <label for="gp-net"><?= $word('PROFIT', 'gp_f_net') ?></label>
        <input id="gp-net" type="text" name="net" inputmode="decimal" value="<?= $e($t['net']) ?>" required aria-describedby="gp-net-hint"<?php if ($errorField === 'net'): ?> aria-invalid="true"<?php endif; ?>>
        <p class="hint" id="gp-net-hint"><?= $word('PROFIT', 'gp_hint_net') ?></p>
      </div>
      <div class="field">
        <label for="gp-vat"><?= $word('PROFIT', 'gp_f_vat') ?></label>
        <input id="gp-vat" type="text" name="vat" inputmode="decimal" value="<?= $e($t['vat']) ?>" required<?php if ($errorField === 'vat'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
    </div>
    <div class="ex-row">
      <div class="field">
        <label for="gp-total"><?= $word('PROFIT', 'gp_f_total') ?></label>
        <input id="gp-total" type="text" name="total" inputmode="decimal" value="<?= $e($t['total']) ?>" required<?php if ($errorField === 'total'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="gp-note"><?= $word('PROFIT', 'gp_f_note') ?></label>
        <input id="gp-note" type="text" name="note" value="<?= $e($t['note']) ?>" maxlength="500">
      </div>
    </div>
  </div>
  <label class="choice"><input type="checkbox" name="use_rate" value="1"<?php if ($t['use_rate'] === '1'): ?> checked<?php endif; ?>> <?= $word('PROFIT', 'gp_f_use_rate') ?></label>
<?php if ($gap !== null): ?>
  <p class="note" role="status"><?= $say('PROFIT', 'gp_gap', $gbp((int) $gap['cw']), $gbp((int) $gap['sales'])) ?></p>
  <label class="choice"><input type="checkbox" name="gap_ok" value="1" aria-invalid="true"> <?= $word('PROFIT', 'gp_f_gap_ok') ?></label>
<?php endif; ?>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('PROFIT', 'gp_save') ?></button></p>
</form>
<?php endif; ?>
