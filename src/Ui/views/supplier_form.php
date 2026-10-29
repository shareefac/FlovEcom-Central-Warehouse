<?php
// A new supplier, or a supplier's details changed, on one page (the owner's approved one-page form, PI_New / PO_New; docs/decisions.md
// W9.4): the 27 fields staged in three sections of two columns (Details with the country picker, Terms, Background check), a refused field
// marked under it, what someone else saved meanwhile listed at the top and marked at its field, then the bar at the bottom: "Leave without
// saving" and the one Save. The form posts every field where it did; typed values are guarded (data-unsaved).
$field = static fn (string $k, string $label, string $control, array $more = []): array => ['k' => $k, 'label' => $label, 'control' => $control] + $more;
$sections = [
    'details' => [
        $field('name', 'name', 'text', ['max' => 128, 'required' => true]), $field('code', 'code', 'text', ['max' => 16]),
        $field('legal_name', 'legal_name', 'text', ['max' => 160]), $field('company_number', 'company_number', 'text', ['max' => 16]),
        $field('vat_number', 'vat_number', 'text', ['max' => 20]), $field('country', 'country', 'country'),
        $field('address_line1', 'address_line1', 'text', ['max' => 128]), $field('address_line2', 'address_line2', 'text', ['max' => 128]),
        $field('city', 'city', 'text', ['max' => 64]), $field('postcode', 'postcode', 'text', ['max' => 16]),
        $field('contact_name', 'contact_name', 'text', ['max' => 128]), $field('email', 'email', 'email', ['max' => 191]),
        $field('phone', 'phone', 'text', ['max' => 32]), $field('contacts_note', 'contacts_note', 'textarea', ['max' => 500, 'wide' => true]),
        $field('notes', 'notes', 'textarea', ['max' => 1000, 'wide' => true]),
    ],
    'terms' => [
        $field('payment_terms', 'payment_terms', 'text', ['max' => 100]), $field('payment_terms_days', 'payment_days', 'number', ['min' => 0, 'max' => 365]),
        $field('default_lead_days', 'lead', 'number', ['min' => 0, 'max' => 120]), $field('review_days', 'every', 'number', ['min' => 1, 'max' => 120]),
        $field('min_order_value', 'min_order', 'text', ['max' => 16, 'short' => true]), $field('default_vat_code', 'vat', 'vat'),
    ],
    'dd' => [
        $field('dd_checked_on', 'dd_on', 'date', ['max' => $today]), $field('dd_checked_by', 'dd_by', 'staff'),
        $field('dd_next_review_on', 'dd_next', 'date'), $field('dd_evidence', 'dd_what', 'textarea', ['max' => 1000, 'wide' => true]),
        $field('is_overseas', 'abroad_tick', 'tick', ['wide' => true]), $field('import_route', 'route', 'textarea', ['max' => 1000, 'wide' => true]),
    ],
];
?>
<p class="crumbs"><a href="/ui/purchasing/suppliers"><?= $word('MENU', 'suppliers') ?></a><?php if ($s !== null): ?> <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id']) ?>"><?= $say('SUPPLIER_FORM', 'back', (string) $s['name']) ?></a><?php endif; ?></p>
<?= $head((string) $title, intro: 'supplier_form', side: '') ?>
<?php if ($error !== null && $theirs !== []): ?>
<div class="error" role="alert">
  <p><?= $e($error) ?></p>
  <p><?= $word('SUPPLIER_FORM', 'theirs_list') ?></p>
  <ul class="plain changes">
<?php foreach ($theirs as $f => $t): ?>
    <li><a href="#f-<?= $e($f) ?>"><?= $say('SUPPLIER_FORM', 'theirs_line', $t['label'], $t['now'], $t['by'], $t['at']) ?></a></li>
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
<form class="doc-details" method="post" action="<?= $e($action) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>"<?php if ($error !== null): ?> data-dirty="1"<?php endif; ?>>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($formKey !== null): ?>
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
<?php endif; ?>
<?php if ($version !== null): ?>
  <input type="hidden" name="version" value="<?= $e($version) ?>">
<?php endif; ?>
<?php foreach ($sections as $key => $fields): ?>
  <fieldset class="doc-grid<?php if ($key === 'dd' && in_array($errorField, ['is_overseas', 'import_route'], true)): ?> invalid<?php endif; ?>" id="sf-<?= $e($key) ?>">
    <legend><?= $word('SUPPLIER_FORM', 'sec_' . $key) ?></legend>
<?php foreach ($fields as $f): ?>
<?= $partial('supplier_field', $f + ['v' => $v, 'errorField' => $errorField, 'fieldError' => $fieldError, 'theirs' => $theirs, 'vatCodes' => $vatCodes, 'staff' => $staff, 'meId' => $meId]) ?>
<?php endforeach; ?>
  </fieldset>
<?php endforeach; ?>
  <p class="muted"><?= $word('SUPPLIER_FORM', 'no_bank') ?></p>
<?= $partial('doc_bar', ['back' => $s === null ? '/ui/purchasing/suppliers' : '/ui/purchasing/suppliers/' . $s['id'],
    'buttons' => [['value' => 'save', 'label' => $s === null ? \CW\Ui\Words::SUPPLIER_FORM['save_new'] : \CW\Ui\Words::SUPPLIER_FORM['save'], 'primary' => true]]]) ?>
</form>
