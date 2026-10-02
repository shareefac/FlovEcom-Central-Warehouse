<p class="crumbs"><a href="/ui/reference/company">Back to the company details</a></p>
<h1>Change the company details</h1>
<p class="muted">What every purchase order prints. Saving a change makes the details "not confirmed" until someone confirms them again (the PDFs say
  "do not send" meanwhile). Orders already approved keep the details they were approved with.</p>
<?php if ($error !== null): ?>
<div class="error" role="alert">
<?php if ($errorCode === 'company_invalid'): ?>
  <p>Nothing was saved: some details need correcting (marked below).</p>
<?php else: ?>
  <p><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($changes !== []): ?>
  <p>What changed meanwhile (your form still shows what you typed; saving it now replaces these):</p>
  <ul class="plain changes">
<?php foreach ($changes as $c): ?>
    <li><span class="field"><?= $e(ucfirst($c['label'])) ?></span>
      <span class="was">Was: <span class="pre"><?php if ($c['before'] === ''): ?>(empty)<?php else: ?><?= $e($c['before']) ?><?php endif; ?></span></span>
      <span class="now">Now: <span class="pre"><?php if ($c['after'] === ''): ?>(empty)<?php else: ?><?= $e($c['after']) ?><?php endif; ?></span></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($confirmed): ?>
<p class="note">These details are confirmed. Saving a change makes them "not confirmed" until someone confirms them again. If you confirm your own
  change of the legal name, company number, VAT, purchasing e-mail or delivery address, another reviewer is asked to check it.</p>
<?php endif; ?>
<form class="record company" method="post" action="/ui/reference/company">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <fieldset>
    <legend>The company</legend>
    <label for="f-legal_name">Legal name <span class="muted">(as registered at Companies House, for example Example Vapes Ltd)</span></label>
    <input id="f-legal_name" type="text" name="legal_name" value="<?= $e($v['legal_name']) ?>" maxlength="<?= $e($limits['name']) ?>" autocomplete="organization"<?php if (isset($errors['legal_name'])): ?> aria-invalid="true" aria-describedby="e-legal_name"<?php endif; ?>>
<?php if (isset($errors['legal_name'])): ?>
    <p class="field-error" id="e-legal_name"><?= $e($errors['legal_name']) ?></p>
<?php endif; ?>
    <label for="f-trading_name">Trading name <span class="muted">(optional: the name customers know, printed under the legal name)</span></label>
    <input id="f-trading_name" type="text" name="trading_name" value="<?= $e($v['trading_name']) ?>" maxlength="<?= $e($limits['name']) ?>"<?php if (isset($errors['trading_name'])): ?> aria-invalid="true" aria-describedby="e-trading_name"<?php endif; ?>>
<?php if (isset($errors['trading_name'])): ?>
    <p class="field-error" id="e-trading_name"><?= $e($errors['trading_name']) ?></p>
<?php endif; ?>
    <label for="f-company_number">Company number <span class="muted">(8 characters, for example 01234567 or SC123456)</span></label>
    <input id="f-company_number" type="text" name="company_number" value="<?= $e($v['company_number']) ?>" maxlength="16" autocapitalize="characters" spellcheck="false"<?php if (isset($errors['company_number'])): ?> aria-invalid="true" aria-describedby="e-company_number"<?php endif; ?>>
<?php if (isset($errors['company_number'])): ?>
    <p class="field-error" id="e-company_number"><?= $e($errors['company_number']) ?></p>
<?php endif; ?>
    <label for="f-address">Registered address <span class="muted">(one line per line, up to <?= $n($limits['lines']) ?> lines)</span></label>
    <textarea id="f-address" name="address" rows="5" maxlength="<?= $e($limits['address']) ?>" autocomplete="street-address"<?php if (isset($errors['address'])): ?> aria-invalid="true" aria-describedby="e-address"<?php endif; ?>><?= $e($v['address']) ?></textarea>
