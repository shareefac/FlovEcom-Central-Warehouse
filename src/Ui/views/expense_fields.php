<?php
// The fields of an expense (CW-1150; the drawing AddExpense), shared by "Add an expense" and an expense's "Change": the supplier (type to find
// one from the list; a new one only with its box ticked), the type, the invoice date and number, the amount without VAT, the VAT as printed,
// the total (net + VAT: worked out by CW when it saves; app.js shows it as you type), the hint for dollar bills and the note.
?>
    <fieldset class="expense-fields">
      <label><?= $word('EXPENSES', 'supplier') ?> <span class="hint"><?= $word('EXPENSES', 'supplier_hint') ?></span>
        <input type="text" name="supplier" value="<?= $e((string) $t['supplier']) ?>" list="expense-suppliers" maxlength="100" autocomplete="off" required<?= $bad('supplier') ?>>
      </label>
      <datalist id="expense-suppliers">
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['name']) ?>"></option>
<?php endforeach; ?>
      </datalist>
<?php if ($newSupplier): ?>
      <label class="choice"><input type="checkbox" name="new_supplier" value="1"<?php if ($t['new_supplier']): ?> checked<?php endif; ?>> <?= $word('EXPENSES', 'new_supplier') ?></label>
<?php endif; ?>
      <label><?= $word('EXPENSES', 'type') ?> <span class="hint"><?= $word('EXPENSES', 'type_hint') ?></span>
        <select name="type" required<?= $bad('type') ?>>
          <option value=""><?= $word('EXPENSES', 'choose_type') ?></option>
<?php foreach ($types as $ty): ?>
          <option value="<?= $e((string) $ty['id']) ?>"<?php if ((string) $t['type'] === (string) $ty['id']): ?> selected<?php endif; ?>><?= $e($ty['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('EXPENSES', 'invoice_date') ?>
        <input type="date" name="invoice_date" value="<?= $e((string) $t['invoice_date']) ?>" required<?= $bad('invoice_date') ?>>
      </label>
      <label><?= $word('EXPENSES', 'invoice_no') ?> <span class="hint"><?= $word('EXPENSES', 'invoice_no_hint') ?></span>
        <input type="text" name="invoice_no" value="<?= $e((string) $t['invoice_no']) ?>" maxlength="64" autocomplete="off"<?= $bad('invoice_no') ?>>
      </label>
      <label><?= $word('EXPENSES', 'net') ?>
        <input type="text" name="net" value="<?= $e((string) $t['net']) ?>" inputmode="decimal" maxlength="16" autocomplete="off" required data-expense-sum<?= $bad('net') ?>>
      </label>
      <label><?= $word('EXPENSES', 'vat') ?> <span class="hint"><?= $word('EXPENSES', 'vat_hint') ?></span>
        <input type="text" name="vat" value="<?= $e((string) $t['vat']) ?>" inputmode="decimal" maxlength="16" autocomplete="off" required data-expense-sum<?= $bad('vat') ?>>
      </label>
      <p class="expense-total"><span><?= $word('EXPENSES', 'total') ?></span> <output data-expense-total><?= $net !== null && $vat !== null ? $e(\CW\Expenses\ExpenseMoney::gbp($net + $vat)) : '-' ?></output></p>
      <p class="note expense-dollar"><?= $word('EXPENSES', 'dollar_hint') ?></p>
      <label><?= $word('EXPENSES', 'note') ?>
        <input type="text" name="note" value="<?= $e((string) $t['note']) ?>" maxlength="500" placeholder="<?= $word('EXPENSES', 'note_placeholder') ?>"<?= $bad('note') ?>>
      </label>
    </fieldset>
