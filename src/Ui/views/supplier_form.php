<p class="crumbs"><a href="/ui/purchasing/suppliers"><?= $word('MENU', 'suppliers') ?></a><?php if ($s !== null): ?> <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id']) ?>"><?= $say('SUPPLIER_FORM', 'back', (string) $s['name']) ?></a><?php endif; ?></p>
<h1><?= $e($title) ?></h1>
<?= $intro('supplier_form') ?>
<?php if ($error !== null && $theirs !== []): ?>
<div class="error" role="alert">
  <p><?= $e($error) ?></p>
  <p><?= $word('SUPPLIER_FORM', 'theirs_list') ?></p>
  <ul class="plain changes">
<?php foreach ($theirs as $field => $t): ?>
    <li><a href="#f-<?= $e($field) ?>"><?= $say('SUPPLIER_FORM', 'theirs_line', $t['label'], $t['now'], $t['by'], $t['at']) ?></a></li>
<?php endforeach; ?>
  </ul>
</div>
<?php elseif ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($errorField !== null): ?> <a href="#f-<?= $e($errorField) ?>"><?= $word('LISTING', 'go_to_field') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($s !== null && $s['status'] === 'active'): ?>
<ul class="note">
  <li><?= $word('SUPPLIER_FORM', 'active_1') ?></li>
  <li><?= $word('SUPPLIER_FORM', 'active_2') ?></li>
</ul>
<?php endif; ?>
<form class="record" method="post" action="<?= $e($action) ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($formKey !== null): ?>
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
<?php endif; ?>
<?php if ($version !== null): ?>
  <input type="hidden" name="version" value="<?= $e($version) ?>">
<?php endif; ?>
  <fieldset>
    <legend><?= $word('SUPPLIER_FORM', 'supplier') ?></legend>
<?php foreach ([['name', 'name', 128, true], ['code', 'code', 16, false], ['legal_name', 'legal_name', 160, false], ['company_number', 'company_number', 16, false], ['vat_number', 'vat_number', 20, false]] as [$k, $label, $max, $req]): ?>
    <label for="f-<?= $e($k) ?>"><?= $word('SUPPLIER_FORM', $label) ?><?php if ($k === 'code'): ?> <span class="hint"><?= $word('SUPPLIER_FORM', 'code_hint') ?></span><?php endif; ?></label>
    <input id="f-<?= $e($k) ?>" type="text" name="<?= $e($k) ?>" value="<?= $e($v[$k]) ?>" maxlength="<?= $e($max) ?>"<?php if ($req): ?> required<?php endif; ?><?php if ($errorField === $k): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php if ($errorField === $k): ?>
    <p class="field-error" id="e-<?= $e($k) ?>"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs[$k])): ?>
    <p class="field-theirs" id="t-<?= $e($k) ?>"><?php if ($theirs[$k]['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs[$k]['by'], $theirs[$k]['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs[$k]['by']) ?><?php endif; ?></p>
<?php endif; ?>
<?php endforeach; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('SUPPLIER_FORM', 'address') ?></legend>
<?php foreach ([['address_line1', 128], ['address_line2', 128], ['city', 64], ['postcode', 16], ['country', 2]] as [$k, $max]): ?>
    <label for="f-<?= $e($k) ?>"><?= $word('SUPPLIER_FORM', $k) ?><?php if ($k === 'country'): ?> <span class="hint"><?= $word('SUPPLIER_FORM', 'country_hint') ?></span><?php endif; ?></label>
    <input id="f-<?= $e($k) ?>" type="text" name="<?= $e($k) ?>" value="<?= $e($v[$k]) ?>" maxlength="<?= $e($max) ?>"<?php if ($k === 'country'): ?> class="short" autocapitalize="characters"<?php endif; ?><?php if ($errorField === $k): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php if ($errorField === $k): ?>
    <p class="field-error" id="e-<?= $e($k) ?>"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs[$k])): ?>
    <p class="field-theirs" id="t-<?= $e($k) ?>"><?php if ($theirs[$k]['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs[$k]['by'], $theirs[$k]['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs[$k]['by']) ?><?php endif; ?></p>
<?php endif; ?>
<?php endforeach; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('SUPPLIER_FORM', 'contact') ?></legend>
<?php foreach ([['contact_name', 'text', 128], ['email', 'email', 191], ['phone', 'text', 32]] as [$k, $type, $max]): ?>
    <label for="f-<?= $e($k) ?>"><?= $word('SUPPLIER_FORM', $k) ?></label>
    <input id="f-<?= $e($k) ?>" type="<?= $e($type) ?>" name="<?= $e($k) ?>" value="<?= $e($v[$k]) ?>" maxlength="<?= $e($max) ?>"<?php if ($errorField === $k): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php if ($errorField === $k): ?>
    <p class="field-error" id="e-<?= $e($k) ?>"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs[$k])): ?>
    <p class="field-theirs" id="t-<?= $e($k) ?>"><?php if ($theirs[$k]['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs[$k]['by'], $theirs[$k]['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs[$k]['by']) ?><?php endif; ?></p>
<?php endif; ?>
<?php endforeach; ?>
    <label for="f-contacts_note"><?= $word('SUPPLIER_FORM', 'contacts_note') ?></label>
    <textarea id="f-contacts_note" name="contacts_note" maxlength="500" rows="2"<?php if ($errorField === 'contacts_note'): ?> aria-invalid="true" aria-describedby="e-contacts_note"<?php endif; ?>><?= $e($v['contacts_note']) ?></textarea>
<?php if ($errorField === 'contacts_note'): ?>
    <p class="field-error" id="e-contacts_note"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['contacts_note'])): ?>
    <p class="field-theirs" id="t-contacts_note"><?php if ($theirs['contacts_note']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['contacts_note']['by'], $theirs['contacts_note']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['contacts_note']['by']) ?><?php endif; ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('SUPPLIER_FORM', 'terms') ?></legend>
    <label for="f-payment_terms"><?= $word('SUPPLIER_FORM', 'payment_terms') ?> <span class="hint"><?= $word('SUPPLIER_FORM', 'payment_terms_hint') ?></span></label>
    <input id="f-payment_terms" type="text" name="payment_terms" value="<?= $e($v['payment_terms']) ?>" maxlength="100"<?php if ($errorField === 'payment_terms'): ?> aria-invalid="true" aria-describedby="e-payment_terms"<?php endif; ?>>
