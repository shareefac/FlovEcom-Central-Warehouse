    <label for="c-kind"><?= $word('CUSTOMER', 'f_kind') ?></label>
    <select id="c-kind" name="kind" required<?php if (isset($errors['kind'])): ?> aria-invalid="true" aria-describedby="e-kind"<?php endif; ?>>
<?php foreach ($kinds as $k): ?>
      <option value="<?= $e($k) ?>"<?php if ($v['kind'] === $k): ?> selected<?php endif; ?>><?= $word('CUSTOMER_KIND', $k) ?></option>
<?php endforeach; ?>
    </select>
<?php if (isset($errors['kind'])): ?>
    <p class="field-error" id="e-kind"><?= $e($errors['kind']) ?></p>
<?php endif; ?>
    <label for="c-name"><?= $word('CUSTOMER', 'f_name') ?> <span class="muted"><?= $word('CUSTOMER', 'f_name_hint') ?></span></label>
    <input id="c-name" type="text" name="name" value="<?= $e($v['name']) ?>" maxlength="160" required autocomplete="organization"<?php if (isset($errors['name'])): ?> aria-invalid="true" aria-describedby="e-name"<?php endif; ?>>
<?php if (isset($errors['name'])): ?>
    <p class="field-error" id="e-name"><?= $e($errors['name']) ?></p>
<?php endif; ?>
    <label for="c-address"><?= $word('CUSTOMER', 'f_address') ?> <span class="muted"><?= $word('CUSTOMER', 'f_address_hint') ?></span></label>
    <textarea id="c-address" name="address" rows="4" maxlength="900"<?php if (isset($errors['address'])): ?> aria-invalid="true" aria-describedby="e-address"<?php endif; ?>><?= $e($v['address']) ?></textarea>
<?php if (isset($errors['address'])): ?>
    <p class="field-error" id="e-address"><?= $e($errors['address']) ?></p>
<?php endif; ?>
    <label for="c-company_number"><?= $word('CUSTOMER', 'f_company_number') ?> <span class="muted"><?= $word('CUSTOMER', 'optional') ?></span></label>
    <input id="c-company_number" type="text" name="company_number" value="<?= $e($v['company_number']) ?>" maxlength="16" autocapitalize="characters" spellcheck="false"<?php if (isset($errors['company_number'])): ?> aria-invalid="true" aria-describedby="e-company_number"<?php endif; ?>>
<?php if (isset($errors['company_number'])): ?>
    <p class="field-error" id="e-company_number"><?= $e($errors['company_number']) ?></p>
<?php endif; ?>
    <label for="c-vat_number"><?= $word('CUSTOMER', 'f_vat_number') ?> <span class="muted"><?= $word('CUSTOMER', 'optional') ?></span></label>
    <input id="c-vat_number" type="text" name="vat_number" value="<?= $e($v['vat_number']) ?>" maxlength="24" autocapitalize="characters" spellcheck="false"<?php if (isset($errors['vat_number'])): ?> aria-invalid="true" aria-describedby="e-vat_number"<?php endif; ?>>
<?php if (isset($errors['vat_number'])): ?>
    <p class="field-error" id="e-vat_number"><?= $e($errors['vat_number']) ?></p>
<?php endif; ?>
    <label for="c-vat_code"><?= $word('CUSTOMER', 'f_vat_code') ?> <span class="muted"><?= $word('CUSTOMER', 'f_vat_code_hint') ?></span></label>
    <select id="c-vat_code" name="vat_code"<?php if (isset($errors['vat_code'])): ?> aria-invalid="true" aria-describedby="e-vat_code"<?php endif; ?>>
      <option value=""><?= $word('CUSTOMER', 'f_vat_code_company') ?></option>
<?php foreach ($vatCodes as $c): ?>
      <option value="<?= $e($c['code']) ?>"<?php if ($v['vat_code'] === $c['code']): ?> selected<?php endif; ?>><?= $e($c['code']) ?> &middot; <?= $e($c['label']) ?></option>
<?php endforeach; ?>
    </select>
<?php if (isset($errors['vat_code'])): ?>
    <p class="field-error" id="e-vat_code"><?= $e($errors['vat_code']) ?></p>
<?php endif; ?>
    <label for="c-email"><?= $word('CUSTOMER', 'f_email') ?> <span class="muted"><?= $word('CUSTOMER', 'optional') ?></span></label>
    <input id="c-email" type="email" name="email" value="<?= $e($v['email']) ?>" maxlength="191" autocomplete="email"<?php if (isset($errors['email'])): ?> aria-invalid="true" aria-describedby="e-email"<?php endif; ?>>
<?php if (isset($errors['email'])): ?>
    <p class="field-error" id="e-email"><?= $e($errors['email']) ?></p>
<?php endif; ?>
    <label for="c-phone"><?= $word('CUSTOMER', 'f_phone') ?> <span class="muted"><?= $word('CUSTOMER', 'optional') ?></span></label>
    <input id="c-phone" type="tel" name="phone" value="<?= $e($v['phone']) ?>" maxlength="32" autocomplete="tel"<?php if (isset($errors['phone'])): ?> aria-invalid="true" aria-describedby="e-phone"<?php endif; ?>>
<?php if (isset($errors['phone'])): ?>
    <p class="field-error" id="e-phone"><?= $e($errors['phone']) ?></p>
<?php endif; ?>
    <label for="c-note"><?= $word('CUSTOMER', 'f_note') ?> <span class="muted"><?= $word('CUSTOMER', 'optional') ?></span></label>
    <input id="c-note" type="text" name="note" value="<?= $e($v['note']) ?>" maxlength="500"<?php if (isset($errors['note'])): ?> aria-invalid="true" aria-describedby="e-note"<?php endif; ?>>
<?php if (isset($errors['note'])): ?>
    <p class="field-error" id="e-note"><?= $e($errors['note']) ?></p>
<?php endif; ?>
