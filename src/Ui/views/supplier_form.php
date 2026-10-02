<p class="crumbs"><a href="/ui/purchasing/suppliers">Suppliers</a><?php if ($s !== null): ?> <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id']) ?>">Back to <?= $e($s['code']) ?></a><?php endif; ?></p>
<h1><?php if ($s === null): ?>New supplier<?php else: ?>Edit <?= $e($s['code']) ?> <span class="muted"><?= $e($s['name']) ?></span><?php endif; ?></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($s !== null && $s['status'] === 'active'): ?>
<p class="note">This supplier is active: a change to its name, legal or registration details, address, e-mail, due diligence or import route is
  reviewed by a second person afterwards, and a change of an overseas supplier's import route, or of whether it is overseas at all, stops its
  purchase orders until a second person approves it.</p>
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
    <legend>Supplier</legend>
    <label>Name (required) <input type="text" name="name" value="<?= $e($v['name']) ?>" maxlength="128" required></label>
    <label>Code <span class="muted">(2-16 capitals or digits; left empty, it is made from the name)</span> <input type="text" name="code" value="<?= $e($v['code']) ?>" maxlength="16"></label>
    <label>Legal name <input type="text" name="legal_name" value="<?= $e($v['legal_name']) ?>" maxlength="160"></label>
    <label>Company number <input type="text" name="company_number" value="<?= $e($v['company_number']) ?>" maxlength="16"></label>
    <label>VAT number <input type="text" name="vat_number" value="<?= $e($v['vat_number']) ?>" maxlength="20"></label>
  </fieldset>
  <fieldset>
    <legend>Address</legend>
    <label>Address line 1 <input type="text" name="address_line1" value="<?= $e($v['address_line1']) ?>" maxlength="128"></label>
    <label>Address line 2 <input type="text" name="address_line2" value="<?= $e($v['address_line2']) ?>" maxlength="128"></label>
    <label>Town / city <input type="text" name="city" value="<?= $e($v['city']) ?>" maxlength="64"></label>
    <label>Postcode <input type="text" name="postcode" value="<?= $e($v['postcode']) ?>" maxlength="16"></label>
    <label>Country (two letters: GB, CN, ...) <input type="text" name="country" value="<?= $e($v['country']) ?>" maxlength="2"></label>
  </fieldset>
  <fieldset>
    <legend>Contact</legend>
    <label>Contact name <input type="text" name="contact_name" value="<?= $e($v['contact_name']) ?>" maxlength="128"></label>
    <label>E-mail (purchase orders go here) <input type="email" name="email" value="<?= $e($v['email']) ?>" maxlength="191"></label>
    <label>Phone <input type="text" name="phone" value="<?= $e($v['phone']) ?>" maxlength="32"></label>
    <label>Other contacts <textarea name="contacts_note" maxlength="500" rows="2"><?= $e($v['contacts_note']) ?></textarea></label>
  </fieldset>
  <fieldset>
    <legend>Terms</legend>
    <label>Payment terms <input type="text" name="payment_terms" value="<?= $e($v['payment_terms']) ?>" maxlength="100"></label>
    <label>Payment days <input type="number" name="payment_terms_days" value="<?= $e($v['payment_terms_days']) ?>" min="0" max="365"></label>
    <label>Lead days (order to delivery) <input type="number" name="default_lead_days" value="<?= $e($v['default_lead_days']) ?>" min="0" max="120"></label>
    <label>Order cycle (days between orders) <input type="number" name="review_days" value="<?= $e($v['review_days']) ?>" min="1" max="120"></label>
    <label>Minimum order value (GBP) <input type="text" name="min_order_value" value="<?= $e($v['min_order_value']) ?>" inputmode="decimal" maxlength="16"></label>
    <label>Default VAT code
      <select name="default_vat_code">
<?php foreach ($vatCodes as $c): ?>
        <option value="<?= $e($c['code']) ?>"<?php if ($v['default_vat_code'] === $c['code']): ?> selected<?php endif; ?>><?= $e($c['code']) ?>: <?= $e($c['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
  </fieldset>
  <fieldset>
    <legend>Overseas and the import route</legend>
    <label class="choice"><input type="checkbox" name="is_overseas" value="1"<?php if ($v['is_overseas'] === '1'): ?> checked<?php endif; ?>> Overseas supplier (we import; required when the country is not GB)</label>
    <label>Import route: how and where UK duty stamps are applied before the goods arrive (required for an overseas supplier)
      <textarea name="import_route" maxlength="1000" rows="3"><?= $e($v['import_route']) ?></textarea></label>
  </fieldset>
  <fieldset>
    <legend>Due diligence</legend>
    <label>Checked on <input type="date" name="dd_checked_on" value="<?= $e($v['dd_checked_on']) ?>" max="<?= $e($today) ?>"></label>
    <label>Checked by
      <select name="dd_checked_by">
        <option value="">Not checked yet</option>
<?php foreach ($staff as $p): ?>
        <option value="<?= $e($p['id']) ?>"<?php if ($v['dd_checked_by'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['display_name']) ?><?php if ((int) $p['id'] === $meId): ?> (you)<?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Evidence (what was checked: Companies House, VAT number, HMRC duty-stamp registration, references ...)
      <textarea name="dd_evidence" maxlength="1000" rows="3"><?= $e($v['dd_evidence']) ?></textarea></label>
    <label>Next review on <input type="date" name="dd_next_review_on" value="<?= $e($v['dd_next_review_on']) ?>"></label>
    <p class="muted">Evidence files are uploaded on the supplier's page once it is saved.</p>
  </fieldset>
  <fieldset>
    <legend>Notes</legend>
    <label>Notes <textarea name="notes" maxlength="1000" rows="3"><?= $e($v['notes']) ?></textarea></label>
  </fieldset>
  <p class="actions"><button type="submit" class="primary"><?php if ($s === null): ?>Create the supplier (draft)<?php else: ?>Save<?php endif; ?></button></p>
</form>
<p class="muted">No bank details are kept in CW: supplier payments stay outside it.</p>