<?php if ($errorField === 'payment_terms'): ?>
    <p class="field-error" id="e-payment_terms"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['payment_terms'])): ?>
    <p class="field-theirs" id="t-payment_terms"><?php if ($theirs['payment_terms']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['payment_terms']['by'], $theirs['payment_terms']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['payment_terms']['by']) ?><?php endif; ?></p>
<?php endif; ?>
<?php foreach ([['payment_terms_days', 'payment_days', 0, 365], ['default_lead_days', 'lead', 0, 120], ['review_days', 'every', 1, 120]] as [$k, $label, $min, $max]): ?>
    <label for="f-<?= $e($k) ?>"><?= $word('SUPPLIER_FORM', $label) ?></label>
    <input id="f-<?= $e($k) ?>" type="number" name="<?= $e($k) ?>" value="<?= $e($v[$k]) ?>" min="<?= $e($min) ?>" max="<?= $e($max) ?>" class="short"<?php if ($errorField === $k): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php if ($errorField === $k): ?>
    <p class="field-error" id="e-<?= $e($k) ?>"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs[$k])): ?>
    <p class="field-theirs" id="t-<?= $e($k) ?>"><?php if ($theirs[$k]['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs[$k]['by'], $theirs[$k]['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs[$k]['by']) ?><?php endif; ?></p>
<?php endif; ?>
<?php endforeach; ?>
    <label for="f-min_order_value"><?= $word('SUPPLIER_FORM', 'min_order') ?></label>
    <input id="f-min_order_value" type="text" name="min_order_value" value="<?= $e($v['min_order_value']) ?>" inputmode="decimal" maxlength="16" class="short"<?php if ($errorField === 'min_order_value'): ?> aria-invalid="true" aria-describedby="e-min_order_value"<?php endif; ?>>
<?php if ($errorField === 'min_order_value'): ?>
    <p class="field-error" id="e-min_order_value"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['min_order_value'])): ?>
    <p class="field-theirs" id="t-min_order_value"><?php if ($theirs['min_order_value']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['min_order_value']['by'], $theirs['min_order_value']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['min_order_value']['by']) ?><?php endif; ?></p>
