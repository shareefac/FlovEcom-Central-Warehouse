<form class="decide" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
<?php if ($proposal !== null): ?>
  <input type="hidden" name="proposal_id" value="<?= $e($proposal['id']) ?>">
<?php endif; ?>
  <input type="hidden" name="sku_id" value="<?= $e($form['sku_id']) ?>">
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
  <fieldset id="f-action"<?php if ($error_field === 'action'): ?> class="invalid"<?php endif; ?>>
    <legend><?php if ($danger): ?><?= $word('LISTING', 'instead') ?><?php else: ?><?= $word('LISTING', 'question') ?><?php endif; ?></legend>
<?php if ($error_field === 'action' && $error !== null): ?>
    <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($offer_link): ?>
    <label class="choice"><input type="radio" name="action" value="link"<?php if ($form['action'] === 'link'): ?> checked<?php endif; ?><?php if (!$can_link): ?> disabled<?php endif; ?>>
      <?php if ($link_label !== null && $target !== null): ?><?= $e($link_label) ?><?php elseif ($target !== null): ?><?= $say('LISTING', 'yes', $target['code']) ?><?php else: ?><?= $word('LISTING', 'yes_none') ?><?php endif; ?></label>
<?php endif; ?>
    <label class="choice"><input type="radio" name="action" value="new_item"<?php if ($form['action'] === 'new_item'): ?> checked<?php endif; ?>>
      <?= $word('LISTING', 'new_item') ?></label>
    <label class="choice"><input type="radio" name="action" value="ignore"<?php if ($form['action'] === 'ignore'): ?> checked<?php endif; ?>>
      <?= $word('LISTING', 'ignore') ?></label>
<?php if ($offer_unlink ?? false): ?>
    <label class="choice"><input type="radio" name="action" value="unlink"<?php if ($form['action'] === 'unlink'): ?> checked<?php endif; ?>>
      <?= $word('LISTING', 'unlink') ?></label>
<?php endif; ?>
<?php if ($offer_reject ?? true): ?>
    <label class="choice"><input type="radio" name="action" value="reject"<?php if ($form['action'] === 'reject'): ?> checked<?php endif; ?><?php if ($target === null): ?> disabled<?php endif; ?>>
      <?php if ($target !== null): ?><?= $word('LISTING', 'reject') ?><?php else: ?><?= $word('LISTING', 'reject_none') ?><?php endif; ?></label>
<?php endif; ?>
  </fieldset>
<?php if ($error_field === 'sku_id' && $error !== null): ?>
  <p class="field-error"><?= $e($error) ?> <a href="#f-pick"><?= $word('LISTING', 'pick_other') ?></a></p>
<?php endif; ?>

  <div class="field">
    <div class="head-help"><label for="units"><?= $word('LISTING', 'units') ?></label> <?= $explain('sale_uses', \CW\Ui\Words::THING['units']) ?></div>
<?php if ($error_field === 'units_per_item' && $error !== null): ?>
    <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
    <input class="units" type="number" id="units" name="units_per_item" min="1" max="<?= $e($max_units) ?>" inputmode="numeric" value="<?= $e($form['units']) ?>" aria-describedby="units-hint" required<?php if ($error_field === 'units_per_item'): ?> aria-invalid="true"<?php endif; ?>>
    <p id="units-hint" class="hint"><?= $word('LISTING', 'units_hint') ?></p>
  </div>
<?php if ($protected): ?>
  <p class="note"><?= $word('LISTING', 'protected_note') ?></p>
<?php endif; ?>

  <label for="f-reason"><span><?= $word('LISTING', 'note') ?> <span class="hint"><?= $word('LISTING', 'note_hint') ?></span></span></label>
<?php if ($error_field === 'reason' && $error !== null): ?>
  <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
  <input type="text" id="f-reason" name="reason" maxlength="<?= $e($max_reason) ?>" value="<?= $e($form['reason']) ?>"<?php if ($error_field === 'reason'): ?> aria-invalid="true"<?php endif; ?>>

  <details class="fold new-product" id="new-product"<?php if ($form['action'] === 'new_item' || ($error_field !== null && str_starts_with($error_field, 'card.'))): ?> open<?php endif; ?>>
    <summary><?= $word('LISTING', 'new_details') ?></summary>
    <p class="hint"><?= $word('LISTING', 'new_details_hint') ?></p>
<?php foreach ($card_fields as $f): ?>
<?php $bad = $error_field === 'card.' . $f['name']; ?>
    <label for="card_<?= $e($f['name']) ?>"><?= $e($f['label']) ?></label>
<?php if ($bad && $error !== null): ?>
    <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($f['options'] !== null): ?>
    <select id="card_<?= $e($f['name']) ?>" name="card_<?= $e($f['name']) ?>"<?php if ($bad): ?> aria-invalid="true"<?php endif; ?>>
<?php foreach ($f['options'] as $value => $label): ?>
      <option value="<?= $e($value) ?>"<?php if ((string) $value === $f['value']): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
<?php else: ?>
    <input type="text" id="card_<?= $e($f['name']) ?>" name="card_<?= $e($f['name']) ?>" value="<?= $e($f['value']) ?>" maxlength="300"<?php if ($bad): ?> aria-invalid="true"<?php endif; ?>>
<?php endif; ?>
<?php endforeach; ?>
  </details>

  <p class="actions"><button type="submit" class="<?php if ($danger): ?>danger<?php else: ?>primary<?php endif; ?>"><?= $e($button) ?></button></p>
</form>
