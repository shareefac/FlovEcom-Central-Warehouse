<?php
// Profit › Expenses › Add an expense (CW-1150; docs/decisions.md EX1-EX4, EX9; the approved drawing AddExpense of 24 Nov 2026): the shop as
// three big buttons (picked each time, none picked for you), the supplier (type to find one; a new one only with its box ticked), the type,
// the invoice date and number, the amount without VAT and the VAT as printed (0 allowed), the total (net + VAT, worked out on save), a hint for
// dollar bills, a note, the PDF or a photo; "Save expense" and "Save and add another". A refusal comes back with what was typed.
$X = \CW\Ui\Words::EXPENSES;
$t = $typed;
$net = \CW\Expenses\ExpenseMoney::pence((string) $t['net']);
$vat = \CW\Expenses\ExpenseMoney::pence((string) $t['vat']);
?>
<?= $head($X['new_title'], intro: 'profit_expense_new', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_expenses'], [$vatPct]), back: ['/ui/profit/expenses', $X['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e((string) $errorCode) ?>"><?= $e($error) ?><?php if ($other !== null): ?> <a href="<?= $e($other) ?>"><?= $word('EXPENSES', 'open_other') ?></a><?php endif; ?></p>
<?php endif; ?>
<form class="record expense-form" method="post" action="/ui/profit/expenses/new" enctype="multipart/form-data" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <div class="expense-main">
    <fieldset class="shop-pick">
      <legend><?= $word('EXPENSES', 'which_shop') ?></legend>
<?php foreach ($shops as $s): ?>
      <label class="choice shop-choice"><input type="radio" name="shop" value="<?= $e((string) $s['id']) ?>" required<?php if ((string) $t['shop'] === (string) $s['id']): ?> checked<?php endif; ?><?php if ($errorField === 'shop'): ?> aria-invalid="true"<?php endif; ?>> <?= $e($s['name']) ?></label>
<?php endforeach; ?>
    </fieldset>
<?= $partial('expense_fields', ['t' => $t, 'types' => $types, 'suppliers' => $suppliers, 'errorField' => $errorField, 'net' => $net, 'vat' => $vat, 'newSupplier' => true]) ?>
<?php if ($sameAsk): ?>
    <label class="choice same-ok"><input type="checkbox" name="same_ok" value="1"> <?= $word('EXPENSES', 'same_ok') ?></label>
    <input type="hidden" name="same_ids" value="<?= $e($sameIds) ?>">
<?php endif; ?>
    <p class="actions">
      <button type="submit" class="btn primary" name="then" value="done"><?= $word('EXPENSES', 'save') ?></button>
      <button type="submit" class="btn" name="then" value="another"><?= $word('EXPENSES', 'save_another') ?></button>
    </p>
  </div>
  <fieldset class="expense-file">
    <legend><?= $word('EXPENSES', 'file_title') ?></legend>
<?php if ($fileAgain): ?>
    <p class="note" role="status"><?= $word('EXPENSES', 'file_again') ?></p>
    <input type="hidden" name="file_again" value="1">
<?php endif; ?>
    <label><?= $word('EXPENSES', 'file_label') ?> <span class="hint"><?= $say('EXPENSES', 'file_hint', $maxMb) ?></span>
      <input type="file" name="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png"<?php if ($errorField === 'file'): ?> aria-invalid="true"<?php endif; ?>>
    </label>
<?php if ($fileAgain): ?>
    <label class="choice"><input type="checkbox" name="no_file_ok" value="1"> <?= $word('EXPENSES', 'no_file_ok') ?></label>
<?php endif; ?>
    <p class="muted"><?= $word('EXPENSES', 'file_kept') ?></p>
  </fieldset>
</form>
