<div class="head-help">
  <h1><?= $word('ACCOUNTS', 'title') ?></h1>
</div>
<?= $intro('accounts', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($accounts === []): ?>
<?php if ($canWarehouses): ?>
<?= $empty(\CW\Ui\Words::ACCOUNTS['none'], \CW\Ui\Words::ACCOUNTS['none_text'], '/ui/reference/warehouses', \CW\Ui\Words::ACCOUNTS['none_link']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::ACCOUNTS['none'], \CW\Ui\Words::ACCOUNTS['none_text']) ?>
<?php endif; ?>
<?php endif; ?>
<?php foreach ($accounts as $a): ?>
<section class="account" id="account-<?= $e($a['id']) ?>" aria-labelledby="account-h-<?= $e($a['id']) ?>">
  <h2 id="account-h-<?= $e($a['id']) ?>"><?= $say('ACCOUNTS', 'room', $a['name'], $a['entity']) ?><?php if (!$a['active']): ?> <?= $chip('off', \CW\Ui\Words::ACCOUNTS['switched_off']) ?><?php endif; ?><?php if ($a['own']): ?> <?= $chip('info', \CW\Ui\Words::ACCOUNTS['ours_now']) ?><?php endif; ?></h2>
<?php if ($a['error'] !== null): ?>
  <p class="error" role="alert"><?= $e($a['error']) ?></p>
<?php endif; ?>
  <div class="kpis-wrap"><ul class="kpis">
    <li class="kpi"><p class="kpi-label"><?= $word('ACCOUNTS', 'tile_holds') ?></p><p class="kpi-value"><?= $n($a['holds_units']) ?></p><p class="kpi-sub"><?= $say('ACCOUNTS', 'units_sub', number_format($a['holds_products'])) ?> · <?= $say('ACCOUNTS', 'holds_value', \CW\Ui\Html::money($a['holds_value'])) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('ACCOUNTS', 'tile_month') ?></p><p class="kpi-value"><?= $money($a['month_amount']) ?></p><p class="kpi-sub"><?= $say('ACCOUNTS', 'month_sub', number_format($a['month_units'])) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('ACCOUNTS', 'tile_balance') ?></p><p class="kpi-value"><?= $e($a['balance_text']) ?></p><p class="kpi-sub"><?= $word('ACCOUNTS', 'balance_sub') ?></p></li>
  </ul></div>
<?php if ($a['unpriced'] > 0): ?>
  <p class="muted"><?= $say('ACCOUNTS', 'holds_unpriced', number_format($a['unpriced'])) ?></p>
<?php endif; ?>
<?php if ($a['last_payment'] !== null): ?>
  <p class="muted"><?= $say('ACCOUNTS', 'last_paid', \CW\Ui\Html::money($a['last_payment']['amount']), \CW\Ui\Html::day($a['last_payment']['paid_on']), $a['last_payment']['reference']) ?></p>
<?php endif; ?>
<?php if ($canRelease && !$a['own'] && $a['active']): ?>
  <p class="actions"><a class="btn secondary" href="/ui/stock/releases#new"><?= $word('ACCOUNTS', 'release') ?></a></p>
<?php endif; ?>
<?php if ($canPay): ?>
  <details class="fold"<?php if ($a['error'] !== null): ?> open<?php endif; ?>>
    <summary><?= $word('ACCOUNTS', 'pay') ?></summary>
    <p class="muted"><?= $word('ACCOUNTS', 'pay_text') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/stock/accounts/' . $a['id'] . '/payments') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label><?= $word('ACCOUNTS', 'amount') ?> <input type="text" name="amount" value="<?= $e($a['typed']['amount']) ?>" inputmode="decimal" maxlength="16" required></label>
      <label><?= $word('ACCOUNTS', 'paid_on') ?> <input type="date" name="paid_on" value="<?= $e($a['typed']['paid_on']) ?>" required></label>
      <label><?= $word('ACCOUNTS', 'reference') ?> <input type="text" name="reference" value="<?= $e($a['typed']['reference']) ?>" maxlength="100" required></label>
      <label><?= $word('ACCOUNTS', 'note') ?> <input type="text" name="note" value="<?= $e($a['typed']['note']) ?>" maxlength="500"></label>
      <p class="actions"><button type="submit" class="primary"><?= $word('ACCOUNTS', 'pay_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
  <h3><?= $word('ACCOUNTS', 'history') ?></h3>
<?php if ($a['entries'] === []): ?>
  <p class="muted"><?= $word('ACCOUNTS', 'no_history') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list account-entries">
    <thead>
      <tr>
        <th scope="col"><?= $word('ACCOUNTS', 'what') ?></th>
        <th scope="col"><?= $word('ACCOUNTS', 'when') ?></th>
        <th scope="col" class="num"><?= $word('ACCOUNTS', 'amount_col') ?></th>
        <th scope="col"><?= $word('ACCOUNTS', 'ref_col') ?></th>
        <th scope="col"><?= $word('ACCOUNTS', 'who') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($a['entries'] as $x): ?>
      <tr<?php if ($x['reversed_by'] !== null): ?> class="off"<?php endif; ?>>
        <th scope="row" class="c-head"><?php if ($x['href'] !== null): ?><a href="<?= $u($x['href']) ?>"><?= $e($x['what']) ?></a><?php else: ?><?= $e($x['what']) ?><?php endif; ?><?php if ($x['reversed_by'] !== null): ?> <?= $chip('off', \CW\Ui\Words::ACCOUNTS['reversed_mark']) ?><?php endif; ?><?php if ($x['note'] !== null): ?><span class="o-sub"><?= $e($x['note']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('ACCOUNTS', 'when') ?>"><?php if ($x['paid_on'] !== null): ?><?= $day($x['paid_on']) ?><?php else: ?><?= $when($x['at']) ?><?php endif; ?></td>
        <td data-label="<?= $word('ACCOUNTS', 'amount_col') ?>" class="num chg <?= $e(str_starts_with($x['amount'], '-') ? 'down' : 'up') ?>"><?= $money($x['amount']) ?></td>
        <td data-label="<?= $word('ACCOUNTS', 'ref_col') ?>"><?php if ($x['reference'] !== null): ?><?= $e($x['reference']) ?><?php endif; ?></td>
        <td data-label="<?= $word('ACCOUNTS', 'who') ?>"><?= $e($x['who']) ?><?php if ($x['reversible']): ?>
          <details class="fold">
            <summary><?= $word('ACCOUNTS', 'reverse') ?></summary>
            <form class="record" method="post" action="<?= $u('/ui/stock/accounts/payments/' . $x['id'] . '/reverse') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <label><?= $word('ACCOUNTS', 'reverse_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
              <p class="actions"><button type="submit" class="danger"><?= $word('ACCOUNTS', 'reverse_button') ?></button></p>
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
