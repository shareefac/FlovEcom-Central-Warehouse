<?= $head(\CW\Ui\Words::PAGE_TITLE['item_new'], intro: 'item_new') ?>
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

<form class="record item-new" id="new" method="post" action="/ui/items/new">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <fieldset>
    <legend><?= $word('ITEM_NEW', 'what') ?></legend>
    <p class="hint"><?= $word('ITEM_NEW', 'what_hint') ?></p>
    <label for="n-name"><?= $word('FIELD', 'name') ?> <span class="hint"><?= $word('ITEM_NEW', 'name_hint') ?></span></label>
    <input id="n-name" type="text" name="name" maxlength="255" required value="<?= $e($v['name']) ?>"<?php if ($errorCode === 'name_required'): ?> aria-invalid="true"<?php endif; ?>>
    <label for="n-brand"><?= $word('FIELD', 'brand') ?></label>
    <input id="n-brand" type="text" name="brand" maxlength="128" value="<?= $e($v['brand']) ?>">
    <label for="n-line"><?= $word('FIELD', 'line') ?></label>
    <input id="n-line" type="text" name="line" maxlength="128" value="<?= $e($v['line']) ?>">
    <label for="n-flavour"><?= $word('FIELD', 'flavour') ?></label>
    <input id="n-flavour" type="text" name="flavour" maxlength="255" value="<?= $e($v['flavour']) ?>">
    <label for="n-strength"><?= $word('FIELD', 'strength_mg') ?></label>
    <input id="n-strength" type="text" name="strength_mg" inputmode="decimal" maxlength="12" value="<?= $e($v['strength_mg']) ?>">
    <label for="n-nic"><?= $word('FIELD', 'nic_type') ?></label>
    <select id="n-nic" name="nic_type">
      <option value=""><?= $word('ITEM_NEW', 'not_known') ?></option>
