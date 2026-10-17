<div class="head-help">
  <h1><?= $word('SEGMENT', 'handover_balances') ?></h1>
</div>
<?= $intro('handover_balances', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<p class="muted"><?= $word('BALANCE', 'accountant') ?></p>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::BALANCE['none'], \CW\Ui\Words::BALANCE['none_text'], '/ui/stock/handovers/customers', \CW\Ui\Words::BALANCE['none_link']) ?>
<?php endif; ?>
<?php foreach ($rows as $a): ?>
<?php $mine = $typed !== null && $typed['for'] === (string) $a['id']; ?>
<section class="account" id="customer-<?= $e($a['id']) ?>" aria-labelledby="customer-h-<?= $e($a['id']) ?>">
  <h2 id="customer-h-<?= $e($a['id']) ?>"><a href="<?= $u('/ui/stock/handovers/customers/' . $a['id']) ?>"><?= $e($a['name']) ?></a> <span class="muted"><?= $word('CUSTOMER_KIND', $a['kind']) ?></span><?php if (!$a['active']): ?> <?= $chip('off', \CW\Ui\Words::CUSTOMER['off']) ?><?php endif; ?></h2>
  <div class="kpis-wrap"><ul class="kpis">
    <li class="kpi"><p class="kpi-label"><?= $word('BALANCE', 'tile_balance') ?></p><p class="kpi-value"><?= $money($a['balance']) ?></p><p class="kpi-sub"><?php if (str_starts_with($a['balance'], '-')): ?><?= $word('BALANCE', 'credit') ?><?php else: ?><?= $word('BALANCE', 'balance_sub') ?><?php endif; ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('BALANCE', 'tile_month') ?></p><p class="kpi-value"><?= $money($a['month_amount']) ?></p><p class="kpi-sub"><?= $say('BALANCE', 'handovers_sub', $a['handovers']) ?></p></li>
  </ul></div>
<?php if ($a['last_payment'] !== null): ?>
  <p class="muted"><?= $say('BALANCE', 'last_paid', \CW\Ui\Html::money($a['last_payment']['amount']), \CW\Ui\Html::day($a['last_payment']['paid_on']), $a['last_payment']['reference']) ?></p>
<?php endif; ?>
<?php if ($canPay): ?>
  <details class="fold"<?php if ($mine): ?> open<?php endif; ?>>
    <summary><?= $word('BALANCE', 'pay') ?></summary>
    <p class="muted"><?= $word('BALANCE', 'pay_text') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/stock/handovers/balances/' . $a['id'] . '/payments') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="form_key" value="<?= $e($a['form_key']) ?>">
      <label><?= $word('BALANCE', 'amount') ?> <input type="text" name="amount" value="<?php if ($mine): ?><?= $e($typed['amount']) ?><?php endif; ?>" inputmode="decimal" maxlength="16" required></label>
      <label><?= $word('BALANCE', 'paid_on') ?> <input type="date" name="paid_on" value="<?php if ($mine): ?><?= $e($typed['paid_on']) ?><?php else: ?><?= $e($today) ?><?php endif; ?>" required></label>
      <label><?= $word('BALANCE', 'reference') ?> <input type="text" name="reference" value="<?php if ($mine): ?><?= $e($typed['reference']) ?><?php endif; ?>" maxlength="100" required></label>
      <label><?= $word('BALANCE', 'note') ?> <input type="text" name="note" value="<?php if ($mine): ?><?= $e($typed['note']) ?><?php endif; ?>" maxlength="500"></label>
      <p class="actions"><button type="submit" class="primary"><?= $word('BALANCE', 'pay_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
  <h3><?= $word('BALANCE', 'history') ?></h3>
<?php if ($a['entries'] === []): ?>
  <p class="muted"><?= $word('BALANCE', 'no_history') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list account-entries">
    <thead>
      <tr>
        <th scope="col"><?= $word('BALANCE', 'what') ?></th>
        <th scope="col"><?= $word('BALANCE', 'when') ?></th>
        <th scope="col" class="num"><?= $word('BALANCE', 'amount_col') ?></th>
        <th scope="col"><?= $word('BALANCE', 'ref_col') ?></th>
        <th scope="col"><?= $word('BALANCE', 'who') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($a['entries'] as $x): ?>
      <tr<?php if ($x['reversed_by'] !== null): ?> class="off"<?php endif; ?>>
        <th scope="row" class="c-head"><?php if ($x['document_id'] !== null): ?><a href="<?= $u('/ui/stock/handovers/' . $x['document_id']) ?>"><?= $say('BALANCE_KIND', $x['kind'], (string) ($x['number'] ?? '')) ?></a><?php else: ?><?= $say('BALANCE_KIND', $x['kind'], '') ?><?php endif; ?><?php if ($x['reversed_by'] !== null): ?> <?= $chip('off', \CW\Ui\Words::BALANCE['reversed_mark']) ?><?php endif; ?><?php if ($x['note'] !== null): ?><span class="o-sub"><?= $e($x['note']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('BALANCE', 'when') ?>"><?php if ($x['paid_on'] !== null): ?><?= $day($x['paid_on']) ?><?php else: ?><?= $when($x['at']) ?><?php endif; ?></td>
        <td data-label="<?= $word('BALANCE', 'amount_col') ?>" class="num chg <?= $e(str_starts_with($x['amount'], '-') ? 'down' : 'up') ?>"><?= $money($x['amount']) ?></td>
        <td data-label="<?= $word('BALANCE', 'ref_col') ?>"><?php if ($x['reference'] !== null): ?><?= $e($x['reference']) ?><?php endif; ?></td>
        <td data-label="<?= $word('BALANCE', 'who') ?>"><?= $e($x['who']) ?><?php if ($canPay && $x['kind'] === 'payment' && $x['reversed_by'] === null): ?>
          <details class="fold">
            <summary><?= $word('BALANCE', 'reverse') ?></summary>
            <form class="record" method="post" action="<?= $u('/ui/stock/handovers/balances/payments/' . $x['id'] . '/reverse') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <label><?= $word('BALANCE', 'reverse_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
              <p class="actions"><button type="submit" class="danger"><?= $word('BALANCE', 'reverse_button') ?></button></p>
            </form>
          </details><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endforeach; ?>
