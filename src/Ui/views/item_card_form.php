<p class="crumbs"><a href="<?= $u('/ui/items/' . $sku['id']) ?>">Back to <?= $e($sku['code']) ?></a></p>
<h1>Item card of <?= $e($sku['code']) ?> <span class="muted"><?= $e($sku['name']) ?></span></h1>
<p class="muted">Fill in what the box or the supplier says. Leave a field empty when nobody knows yet. Nothing here is filled in by itself: suggestions
  from the matcher and the listings are on the item page, each with its own button.</p>
<?php if ($error !== null): ?>
<div class="error" role="alert">
<?php if ($errors !== []): ?>
  <p>Nothing was saved: some fields need correcting (marked below).</p>
<?php else: ?>
  <p><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($changes !== []): ?>
  <p>What changed meanwhile (your form still shows what you typed; saving it now replaces these):</p>
  <ul class="plain changes">
<?php foreach ($changes as $c): ?>
    <li><?= $e($c) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($confirmed): ?>
<p class="note">This card is confirmed. Changing a legal field makes it "not confirmed" until someone confirms it again. A rule the confirmation blocked keeps
  blocking the item until then, even when you correct the field; a rule your change breaks is a warning until someone confirms the card.</p>
<?php elseif ($enforced): ?>
<p class="note">This card was confirmed before. A rule that confirmation blocked keeps blocking the item until someone confirms the card again.</p>
<?php endif; ?>
<form class="record item-card-form" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <fieldset>
    <legend>What it is</legend>
    <label for="f-product_type">Product type</label>
    <select id="f-product_type" name="product_type"<?php if (isset($errors['product_type'])): ?> aria-invalid="true" aria-describedby="e-product_type"<?php endif; ?>>
      <option value="">Not known</option>