<?php endif; ?>
    <label for="f-default_vat_code"><?= $word('SUPPLIER_FORM', 'vat') ?></label>
    <select id="f-default_vat_code" name="default_vat_code"<?php if ($errorField === 'default_vat_code'): ?> aria-invalid="true" aria-describedby="e-default_vat_code"<?php endif; ?>>
<?php foreach ($vatCodes as $c): ?>
      <option value="<?= $e($c['code']) ?>"<?php if ($v['default_vat_code'] === $c['code']): ?> selected<?php endif; ?>><?= $e($c['code']) ?> – <?= $e($c['label']) ?></option>
<?php endforeach; ?>
    </select>
<?php if ($errorField === 'default_vat_code'): ?>
    <p class="field-error" id="e-default_vat_code"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['default_vat_code'])): ?>
    <p class="field-theirs" id="t-default_vat_code"><?php if ($theirs['default_vat_code']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['default_vat_code']['by'], $theirs['default_vat_code']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['default_vat_code']['by']) ?><?php endif; ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset<?php if ($errorField === 'is_overseas' || $errorField === 'import_route'): ?> class="invalid"<?php endif; ?>>
    <legend><?= $word('SUPPLIER_FORM', 'abroad') ?></legend>
    <label class="choice"><input id="f-is_overseas" type="checkbox" name="is_overseas" value="1"<?php if ($v['is_overseas'] === '1'): ?> checked<?php endif; ?>> <?= $word('SUPPLIER_FORM', 'abroad_tick') ?></label>
<?php if ($errorField === 'is_overseas'): ?>
    <p class="field-error" id="e-is_overseas"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['is_overseas'])): ?>
    <p class="field-theirs" id="t-is_overseas"><?php if ($theirs['is_overseas']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['is_overseas']['by'], $theirs['is_overseas']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['is_overseas']['by']) ?><?php endif; ?></p>
<?php endif; ?>
    <label for="f-import_route"><?= $word('SUPPLIER_FORM', 'route') ?></label>
    <textarea id="f-import_route" name="import_route" maxlength="1000" rows="3"<?php if ($errorField === 'import_route'): ?> aria-invalid="true" aria-describedby="e-import_route"<?php endif; ?>><?= $e($v['import_route']) ?></textarea>
<?php if ($errorField === 'import_route'): ?>
    <p class="field-error" id="e-import_route"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['import_route'])): ?>
    <p class="field-theirs" id="t-import_route"><?php if ($theirs['import_route']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['import_route']['by'], $theirs['import_route']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['import_route']['by']) ?><?php endif; ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('SUPPLIER_FORM', 'dd') ?></legend>
    <label for="f-dd_checked_on"><?= $word('SUPPLIER_FORM', 'dd_on') ?></label>
    <input id="f-dd_checked_on" type="date" name="dd_checked_on" value="<?= $e($v['dd_checked_on']) ?>" max="<?= $e($today) ?>"<?php if ($errorField === 'dd_checked_on'): ?> aria-invalid="true" aria-describedby="e-dd_checked_on"<?php endif; ?>>
<?php if ($errorField === 'dd_checked_on'): ?>
    <p class="field-error" id="e-dd_checked_on"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['dd_checked_on'])): ?>
    <p class="field-theirs" id="t-dd_checked_on"><?php if ($theirs['dd_checked_on']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['dd_checked_on']['by'], $theirs['dd_checked_on']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['dd_checked_on']['by']) ?><?php endif; ?></p>
<?php endif; ?>
    <label for="f-dd_checked_by"><?= $word('SUPPLIER_FORM', 'dd_by') ?> <span class="hint"><?= $word('SUPPLIER_FORM', 'dd_by_hint') ?></span></label>
    <select id="f-dd_checked_by" name="dd_checked_by"<?php if ($errorField === 'dd_checked_by'): ?> aria-invalid="true" aria-describedby="e-dd_checked_by"<?php endif; ?>>
      <option value=""><?= $word('SUPPLIER_FORM', 'dd_nobody') ?></option>
