<p class="crumbs"><a href="/ui/reference/company"><?= $word('COMPANY', 'back') ?></a></p>
<h1><?= $word('PAGE_TITLE', 'company_edit') ?></h1>
<?= $intro('company_edit') ?>
<p class="muted"><?= $word('COMPANY', 'until_confirmed') ?></p>
<?php if ($error !== null): ?>
<div class="error" role="alert">
<?php if ($errorCode === 'company_invalid'): ?>
  <p><?= $word('COMPANY', 'invalid') ?></p>
<?php else: ?>
  <p><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($changes !== []): ?>
  <p><?= $word('COMPANY', 'changed_meanwhile') ?></p>
  <ul class="plain changes">
<?php foreach ($changes as $c): ?>
    <li><span class="field"><?= $e(ucfirst($c['label'])) ?></span>
      <span class="was"><?= $word('COMPANY', 'was') ?> <span class="pre"><?php if ($c['before'] === ''): ?><?= $word('COMPANY', 'empty') ?><?php else: ?><?= $e($c['before']) ?><?php endif; ?></span></span>
      <span class="now"><?= $word('COMPANY', 'now') ?> <span class="pre"><?php if ($c['after'] === ''): ?><?= $word('COMPANY', 'empty') ?><?php else: ?><?= $e($c['after']) ?><?php endif; ?></span></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($confirmed): ?>
<p class="note"><?= $word('COMPANY', 'is_confirmed') ?></p>
<?php endif; ?>
<form class="record company" method="post" action="/ui/reference/company">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <fieldset>
    <legend><?= $word('COMPANY', 'the_company') ?></legend>
    <label for="f-legal_name"><?= $word('COMPANY', 'legal_name') ?> <span class="muted"><?= $word('COMPANY', 'legal_name_hint') ?></span></label>
    <input id="f-legal_name" type="text" name="legal_name" value="<?= $e($v['legal_name']) ?>" maxlength="<?= $e($limits['name']) ?>" autocomplete="organization"<?php if (isset($errors['legal_name'])): ?> aria-invalid="true" aria-describedby="e-legal_name"<?php endif; ?>>
<?php if (isset($errors['legal_name'])): ?>
    <p class="field-error" id="e-legal_name"><?= $e($errors['legal_name']) ?></p>
<?php endif; ?>
    <label for="f-trading_name"><?= $word('COMPANY', 'trading_name') ?> <span class="muted"><?= $word('COMPANY', 'trading_name_hint') ?></span></label>
    <input id="f-trading_name" type="text" name="trading_name" value="<?= $e($v['trading_name']) ?>" maxlength="<?= $e($limits['name']) ?>"<?php if (isset($errors['trading_name'])): ?> aria-invalid="true" aria-describedby="e-trading_name"<?php endif; ?>>
<?php if (isset($errors['trading_name'])): ?>
    <p class="field-error" id="e-trading_name"><?= $e($errors['trading_name']) ?></p>
<?php endif; ?>
    <label for="f-company_number"><?= $word('COMPANY', 'company_number') ?> <span class="muted"><?= $word('COMPANY', 'company_number_hint') ?></span></label>
    <input id="f-company_number" type="text" name="company_number" value="<?= $e($v['company_number']) ?>" maxlength="16" autocapitalize="characters" spellcheck="false"<?php if (isset($errors['company_number'])): ?> aria-invalid="true" aria-describedby="e-company_number"<?php endif; ?>>
<?php if (isset($errors['company_number'])): ?>
    <p class="field-error" id="e-company_number"><?= $e($errors['company_number']) ?></p>
<?php endif; ?>
    <label for="f-address"><?= $word('COMPANY', 'address') ?> <span class="muted"><?= $say('COMPANY', 'address_hint', (int) $limits['lines']) ?></span></label>
    <textarea id="f-address" name="address" rows="5" maxlength="<?= $e($limits['address']) ?>" autocomplete="street-address"<?php if (isset($errors['address'])): ?> aria-invalid="true" aria-describedby="e-address"<?php endif; ?>><?= $e($v['address']) ?></textarea>
