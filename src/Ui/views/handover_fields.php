    <div class="field">
      <label for="h-counterparty"><?= $word('HANDOVER', 'f_customer') ?></label>
      <select id="h-counterparty" name="counterparty" required>
        <option value=""><?= $word('HANDOVER', 'choose') ?></option>
<?php foreach ($form['customers'] as $c): ?>
        <option value="<?= $e($c['id']) ?>"<?php if ($form['counterparty'] === (string) $c['id']): ?> selected<?php endif; ?>><?= $e($c['name']) ?> &middot; <?= $word('CUSTOMER_KIND', $c['kind']) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="h-warehouse"><?= $word('HANDOVER', 'f_warehouse') ?></label>
      <select id="h-warehouse" name="warehouse" required>
<?php foreach ($form['warehouses'] as $w): ?>
        <option value="<?= $e($w['id']) ?>"<?php if ($form['warehouse'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="h-vat"><?= $word('HANDOVER', 'f_vat_code') ?> <span class="muted"><?php if ($form['notRegistered']): ?><?= $word('HANDOVER', 'f_vat_not_registered') ?><?php else: ?><?= $word('HANDOVER', 'f_vat_hint') ?><?php endif; ?></span></label>
      <select id="h-vat" name="vat_code">
        <option value=""><?php if ($form['companyVat'] !== null): ?><?= $say('HANDOVER', 'f_vat_default', $form['companyVat']) ?><?php else: ?><?= $word('HANDOVER', 'f_vat_default_none') ?><?php endif; ?></option>
<?php foreach ($form['vatCodes'] as $c): ?>
        <option value="<?= $e($c['code']) ?>"<?php if ($form['vat_code'] === $c['code']): ?> selected<?php endif; ?>><?= $e($c['code']) ?> &middot; <?= $e($c['label']) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="h-ref"><?= $word('HANDOVER', 'f_their_ref') ?> <span class="muted"><?= $word('HANDOVER', 'optional') ?></span></label>
      <input id="h-ref" type="text" name="external_ref" value="<?= $e($form['external_ref']) ?>" maxlength="100">
    </div>
    <div class="field">
      <label for="h-date"><?= $word('HANDOVER', 'f_date') ?></label>
      <input id="h-date" type="date" name="doc_date" value="<?= $e($form['doc_date']) ?>">
    </div>
    <div class="field">
      <label for="h-note"><?= $word('HANDOVER', 'f_note') ?> <span class="muted"><?= $word('HANDOVER', 'optional') ?></span></label>
      <input id="h-note" type="text" name="note" value="<?= $e($form['note']) ?>" maxlength="1000">
    </div>
