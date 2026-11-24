<?php
// Profit › Regular costs › Add a regular cost (CW-1150; docs/decisions.md EX5-EX7): its name, the shop (picked each time, none picked for
// you), its group, how often (every week, month or year), the amount as given, its first day, its last day (empty: no end), a note.
$R = \CW\Ui\Words::REGULAR;
$X = \CW\Ui\Words::EXPENSES;
$t = $typed;
$bad = static fn (string $f): string => $errorField === $f ? ' aria-invalid="true"' : '';
?>
<?= $head($R['new_title'], intro: 'profit_regular_new', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_regular']), back: ['/ui/profit/regular', $R['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($other !== null): ?> <a href="<?= $e($other) ?>"><?= $word('REGULAR', 'open_other') ?></a><?php endif; ?></p>
<?php endif; ?>
<form class="record" method="post" action="/ui/profit/regular/new" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <fieldset class="shop-pick">
    <legend><?= $word('REGULAR', 'which_shop') ?></legend>
<?php foreach ($shops as $s): ?>
    <label class="choice shop-choice"><input type="radio" name="shop" value="<?= $e((string) $s['id']) ?>" required<?php if ((string) $t['shop'] === (string) $s['id']): ?> checked<?php endif; ?><?= $bad('shop') ?>> <?= $e($s['name']) ?></label>
<?php endforeach; ?>
  </fieldset>
  <fieldset>
    <label><?= $word('REGULAR', 'name') ?>
      <input type="text" name="name" value="<?= $e((string) $t['name']) ?>" maxlength="100" required<?= $bad('name') ?>>
    </label>
    <label><?= $word('REGULAR', 'group') ?> <span class="hint"><?= $word('EXPENSES', 'type_hint') ?></span>
      <select name="type" required<?= $bad('type') ?>>
        <option value=""><?= $word('REGULAR', 'choose_group') ?></option>
<?php foreach ($types as $ty): ?>
        <option value="<?= $e((string) $ty['id']) ?>"<?php if ((string) $t['type'] === (string) $ty['id']): ?> selected<?php endif; ?>><?= $e($ty['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('REGULAR', 'every') ?>
      <select name="every"<?= $bad('every') ?>>
<?php foreach (\CW\Expenses\RegularCostMath::EVERY as $ev): ?>
        <option value="<?= $e($ev) ?>"<?php if ((string) $t['every'] === $ev): ?> selected<?php endif; ?>><?= $e($X['every_' . $ev]) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('REGULAR', 'amount_label') ?> <span class="hint"><?= $word('REGULAR', 'amount_hint') ?></span>
      <input type="text" name="amount" value="<?= $e((string) $t['amount']) ?>" inputmode="decimal" maxlength="16" autocomplete="off" required<?= $bad('amount') ?>>
    </label>
    <label><?= $word('REGULAR', 'from') ?>
      <input type="date" name="from" value="<?= $e((string) $t['from']) ?>" required<?= $bad('from') ?>>
    </label>
    <label><?= $word('REGULAR', 'until') ?> <span class="hint"><?= $word('REGULAR', 'until_hint') ?></span>
      <input type="date" name="until" value="<?= $e((string) $t['until']) ?>"<?= $bad('until') ?>>
    </label>
    <label><?= $word('EXPENSES', 'note') ?>
      <input type="text" name="note" value="<?= $e((string) $t['note']) ?>" maxlength="500"<?= $bad('note') ?>>
    </label>
  </fieldset>
<?php if ($sameAsk): ?>
  <label class="choice same-ok"><input type="checkbox" name="same_ok" value="1"> <?= $word('REGULAR', 'same_ok') ?></label>
<?php endif; ?>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('REGULAR', 'save') ?></button></p>
</form>
<p class="muted"><?= $word('REGULAR', 'how_counts') ?></p>
