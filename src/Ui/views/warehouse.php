<?php
// One warehouse, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.12): the list as the
// eyebrow (DC3), its name with its status; a card of what it is and what it holds (a row each); for whoever changes warehouses a card of its
// changes, each under its row's own Change and saved on its own with a reason (the name and note, whose stock while it is empty, whether
// websites sell from it, switching it off or on) or a line that says why not; its places inside (`#places`), each changed under its row;
// then its History.
$on = $w['is_active'];
?>
<?= $head((string) $w['name'], intro: 'warehouse', more: $lookOnly, back: ['/ui/reference/warehouses', \CW\Ui\Words::MENU['warehouses']],
    chips: $w['is_active'] ? $chip('done', \CW\Ui\Words::WAREHOUSES['status_on']) : $chip('off', \CW\Ui\Words::WAREHOUSES['status_off'])) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'warehouses']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<div class="set-list">
<section class="set-card warehouse-facts" aria-labelledby="facts-h">
  <h2 id="facts-h"><?= $word('SETTINGS_PAGE', 'facts') ?></h2>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'code_label') ?></p></div><div class="set-v"><code><?= $e($w['code']) ?></code></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'owner') ?></p></div><div class="set-v"><?php if ($w['stock_owner'] === 'other'): ?><?= $say('WAREHOUSES', 'theirs', (string) $w['owner_entity']) ?> <a href="<?= $u('/ui/stock/accounts') ?>#account-<?= $e($w['id']) ?>"><?= $word('WAREHOUSES', 'balance_link') ?></a><?php else: ?><?= $word('WAREHOUSES', 'ours') ?><?php endif; ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'sold_from') ?></p></div><div class="set-v"><?php if ($w['is_sellable']): ?><?= $word('CONFIG', 'yes') ?><?php else: ?><?= $word('CONFIG', 'no') ?><?php endif; ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'websites') ?></p></div><div class="set-v"><?php if ($w['selling'] === []): ?><span class="muted"><?= $word('WAREHOUSES', 'none') ?></span><?php else: ?><?= $e(implode(', ', $w['selling'])) ?><?php endif; ?></div></div>
<?php if ($w['assigned'] !== []): ?>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'assigned') ?></p></div><div class="set-v"><?= $e(implode(', ', $w['assigned'])) ?></div></div>
<?php endif; ?>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'in_building') ?></p></div><div class="set-v"><?= $n($w['stock']['on_hand']) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'sold_waiting') ?></p></div><div class="set-v"><?= $n($w['stock']['allocated']) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'reserved') ?></p></div><div class="set-v"><?= $n($w['stock']['held']) ?></div></div>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'with_stock') ?></p></div><div class="set-v"><?= $n($w['stock']['items']) ?></div></div>
<?php if ($w['note'] !== null): ?>
  <div class="set-row set-fact"><div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'note') ?></p></div><div class="set-v"><?= $e($w['note']) ?></div></div>
<?php endif; ?>
</section>
<?php if ($w['is_system']): ?>
<p class="note read-only"><?= $word('WAREHOUSES', 'built_in') ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<section class="set-card warehouse-changes" aria-labelledby="changes-h">
  <h2 id="changes-h"><?= $word('SETTINGS_PAGE', 'changes') ?></h2>
  <div class="set-row">
    <div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'rename') ?></p></div>
    <details class="set-fold"<?php if ($typed['do'] === 'rename'): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('SETTINGS_PAGE', 'change') ?></summary>
      <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="do" value="rename">
        <input type="hidden" name="seen" value="<?= $e($seen) ?>">
        <label><?= $word('WAREHOUSES', 'wh_name') ?>
          <input type="text" name="name" value="<?= $e($typed['name']) ?>" maxlength="100" required>
        </label>
        <label><?= $word('WAREHOUSES', 'note') ?>
          <input type="text" name="note" value="<?= $e($typed['note']) ?>" maxlength="255">
        </label>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'rename'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
        <p class="actions"><button type="submit" class="btn"><?= $word('WAREHOUSES', 'rename_button') ?></button></p>
      </form>
    </details>
  </div>
<?php if (!$w['is_system']): ?>
  <div class="set-row">
    <div class="set-k">
      <p class="set-kt"><?= $word('WAREHOUSES', 'owner_title') ?> <?= $explain('stock_owner', \CW\Ui\Words::WAREHOUSES['owner']) ?></p>
<?php if ($w['not_empty'] !== []): ?>
      <p class="set-kd read-only"><?= $say('WAREHOUSES', 'owner_not_empty', $notEmpty) ?></p>
<?php else: ?>
      <p class="set-kd"><?= $word('WAREHOUSES', 'owner_text') ?></p>
<?php endif; ?>
    </div>