<?php foreach ($types as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($v['product_type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
<?php if (isset($errors['product_type'])): ?>
    <p class="field-error" id="e-product_type"><?= $e($errors['product_type']) ?></p>
<?php endif; ?>
    <label for="f-liquid_ml">Liquid (ml) <span class="muted">(a bottle's contents, or what a tank, pod or device holds; to 0.1 ml, for example 10 or 2; for a device / kit
      sold without a tank or pod, 0)</span></label>
    <input id="f-liquid_ml" type="text" name="liquid_ml" inputmode="decimal" maxlength="12" value="<?= $e($v['liquid_ml']) ?>"<?php if (isset($errors['liquid_ml'])): ?> aria-invalid="true" aria-describedby="e-liquid_ml"<?php endif; ?>>
<?php if (isset($errors['liquid_ml'])): ?>
    <p class="field-error" id="e-liquid_ml"><?= $e($errors['liquid_ml']) ?></p>
<?php endif; ?>
    <label for="f-nicotine_mg">Nicotine (mg/ml) <span class="muted">(0 for nicotine-free; a percentage is fine: 2% = 20 mg/ml)</span></label>
    <input id="f-nicotine_mg" type="text" name="nicotine_mg" inputmode="decimal" maxlength="12" value="<?= $e($v['nicotine_mg']) ?>"<?php if (isset($errors['nicotine_mg'])): ?> aria-invalid="true" aria-describedby="e-nicotine_mg"<?php endif; ?>>
<?php if (isset($errors['nicotine_mg'])): ?>
    <p class="field-error" id="e-nicotine_mg"><?= $e($errors['nicotine_mg']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset<?php if (isset($errors['duty_liable'])): ?> aria-describedby="e-duty_liable"<?php endif; ?>>
    <legend>Duty-liable (Vaping Products Duty)</legend>
    <label class="choice"><input type="radio" name="duty_liable" value="yes"<?php if ($v['duty_liable'] === 'yes'): ?> checked<?php endif; ?>> Yes <span class="muted">(all vaping liquids from 1 Oct 2026, nicotine-free included)</span></label>
    <label class="choice"><input type="radio" name="duty_liable" value="no"<?php if ($v['duty_liable'] === 'no'): ?> checked<?php endif; ?>> No</label>
    <label class="choice"><input type="radio" name="duty_liable" value=""<?php if ($v['duty_liable'] !== 'yes' && $v['duty_liable'] !== 'no'): ?> checked<?php endif; ?>> Not known yet</label>
<?php if (isset($errors['duty_liable'])): ?>
    <p class="field-error" id="e-duty_liable"><?= $e($errors['duty_liable']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset<?php if (isset($errors['single_use'])): ?> aria-describedby="e-single_use"<?php endif; ?>>
    <legend>Single-use vape?</legend>
    <p class="muted">A person answers this from the box: it is never assumed from the word "disposable". Single-use vapes may not be sold in the UK, so "yes" blocks
      the item once the card is confirmed.</p>
    <label class="choice"><input type="radio" name="single_use" value="yes"<?php if ($v['single_use'] === 'yes'): ?> checked<?php endif; ?>> Yes, single-use</label>
    <label class="choice"><input type="radio" name="single_use" value="no"<?php if ($v['single_use'] === 'no'): ?> checked<?php endif; ?>> No (rechargeable and refillable, or not a device)</label>
    <label class="choice"><input type="radio" name="single_use" value=""<?php if ($v['single_use'] !== 'yes' && $v['single_use'] !== 'no'): ?> checked<?php endif; ?>> Not known yet</label>
<?php if (isset($errors['single_use'])): ?>
    <p class="field-error" id="e-single_use"><?= $e($errors['single_use']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend>Who makes it</legend>
    <label for="f-ecid">ECID / GB-ID <span class="muted">(the notification number on the box or the MHRA list, for example 12345-16-12345)</span></label>
    <input id="f-ecid" type="text" name="ecid" maxlength="40" autocapitalize="characters" spellcheck="false" value="<?= $e($v['ecid']) ?>"<?php if (isset($errors['ecid'])): ?> aria-invalid="true" aria-describedby="e-ecid"<?php endif; ?>>
<?php if (isset($errors['ecid'])): ?>
    <p class="field-error" id="e-ecid"><?= $e($errors['ecid']) ?></p>
<?php endif; ?>
    <label for="f-manufacturer">Manufacturer</label>
    <input id="f-manufacturer" type="text" name="manufacturer" maxlength="128" value="<?= $e($v['manufacturer']) ?>"<?php if (isset($errors['manufacturer'])): ?> aria-invalid="true" aria-describedby="e-manufacturer"<?php endif; ?>>
<?php if (isset($errors['manufacturer'])): ?>
    <p class="field-error" id="e-manufacturer"><?= $e($errors['manufacturer']) ?></p>
<?php endif; ?>
    <label for="f-brand">Brand <span class="muted">(as on the box)</span></label>
    <input id="f-brand" type="text" name="brand" maxlength="128" value="<?= $e($v['brand']) ?>"<?php if (isset($errors['brand'])): ?> aria-invalid="true" aria-describedby="e-brand"<?php endif; ?>>
<?php if (isset($errors['brand'])): ?>
    <p class="field-error" id="e-brand"><?= $e($errors['brand']) ?></p>
<?php endif; ?>
    <label for="f-flavour">Flavour <span class="muted">(a flavour you type here counts as confirmed)</span><?php if ($flavourProposed): ?>
      <span class="tag warn">proposed by a file, not confirmed</span> <span class="muted">(saving this form leaves it proposed: confirm it on the item page, or
      type the right one)</span><?php endif; ?></label>
    <input id="f-flavour" type="text" name="flavour" maxlength="255" value="<?= $e($v['flavour']) ?>"<?php if (isset($errors['flavour'])): ?> aria-invalid="true" aria-describedby="e-flavour"<?php endif; ?>>
<?php if (isset($errors['flavour'])): ?>
    <p class="field-error" id="e-flavour"><?= $e($errors['flavour']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend>Buying</legend>
    <label class="choice"><input type="checkbox" name="discontinued" value="yes"<?php if ($v['discontinued'] === 'yes'): ?> checked<?php endif; ?>> Discontinued: do not reorder
      <span class="muted">(never suggested on the reorder list; a buyer can still order it on purpose)</span></label>
  </fieldset>
  <p class="actions"><button type="submit">Save the item card</button> <a href="<?= $u('/ui/items/' . $sku['id']) ?>">Cancel</a></p>
</form>
