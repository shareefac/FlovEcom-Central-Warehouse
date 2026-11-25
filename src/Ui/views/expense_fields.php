<?php
// The fields of an expense (CW-1150; the drawing AddExpense), shared by "Add an expense" and an expense's "Change": the supplier (type to find
// one from the list; a new one only with its box ticked), the type, the invoice date and number, the amount without VAT, the VAT as printed,
// the total (net + VAT: worked out by CW when it saves; app.js shows it as you type), the hint for dollar bills and the note. "This is a credit
// note" (EX37): its amounts are typed as printed, without a minus, and kept as minus; the total shows the minus.
// The layout (docs/decisions.md EX64): two cells a row (div.ex-row), each cell its label, its box and its hint UNDER the box (tied to it with
// aria-describedby), so the boxes of a row line up; "Add it as a new supplier" under the supplier's hint; the credit tick before the amounts.
?>
    <div class="ex-fields">
      <div class="ex-row">
        <div class="field">
          <label for="ex-supplier"><?= $word('EXPENSES', 'supplier') ?></label>
          <input id="ex-supplier" type="text" name="supplier" value="<?= $e((string) $t['supplier']) ?>" list="expense-suppliers" maxlength="100" autocomplete="off" required aria-describedby="ex-supplier-hint"<?php if ($errorField === 'supplier'): ?> aria-invalid="true"<?php endif; ?>>
          <div class="ex-under">
            <p class="hint" id="ex-supplier-hint"><?= $word('EXPENSES', 'supplier_hint') ?></p>
<?php if ($newSupplier): ?>
            <label class="choice ex-tick"><input type="checkbox" name="new_supplier" value="1"<?php if ($t['new_supplier']): ?> checked<?php endif; ?>> <?= $word('EXPENSES', 'new_supplier') ?></label>
<?php endif; ?>
          </div>
          <datalist id="expense-suppliers">
<?php foreach ($suppliers as $s): ?>
            <option value="<?= $e($s['name']) ?>"></option>
<?php endforeach; ?>
          </datalist>
        </div>
        <div class="field">
          <label for="ex-type"><?= $word('EXPENSES', 'type') ?></label>
          <select id="ex-type" name="type" required aria-describedby="ex-type-hint"<?php if ($errorField === 'type'): ?> aria-invalid="true"<?php endif; ?>>
            <option value=""><?= $word('EXPENSES', 'choose_type') ?></option>
<?php foreach ($types as $ty): ?>
            <option value="<?= $e((string) $ty['id']) ?>"<?php if ((string) $t['type'] === (string) $ty['id']): ?> selected<?php endif; ?>><?= $e($ty['name']) ?></option>
<?php endforeach; ?>
          </select>
          <p class="hint" id="ex-type-hint"><?= $word('EXPENSES', 'type_hint') ?></p>
        </div>
      </div>
      <div class="ex-row">
        <div class="field">
          <label for="ex-invoice-date"><?= $word('EXPENSES', 'invoice_date') ?></label>
          <input id="ex-invoice-date" type="date" name="invoice_date" value="<?= $e((string) $t['invoice_date']) ?>" required<?php if ($errorField === 'invoice_date'): ?> aria-invalid="true"<?php endif; ?>>
        </div>
        <div class="field">
          <label for="ex-invoice-no"><?= $word('EXPENSES', 'invoice_no') ?></label>
          <input id="ex-invoice-no" type="text" name="invoice_no" value="<?= $e((string) $t['invoice_no']) ?>" maxlength="64" autocomplete="off" aria-describedby="ex-invoice-no-hint"<?php if ($errorField === 'invoice_no'): ?> aria-invalid="true"<?php endif; ?>>
          <p class="hint" id="ex-invoice-no-hint"><?= $word('EXPENSES', 'invoice_no_hint') ?></p>
        </div>
      </div>
      <div class="ex-credit">
        <label class="choice ex-tick"><input type="checkbox" name="credit" value="1" data-expense-credit aria-describedby="ex-credit-hint"<?php if ($t['credit']): ?> checked<?php endif; ?>> <?= $word('EXPENSES', 'credit') ?></label>
        <p class="hint" id="ex-credit-hint"><?= $word('EXPENSES', 'credit_hint') ?></p>
      </div>
      <div class="ex-row">
        <div class="field">
          <label for="ex-net"><?= $word('EXPENSES', 'net') ?></label>
          <input id="ex-net" type="text" name="net" value="<?= $e((string) $t['net']) ?>" inputmode="decimal" maxlength="16" autocomplete="off" required data-expense-sum<?php if ($errorField === 'net'): ?> aria-invalid="true"<?php endif; ?>>
        </div>
        <div class="field">
          <label for="ex-vat"><?= $word('EXPENSES', 'vat') ?></label>
          <input id="ex-vat" type="text" name="vat" value="<?= $e((string) $t['vat']) ?>" inputmode="decimal" maxlength="16" autocomplete="off" required data-expense-sum aria-describedby="ex-vat-hint"<?php if ($errorField === 'vat'): ?> aria-invalid="true"<?php endif; ?>>
          <p class="hint" id="ex-vat-hint"><?= $word('EXPENSES', 'vat_hint') ?></p>
        </div>
      </div>
      <p class="expense-total"><span><?= $word('EXPENSES', 'total') ?></span> <output data-expense-total><?php if ($net !== null && $vat !== null && $t['credit']): ?><?= $e(\CW\Expenses\ExpenseMoney::gbp(-($net + $vat))) ?><?php elseif ($net !== null && $vat !== null): ?><?= $e(\CW\Expenses\ExpenseMoney::gbp($net + $vat)) ?><?php else: ?>-<?php endif; ?></output></p>
      <p class="alert expense-dollar"><?= $word('EXPENSES', 'dollar_hint') ?></p>
      <div class="field ex-wide">
        <label for="ex-note"><?= $word('EXPENSES', 'note') ?></label>
        <input id="ex-note" type="text" name="note" value="<?= $e((string) $t['note']) ?>" maxlength="500" placeholder="<?= $word('EXPENSES', 'note_placeholder') ?>"<?php if ($errorField === 'note'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
    </div>