<?php if ($w['not_empty'] === []): ?>
    <details class="set-fold"<?php if ($typed['do'] === 'owner'): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('SETTINGS_PAGE', 'change') ?></summary>
      <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="do" value="owner">
        <input type="hidden" name="seen" value="<?= $e($seen) ?>">
        <fieldset>
          <legend><?= $word('WAREHOUSES', 'owner_q') ?></legend>
          <label class="choice"><input type="radio" name="owner" value="own"<?php if ($typed['owner'] !== 'other'): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'owner_ours') ?></label>
          <label class="choice"><input type="radio" name="owner" value="other"<?php if ($typed['owner'] === 'other'): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'owner_other') ?></label>
          <label><?= $word('WAREHOUSES', 'owner_name') ?>
            <input type="text" name="owner_name" value="<?= $e($typed['owner_name']) ?>" maxlength="64">
          </label>
        </fieldset>
        <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('WAREHOUSES', 'owner_confirm') ?></label>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'owner'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
        <p class="actions"><button type="submit" class="btn"><?= $word('WAREHOUSES', 'owner_button') ?></button></p>
      </form>
    </details>
<?php endif; ?>
  </div>
  <div class="set-row">
    <div class="set-k">
      <p class="set-kt"><?= $word('WAREHOUSES', 'sellable_title') ?></p>
      <p class="set-kd"><?php if ($w['is_sellable']): ?><?= $word('WAREHOUSES', 'sellable_now_yes') ?><?php else: ?><?= $word('WAREHOUSES', 'sellable_now_no') ?><?php endif; ?> <?= $word('WAREHOUSES', 'sellable_text') ?></p>
<?php if ($w['selling'] !== [] || $w['assigned'] !== []): ?>
      <p class="set-kd read-only"><?= $say('WAREHOUSES', 'in_use', implode(', ', array_merge($w['selling'], $w['assigned']))) ?></p>
<?php endif; ?>
    </div>
<?php if ($w['selling'] === [] && $w['assigned'] === [] && $w['stock_owner'] !== 'other' && $on): ?>
    <details class="set-fold"<?php if ($typed['do'] === 'sellable'): ?> open<?php endif; ?>>
      <summary class="btn"><?php if ($w['is_sellable']): ?><?= $word('WAREHOUSES', 'sellable_off_button') ?><?php else: ?><?= $word('WAREHOUSES', 'sellable_on_button') ?><?php endif; ?></summary>
      <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="do" value="sellable">
        <input type="hidden" name="seen" value="<?= $e($seen) ?>">
        <input type="hidden" name="sellable" value="<?php if ($w['is_sellable']): ?>0<?php else: ?>1<?php endif; ?>">
        <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?php if ($w['is_sellable']): ?><?= $word('WAREHOUSES', 'sellable_confirm_off') ?><?php else: ?><?= $word('WAREHOUSES', 'sellable_confirm') ?><?php endif; ?></label>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'sellable'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
        <p class="actions"><button type="submit" class="btn risky"><?php if ($w['is_sellable']): ?><?= $word('WAREHOUSES', 'sellable_off_button') ?><?php else: ?><?= $word('WAREHOUSES', 'sellable_on_button') ?><?php endif; ?></button></p>
      </form>
    </details>
<?php endif; ?>
  </div>
  <div class="set-row">
    <div class="set-k">
      <p class="set-kt"><?= $word('WAREHOUSES', 'switch_title') ?></p>
<?php if ($on): ?>
      <p class="set-kd"><?= $word('WAREHOUSES', 'switch_off_text') ?></p>
<?php if ($notEmpty !== ''): ?>
      <p class="set-kd read-only"><?= $say('WAREHOUSES', 'not_empty', $notEmpty) ?></p>
<?php endif; ?>
<?php endif; ?>
    </div>
<?php if (!$on || $notEmpty === ''): ?>
    <details class="set-fold"<?php if ($typed['do'] === 'switch_off' || $typed['do'] === 'switch_on'): ?> open<?php endif; ?>>
      <summary class="btn"><?php if ($on): ?><?= $word('WAREHOUSES', 'switch_off_button') ?><?php else: ?><?= $word('WAREHOUSES', 'switch_on_button') ?><?php endif; ?></summary>
      <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="do" value="<?php if ($on): ?>switch_off<?php else: ?>switch_on<?php endif; ?>">
        <input type="hidden" name="seen" value="<?= $e($seen) ?>">
<?php if ($on): ?>
        <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('WAREHOUSES', 'switch_off_confirm') ?></label>
<?php endif; ?>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'switch_off' || $typed['do'] === 'switch_on'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
<?php if ($on): ?>
        <p class="actions"><button type="submit" class="btn risky"><?= $word('WAREHOUSES', 'switch_off_button') ?></button></p>
<?php else: ?>
        <p class="actions"><button class="btn" type="submit"><?= $word('WAREHOUSES', 'switch_on_button') ?></button></p>
<?php endif; ?>
      </form>
    </details>
