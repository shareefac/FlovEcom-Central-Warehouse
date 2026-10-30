<?php
// Products › Add Item, the one-page form (the approved design of 29 Oct 2026, PI_New; docs/decisions.md W11.2): the link back to the items
// made by hand above the heading (their list is a view of its own, items_by_hand); a refusal and the "same item" warning first; then what the
// item is in two columns, name, brand and barcode first; the legal details (the product card) and the buying details each in a fold that opens
// when something in it was typed or refused; then the bar at the bottom: "Leave without saving" and the one main button. Every field posts
// where and as it did (the button sends no field of its own); typed values are guarded (data-unsaved).
?>
<?php $madeHref = \CW\Ui\Html::url('/ui/items/new', ['show' => 'all']); ?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['item_new'], intro: 'item_new', side: '', back: [$madeHref, \CW\Ui\Words::ITEM_NEW['made_link']]) ?>
<?php if ($error !== null): ?>
<div class="error" role="alert" data-code="<?= $e($errorCode) ?>">
  <p><?= $e($error) ?><?php if ($errorItem !== null): ?> <a href="<?= $u('/ui/items/' . $errorItem['id']) ?>"><?= $e($errorItem['code']) ?></a><?php endif; ?></p>
<?php if ($cardErrors !== []): ?>
  <ul class="plain">
<?php foreach ($cardErrors as $ce): ?>
    <li><?= $e($ce) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($same !== null): ?>
<div class="note same-item" role="alert" data-code="same_item_exists">
  <p><strong><?= $word('ITEM_NEW', 'same_title') ?></strong></p>
  <ul class="plain">
<?php foreach ($same as $s): ?>
    <li><a href="<?= $u('/ui/items/' . $s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?></li>
<?php endforeach; ?>
  </ul>
  <p><?= $word('ITEM_NEW', 'same_text') ?></p>
</div>
<?php endif; ?>

<form class="doc-details item-new" id="new" method="post" action="/ui/items/new" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>"<?php if ($error !== null || $same !== null): ?> data-dirty="1"<?php endif; ?>>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <fieldset class="doc-grid">
    <legend><?= $word('ITEM_NEW', 'what') ?></legend>
    <div class="field">
      <label for="n-name"><?= $word('FIELD', 'name') ?> <span class="hint"><?= $word('ITEM_NEW', 'name_hint') ?></span></label>
      <input id="n-name" type="text" name="name" maxlength="255" required value="<?= $e($v['name']) ?>"<?php if ($errorCode === 'name_required'): ?> aria-invalid="true"<?php endif; ?>>
    </div>
    <div class="field">
      <label for="n-brand"><?= $word('FIELD', 'brand') ?></label>
      <input id="n-brand" type="text" name="brand" maxlength="128" value="<?= $e($v['brand']) ?>">
    </div>
    <div class="field">
      <label for="n-barcode"><?= $word('ITEM_NEW', 'barcode') ?> <span class="hint"><?= $word('ITEM_NEW', 'barcode_hint') ?></span></label>
      <input id="n-barcode" type="text" name="barcode" inputmode="numeric" maxlength="20" autocomplete="off" value="<?= $e($v['barcode']) ?>"<?php if (in_array($errorCode, ['bad_barcode', 'barcode_on_other_item'], true)): ?> aria-invalid="true"<?php endif; ?>>
    </div>
    <div class="field">
      <label for="n-units"><?= $word('ITEM_NEW', 'barcode_units') ?> <span class="hint"><?= $word('ITEM_NEW', 'barcode_units_hint') ?></span></label>
      <input id="n-units" type="text" name="barcode_units" inputmode="numeric" maxlength="5" value="<?= $e($v['barcode_units']) ?>"<?php if ($errorField === 'barcode_units'): ?> aria-invalid="true"<?php endif; ?>>
    </div>
    <div class="field">
      <label for="n-line"><?= $word('FIELD', 'line') ?></label>
      <input id="n-line" type="text" name="line" maxlength="128" value="<?= $e($v['line']) ?>">
    </div>
    <div class="field">
      <label for="n-flavour"><?= $word('FIELD', 'flavour') ?></label>
      <input id="n-flavour" type="text" name="flavour" maxlength="255" value="<?= $e($v['flavour']) ?>">
    </div>
    <div class="field">
      <label for="n-strength"><?= $word('FIELD', 'strength_mg') ?></label>
      <input id="n-strength" type="text" name="strength_mg" inputmode="decimal" maxlength="12" value="<?= $e($v['strength_mg']) ?>">
    </div>
    <div class="field">
      <label for="n-nic"><?= $word('FIELD', 'nic_type') ?></label>
      <select id="n-nic" name="nic_type">
        <option value=""><?= $word('ITEM_NEW', 'not_known') ?></option>