<?php foreach ($nicTypes as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($v['nic_type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label for="n-form"><?= $word('FIELD', 'form') ?></label>
    <select id="n-form" name="form">
      <option value=""><?= $word('ITEM_NEW', 'not_known') ?></option>
<?php foreach ($forms as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($v['form'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label for="n-volume"><?= $word('FIELD', 'volume_ml') ?></label>
    <input id="n-volume" type="text" name="volume_ml" inputmode="decimal" maxlength="12" value="<?= $e($v['volume_ml']) ?>">
    <label for="n-puffs"><?= $word('FIELD', 'puffs') ?></label>
    <input id="n-puffs" type="text" name="puffs" inputmode="numeric" maxlength="10" value="<?= $e($v['puffs']) ?>">
    <label for="n-pack"><?= $word('FIELD', 'pack_units') ?> <span class="hint"><?= $word('ITEM_NEW', 'pack_hint') ?></span></label>
    <input id="n-pack" type="text" name="pack_units" inputmode="numeric" maxlength="5" value="<?= $e($v['pack_units']) ?>">
  </fieldset>
  <fieldset>
    <legend><?= $word('ITEM_NEW', 'card') ?></legend>
    <p class="hint"><?= $word('ITEM_NEW', 'card_hint') ?></p>
    <label for="c-type"><?= $word('CARD_FIELD', 'product_type') ?></label>
    <select id="c-type" name="c_product_type">
      <option value=""><?= $word('CARD', 'kind_unknown') ?></option>
<?php foreach ($types as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($v['c_product_type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label for="c-ml"><?= $word('CARD_FIELD', 'liquid_ml') ?> <span class="hint"><?= $word('CARD', 'ml_hint') ?></span></label>
    <input id="c-ml" type="text" name="c_liquid_ml" inputmode="decimal" maxlength="12" value="<?= $e($v['c_liquid_ml']) ?>">
    <label for="c-mg"><?= $word('CARD_FIELD', 'nicotine_mg') ?> <span class="hint"><?= $word('CARD', 'mg_hint') ?></span></label>
    <input id="c-mg" type="text" name="c_nicotine_mg" inputmode="decimal" maxlength="12" value="<?= $e($v['c_nicotine_mg']) ?>">
    <fieldset>
      <legend><?= $word('CARD', 'duty') ?></legend>
      <label class="choice"><input type="radio" name="c_duty_liable" value="yes"<?php if ($v['c_duty_liable'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'duty_yes') ?> <span class="hint"><?= $word('CARD', 'duty_yes_hint') ?></span></label>
      <label class="choice"><input type="radio" name="c_duty_liable" value="no"<?php if ($v['c_duty_liable'] === 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'no') ?></label>
      <label class="choice"><input type="radio" name="c_duty_liable" value=""<?php if ($v['c_duty_liable'] !== 'yes' && $v['c_duty_liable'] !== 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'unknown') ?></label>
    </fieldset>
    <fieldset>
      <legend><?= $word('CARD', 'single') ?></legend>
      <p class="hint"><?= $word('CARD', 'single_text') ?></p>
      <label class="choice"><input type="radio" name="c_single_use" value="yes"<?php if ($v['c_single_use'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'single_yes') ?></label>
      <label class="choice"><input type="radio" name="c_single_use" value="no"<?php if ($v['c_single_use'] === 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'single_no') ?></label>
      <label class="choice"><input type="radio" name="c_single_use" value=""<?php if ($v['c_single_use'] !== 'yes' && $v['c_single_use'] !== 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'unknown') ?></label>
    </fieldset>
    <label for="c-ecid"><?= $word('CARD_FIELD', 'ecid') ?> <span class="hint"><?= $word('CARD', 'ecid_hint') ?></span></label>
    <input id="c-ecid" type="text" name="c_ecid" maxlength="40" autocapitalize="characters" spellcheck="false" value="<?= $e($v['c_ecid']) ?>">
    <label for="c-maker"><?= $word('CARD_FIELD', 'manufacturer') ?></label>
    <input id="c-maker" type="text" name="c_manufacturer" maxlength="128" value="<?= $e($v['c_manufacturer']) ?>">
    <label for="c-brand"><?= $word('ITEM_NEW', 'card_brand') ?> <span class="hint"><?= $word('CARD', 'brand_hint') ?></span></label>
    <input id="c-brand" type="text" name="c_brand" maxlength="128" value="<?= $e($v['c_brand']) ?>">
    <label for="c-flavour"><?= $word('ITEM_NEW', 'card_flavour') ?> <span class="hint"><?= $word('CARD', 'flavour_hint') ?></span></label>
    <input id="c-flavour" type="text" name="c_flavour" maxlength="255" value="<?= $e($v['c_flavour']) ?>">
    <label class="choice"><input type="checkbox" name="c_discontinued" value="yes"<?php if ($v['c_discontinued'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'discontinued') ?> <span class="hint"><?= $word('CARD', 'discontinued_hint') ?></span></label>
  </fieldset>
  <fieldset>
    <legend><?= $word('ITEM_NEW', 'barcode') ?></legend>
    <label for="n-barcode"><?= $word('ITEM_NEW', 'barcode') ?> <span class="hint"><?= $word('ITEM_NEW', 'barcode_hint') ?></span></label>
    <input id="n-barcode" type="text" name="barcode" inputmode="numeric" maxlength="20" autocomplete="off" value="<?= $e($v['barcode']) ?>"<?php if (in_array($errorCode, ['bad_barcode', 'barcode_on_other_item'], true)): ?> aria-invalid="true"<?php endif; ?>>
    <label for="n-units"><?= $word('ITEM_NEW', 'barcode_units') ?> <span class="hint"><?= $word('ITEM_NEW', 'barcode_units_hint') ?></span></label>
    <input id="n-units" type="text" name="barcode_units" inputmode="numeric" maxlength="5" value="<?= $e($v['barcode_units']) ?>">
  </fieldset>
  <fieldset>
    <legend><?= $word('ITEM_NEW', 'family') ?></legend>
    <label for="n-family"><?= $word('ITEM_NEW', 'family') ?> <span class="hint"><?= $word('ITEM_NEW', 'family_hint') ?></span></label>
    <input id="n-family" type="text" name="family" maxlength="255" list="n-families" value="<?= $e($v['family']) ?>"<?php if ($errorField === 'family'): ?> aria-invalid="true"<?php endif; ?>>
    <datalist id="n-families">
<?php foreach ($families as $fname): ?>
      <option value="<?= $e($fname) ?>"></option>
<?php endforeach; ?>
    </datalist>
  </fieldset>
<?php if ($same !== null): ?>
  <label class="choice dup-confirm"><input type="checkbox" name="anyway" value="1"<?php if ($v['anyway'] === '1'): ?> checked<?php endif; ?>> <?= $word('ITEM_NEW', 'anyway') ?></label>
<?php endif; ?>
  <p class="hint"><?= $word('ITEM_NEW', 'add_does') ?></p>
  <p class="actions"><button type="submit" class="primary"><?= $word('ITEM_NEW', 'add') ?></button></p>
</form>

<section class="card made-by-hand" aria-labelledby="made-h">
  <h2 id="made-h"><?= $word('ITEM_NEW', 'made') ?></h2>
  <p class="hint"><?= $word('ITEM_NEW', 'made_text') ?> <?php if ($checkOn): ?><?= $word('ITEM_NEW', 'check_on') ?><?php else: ?><?= $word('ITEM_NEW', 'check_off') ?><?php endif; ?></p>
  <p class="seg-links"><?php if ($waiting): ?><a href="/ui/items/new"><?= $word('ITEM_NEW', 'show_all') ?></a><?php else: ?><a href="<?= $u('/ui/items/new', ['show' => 'waiting']) ?>"><?= $word('ITEM_NEW', 'show_waiting') ?></a><?php endif; ?></p>
<?php if ($rows === []): ?>
<?php if ($waiting): ?>
  <?= $empty(\CW\Ui\Words::ITEM_NEW['waiting_none'], \CW\Ui\Words::ITEM_NEW['waiting_none_text'], '/ui/items/new', \CW\Ui\Words::ITEM_NEW['show_all']) ?>
<?php else: ?>
  <?= $empty(\CW\Ui\Words::ITEM_NEW['made_none'], \CW\Ui\Words::ITEM_NEW['made_none_text']) ?>
<?php endif; ?>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list by-hand">
    <thead>
      <tr>
        <th scope="col"><?= $word('ITEM_NEW', 'item') ?></th>
        <th scope="col"><?= $word('ITEM_NEW', 'who') ?></th>
        <th scope="col"><?= $word('ITEM_NEW', 'when') ?></th>
        <th scope="col" class="c-status"><?= $word('ITEM_NEW', 'check') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['code']) ?></a> <?= $e($r['name']) ?><?php if ($r['brand'] !== null): ?><span class="o-sub"><?= $e($r['brand']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('ITEM_NEW', 'who') ?>"><?= $e($r['who']) ?></td>
        <td data-label="<?= $word('ITEM_NEW', 'when') ?>"><?= $when($r['at']) ?></td>
        <td class="c-status"><?= $stateChip('BY_HAND_STATE', $r['state']) ?>
<?php if ($r['checked'] !== null): ?>
          <span class="o-sub"><?= $e($r['checked']) ?><?php if ($r['note'] !== null): ?> · <?= $e($r['note']) ?><?php endif; ?></span>
<?php elseif ($r['canCheck']): ?>
          <form class="inline check-item" method="post" action="<?= $u('/ui/items/new/' . $r['id'] . '/check') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <label class="visually-hidden" for="cn-<?= $e($r['id']) ?>"><?= $word('ITEM_NEW', 'check_note') ?></label>
            <input id="cn-<?= $e($r['id']) ?>" type="text" name="note" maxlength="500" placeholder="<?= $word('ITEM_NEW', 'check_note') ?>">
            <button type="submit" class="secondary sm"><?= $word('ITEM_NEW', 'check_button') ?></button>
          </form>
<?php elseif ($r['own']): ?>
          <span class="o-sub"><?= $word('ITEM_NEW', 'check_own') ?></span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="<?= $say('ITEM_NEW', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('ITEM_NEW', 'prev') ?></a><?php endif; ?>
    <span><?= $say('ITEM_NEW', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('ITEM_NEW', 'next') ?></a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</section>
