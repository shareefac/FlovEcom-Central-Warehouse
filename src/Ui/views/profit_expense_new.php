<?php
// Profit › Expenses › Add an expense (CW-1150; docs/decisions.md EX1-EX4, EX9; the approved drawing AddExpense of 24 Nov 2026): the shop as
// three big buttons (picked each time, none picked for you), the supplier (type to find one; a new one only with its box ticked), the type,
// the invoice date and number, the amount without VAT and the VAT as printed (0 allowed), the total (net + VAT, worked out on save), a hint for
// dollar bills, a note, the PDF or a photo; "Save expense" and "Save and add another". A refusal comes back with what was typed.
// The layout (EX64): one card (div.ex-main: the shop and the fields), the file panel (div.ex-side: beside the card on a wide screen, inside it
// on a narrow one), then at the card's foot the "same one" tick when asked and Save; the file comes before Save in the reading and keyboard
// order, the tick just above Save. Claude reads the invoice (CW-1182, AI5): while it is on, "Fill from the file with Claude" under the file sends
// the form with its file to be read (the page that waits, then this form filled: a note says which boxes Claude filled; the file is asked for
// again: the copy Claude read is deleted).
$X = \CW\Ui\Words::EXPENSES;
$t = $typed;
$net = \CW\Expenses\ExpenseMoney::pence((string) $t['net']);
$vat = \CW\Expenses\ExpenseMoney::pence((string) $t['vat']);
?>
<?= $head($X['new_title'], intro: 'profit_expense_new', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_expenses'], [$vatPct]), back: ['/ui/profit/expenses', $X['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e((string) $errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if (($aiFilled ?? null) !== null): ?>
<div class="alert ai-filled" role="status">
  <p><?= $e($aiFilled) ?></p>
<?php foreach ($aiNotes as $aiNote): ?>
  <p><?= $e($aiNote) ?></p>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?= $partial('same_list', ['items' => $sameList, 'group' => 'EXPENSES']) ?>
<form class="record expense-form" method="post" action="/ui/profit/expenses/new" enctype="multipart/form-data" data-vat-bp="<?= $e((string) $vatBp) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <div class="ex-main">
    <fieldset class="shop-pick">
      <legend><?= $word('EXPENSES', 'which_shop') ?></legend>
<?php foreach ($shops as $s): ?>
      <label class="choice shop-choice"><input type="radio" name="shop" value="<?= $e((string) $s['id']) ?>" required<?php if ((string) $t['shop'] === (string) $s['id']): ?> checked<?php endif; ?><?php if ($errorField === 'shop'): ?> aria-invalid="true"<?php endif; ?>> <?= $e($s['name']) ?></label>
<?php endforeach; ?>
    </fieldset>
<?= $partial('expense_fields', ['t' => $t, 'types' => $types, 'suppliers' => $suppliers, 'errorField' => $errorField, 'net' => $net, 'vat' => $vat, 'newSupplier' => true, 'vatPct' => $vatPct]) ?>
  </div>
  <div class="ex-side">
    <fieldset class="expense-file">
      <legend><?= $word('EXPENSES', 'file_title') ?></legend>
<?php if ($fileAgain): ?>
      <p class="note" role="status"><?= $word(($aiFilled ?? null) !== null ? 'AIREAD' : 'EXPENSES', 'file_again') ?></p>
      <input type="hidden" name="file_again" value="1">
<?php endif; ?>
      <div class="field">
        <label for="ex-file"><?= $word('EXPENSES', 'file_label') ?></label>
        <div class="ex-drop">
          <input id="ex-file" type="file" name="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" aria-describedby="ex-file-hint"<?php if ($errorField === 'file'): ?> aria-invalid="true"<?php endif; ?>>
          <span class="ex-drop-words" aria-hidden="true"><?= $word('EXPENSES', 'file_drop') ?></span>
        </div>
        <p class="hint" id="ex-file-hint"><?= $say('EXPENSES', 'file_hint', $maxMb) ?></p>
      </div>
<?php if ($fileAgain): ?>
      <label class="choice"><input type="checkbox" name="no_file_ok" value="1"> <?= $word('EXPENSES', 'no_file_ok') ?></label>
<?php endif; ?>
<?php if ($aiOn ?? false): ?>
      <p class="actions ai-read"><button type="submit" class="btn" formaction="/ui/profit/expenses/read" formnovalidate><?= $word('AIREAD', 'read_button') ?></button></p>
      <p class="hint"><?= $word('AIREAD', 'read_hint') ?></p>
<?php endif; ?>
      <p class="muted"><?= $word('EXPENSES', 'file_kept') ?></p>
    </fieldset>
  </div>
<?php if ($sameAsk): ?>
  <label class="choice same-ok"><input type="checkbox" name="same_ok" value="1"> <?= $word('EXPENSES', 'same_ok') ?></label>
  <input type="hidden" name="same_ids" value="<?= $e($sameIds) ?>">
<?php endif; ?>
  <p class="actions">
    <button type="submit" class="btn primary lg" name="then" value="done"><?= $word('EXPENSES', 'save') ?></button>
    <button type="submit" class="btn lg" name="then" value="another"><?= $word('EXPENSES', 'save_another') ?></button>
  </p>
</form>
