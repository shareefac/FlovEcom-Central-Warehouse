<?php
// Settings › Expense types (CW-1150; docs/decisions.md EX10), on the settings shell like Reasons: the types an expense is put under and the
// groups of the regular costs, a card each, a row per type (in use or switched off, how many rows name it); for an admin or a reviewer: add a
// type, rename one, switch one off or on, each with its reason; each type's history under its row. Nothing is ever deleted.
$T = \CW\Ui\Words::EXPENSE_TYPES;
$help = $T['help'] . ($lookOnly !== null ? ' ' . \CW\Ui\Words::say('UI', 'look_only', $lookOnly) : '');
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['expense_types'], intro: 'expense_types', more: $help) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'expense_types']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<details class="fold drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('EXPENSE_TYPES', 'add_title') ?></summary>
  <form class="record" method="post" action="/ui/reference/expense-types/add">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <fieldset>
      <legend><?= $word('EXPENSE_TYPES', 'kind') ?></legend>
<?php foreach (\CW\Expenses\ExpenseTypes::KINDS as $k): ?>
      <label class="choice"><input type="radio" name="kind" value="<?= $e($k) ?>"<?php if ($typed['kind'] === $k): ?> checked<?php endif; ?>> <?= $word('EXPENSE_TYPES', 'kind_' . $k) ?></label>
<?php endforeach; ?>
    </fieldset>
    <label><?= $word('EXPENSE_TYPES', 'name') ?>
      <input type="text" name="name" value="<?= $e($typed['name']) ?>" maxlength="64" required>
    </label>
    <label><?= $word('CONFIG', 'reason') ?> <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?= $e($typed['reason']) ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('EXPENSE_TYPES', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<div class="set-list">
<?php foreach ($kinds as $k): ?>
<section class="set-card expense-types" aria-labelledby="types-<?= $e($k['kind']) ?>-h">
  <div class="set-head"><h2 id="types-<?= $e($k['kind']) ?>-h"><?= $word('EXPENSE_TYPES', 'title_' . $k['kind']) ?></h2></div>
  <div class="table-wrap">
  <table class="stack rp-table expense-type-list">
    <thead>
      <tr>
        <th scope="col"><?= $word('EXPENSE_TYPES', 'name') ?></th>
        <th scope="col" class="num"><?= $word('EXPENSE_TYPES', 'used') ?></th>
        <th scope="col"><?= $word('EXPENSE_TYPES', 'status') ?></th>
<?php if ($canEdit): ?>
        <th scope="col"><?= $word('EXPENSE_TYPES', 'change') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($k['types'] as $ty): ?>
      <tr<?php if (!$ty['is_active']): ?> class="off"<?php endif; ?>>
        <th scope="row"><?= $e($ty['name']) ?></th>
        <td class="num" data-label="<?= $word('EXPENSE_TYPES', 'used') ?>"><?= $n($ty['used']) ?></td>
        <td data-label="<?= $word('EXPENSE_TYPES', 'status') ?>"><?= $ty['is_active'] ? $chip('done', \CW\Ui\Words::EXPENSES['in_use']) : $chip('off', \CW\Ui\Words::EXPENSES['off_chip']) ?></td>
<?php if ($canEdit): ?>
        <td data-label="<?= $word('EXPENSE_TYPES', 'change') ?>">
          <form class="inline" method="post" action="<?= $u('/ui/reference/expense-types/' . $ty['id'] . '/change') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="seen" value="<?= $e((string) $ty['version']) ?>">
            <input type="text" name="name" value="<?= $e($ty['name']) ?>" maxlength="64" aria-label="<?= $word('EXPENSE_TYPES', 'name') ?>">
            <input type="text" name="reason" value="" maxlength="500" placeholder="<?= $word('EXPENSE_TYPES', 'why') ?>" aria-label="<?= $word('CONFIG', 'reason') ?>">
            <button type="submit" class="btn" name="do" value="rename"><?= $word('EXPENSES', 'rename') ?></button>
            <button type="submit" class="btn" name="do" value="<?= $ty['is_active'] ? 'off' : 'on' ?>"><?= $word('EXPENSES', $ty['is_active'] ? 'switch_off' : 'switch_on') ?></button>
          </form>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>
<?php if ($seeHistory): ?>
<details class="fold expense-types-history">
  <summary><?= $word('EXPENSES', 'history') ?></summary>
<?php
$all = [];
foreach ($kinds as $k) {
    foreach ($k['types'] as $ty) {
        foreach ($history[$ty['id']] ?? [] as $h) {
            $all[] = $h + ['type' => $ty['name']];
        }
    }
}
usort($all, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
?>
<?= $partial('expense_history', ['history' => array_slice($all, 0, 100)]) ?>
</details>
<?php endif; ?>
</div>
</div>
</div>
