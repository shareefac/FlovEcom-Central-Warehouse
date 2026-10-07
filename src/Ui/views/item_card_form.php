<p class="crumbs"><a href="<?= $u('/ui/items/' . $sku['id']) ?>"><?= $say('CARD', 'back', (string) $sku['name']) ?></a></p>
<h1><?= $e($title) ?> <span class="muted">(<?= $e($sku['code']) ?>)</span></h1>
<?= $intro('card_form') ?>
<p class="hint"><?= $word('CARD', 'form_intro') ?></p>
<?php if ($error !== null): ?>
<div class="error" role="alert">
<?php if ($errors !== []): ?>
  <p><?= $word('CARD', 'invalid') ?></p>
<?php else: ?>
  <p><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($changes !== []): ?>
  <p><?= $word('CARD', 'changed_meanwhile') ?></p>
  <ul class="plain changes">
<?php foreach ($changes as $c): ?>
    <li><?= $e($c) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($confirmed): ?>
<p class="note"><?= $word('CARD', 'is_confirmed') ?></p>
<?php elseif ($enforced): ?>
<p class="note"><?= $word('CARD', 'was_confirmed') ?></p>
<?php endif; ?>
<form class="record item-card-form" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <fieldset>
    <legend><?= $word('CARD', 'what') ?></legend>
    <label for="f-product_type"><?= $word('CARD_FIELD', 'product_type') ?></label>
    <select id="f-product_type" name="product_type"<?php if (isset($errors['product_type'])): ?> aria-invalid="true" aria-describedby="e-product_type"<?php endif; ?>>
      <option value=""><?= $word('CARD', 'kind_unknown') ?></option>
