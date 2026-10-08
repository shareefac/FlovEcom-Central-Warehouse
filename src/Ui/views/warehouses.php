<div class="head-help">
  <h1><?= $word('MENU', 'warehouses') ?></h1>
  <?= $explain('stock_owner', \CW\Ui\Words::WAREHOUSES['owner']) ?>
</div>
<?= $intro('warehouses', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack list warehouses">
  <thead>
    <tr>
      <th scope="col"><?= $word('WAREHOUSES', 'name') ?></th>
      <th scope="col"><?= $word('WAREHOUSES', 'status') ?></th>
      <th scope="col"><?= $word('WAREHOUSES', 'sold_from') ?></th>
      <th scope="col"><?= $word('WAREHOUSES', 'owner') ?></th>
      <th scope="col"><?= $word('WAREHOUSES', 'stock') ?></th>
      <th scope="col"><?= $word('WAREHOUSES', 'places') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($warehouses as $w): ?>
    <tr class="<?php if ($w['is_active']): ?>done<?php else: ?>inactive off<?php endif; ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $e($w['href']) ?>"><?= $e($w['name']) ?></a> <small class="muted"><code><?= $e($w['code']) ?></code></small></th>
      <td class="c-status"><?php if ($w['is_active']): ?><?= $chip('done', \CW\Ui\Words::WAREHOUSES['status_on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::WAREHOUSES['status_off']) ?><?php endif; ?></td>
      <td data-label="<?= $word('WAREHOUSES', 'sold_from') ?>"><?php if (!$w['is_sellable']): ?><?= $word('WAREHOUSES', 'no') ?><?php elseif ($w['selling'] === []): ?><?= $word('WAREHOUSES', 'yes_no_site') ?><?php else: ?><?= $say('WAREHOUSES', 'yes_sites', implode(', ', $w['selling'])) ?><?php endif; ?></td>
      <td data-label="<?= $word('WAREHOUSES', 'owner') ?>"><?php if ($w['stock_owner'] === 'other'): ?><?= $say('WAREHOUSES', 'theirs', (string) $w['owner_entity']) ?><?php else: ?><?= $word('WAREHOUSES', 'ours') ?><?php endif; ?></td>
      <td data-label="<?= $word('WAREHOUSES', 'stock') ?>" class="num"><?= $say('WAREHOUSES', 'stock_line', $w['stock']['on_hand'], $w['stock']['items']) ?></td>
      <td data-label="<?= $word('WAREHOUSES', 'places') ?>" class="num"><?= $n($w['places']['active']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($canEdit): ?>
<p class="muted"><?= $word('WAREHOUSES', 'tip') ?></p>
<details class="fold add-warehouse" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('WAREHOUSES', 'add_title') ?></summary>
  <form class="record" method="post" action="/ui/reference/warehouses">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('WAREHOUSES', 'wh_name') ?>
      <input type="text" name="name" value="<?= $e($typed['name']) ?>" maxlength="100" required<?php if ($errorCode === 'bad_name'): ?> aria-invalid="true"<?php endif; ?>>
    </label>
    <label><?= $word('WAREHOUSES', 'code') ?>
      <span class="hint"><?= $word('WAREHOUSES', 'code_hint') ?></span>
      <input type="text" name="code" value="<?= $e($typed['code']) ?>" maxlength="32" autocapitalize="characters" spellcheck="false" required<?php if (in_array($errorCode, ['bad_code', 'warehouse_exists'], true)): ?> aria-invalid="true"<?php endif; ?>>
    </label>
    <fieldset>
      <legend><?= $word('WAREHOUSES', 'owner_q') ?></legend>
      <label class="choice"><input type="radio" name="owner" value="own"<?php if ($typed['owner'] !== 'other'): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'owner_ours') ?></label>
      <label class="choice"><input type="radio" name="owner" value="other"<?php if ($typed['owner'] === 'other'): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'owner_other') ?></label>
      <label><?= $word('WAREHOUSES', 'owner_name') ?>
        <input type="text" name="owner_name" value="<?= $e($typed['owner_name']) ?>" maxlength="64"<?php if ($errorCode === 'bad_owner'): ?> aria-invalid="true"<?php endif; ?>>
      </label>
    </fieldset>
    <fieldset>
      <legend><?= $word('WAREHOUSES', 'sellable') ?></legend>
      <label class="choice"><input type="checkbox" name="sellable" value="1"<?php if ($typed['sellable']): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'sellable') ?></label>
      <label class="choice"><input type="checkbox" name="confirm" value="1"<?php if ($typed['confirm']): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'sellable_confirm') ?></label>
    </fieldset>
    <label><?= $word('WAREHOUSES', 'note') ?>
      <input type="text" name="note" value="<?= $e($typed['note']) ?>" maxlength="255">
    </label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?= $e($typed['reason']) ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="primary"><?= $word('WAREHOUSES', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
