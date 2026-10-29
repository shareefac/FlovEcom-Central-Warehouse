<?php
// Stock › Hand-overs › Balances, a report (the approved design of 29 Oct 2026, pattern 5, TP_Report; docs/decisions.md W8): the figures
// strip (what the customers owe us in all, what was handed over this month, how many owe something), one board of every customer (what it
// owes, this month, its last payment; the total under it; a name leads to its part below), then each customer's part at its own address
// (#customer-<id>, where a payment lands): record a payment received (a fold), and every entry as a board, a payment reversed with a reason.
?>
<?= $head(\CW\Ui\Words::SEGMENT['handover_balances'], intro: 'handover_balances', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<p class="muted"><?= $word('BALANCE', 'accountant') ?></p>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::BALANCE['none'], \CW\Ui\Words::BALANCE['none_text'], '/ui/stock/handovers/customers', \CW\Ui\Words::BALANCE['none_link']) ?>
<?php else: ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('BALANCE', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('BALANCE', 'fig_owed') ?></p><p class="doc-step-big"><?= $money($figures['owed']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('BALANCE', 'tile_month') ?></p><p class="doc-step-big"><?= $money($figures['month']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('BALANCE', 'fig_owing') ?></p><p class="doc-step-big"><?= $n($figures['owing']) ?></p></div>
</section>

<div class="table-wrap doc-board info">
<table class="stack list board doc-list balances">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('BALANCE', 'customer') ?></th>
      <th scope="col" class="num"><?= $word('BALANCE', 'tile_balance') ?></th>
      <th scope="col" class="num"><?= $word('BALANCE', 'tile_month') ?></th>
      <th scope="col"><?= $word('BALANCE', 'last_col') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $a): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="#customer-<?= $e($a['id']) ?>"><?= $e($a['name']) ?></a><span class="o-sub"><?= $word('CUSTOMER_KIND', $a['kind']) ?><?php if (!$a['active']): ?> · <?= $word('CUSTOMER', 'off') ?><?php endif; ?></span></th>
      <td class="num" data-label="<?= $word('BALANCE', 'tile_balance') ?>"><?= $money($a['balance']) ?><?php if (str_starts_with($a['balance'], '-')): ?><span class="o-sub"><?= $word('BALANCE', 'credit') ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('BALANCE', 'tile_month') ?>"><?= $money($a['month_amount']) ?><span class="o-sub"><?= $say('BALANCE', 'handovers_sub', $a['handovers']) ?></span></td>
      <td data-label="<?= $word('BALANCE', 'last_col') ?>"><?php if ($a['last_payment'] !== null): ?><?= $money($a['last_payment']['amount']) ?><span class="o-sub"><?= $day($a['last_payment']['paid_on']) ?> · <?= $e($a['last_payment']['reference']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="grp-foot">
      <th scope="row" class="c-head c-item"><?= $word('BALANCE', 'total') ?></th>
      <td class="num" data-label="<?= $word('BALANCE', 'tile_balance') ?>"><?= $money($figures['owed']) ?></td>
      <td class="num" data-label="<?= $word('BALANCE', 'tile_month') ?>"><?= $money($figures['month']) ?></td>
      <td data-label=""></td>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php foreach ($rows as $a): ?>
<?php $mine = $typed !== null && $typed['for'] === (string) $a['id']; ?>
<section class="account doc-related" id="customer-<?= $e($a['id']) ?>" aria-labelledby="customer-h-<?= $e($a['id']) ?>">
  <div class="board-head"><h2 id="customer-h-<?= $e($a['id']) ?>" class="board-sub"><a href="<?= $u('/ui/stock/handovers/customers/' . $a['id']) ?>"><?= $e($a['name']) ?></a> <span class="muted"><?= $money($a['balance']) ?></span><?php if (!$a['active']): ?> <?= $chip('off', \CW\Ui\Words::CUSTOMER['off']) ?><?php endif; ?></h2></div>
<?php if ($canPay): ?>
  <details class="fold"<?php if ($mine): ?> open<?php endif; ?>>
    <summary><?= $word('BALANCE', 'pay') ?></summary>
    <p class="muted"><?= $word('BALANCE', 'pay_text') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/stock/handovers/balances/' . $a['id'] . '/payments') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="form_key" value="<?= $e($a['form_key']) ?>">
      <fieldset class="doc-grid">
        <legend class="visually-hidden"><?= $word('BALANCE', 'pay') ?></legend>
        <label><?= $word('BALANCE', 'amount') ?> <input type="text" name="amount" value="<?php if ($mine): ?><?= $e($typed['amount']) ?><?php endif; ?>" inputmode="decimal" maxlength="16" required></label>
        <label><?= $word('BALANCE', 'paid_on') ?> <input type="date" name="paid_on" value="<?php if ($mine): ?><?= $e($typed['paid_on']) ?><?php else: ?><?= $e($today) ?><?php endif; ?>" required></label>
        <label><?= $word('BALANCE', 'reference') ?> <input type="text" name="reference" value="<?php if ($mine): ?><?= $e($typed['reference']) ?><?php endif; ?>" maxlength="100" required></label>
        <label><?= $word('BALANCE', 'note') ?> <input type="text" name="note" value="<?php if ($mine): ?><?= $e($typed['note']) ?><?php endif; ?>" maxlength="500"></label>
      </fieldset>
      <p class="actions"><button type="submit" class="btn"><?= $word('BALANCE', 'pay_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
<?php if ($a['entries'] === []): ?>
  <p class="muted"><?= $word('BALANCE', 'no_history') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list account-entries">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('BALANCE', 'what') ?></th>
        <th scope="col"><?= $word('BALANCE', 'when') ?></th>
        <th scope="col" class="num"><?= $word('BALANCE', 'amount_col') ?></th>
        <th scope="col"><?= $word('BALANCE', 'ref_col') ?></th>
        <th scope="col"><?= $word('BALANCE', 'who') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($a['entries'] as $x): ?>
      <tr<?php if ($x['reversed_by'] !== null): ?> class="off"<?php endif; ?>>
        <th scope="row" class="c-head c-item"><?php if ($x['document_id'] !== null): ?><a href="<?= $u('/ui/stock/handovers/' . $x['document_id']) ?>"><?= $say('BALANCE_KIND', $x['kind'], (string) ($x['number'] ?? '')) ?></a><?php else: ?><?= $say('BALANCE_KIND', $x['kind'], '') ?><?php endif; ?><?php if ($x['reversed_by'] !== null): ?> <?= $chip('off', \CW\Ui\Words::BALANCE['reversed_mark']) ?><?php endif; ?><?php if ($x['note'] !== null): ?><span class="o-sub"><?= $e($x['note']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('BALANCE', 'when') ?>"><?php if ($x['paid_on'] !== null): ?><?= $day($x['paid_on']) ?><?php else: ?><?= $when($x['at']) ?><?php endif; ?></td>
        <td data-label="<?= $word('BALANCE', 'amount_col') ?>" class="num chg <?= $e(str_starts_with($x['amount'], '-') ? 'down' : 'up') ?>"><?= $money($x['amount']) ?></td>
        <td data-label="<?= $word('BALANCE', 'ref_col') ?>"><?php if ($x['reference'] !== null): ?><?= $e($x['reference']) ?><?php endif; ?></td>
        <td data-label="<?= $word('BALANCE', 'who') ?>"><?= $e($x['who']) ?><?php if ($canPay && $x['kind'] === 'payment' && $x['reversed_by'] === null): ?>
          <details class="fold">
            <summary><?= $word('BALANCE', 'reverse') ?></summary>
            <form class="record" method="post" action="<?= $u('/ui/stock/handovers/balances/payments/' . $x['id'] . '/reverse') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <label><?= $word('BALANCE', 'reverse_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
              <p class="actions"><button type="submit" class="btn risky"><?= $word('BALANCE', 'reverse_button') ?></button></p>
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
