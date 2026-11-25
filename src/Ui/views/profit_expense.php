<?php
// Profit › Expenses › one expense (CW-1150; docs/decisions.md EX1-EX9, EX13): its facts (shop, supplier, type, invoice date and number, without
// VAT, VAT, total, note, who added it), its PDF or photo (opened as a download), for the Expenses job "Change this expense" (the same fields as
// the add form, a new file optional) and "Delete this expense" (a reason and a box: a mark, never a removal), and its history. The Change
// form is laid out as the add form's card (docs/decisions.md EX64): the fields, the file panel, then Save.
$X = \CW\Ui\Words::EXPENSES;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
$t = $typed;
$net = \CW\Expenses\ExpenseMoney::pence((string) $t['net']);
$vat = \CW\Expenses\ExpenseMoney::pence((string) $t['vat']);
$deleted = $x['deleted_at'] !== null;
// A refusal is said in the fold of the form it refused, just above that form and as wide as it (EX64; Codex r1 L1): Delete's own two
// (the box not ticked, no reason) in Delete, the rest in Change; one about the whole page (someone else changed it meanwhile), or one with
// no fold to show it in, at the top.
$errorIn = $error === null || !$canEdit || $errorCode === 'changed_meanwhile' ? null : (in_array($errorCode, ['not_sure', 'bad_reason'], true) ? 'delete' : 'change');
?>
<?= $head(\CW\Ui\Words::say('EXPENSES', 'one_title', (string) $x['supplier'], (string) $x['invoice_no'] !== '' ? (string) $x['invoice_no'] : \CW\Ui\Html::day((string) $x['invoice_date'])),
    intro: 'profit_expense', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_expenses'], [$vatPct]), back: [$listHref, $X['back']]) ?>
<?php if ($errorIn === null): ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e((string) $errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('same_list', ['items' => $sameList, 'group' => 'EXPENSES']) ?>
<?php endif; ?>
<?php if ((int) $x['net_pence'] < 0): ?>
<p class="note credit-banner" role="status"><?= $say('EXPENSES', 'credit_banner', (string) $x['shop']) ?></p>
<?php endif; ?>
<?php if ($deleted): ?>
<p class="note" role="status"><?= $word('EXPENSES', 'deleted_banner') ?> <?= $say('EXPENSES', 'deleted_by', (string) ($x['deleted_name'] ?? $x['deleted_actor']), \CW\Ui\Html::when((string) $x['deleted_at']), (string) $x['deleted_reason']) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table expense-facts">
  <tbody>
    <tr><th scope="row"><?= $word('EXPENSES', 'col_shop') ?></th><td><?= $e((string) $x['shop']) ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'supplier') ?></th><td><?= $e((string) $x['supplier']) ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'type') ?></th><td><?= $e((string) $x['type']) ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'invoice_date') ?></th><td><?= $day($x['invoice_date']) ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'invoice_no') ?></th><td><?= $e((string) $x['invoice_no'] !== '' ? (string) $x['invoice_no'] : '-') ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'f_net_pence') ?></th><td class="num"><?= $e($gbp((int) $x['net_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'f_vat_pence') ?></th><td class="num"><?= $e($gbp((int) $x['vat_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'f_total_pence') ?></th><td class="num"><strong><?= $e($gbp((int) $x['total_pence'])) ?></strong></td></tr>
<?php if ($x['note'] !== null): ?>
    <tr><th scope="row"><?= $word('EXPENSES', 'f_note') ?></th><td><?= $e((string) $x['note']) ?></td></tr>
<?php endif; ?>
    <tr><th scope="row"><?= $word('EXPENSES', 'file') ?></th><td><?php if ($x['file_id'] !== null): ?><a href="<?= $u('/ui/profit/expenses/' . (int) $x['id'] . '/file') ?>"><?= $word('EXPENSES', 'open_file') ?></a><?php else: ?><?= $word('EXPENSES', 'no_file') ?><?php endif; ?></td></tr>
    <tr><th scope="row"><?= $word('EXPENSES', 'added_by') ?></th><td><?= $e((string) ($x['created_name'] ?? $x['created_actor'])) ?> <span class="muted"><?= $when($x['created_at']) ?></span></td></tr>
  </tbody>
</table>
</div>
<?php if ($canEdit): ?>
<details class="fold expense-change" id="change"<?php if ($error !== null && $errorCode !== 'not_sure' && $errorCode !== 'bad_reason'): ?> open<?php endif; ?>>
  <summary><?= $word('EXPENSES', 'change_title') ?></summary>
<?php if ($errorIn === 'change'): ?>
  <p class="error" role="alert" data-code="<?= $e((string) $errorCode) ?>"><?= $e($error) ?></p>
<?= $partial('same_list', ['items' => $sameList, 'group' => 'EXPENSES']) ?>
<?php endif; ?>
  <form class="record expense-form" method="post" enctype="multipart/form-data" action="<?= $u('/ui/profit/expenses/' . (int) $x['id'] . '/change') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="seen" value="<?= $e((string) $seen) ?>">
    <input type="hidden" name="supplier_id" value="<?= $e((string) ($t['supplier_id'] ?? '')) ?>">
    <input type="hidden" name="supplier_was" value="<?= $e((string) ($t['supplier_was'] ?? '')) ?>">
    <div class="ex-main">
      <fieldset class="shop-pick">
        <legend><?= $word('EXPENSES', 'which_shop') ?></legend>
<?php foreach ($shops as $s): ?>
        <label class="choice shop-choice"><input type="radio" name="shop" value="<?= $e((string) $s['id']) ?>" required<?php if ((string) $t['shop'] === (string) $s['id']): ?> checked<?php endif; ?><?php if ($errorField === 'shop'): ?> aria-invalid="true"<?php endif; ?>> <?= $e($s['name']) ?></label>
<?php endforeach; ?>
      </fieldset>
<?= $partial('expense_fields', ['t' => $t, 'types' => $types, 'suppliers' => $suppliers, 'errorField' => $errorField, 'net' => $net, 'vat' => $vat, 'newSupplier' => true]) ?>
    </div>
    <div class="ex-side">
      <fieldset class="expense-file">
        <legend><?= $word('EXPENSES', 'file_title') ?></legend>
<?php if ($fileAgain): ?>
        <p class="note" role="status"><?= $word('EXPENSES', 'file_again') ?></p>
        <input type="hidden" name="file_again" value="1">
<?php endif; ?>
        <div class="field">
          <label for="ex-file"><?= $word('EXPENSES', 'change_file') ?></label>
          <div class="ex-drop">
            <input id="ex-file" type="file" name="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" aria-describedby="ex-file-hint"<?php if ($errorField === 'file'): ?> aria-invalid="true"<?php endif; ?>>
            <span class="ex-drop-words" aria-hidden="true"><?= $word('EXPENSES', 'file_drop') ?></span>
          </div>
          <p class="hint" id="ex-file-hint"><?= $say('EXPENSES', 'file_hint', $maxMb) ?></p>
        </div>
<?php if ($fileAgain): ?>
        <label class="choice"><input type="checkbox" name="no_file_ok" value="1"> <?= $word('EXPENSES', 'no_file_ok') ?></label>
<?php endif; ?>
      </fieldset>
    </div>
<?php if ($sameAsk): ?>
    <label class="choice same-ok"><input type="checkbox" name="same_ok" value="1"> <?= $word('EXPENSES', 'same_ok') ?></label>
    <input type="hidden" name="same_ids" value="<?= $e($sameIds) ?>">
<?php endif; ?>
    <p class="actions"><button type="submit" class="btn primary lg"><?= $word('EXPENSES', 'change_save') ?></button></p>
  </form>
</details>
<details class="fold expense-delete" id="delete"<?php if (in_array($errorCode, ['not_sure', 'bad_reason'], true)): ?> open<?php endif; ?>>
  <summary><?= $word('EXPENSES', 'delete_title') ?></summary>
<?php if ($errorIn === 'delete'): ?>
  <p class="error" role="alert" data-code="<?= $e((string) $errorCode) ?>"><?= $e($error) ?></p>
<?= $partial('same_list', ['items' => $sameList, 'group' => 'EXPENSES']) ?>
<?php endif; ?>
  <form class="record" method="post" action="<?= $u('/ui/profit/expenses/' . (int) $x['id'] . '/delete') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="seen" value="<?= $e((string) $seen) ?>">
    <p><?= $word('EXPENSES', 'delete_text') ?></p>
    <label><?= $word('EXPENSES', 'delete_reason') ?>
      <input type="text" name="reason" value="" minlength="3" maxlength="500" required>
    </label>
    <label class="choice"><input type="checkbox" name="sure" value="1"> <?= $word('EXPENSES', 'delete_tick') ?></label>
    <p class="actions"><button type="submit" class="btn risky"><?= $word('EXPENSES', 'delete_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<h2><?= $word('EXPENSES', 'history') ?></h2>
<?= $partial('expense_history', ['history' => $x['history']]) ?>
