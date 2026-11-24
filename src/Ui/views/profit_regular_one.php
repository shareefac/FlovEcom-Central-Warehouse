<?php
// Profit › Regular costs › one line (CW-1150; docs/decisions.md EX5-EX7, EX14): its facts (shop, group, how often, amount, about a day, this
// month, from, until, the line it continues or that continues it, note); for the Expenses job: "The amount changes" (this line ends the day
// before, a new one starts: the old days keep the old amount), its name, group, end and note changed, "Delete" for a line put in by mistake
// (a reason and a box); its history.
$R = \CW\Ui\Words::REGULAR;
$X = \CW\Ui\Words::EXPENSES;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
$t = $typed;
$bad = static fn (string $f): string => $errorField === $f ? ' aria-invalid="true"' : '';
$deleted = $r['deleted_at'] !== null;
?>
<?= $head((string) $r['name'], intro: 'profit_regular_one', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_regular']), back: ['/ui/profit/regular', $R['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($deleted): ?>
<p class="note" role="status"><?= $word('REGULAR', 'deleted_banner') ?> <?= $say('EXPENSES', 'deleted_by', (string) ($r['deleted_name'] ?? $r['deleted_actor']), \CW\Ui\Html::when((string) $r['deleted_at']), (string) $r['deleted_reason']) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table regular-facts">
  <tbody>
    <tr><th scope="row"><?= $word('REGULAR', 'shop') ?></th><td><?= $e((string) $r['shop']) ?></td></tr>
    <tr><th scope="row"><?= $word('REGULAR', 'group') ?></th><td><?= $e((string) $r['grp']) ?></td></tr>
    <tr><th scope="row"><?= $word('REGULAR', 'every') ?></th><td><?= $e($X['every_' . (string) $r['every']]) ?></td></tr>
    <tr><th scope="row"><?= $word('REGULAR', 'amount') ?></th><td class="num"><?= $e($amountText) ?></td></tr>
    <tr><th scope="row"><?= $word('REGULAR', 'a_day') ?></th><td class="num"><?= $say('REGULAR', 'about_a_day', $gbp($perDay)) ?></td></tr>
    <tr><th scope="row"><?= $say('REGULAR', 'this_month', $monthName) ?></th><td class="num"><?= $e($gbp($thisMonth)) ?></td></tr>
    <tr><th scope="row"><?= $word('REGULAR', 'from') ?></th><td><?= $day($r['from_day']) ?></td></tr>
    <tr><th scope="row"><?= $word('REGULAR', 'until') ?></th><td><?php if ($r['until_day'] === null): ?><?= $word('EXPENSES', 'no_end') ?><?php else: ?><?= $day($r['until_day']) ?><?php endif; ?></td></tr>
<?php if ($r['replaces_id'] !== null): ?>
    <tr><th scope="row"><?= $word('REGULAR', 'continues') ?></th><td><a href="<?= $u('/ui/profit/regular/' . (int) $r['replaces_id']) ?>"><?= $word('REGULAR', 'old_line') ?></a></td></tr>
<?php endif; ?>
<?php if ($r['next'] !== null): ?>
    <tr><th scope="row"><?= $word('REGULAR', 'continued_by') ?></th><td><a href="<?= $u('/ui/profit/regular/' . (int) $r['next']['id']) ?>"><?= $say('REGULAR', 'new_line', $gbp((int) $r['next']['amount_pence']), \CW\Ui\Html::day((string) $r['next']['from_day'])) ?></a></td></tr>
<?php endif; ?>
<?php if ($r['note'] !== null): ?>
    <tr><th scope="row"><?= $word('EXPENSES', 'f_note') ?></th><td><?= $e((string) $r['note']) ?></td></tr>
<?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($canEdit): ?>
<?php if ($r['until_day'] === null || (string) $r['until_day'] > (string) $r['from_day']): ?>
<details class="fold regular-amount" id="amount"<?php if ($form === 'amount'): ?> open<?php endif; ?>>
  <summary><?= $word('REGULAR', 'new_amount_title') ?></summary>
  <form class="record" method="post" action="<?= $u('/ui/profit/regular/' . (int) $r['id'] . '/amount') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="seen" value="<?= $e((string) $r['version']) ?>">
    <p><?= $word('REGULAR', 'new_amount_text') ?></p>
    <fieldset>
      <label><?= $word('REGULAR', 'new_amount') ?>
        <input type="text" name="amount" value="<?= $e((string) $t['amount']) ?>" inputmode="decimal" maxlength="16" autocomplete="off" required<?= $bad('amount') ?>>
      </label>
      <label><?= $word('REGULAR', 'new_from') ?>
        <input type="date" name="from" value="<?= $e((string) $t['from']) ?>" required<?= $bad('from') ?>>
      </label>
    </fieldset>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('REGULAR', 'new_amount_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<details class="fold regular-change" id="change"<?php if ($form === 'change'): ?> open<?php endif; ?>>
  <summary><?= $word('REGULAR', 'details_title') ?></summary>
  <form class="record" method="post" action="<?= $u('/ui/profit/regular/' . (int) $r['id'] . '/change') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="seen" value="<?= $e((string) $r['version']) ?>">
    <fieldset>
      <label><?= $word('REGULAR', 'name') ?>
        <input type="text" name="name" value="<?= $e((string) $t['name']) ?>" maxlength="100" required<?= $bad('name') ?>>
      </label>
      <label><?= $word('REGULAR', 'group') ?>
        <select name="type" required<?= $bad('type') ?>>
<?php foreach ($types as $ty): ?>
          <option value="<?= $e((string) $ty['id']) ?>"<?php if ((string) $t['type'] === (string) $ty['id']): ?> selected<?php endif; ?>><?= $e($ty['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('REGULAR', 'until') ?> <span class="hint"><?= $word('REGULAR', 'until_hint') ?></span>
        <input type="date" name="until" value="<?= $e((string) $t['until']) ?>"<?= $bad('until') ?>>
      </label>
      <label><?= $word('EXPENSES', 'note') ?>
        <input type="text" name="note" value="<?= $e((string) $t['note']) ?>" maxlength="500"<?= $bad('note') ?>>
      </label>
    </fieldset>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('EXPENSES', 'change_save') ?></button></p>
  </form>
</details>
<details class="fold regular-delete" id="delete"<?php if ($form === 'delete'): ?> open<?php endif; ?>>
  <summary><?= $word('REGULAR', 'delete_title') ?></summary>
  <form class="record" method="post" action="<?= $u('/ui/profit/regular/' . (int) $r['id'] . '/delete') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="seen" value="<?= $e((string) $r['version']) ?>">
    <p><?= $word('REGULAR', 'delete_text') ?></p>
    <label><?= $word('EXPENSES', 'delete_reason') ?>
      <input type="text" name="reason" value="" minlength="3" maxlength="500" required>
    </label>
    <label class="choice"><input type="checkbox" name="sure" value="1"> <?= $word('REGULAR', 'delete_tick') ?></label>
    <p class="actions"><button type="submit" class="btn danger"><?= $word('EXPENSES', 'delete_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<h2><?= $word('EXPENSES', 'history') ?></h2>
<?= $partial('expense_history', ['history' => $r['history']]) ?>
