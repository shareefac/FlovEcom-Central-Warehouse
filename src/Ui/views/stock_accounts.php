<?php
// Stock › Transfers › Balance owed, a report (the approved design of 29 Oct 2026, pattern 5, TP_Report; docs/decisions.md W8): the figures
// strip (what the other accounts' rooms hold, what was released this month, the balance owed, every account added up), one board of every
// account (a name leads to its part below), then each account's part at its own address (#account-<id>, where a payment lands): Release
// stock, record a payment (a fold), and its releases and payments as a board, a payment reversed with a reason.
?>
<?= $head(\CW\Ui\Words::ACCOUNTS['title'], intro: 'accounts', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($accounts === []): ?>
<?php if ($canWarehouses): ?>
<?= $empty(\CW\Ui\Words::ACCOUNTS['none'], \CW\Ui\Words::ACCOUNTS['none_text'], '/ui/reference/warehouses', \CW\Ui\Words::ACCOUNTS['none_link']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::ACCOUNTS['none'], \CW\Ui\Words::ACCOUNTS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('ACCOUNTS', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('ACCOUNTS', 'tile_holds') ?></p><p class="doc-step-big"><?= $n($figures['units']) ?></p><p class="step-sub"><?= $word('ACCOUNTS', 'units_word') ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('ACCOUNTS', 'tile_month') ?></p><p class="doc-step-big"><?= $money($figures['month']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('ACCOUNTS', 'tile_balance') ?></p><p class="doc-step-big"><?= $e($figures['owed']) ?></p><p class="step-sub"><?= $word('ACCOUNTS', 'balance_sub') ?></p></div>
</section>

<div class="table-wrap doc-board info">
<table class="stack list board doc-list accounts">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('ACCOUNTS', 'account_col') ?></th>
      <th scope="col" class="num"><?= $word('ACCOUNTS', 'tile_holds') ?></th>
      <th scope="col" class="num"><?= $word('ACCOUNTS', 'tile_month') ?></th>
      <th scope="col" class="num"><?= $word('ACCOUNTS', 'tile_balance') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($accounts as $a): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="#account-<?= $e($a['id']) ?>"><?= $say('ACCOUNTS', 'room', $a['name'], $a['entity']) ?></a><?php if (!$a['active']): ?><span class="o-sub"><?= $word('ACCOUNTS', 'switched_off') ?></span><?php elseif ($a['own']): ?><span class="o-sub"><?= $word('ACCOUNTS', 'ours_now') ?></span><?php endif; ?></th>
      <td class="num" data-label="<?= $word('ACCOUNTS', 'tile_holds') ?>"><?= $n($a['holds_units']) ?><span class="o-sub"><?= $say('ACCOUNTS', 'units_sub', number_format($a['holds_products'])) ?> · <?= $say('ACCOUNTS', 'holds_value', \CW\Ui\Html::money($a['holds_value'])) ?></span></td>
      <td class="num" data-label="<?= $word('ACCOUNTS', 'tile_month') ?>"><?= $money($a['month_amount']) ?><span class="o-sub"><?= $say('ACCOUNTS', 'month_sub', number_format($a['month_units'])) ?></span></td>
      <td class="num" data-label="<?= $word('ACCOUNTS', 'tile_balance') ?>"><?= $e($a['balance_text']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php foreach ($accounts as $a): ?>
<section class="account doc-related" id="account-<?= $e($a['id']) ?>" aria-labelledby="account-h-<?= $e($a['id']) ?>">
  <div class="board-head"><h2 id="account-h-<?= $e($a['id']) ?>" class="board-sub"><?= $say('ACCOUNTS', 'room', $a['name'], $a['entity']) ?> <span class="muted"><?= $e($a['balance_text']) ?></span><?php if (!$a['active']): ?> <?= $chip('off', \CW\Ui\Words::ACCOUNTS['switched_off']) ?><?php endif; ?><?php if ($a['own']): ?> <?= $chip('info', \CW\Ui\Words::ACCOUNTS['ours_now']) ?><?php endif; ?></h2><?php if ($canRelease && !$a['own'] && $a['active']): ?><a class="btn" href="/ui/stock/releases#new"><?= $word('ACCOUNTS', 'release') ?></a><?php endif; ?></div>
<?php if ($a['error'] !== null): ?>
  <p class="error" role="alert"><?= $e($a['error']) ?></p>
<?php endif; ?>
<?php if ($a['unpriced'] > 0): ?>
  <p class="muted"><?= $say('ACCOUNTS', 'holds_unpriced', number_format($a['unpriced'])) ?></p>
<?php endif; ?>
<?php if ($a['last_payment'] !== null): ?>
  <p class="muted"><?= $say('ACCOUNTS', 'last_paid', \CW\Ui\Html::money($a['last_payment']['amount']), \CW\Ui\Html::day($a['last_payment']['paid_on']), $a['last_payment']['reference']) ?></p>
<?php endif; ?>
<?php if ($canPay): ?>
  <details class="fold"<?php if ($a['error'] !== null): ?> open<?php endif; ?>>
    <summary><?= $word('ACCOUNTS', 'pay') ?></summary>
    <p class="muted"><?= $word('ACCOUNTS', 'pay_text') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/stock/accounts/' . $a['id'] . '/payments') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <fieldset class="doc-grid">
        <legend class="visually-hidden"><?= $word('ACCOUNTS', 'pay') ?></legend>
        <label><?= $word('ACCOUNTS', 'amount') ?> <input type="text" name="amount" value="<?= $e($a['typed']['amount']) ?>" inputmode="decimal" maxlength="16" required></label>
        <label><?= $word('ACCOUNTS', 'paid_on') ?> <input type="date" name="paid_on" value="<?= $e($a['typed']['paid_on']) ?>" required></label>
        <label><?= $word('ACCOUNTS', 'reference') ?> <input type="text" name="reference" value="<?= $e($a['typed']['reference']) ?>" maxlength="100" required></label>
        <label><?= $word('ACCOUNTS', 'note') ?> <input type="text" name="note" value="<?= $e($a['typed']['note']) ?>" maxlength="500"></label>
      </fieldset>
      <p class="actions"><button type="submit" class="btn"><?= $word('ACCOUNTS', 'pay_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
<?php if ($a['entries'] === []): ?>
  <p class="muted"><?= $word('ACCOUNTS', 'no_history') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list account-entries">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('ACCOUNTS', 'what') ?></th>
        <th scope="col"><?= $word('ACCOUNTS', 'when') ?></th>
        <th scope="col" class="num"><?= $word('ACCOUNTS', 'amount_col') ?></th>
        <th scope="col"><?= $word('ACCOUNTS', 'ref_col') ?></th>
        <th scope="col"><?= $word('ACCOUNTS', 'who') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($a['entries'] as $x): ?>
      <tr<?php if ($x['reversed_by'] !== null): ?> class="off"<?php endif; ?>>
        <th scope="row" class="c-head c-item"><?php if ($x['href'] !== null): ?><a href="<?= $u($x['href']) ?>"><?= $e($x['what']) ?></a><?php else: ?><?= $e($x['what']) ?><?php endif; ?><?php if ($x['reversed_by'] !== null): ?> <?= $chip('off', \CW\Ui\Words::ACCOUNTS['reversed_mark']) ?><?php endif; ?><?php if ($x['note'] !== null): ?><span class="o-sub"><?= $e($x['note']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('ACCOUNTS', 'when') ?>"><?php if ($x['paid_on'] !== null): ?><?= $day($x['paid_on']) ?><?php else: ?><?= $when($x['at']) ?><?php endif; ?></td>
        <td data-label="<?= $word('ACCOUNTS', 'amount_col') ?>" class="num chg <?= $e(str_starts_with($x['amount'], '-') ? 'down' : 'up') ?>"><?= $money($x['amount']) ?></td>
        <td data-label="<?= $word('ACCOUNTS', 'ref_col') ?>"><?php if ($x['reference'] !== null): ?><?= $e($x['reference']) ?><?php endif; ?></td>
        <td data-label="<?= $word('ACCOUNTS', 'who') ?>"><?= $e($x['who']) ?><?php if ($x['reversible']): ?>
          <details class="fold">
            <summary><?= $word('ACCOUNTS', 'reverse') ?></summary>
            <form class="record" method="post" action="<?= $u('/ui/stock/accounts/payments/' . $x['id'] . '/reverse') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <label><?= $word('ACCOUNTS', 'reverse_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
              <p class="actions"><button type="submit" class="btn risky"><?= $word('ACCOUNTS', 'reverse_button') ?></button></p>
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