<?php if (isset($errors['address'])): ?>
    <p class="field-error" id="e-address"><?= $e($errors['address']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset<?php if (isset($errors['vat_number'])): ?> aria-describedby="e-vat_number"<?php endif; ?>>
    <legend><?= $word('COMPANY', 'vat') ?></legend>
    <label class="choice"><input type="radio" name="vat_registered" value="yes"<?php if ($v['vat_registered'] === 'yes'): ?> checked<?php endif; ?>> <?= $word('COMPANY', 'vat_registered') ?></label>
    <label class="choice"><input type="radio" name="vat_registered" value="no"<?php if ($v['vat_registered'] === 'no'): ?> checked<?php endif; ?>> <?= $word('COMPANY', 'vat_not') ?> <span class="muted"><?= $word('COMPANY', 'vat_not_hint') ?></span></label>
    <label class="choice"><input type="radio" name="vat_registered" value=""<?php if ($v['vat_registered'] !== 'yes' && $v['vat_registered'] !== 'no'): ?> checked<?php endif; ?>> <?= $word('COMPANY', 'vat_unknown') ?></label>
    <label for="f-vat_number"><?= $word('COMPANY', 'vat_number') ?> <span class="muted"><?= $word('COMPANY', 'vat_number_hint') ?></span></label>
    <input id="f-vat_number" type="text" name="vat_number" value="<?= $e($v['vat_number']) ?>" maxlength="24" autocapitalize="characters" spellcheck="false"<?php if (isset($errors['vat_number'])): ?> aria-invalid="true"<?php endif; ?>>
<?php if (isset($errors['vat_number'])): ?>
    <p class="field-error" id="e-vat_number"><?= $e($errors['vat_number']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('COMPANY', 'contact') ?></legend>
    <label for="f-phone"><?= $word('COMPANY', 'phone_label') ?> <span class="muted"><?= $word('COMPANY', 'optional') ?></span></label>
    <input id="f-phone" type="tel" name="phone" value="<?= $e($v['phone']) ?>" maxlength="<?= $e($limits['phone']) ?>" autocomplete="tel"<?php if (isset($errors['phone'])): ?> aria-invalid="true" aria-describedby="e-phone"<?php endif; ?>>
<?php if (isset($errors['phone'])): ?>
    <p class="field-error" id="e-phone"><?= $e($errors['phone']) ?></p>
<?php endif; ?>
    <label for="f-email"><?= $word('COMPANY', 'email_label') ?> <span class="muted"><?= $word('COMPANY', 'email_hint') ?></span></label>
    <input id="f-email" type="email" name="email" value="<?= $e($v['email']) ?>" maxlength="<?= $e($limits['email']) ?>" autocomplete="email"<?php if (isset($errors['email'])): ?> aria-invalid="true" aria-describedby="e-email"<?php endif; ?>>
<?php if (isset($errors['email'])): ?>
    <p class="field-error" id="e-email"><?= $e($errors['email']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('COMPANY', 'delivery') ?></legend>
    <label for="f-delivery_address"><?= $word('COMPANY', 'delivery_address') ?> <span class="muted"><?= $say('COMPANY', 'delivery_hint', (int) $limits['lines']) ?></span></label>
    <textarea id="f-delivery_address" name="delivery_address" rows="5" maxlength="<?= $e($limits['address']) ?>"<?php if (isset($errors['delivery_address'])): ?> aria-invalid="true" aria-describedby="e-delivery_address"<?php endif; ?>><?= $e($v['delivery_address']) ?></textarea>
<?php if (isset($errors['delivery_address'])): ?>
    <p class="field-error" id="e-delivery_address"><?= $e($errors['delivery_address']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend><?= $word('COMPANY', 'why_legend') ?></legend>
    <label for="f-reason"><?= $word('COMPANY', 'reason') ?> <span class="muted"><?= $word('COMPANY', 'reason_hint') ?></span></label>
    <input id="f-reason" type="text" name="reason" value="<?= $e($reason) ?>" maxlength="<?= $e($limits['reason']) ?>"<?php if (isset($errors['reason'])): ?> aria-invalid="true" aria-describedby="e-reason"<?php endif; ?>>
<?php if (isset($errors['reason'])): ?>
    <p class="field-error" id="e-reason"><?= $e($errors['reason']) ?></p>
<?php endif; ?>
  </fieldset>
  <p class="actions"><button type="submit" class="primary"><?= $word('COMPANY', 'save') ?></button> <a href="/ui/reference/company"><?= $word('COMPANY', 'cancel') ?></a></p>
</form>
<p class="muted"><?= $word('COMPANY', 'letters') ?></p>
