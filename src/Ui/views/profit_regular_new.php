<?php
// Profit › Regular costs › Add a regular cost (CW-1150; docs/decisions.md EX5-EX7): its name, the shop (picked each time, none picked for
// you), its group, how often (every week, month or year), the amount as given, its VAT ("Includes 20% VAT", picked for you, or "No VAT":
// the owner, 24 Nov 2026, EX38), its first day, its last day (empty: no end), a note. Laid out as the expense form's card (docs/decisions.md
// EX64): the shop as buttons in a row, the fields two by two with each hint under its box, the VAT choice in a row, Save at the card's foot.
$R = \CW\Ui\Words::REGULAR;
$X = \CW\Ui\Words::EXPENSES;
$t = $typed;
?>
<?= $head($R['new_title'], intro: 'profit_regular_new', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_regular'], [$vatPct]), back: ['/ui/profit/regular', $R['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('same_list', ['items' => $sameList, 'group' => 'REGULAR']) ?>
<form class="record regular-form" method="post" action="/ui/profit/regular/new" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <div class="ex-main">
    <fieldset class="shop-pick">
      <legend><?= $word('REGULAR', 'which_shop') ?></legend>
<?php foreach ($shops as $s): ?>
      <label class="choice shop-choice"><input type="radio" name="shop" value="<?= $e((string) $s['id']) ?>" required<?php if ((string) $t['shop'] === (string) $s['id']): ?> checked<?php endif; ?><?php if ($errorField === 'shop'): ?> aria-invalid="true"<?php endif; ?>> <?= $e($s['name']) ?></label>
<?php endforeach; ?>
    </fieldset>
    <div class="ex-fields">
      <div class="ex-row">
        <div class="field">
          <label for="rc-name"><?= $word('REGULAR', 'name') ?></label>
          <input id="rc-name" type="text" name="name" value="<?= $e((string) $t['name']) ?>" maxlength="100" required<?php if ($errorField === 'name'): ?> aria-invalid="true"<?php endif; ?>>
        </div>
        <div class="field">
          <label for="rc-type"><?= $word('REGULAR', 'group') ?></label>
          <select id="rc-type" name="type" required aria-describedby="rc-type-hint"<?php if ($errorField === 'type'): ?> aria-invalid="true"<?php endif; ?>>
            <option value=""><?= $word('REGULAR', 'choose_group') ?></option>
<?php foreach ($types as $ty): ?>
            <option value="<?= $e((string) $ty['id']) ?>"<?php if ((string) $t['type'] === (string) $ty['id']): ?> selected<?php endif; ?>><?= $e($ty['name']) ?></option>
<?php endforeach; ?>
          </select>
          <p class="hint" id="rc-type-hint"><?= $word('EXPENSES', 'type_hint') ?></p>
        </div>
      </div>
      <div class="ex-row">
        <div class="field">
          <label for="rc-every"><?= $word('REGULAR', 'every') ?></label>
          <select id="rc-every" name="every"<?php if ($errorField === 'every'): ?> aria-invalid="true"<?php endif; ?>>
<?php foreach (\CW\Expenses\RegularCostMath::EVERY as $ev): ?>
            <option value="<?= $e($ev) ?>"<?php if ((string) $t['every'] === $ev): ?> selected<?php endif; ?>><?= $e($X['every_' . $ev]) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="rc-amount"><?= $word('REGULAR', 'amount_label') ?></label>
          <input id="rc-amount" type="text" name="amount" value="<?= $e((string) $t['amount']) ?>" inputmode="decimal" maxlength="16" autocomplete="off" required aria-describedby="rc-amount-hint"<?php if ($errorField === 'amount'): ?> aria-invalid="true"<?php endif; ?>>
          <p class="hint" id="rc-amount-hint"><?= $word('REGULAR', 'amount_hint') ?></p>
        </div>
      </div>
      <fieldset class="vat-pick">
        <legend><?= $word('REGULAR', 'vat_choice') ?> <span class="hint"><?= $word('REGULAR', 'vat_hint') ?></span></legend>
        <label class="choice"><input type="radio" name="vat" value="inc"<?php if ((string) $t['vat'] !== 'none'): ?> checked<?php endif; ?><?php if ($errorField === 'vat'): ?> aria-invalid="true"<?php endif; ?>> <?= $say('REGULAR', 'vat_inc', $vatRate) ?></label>
        <label class="choice"><input type="radio" name="vat" value="none"<?php if ((string) $t['vat'] === 'none'): ?> checked<?php endif; ?>> <?= $word('REGULAR', 'vat_none') ?></label>
      </fieldset>
      <div class="ex-row">
        <div class="field">
          <label for="rc-from"><?= $word('REGULAR', 'from') ?></label>
          <input id="rc-from" type="date" name="from" value="<?= $e((string) $t['from']) ?>" required<?php if ($errorField === 'from'): ?> aria-invalid="true"<?php endif; ?>>
        </div>
        <div class="field">
          <label for="rc-until"><?= $word('REGULAR', 'until') ?></label>
          <input id="rc-until" type="date" name="until" value="<?= $e((string) $t['until']) ?>" aria-describedby="rc-until-hint"<?php if ($errorField === 'until'): ?> aria-invalid="true"<?php endif; ?>>
          <p class="hint" id="rc-until-hint"><?= $word('REGULAR', 'until_hint') ?></p>
        </div>
      </div>
      <div class="field ex-wide">
        <label for="rc-note"><?= $word('EXPENSES', 'note') ?></label>
        <input id="rc-note" type="text" name="note" value="<?= $e((string) $t['note']) ?>" maxlength="500"<?php if ($errorField === 'note'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
    </div>
  </div>
<?php if ($sameAsk): ?>
  <label class="choice same-ok"><input type="checkbox" name="same_ok" value="1"> <?= $word('REGULAR', 'same_ok') ?></label>
  <input type="hidden" name="same_ids" value="<?= $e($sameIds) ?>">
<?php endif; ?>
  <p class="actions"><button type="submit" class="btn primary lg"><?= $word('REGULAR', 'save') ?></button></p>
</form>
<p class="muted"><?= $e($howCounts) ?></p>