<?php foreach ($nicTypes as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($v['nic_type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="n-form"><?= $word('FIELD', 'form') ?></label>
      <select id="n-form" name="form">
        <option value=""><?= $word('ITEM_NEW', 'not_known') ?></option>
<?php foreach ($forms as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($v['form'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="n-volume"><?= $word('FIELD', 'volume_ml') ?></label>
      <input id="n-volume" type="text" name="volume_ml" inputmode="decimal" maxlength="12" value="<?= $e($v['volume_ml']) ?>">
    </div>
    <div class="field">
      <label for="n-puffs"><?= $word('FIELD', 'puffs') ?></label>
      <input id="n-puffs" type="text" name="puffs" inputmode="numeric" maxlength="10" value="<?= $e($v['puffs']) ?>">
    </div>
  </fieldset>
  <details class="fold" id="new-legal"<?php if ($legalOpen): ?> open<?php endif; ?>>
    <summary><?= $word('ITEM_NEW', 'legal') ?></summary>
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('ITEM_NEW', 'legal') ?></legend>
      <div class="field">
        <label for="c-type"><?= $word('CARD_FIELD', 'product_type') ?></label>
        <select id="c-type" name="c_product_type">
          <option value=""><?= $word('CARD', 'kind_unknown') ?></option>
<?php foreach ($types as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($v['c_product_type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="c-ml"><?= $word('CARD_FIELD', 'liquid_ml') ?> <span class="hint"><?= $word('CARD', 'ml_hint') ?></span></label>
        <input id="c-ml" type="text" name="c_liquid_ml" inputmode="decimal" maxlength="12" value="<?= $e($v['c_liquid_ml']) ?>">
      </div>
      <div class="field">
        <label for="c-mg"><?= $word('CARD_FIELD', 'nicotine_mg') ?> <span class="hint"><?= $word('CARD', 'mg_hint') ?></span></label>
        <input id="c-mg" type="text" name="c_nicotine_mg" inputmode="decimal" maxlength="12" value="<?= $e($v['c_nicotine_mg']) ?>">
      </div>
      <fieldset class="doc-wide">
        <legend><?= $word('CARD', 'duty') ?></legend>
        <label class="choice"><input type="radio" name="c_duty_liable" value="yes"<?php if ($v['c_duty_liable'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'duty_yes') ?> <span class="hint"><?= $word('CARD', 'duty_yes_hint') ?></span></label>
        <label class="choice"><input type="radio" name="c_duty_liable" value="no"<?php if ($v['c_duty_liable'] === 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'no') ?></label>
        <label class="choice"><input type="radio" name="c_duty_liable" value=""<?php if ($v['c_duty_liable'] !== 'yes' && $v['c_duty_liable'] !== 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'unknown') ?></label>
      </fieldset>
      <fieldset class="doc-wide">
        <legend><?= $word('CARD', 'single') ?></legend>
        <label class="choice"><input type="radio" name="c_single_use" value="yes"<?php if ($v['c_single_use'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'single_yes') ?></label>
        <label class="choice"><input type="radio" name="c_single_use" value="no"<?php if ($v['c_single_use'] === 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'single_no') ?></label>
        <label class="choice"><input type="radio" name="c_single_use" value=""<?php if ($v['c_single_use'] !== 'yes' && $v['c_single_use'] !== 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'unknown') ?></label>
      </fieldset>
      <div class="field">
        <label for="c-ecid"><?= $word('CARD_FIELD', 'ecid') ?> <span class="hint"><?= $word('CARD', 'ecid_hint') ?></span></label>
        <input id="c-ecid" type="text" name="c_ecid" maxlength="40" autocapitalize="characters" spellcheck="false" value="<?= $e($v['c_ecid']) ?>">
      </div>
      <div class="field">
        <label for="c-maker"><?= $word('CARD_FIELD', 'manufacturer') ?></label>
        <input id="c-maker" type="text" name="c_manufacturer" maxlength="128" value="<?= $e($v['c_manufacturer']) ?>">
      </div>
      <div class="field">
        <label for="c-brand"><?= $word('ITEM_NEW', 'card_brand') ?> <span class="hint"><?= $word('CARD', 'brand_hint') ?></span></label>
        <input id="c-brand" type="text" name="c_brand" maxlength="128" value="<?= $e($v['c_brand']) ?>">
      </div>
      <div class="field">
        <label for="c-flavour"><?= $word('ITEM_NEW', 'card_flavour') ?> <span class="hint"><?= $word('CARD', 'flavour_hint') ?></span></label>
        <input id="c-flavour" type="text" name="c_flavour" maxlength="255" value="<?= $e($v['c_flavour']) ?>">
      </div>
    </fieldset>
  </details>
  <details class="fold" id="new-buying"<?php if ($buyingOpen): ?> open<?php endif; ?>>
    <summary><?= $word('ITEM_NEW', 'buying') ?></summary>
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('ITEM_NEW', 'buying') ?></legend>
      <div class="field">
        <label for="n-pack"><?= $word('FIELD', 'pack_units') ?> <span class="hint"><?= $word('ITEM_NEW', 'pack_hint') ?></span></label>
        <input id="n-pack" type="text" name="pack_units" inputmode="numeric" maxlength="5" value="<?= $e($v['pack_units']) ?>"<?php if ($errorField === 'pack_units'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="n-family"><?= $word('ITEM_NEW', 'family') ?> <span class="hint"><?= $word('ITEM_NEW', 'family_hint') ?></span></label>
        <input id="n-family" type="text" name="family" maxlength="255" list="n-families" value="<?= $e($v['family']) ?>"<?php if ($errorField === 'family'): ?> aria-invalid="true"<?php endif; ?>>
        <datalist id="n-families">
<?php foreach ($families as $fname): ?>
          <option value="<?= $e($fname) ?>"></option>
<?php endforeach; ?>
        </datalist>
      </div>
      <label class="choice doc-wide"><input type="checkbox" name="c_discontinued" value="yes"<?php if ($v['c_discontinued'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'discontinued') ?> <span class="hint"><?= $word('CARD', 'discontinued_hint') ?></span></label>
    </fieldset>
  </details>
<?php if ($same !== null): ?>
  <label class="choice dup-confirm"><input type="checkbox" name="anyway" value="1"<?php if ($v['anyway'] === '1'): ?> checked<?php endif; ?>> <?= $word('ITEM_NEW', 'anyway') ?></label>
<?php endif; ?>
<?= $partial('doc_bar', ['back' => '/ui/items/cards', 'buttons' => [['label' => \CW\Ui\Words::ITEM_NEW['add'], 'primary' => true]]]) ?>
</form>