<?php if (isset($errors['address'])): ?>
    <p class="field-error" id="e-address"><?= $e($errors['address']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset<?php if (isset($errors['vat_number'])): ?> aria-describedby="e-vat_number"<?php endif; ?>>
    <legend>VAT</legend>
    <label class="choice"><input type="radio" name="vat_registered" value="yes"<?php if ($v['vat_registered'] === 'yes'): ?> checked<?php endif; ?>> VAT registered</label>
    <label class="choice"><input type="radio" name="vat_registered" value="no"<?php if ($v['vat_registered'] === 'no'): ?> checked<?php endif; ?>> Not VAT registered <span class="muted">(no VAT number is printed)</span></label>
    <label class="choice"><input type="radio" name="vat_registered" value=""<?php if ($v['vat_registered'] !== 'yes' && $v['vat_registered'] !== 'no'): ?> checked<?php endif; ?>> Not known yet</label>
    <label for="f-vat_number">VAT number <span class="muted">(if VAT registered, for example GB 123 4567 89; spaces are fine)</span></label>
    <input id="f-vat_number" type="text" name="vat_number" value="<?= $e($v['vat_number']) ?>" maxlength="24" autocapitalize="characters" spellcheck="false"<?php if (isset($errors['vat_number'])): ?> aria-invalid="true"<?php endif; ?>>
<?php if (isset($errors['vat_number'])): ?>
    <p class="field-error" id="e-vat_number"><?= $e($errors['vat_number']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend>Purchasing contact</legend>
    <label for="f-phone">Phone <span class="muted">(optional)</span></label>
    <input id="f-phone" type="tel" name="phone" value="<?= $e($v['phone']) ?>" maxlength="<?= $e($limits['phone']) ?>" autocomplete="tel"<?php if (isset($errors['phone'])): ?> aria-invalid="true" aria-describedby="e-phone"<?php endif; ?>>
<?php if (isset($errors['phone'])): ?>
    <p class="field-error" id="e-phone"><?= $e($errors['phone']) ?></p>
<?php endif; ?>
    <label for="f-email">E-mail <span class="muted">(where suppliers reply about orders)</span></label>
    <input id="f-email" type="email" name="email" value="<?= $e($v['email']) ?>" maxlength="<?= $e($limits['email']) ?>" autocomplete="email"<?php if (isset($errors['email'])): ?> aria-invalid="true" aria-describedby="e-email"<?php endif; ?>>
<?php if (isset($errors['email'])): ?>
    <p class="field-error" id="e-email"><?= $e($errors['email']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend>Delivery</legend>
    <label for="f-delivery_address">Delivery address <span class="muted">(the warehouse goods are delivered to, printed in the "Deliver to" box; up to <?= $n($limits['lines']) ?> lines)</span></label>
    <textarea id="f-delivery_address" name="delivery_address" rows="5" maxlength="<?= $e($limits['address']) ?>"<?php if (isset($errors['delivery_address'])): ?> aria-invalid="true" aria-describedby="e-delivery_address"<?php endif; ?>><?= $e($v['delivery_address']) ?></textarea>
<?php if (isset($errors['delivery_address'])): ?>
    <p class="field-error" id="e-delivery_address"><?= $e($errors['delivery_address']) ?></p>
<?php endif; ?>
  </fieldset>
  <fieldset>
    <legend>Why</legend>
    <label for="f-reason">Reason for the change <span class="muted">(optional, kept in the history)</span></label>
    <input id="f-reason" type="text" name="reason" value="<?= $e($reason) ?>" maxlength="<?= $e($limits['reason']) ?>"<?php if (isset($errors['reason'])): ?> aria-invalid="true" aria-describedby="e-reason"<?php endif; ?>>
<?php if (isset($errors['reason'])): ?>
    <p class="field-error" id="e-reason"><?= $e($errors['reason']) ?></p>
<?php endif; ?>
  </fieldset>
  <p class="actions"><button type="submit" class="primary">Save</button> <a href="/ui/reference/company">Cancel</a></p>
</form>
<p class="muted">Letters such as é, ü, ß, £ and € print on the PDF; other alphabets and emoji do not, so they are refused. We never keep bank
  details here.</p>
