<?php
// Profit › Expenses › Suppliers (CW-1150; docs/decisions.md EX11): the companies expenses are paid to, their own list (not the stock suppliers),
// by name: how many expenses name each, in use or switched off; for the Expenses job, add one, rename one, switch one off or on. Never deleted.
$X = \CW\Ui\Words::EXPENSES;
?>
<?= $head($X['suppliers_title'], intro: 'profit_expense_suppliers', back: ['/ui/profit/expenses', $X['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<form class="record" method="post" action="/ui/profit/expenses/suppliers/add">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <fieldset>
    <legend><?= $word('EXPENSES', 'supplier_add') ?></legend>
    <label><?= $word('EXPENSES', 'supplier_name') ?>
      <input type="text" name="name" value="<?= $e($typed) ?>" maxlength="100" required>
    </label>
  </fieldset>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('EXPENSES', 'supplier_add') ?></button></p>
</form>
<?php endif; ?>
<?php if ($suppliers === []): ?>
<?= $empty($X['no_suppliers'], $canEdit ? $X['no_suppliers_text'] : '') ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table expense-suppliers">
  <thead>
    <tr>
      <th scope="col"><?= $word('EXPENSES', 'supplier_name') ?></th>
      <th scope="col" class="num"><?= $word('EXPENSES', 'used') ?></th>
      <th scope="col"><?= $word('EXPENSES', 'status') ?></th>
<?php if ($canEdit): ?>
      <th scope="col"><?= $word('EXPENSES', 'change') ?></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($suppliers as $s): ?>
    <tr<?php if (!$s['is_active']): ?> class="off"<?php endif; ?>>
      <th scope="row"><?= $e($s['name']) ?></th>
      <td class="num" data-label="<?= $word('EXPENSES', 'used') ?>"><?= $n($s['used']) ?></td>
      <td data-label="<?= $word('EXPENSES', 'status') ?>"><?php if ($s['is_active']): ?><?= $chip('done', $X['in_use']) ?><?php else: ?><?= $chip('off', $X['off_chip']) ?><?php endif; ?></td>
<?php if ($canEdit): ?>
      <td data-label="<?= $word('EXPENSES', 'change') ?>">
        <form class="inline" method="post" action="<?= $u('/ui/profit/expenses/suppliers/' . $s['id'] . '/change') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="seen" value="<?= $e((string) $s['version']) ?>">
          <input type="text" name="name" value="<?= $e($s['name']) ?>" maxlength="100" aria-label="<?= $word('EXPENSES', 'supplier_name') ?>">
          <button type="submit" class="btn" name="do" value="rename"><?= $word('EXPENSES', 'rename') ?></button>
          <?php if ($s['is_active']): ?><button type="submit" class="btn" name="do" value="off"><?= $word('EXPENSES', 'switch_off') ?></button><?php else: ?><button type="submit" class="btn" name="do" value="on"><?= $word('EXPENSES', 'switch_on') ?></button><?php endif; ?>
        </form>
      </td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
