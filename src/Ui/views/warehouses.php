<?php
// Settings › Warehouses, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.12): the heading
// (whose stock: its "?") with "+ New warehouse" at its right for whoever changes them (its form opens here, `#new`); then a card of the
// warehouses in use and one of those switched off, a row each: its name (its page: the name and note, whose stock, whether websites sell
// from it, switching it off, its places inside, its History), whether websites sell from it and whose stock it holds, its places, and at
// the right what is in the building.
?>
<?= $head(\CW\Ui\Words::MENU['warehouses'], intro: 'warehouses', look: $lookOnly, aside: $explain('stock_owner', \CW\Ui\Words::WAREHOUSES['owner'])) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'warehouses']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<details class="fold add-warehouse drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('WAREHOUSES', 'add_title') ?></summary>
  <form class="record" method="post" action="/ui/reference/warehouses" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <p class="hint"><?= $word('WAREHOUSES', 'tip') ?></p>
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
    <p class="actions"><button type="submit" class="btn primary"><?= $word('WAREHOUSES', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<div class="set-list">
<?php foreach ([true, false] as $on): ?>
<?php if ($on || array_filter($warehouses, static fn (array $x): bool => !$x['is_active']) !== []): ?>
<section class="set-card warehouses" aria-labelledby="wh-<?php if ($on): ?>on<?php else: ?>off<?php endif; ?>-h">
  <h2 id="wh-<?php if ($on): ?>on<?php else: ?>off<?php endif; ?>-h"><?php if ($on): ?><?= $word('WAREHOUSES', 'status_on') ?><?php else: ?><?= $word('WAREHOUSES', 'status_off') ?><?php endif; ?></h2>
<?php foreach ($warehouses as $w): ?>
<?php if ((bool) $w['is_active'] === $on): ?>
  <div class="set-row<?php if (!$on): ?> unused<?php endif; ?>" data-code="<?= $e($w['code']) ?>">
    <div class="set-k">
      <a class="set-kt" href="<?= $e($w['href']) ?>"><?= $e($w['name']) ?></a> <code><?= $e($w['code']) ?></code>
      <p class="set-kd"><?= $word('WAREHOUSES', 'sold_from') ?>: <?php if (!$w['is_sellable']): ?><?= $word('WAREHOUSES', 'no') ?><?php elseif ($w['selling'] === []): ?><?= $word('WAREHOUSES', 'yes_no_site') ?><?php else: ?><?= $say('WAREHOUSES', 'yes_sites', implode(', ', $w['selling'])) ?><?php endif; ?> · <?= $word('WAREHOUSES', 'owner') ?>: <?php if ($w['stock_owner'] === 'other'): ?><?= $say('WAREHOUSES', 'theirs', (string) $w['owner_entity']) ?><?php else: ?><?= $word('WAREHOUSES', 'ours') ?><?php endif; ?></p>
      <p class="set-kh"><?= $word('WAREHOUSES', 'places') ?>: <?= $n($w['places']['active']) ?></p>
    </div>
    <div class="set-v"><span class="muted"><?= $word('WAREHOUSES', 'stock') ?></span> <span class="set-val"><?= $say('WAREHOUSES', 'stock_line', $w['stock']['on_hand'], $w['stock']['items']) ?></span></div>
  </div>
<?php endif; ?>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php endforeach; ?>
</div>
</div>
</div>