<?php foreach ($types as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($v['product_type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
<?php if (isset($errors['product_type'])): ?>
    <p class="field-error" id="e-product_type"><?= $e($errors['product_type']) ?></p>
<?php endif; ?>
    <label for="f-liquid_ml"><?= $word('CARD_FIELD', 'liquid_ml') ?> <span class="hint"><?= $word('CARD', 'ml_hint') ?></span></label>
    <input id="f-liquid_ml" type="text" name="liquid_ml" inputmode="decimal" maxlength="12" value="<?= $e($v['liquid_ml']) ?>"<?php if (isset($errors['liquid_ml'])): ?> aria-invalid="true" aria-describedby="e-liquid_ml"<?php endif; ?>>
<?php if (isset($errors['liquid_ml'])): ?>
    <p class="field-error" id="e-liquid_ml"><?= $e($errors['liquid_ml']) ?></p>
<?php endif; ?>
    <label for="f-nicotine_mg"><?= $word('CARD_FIELD', 'nicotine_mg') ?> <span class="hint"><?= $word('CARD', 'mg_hint') ?></span></label>
    <input id="f-nicotine_mg" type="text" name="nicotine_mg" inputmode="decimal" maxlength="12" value="<?= $e($v['nicotine_mg']) ?>"<?php if (isset($errors['nicotine_mg'])): ?> aria-invalid="true" aria-describedby="e-nicotine_mg"<?php endif; ?>>
<?php if (isset($errors['nicotine_mg'])): ?>
    <p class="field-error" id="e-nicotine_mg"><?= $e($errors['nicotine_mg']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset<?php if (isset($errors['duty_liable'])): ?> aria-describedby="e-duty_liable"<?php endif; ?>>
    <legend><?= $word('CARD', 'duty') ?></legend>
    <label class="choice"><input type="radio" name="duty_liable" value="yes"<?php if ($v['duty_liable'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'duty_yes') ?> <span class="hint"><?= $word('CARD', 'duty_yes_hint') ?></span></label>
    <label class="choice"><input type="radio" name="duty_liable" value="no"<?php if ($v['duty_liable'] === 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'no') ?></label>
    <label class="choice"><input type="radio" name="duty_liable" value=""<?php if ($v['duty_liable'] !== 'yes' && $v['duty_liable'] !== 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'unknown') ?></label>
<?php if (isset($errors['duty_liable'])): ?>
    <p class="field-error" id="e-duty_liable"><?= $e($errors['duty_liable']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset<?php if (isset($errors['single_use'])): ?> aria-describedby="e-single_use"<?php endif; ?>>
    <legend><?= $word('CARD', 'single') ?></legend>
    <p class="hint"><?= $word('CARD', 'single_text') ?></p>
    <label class="choice"><input type="radio" name="single_use" value="yes"<?php if ($v['single_use'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'single_yes') ?></label>
    <label class="choice"><input type="radio" name="single_use" value="no"<?php if ($v['single_use'] === 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'single_no') ?></label>
    <label class="choice"><input type="radio" name="single_use" value=""<?php if ($v['single_use'] !== 'yes' && $v['single_use'] !== 'no'): ?> checked<?php endif; ?>> <?= $word('CARD', 'unknown') ?></label>
<?php if (isset($errors['single_use'])): ?>
    <p class="field-error" id="e-single_use"><?= $e($errors['single_use']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('CARD', 'maker') ?></legend>
    <label for="f-ecid"><?= $word('CARD_FIELD', 'ecid') ?> <span class="hint"><?= $word('CARD', 'ecid_hint') ?></span></label>
    <input id="f-ecid" type="text" name="ecid" maxlength="40" autocapitalize="characters" spellcheck="false" value="<?= $e($v['ecid']) ?>"<?php if (isset($errors['ecid'])): ?> aria-invalid="true" aria-describedby="e-ecid"<?php endif; ?>>
<?php if (isset($errors['ecid'])): ?>
    <p class="field-error" id="e-ecid"><?= $e($errors['ecid']) ?></p>
<?php endif; ?>
    <label for="f-manufacturer"><?= $word('CARD_FIELD', 'manufacturer') ?></label>
    <input id="f-manufacturer" type="text" name="manufacturer" maxlength="128" value="<?= $e($v['manufacturer']) ?>"<?php if (isset($errors['manufacturer'])): ?> aria-invalid="true" aria-describedby="e-manufacturer"<?php endif; ?>>
<?php if (isset($errors['manufacturer'])): ?>
    <p class="field-error" id="e-manufacturer"><?= $e($errors['manufacturer']) ?></p>
<?php endif; ?>
    <label for="f-brand"><?= $word('CARD_FIELD', 'brand') ?> <span class="hint"><?= $word('CARD', 'brand_hint') ?></span></label>
    <input id="f-brand" type="text" name="brand" maxlength="128" value="<?= $e($v['brand']) ?>"<?php if (isset($errors['brand'])): ?> aria-invalid="true" aria-describedby="e-brand"<?php endif; ?>>
<?php if (isset($errors['brand'])): ?>
    <p class="field-error" id="e-brand"><?= $e($errors['brand']) ?></p>
<?php endif; ?>
    <label for="f-flavour"><?= $word('CARD_FIELD', 'flavour') ?> <span class="hint"><?= $word('CARD', 'flavour_hint') ?></span><?php if ($flavourProposed): ?>
      <span class="tag warn"><?= $word('CARD', 'from_file') ?></span> <span class="hint"><?= $word('CARD', 'flavour_file') ?></span><?php endif; ?></label>
    <input id="f-flavour" type="text" name="flavour" maxlength="255" value="<?= $e($v['flavour']) ?>"<?php if (isset($errors['flavour'])): ?> aria-invalid="true" aria-describedby="e-flavour"<?php endif; ?>>
<?php if (isset($errors['flavour'])): ?>
    <p class="field-error" id="e-flavour"><?= $e($errors['flavour']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('CARD', 'buying') ?></legend>
    <label class="choice"><input type="checkbox" name="discontinued" value="yes"<?php if ($v['discontinued'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('CARD', 'discontinued') ?>
      <span class="hint"><?= $word('CARD', 'discontinued_hint') ?></span></label>
  </fieldset>
  <p class="actions"><button type="submit" class="primary"><?= $word('CARD', 'save') ?></button> <a href="<?= $u('/ui/items/' . $sku['id']) ?>"><?= $word('CARD', 'cancel') ?></a></p>
</form>
