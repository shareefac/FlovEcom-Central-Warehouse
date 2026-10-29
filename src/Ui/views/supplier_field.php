<?php
// One field of the supplier form (docs/decisions.md W9.4): its label, its box, the refusal under it and, after a stale save, what someone
// else saved meanwhile. A cell of the section's two-column grid (`wide`: across both). $k: the field (Suppliers::FIELDS); $label: its
// Words::SUPPLIER_FORM key; $control: text, email, number, date, textarea, vat, staff, country or tick; $max (length or largest number),
// $min, $required, $wide, $short; the form's $v, $errorField, $fieldError, $theirs, $vatCodes, $staff, $meId.
$max = $max ?? null; $min = $min ?? null; $required = $required ?? false; $wide = $wide ?? false; $short = $short ?? false;
$invalid = $errorField === $k;
?>
<div class="doc-field<?php if ($wide): ?> doc-wide<?php endif; ?>">
<?php if ($control === 'tick'): ?>
  <label class="choice"><input id="f-<?= $e($k) ?>" type="checkbox" name="<?= $e($k) ?>" value="1"<?php if ($v[$k] === '1'): ?> checked<?php endif; ?><?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>> <?= $word('SUPPLIER_FORM', $label) ?></label>
<?php else: ?>
  <label for="f-<?= $e($k) ?>"><?= $word('SUPPLIER_FORM', $label) ?></label>
<?php if ($control === 'textarea'): ?>
  <textarea id="f-<?= $e($k) ?>" name="<?= $e($k) ?>" maxlength="<?= $e($max) ?>" rows="3"<?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>><?= $e($v[$k]) ?></textarea>
<?php elseif ($control === 'country'): ?>
  <select id="f-<?= $e($k) ?>" name="<?= $e($k) ?>" autocomplete="country"<?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php if ($v[$k] !== '' && !isset(\CW\Ui\Words::COUNTRY[$v[$k]])): ?>
    <option value="<?= $e($v[$k]) ?>" selected><?= $e($v[$k]) ?></option>
<?php endif; ?>
    <option value="GB"<?php if ($v[$k] === 'GB' || $v[$k] === ''): ?> selected<?php endif; ?>><?= $e(\CW\Ui\Words::COUNTRY['GB']) ?></option>
<?php foreach (\CW\Ui\Words::COUNTRY as $code => $name): ?>
<?php if ($code !== 'GB'): ?>
    <option value="<?= $e($code) ?>"<?php if ($v[$k] === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endif; ?>
<?php endforeach; ?>
  </select>
<?php elseif ($control === 'vat'): ?>
  <select id="f-<?= $e($k) ?>" name="<?= $e($k) ?>"<?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php foreach ($vatCodes as $c): ?>
    <option value="<?= $e($c['code']) ?>"<?php if ($v[$k] === $c['code']): ?> selected<?php endif; ?>><?= $e($c['code']) ?> – <?= $e($c['label']) ?></option>
<?php endforeach; ?>
  </select>
<?php elseif ($control === 'staff'): ?>
  <select id="f-<?= $e($k) ?>" name="<?= $e($k) ?>"<?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
    <option value=""><?= $word('SUPPLIER_FORM', 'dd_nobody') ?></option>
<?php foreach ($staff as $p): ?>
    <option value="<?= $e($p['id']) ?>"<?php if ($v[$k] === (string) $p['id']): ?> selected<?php endif; ?>><?php if ((int) $p['id'] === $meId): ?><?= $say('SUPPLIER_FORM', 'you', (string) $p['display_name']) ?><?php else: ?><?= $e($p['display_name']) ?><?php endif; ?></option>
<?php endforeach; ?>
  </select>
<?php elseif ($control === 'number'): ?>
  <input id="f-<?= $e($k) ?>" type="number" name="<?= $e($k) ?>" value="<?= $e($v[$k]) ?>" min="<?= $e($min) ?>" max="<?= $e($max) ?>" class="short"<?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php elseif ($control === 'date'): ?>
  <input id="f-<?= $e($k) ?>" type="date" name="<?= $e($k) ?>" value="<?= $e($v[$k]) ?>"<?php if ($max !== null): ?> max="<?= $e($max) ?>"<?php endif; ?><?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php else: ?>
  <input id="f-<?= $e($k) ?>" type="<?= $e($control) ?>" name="<?= $e($k) ?>" value="<?= $e($v[$k]) ?>" maxlength="<?= $e($max) ?>"<?php if ($short): ?> class="short" inputmode="decimal"<?php endif; ?><?php if ($required): ?> required<?php endif; ?><?php if ($invalid): ?> aria-invalid="true" aria-describedby="e-<?= $e($k) ?>"<?php endif; ?>>
<?php endif; ?>
<?php endif; ?>
<?php if ($invalid): ?>
  <p class="field-error" id="e-<?= $e($k) ?>"><?= $e($fieldError) ?></p>
<?php endif; ?>
<?php if (isset($theirs[$k])): ?>
  <p class="field-theirs" id="t-<?= $e($k) ?>"><?php if ($theirs[$k]['kept']): ?><?= $say('SUPPLIER_FORM', 'theirs_kept', $theirs[$k]['by'], $theirs[$k]['now']) ?><?php else: ?><?= $say('SUPPLIER_FORM', 'theirs_taken', $theirs[$k]['by']) ?><?php endif; ?></p>
<?php endif; ?>
</div>