<?php foreach ($staff as $p): ?>
      <option value="<?= $e($p['id']) ?>"<?php if ($v['dd_checked_by'] === (string) $p['id']): ?> selected<?php endif; ?>><?php if ((int) $p['id'] === $meId): ?><?= $say('SUPPLIER_FORM', 'you', (string) $p['display_name']) ?><?php else: ?><?= $e($p['display_name']) ?><?php endif; ?></option>
<?php endforeach; ?>
    </select>
<?php if ($errorField === 'dd_checked_by'): ?>
    <p class="field-error" id="e-dd_checked_by"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['dd_checked_by'])): ?>
    <p class="field-theirs" id="t-dd_checked_by"><?php if ($theirs['dd_checked_by']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['dd_checked_by']['by'], $theirs['dd_checked_by']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['dd_checked_by']['by']) ?><?php endif; ?></p>
<?php endif; ?>
    <label for="f-dd_evidence"><?= $word('SUPPLIER_FORM', 'dd_what') ?></label>
    <textarea id="f-dd_evidence" name="dd_evidence" maxlength="1000" rows="3"<?php if ($errorField === 'dd_evidence'): ?> aria-invalid="true" aria-describedby="e-dd_evidence"<?php endif; ?>><?= $e($v['dd_evidence']) ?></textarea>
<?php if ($errorField === 'dd_evidence'): ?>
    <p class="field-error" id="e-dd_evidence"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['dd_evidence'])): ?>
    <p class="field-theirs" id="t-dd_evidence"><?php if ($theirs['dd_evidence']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['dd_evidence']['by'], $theirs['dd_evidence']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['dd_evidence']['by']) ?><?php endif; ?></p>
<?php endif; ?>
    <label for="f-dd_next_review_on"><?= $word('SUPPLIER_FORM', 'dd_next') ?></label>
    <input id="f-dd_next_review_on" type="date" name="dd_next_review_on" value="<?= $e($v['dd_next_review_on']) ?>"<?php if ($errorField === 'dd_next_review_on'): ?> aria-invalid="true" aria-describedby="e-dd_next_review_on"<?php endif; ?>>
<?php if ($errorField === 'dd_next_review_on'): ?>
    <p class="field-error" id="e-dd_next_review_on"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['dd_next_review_on'])): ?>
    <p class="field-theirs" id="t-dd_next_review_on"><?php if ($theirs['dd_next_review_on']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['dd_next_review_on']['by'], $theirs['dd_next_review_on']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['dd_next_review_on']['by']) ?><?php endif; ?></p>
<?php endif; ?>
    <p class="hint"><?= $word('SUPPLIER_FORM', 'dd_files') ?></p>
  </fieldset>
  <fieldset>
    <legend><?= $word('SUPPLIER_FORM', 'notes') ?></legend>
    <label for="f-notes" class="visually-hidden"><?= $word('SUPPLIER_FORM', 'notes') ?></label>
    <textarea id="f-notes" name="notes" maxlength="1000" rows="3"<?php if ($errorField === 'notes'): ?> aria-invalid="true" aria-describedby="e-notes"<?php endif; ?>><?= $e($v['notes']) ?></textarea>
<?php if ($errorField === 'notes'): ?>
    <p class="field-error" id="e-notes"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs['notes'])): ?>
    <p class="field-theirs" id="t-notes"><?php if ($theirs['notes']['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs['notes']['by'], $theirs['notes']['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs['notes']['by']) ?><?php endif; ?></p>
<?php endif; ?>
  </fieldset>
  <p class="actions"><button type="submit" class="primary"><?php if ($s === null): ?><?= $word('SUPPLIER_FORM', 'save_new') ?><?php else: ?><?= $word('SUPPLIER_FORM', 'save') ?><?php endif; ?></button><?php if ($s === null): ?> <span class="hint"><?= $word('SUPPLIER_FORM', 'save_new_hint') ?></span><?php endif; ?></p>
</form>
<p class="hint"><?= $word('SUPPLIER_FORM', 'no_bank') ?></p>