<?php endif; ?>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="set-card places-card" id="places" aria-labelledby="places-h">
  <div class="set-head"><h2 id="places-h"><?= $word('WAREHOUSES', 'places_title') ?></h2> <?= $explain('places', \CW\Ui\Words::WAREHOUSES['places']) ?></div>
  <p class="set-kd set-intro"><?= $word('WAREHOUSES', 'places_text') ?></p>
<?php if ($places === []): ?>
  <div class="set-row"><div class="set-k"><p class="set-kd"><?= $word('WAREHOUSES', 'places_none') ?></p></div></div>
<?php endif; ?>
<?php foreach ($places as $p): ?>
  <div class="set-row place<?php if (!$p['is_active']): ?> unused<?php endif; ?>" data-code="<?= $e($p['code']) ?>">
    <div class="set-k">
      <p class="set-kt"><?= $e($p['name']) ?> <code><?= $e($p['code']) ?></code> <span class="tag <?php if ($p['is_active']): ?>done<?php else: ?>off<?php endif; ?>"><?php if ($p['is_active']): ?><?= $word('WAREHOUSES', 'status_on') ?><?php else: ?><?= $word('WAREHOUSES', 'status_off') ?><?php endif; ?></span></p>
<?php if (($p['note'] ?? '') !== ''): ?>
      <p class="set-kd"><?= $e($p['note']) ?></p>
<?php endif; ?>
    </div>
<?php if ($canEdit): ?>
    <details class="set-fold place-change"<?php if ($p['open']): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('SETTINGS_PAGE', 'change') ?><span class="visually-hidden">: <?= $e($p['name']) ?></span></summary>
      <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="do" value="place_rename">
        <input type="hidden" name="place" value="<?= $e($p['id']) ?>">
        <input type="hidden" name="seen" value="<?= $e($p['version']) ?>">
        <label><?= $word('WAREHOUSES', 'place_name') ?>
          <input type="text" name="name" value="<?= $e($p['typed']['name']) ?>" maxlength="100" required>
        </label>
        <label><?= $word('WAREHOUSES', 'note') ?>
          <input type="text" name="note" value="<?= $e($p['typed']['note']) ?>" maxlength="255">
        </label>
        <label><?= $word('CONFIG', 'reason') ?>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required></textarea>
        </label>
        <p class="actions"><button class="btn" type="submit"><?= $word('WAREHOUSES', 'place_rename_button') ?></button></p>
      </form>
      <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="do" value="<?php if ($p['is_active']): ?>place_off<?php else: ?>place_on<?php endif; ?>">
        <input type="hidden" name="place" value="<?= $e($p['id']) ?>">
        <input type="hidden" name="seen" value="<?= $e($p['version']) ?>">
        <label><?= $word('CONFIG', 'reason') ?>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required></textarea>
        </label>
        <p class="actions"><button type="submit" class="btn<?php if ($p['is_active']): ?> risky<?php endif; ?>"><?php if ($p['is_active']): ?><?= $word('WAREHOUSES', 'place_off_button') ?><?php else: ?><?= $word('WAREHOUSES', 'place_on_button') ?><?php endif; ?></button></p>
      </form>
      <?= $partial('config_history', ['rows' => $p['history']]) ?>
    </details>
<?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($canEdit && $on): ?>
  <div class="set-row">
    <div class="set-k"><p class="set-kt"><?= $word('WAREHOUSES', 'place_add') ?></p><p class="set-kd"><?= $word('WAREHOUSES', 'place_code_hint') ?></p></div>
    <details class="set-fold place-add"<?php if ($typed['do'] === 'place_add'): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('WAREHOUSES', 'place_add') ?></summary>
      <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="do" value="place_add">
        <label><?= $word('WAREHOUSES', 'place_name') ?>
          <input type="text" name="name" value="<?= $e($typed['place_name']) ?>" maxlength="100" required>
        </label>
        <label><?= $word('WAREHOUSES', 'place_code') ?>
          <span class="hint"><?= $word('WAREHOUSES', 'place_code_hint') ?></span>
          <input type="text" name="code" value="<?= $e($typed['place_code']) ?>" maxlength="31" autocapitalize="characters" spellcheck="false" required>
        </label>
        <label><?= $word('WAREHOUSES', 'note') ?>
          <input type="text" name="note" value="<?= $e($typed['place_note']) ?>" maxlength="255">
        </label>
        <label><?= $word('CONFIG', 'reason') ?>
          <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
          <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'place_add'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
        </label>
        <p class="actions"><button type="submit" class="btn"><?= $word('WAREHOUSES', 'place_add_button') ?></button></p>
      </form>
    </details>
  </div>
<?php endif; ?>
</section>

<section class="set-card history" aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('WAREHOUSES', 'history') ?></h2>
<?= $partial('config_history', ['rows' => $history]) ?>
</section>
</div>
</div>
</div>
