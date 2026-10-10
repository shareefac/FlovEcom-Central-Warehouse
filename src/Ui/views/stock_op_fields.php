<label><?php if ($form['hasTo']): ?><?= $word('STOCK_OPS', 'from') ?><?php else: ?><?= $word('STOCK_OPS', 'warehouse') ?><?php endif; ?>
  <select name="warehouse" required>
<?php foreach ($form['from'] as $w): ?>
    <option value="<?= $e($w['id']) ?>"<?php if ($form['warehouse'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['label']) ?></option>
<?php endforeach; ?>
  </select>
</label>
<label><?= $word('STOCK_OPS', 'place') ?>
  <select name="location">
    <option value=""><?= $word('STOCK_OPS', 'no_place') ?></option>
<?php foreach ($form['places'] as $p): ?>
    <option value="<?= $e($p['id']) ?>"<?php if ($form['location'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['label']) ?></option>
<?php endforeach; ?>
  </select>
</label>
<?php if ($form['hasTo']): ?>
<label><?= $word('STOCK_OPS', 'warehouse_to') ?>
  <select name="to_warehouse"<?php if ($kind === 'release'): ?> required<?php endif; ?>>
<?php if ($kind !== 'release'): ?>
    <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php endif; ?>
<?php foreach ($form['to'] as $w): ?>
    <option value="<?= $e($w['id']) ?>"<?php if ($form['to_warehouse'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['label']) ?></option>
<?php endforeach; ?>
  </select>
</label>
<label><?= $word('STOCK_OPS', 'place_to') ?>
  <select name="to_location">
    <option value=""><?= $word('STOCK_OPS', 'no_place') ?></option>
<?php foreach ($form['places'] as $p): ?>
    <option value="<?= $e($p['id']) ?>"<?php if ($form['to_location'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['label']) ?></option>
<?php endforeach; ?>
  </select>
</label>
<?php endif; ?>
<?php if ($form['hasReason']): ?>
<label><?= $word('STOCK_OPS', 'reason') ?>
  <select name="reason_code" required>
    <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($form['reasons'] as $r): ?>
    <option value="<?= $e($r['code']) ?>"<?php if ($form['reason_code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
  </select>
</label>
<?php endif; ?>
<?php if ($form['hasGiven']): ?>
<label><?= $word('STOCK_OPS', 'given_to') ?>
<?php if ($form['givenHint'] !== null): ?>
  <span class="hint"><?= $e($form['givenHint']) ?></span>
<?php endif; ?>
  <input type="text" name="given_to" value="<?= $e($form['given_to']) ?>" maxlength="100" autocomplete="off">
</label>
<?php endif; ?>
<label><?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'ref_release') ?><?php else: ?><?= $word('STOCK_OPS', 'ref') ?><?php endif; ?>
  <input type="text" name="external_ref" value="<?= $e($form['external_ref']) ?>" maxlength="100" autocomplete="off">
</label>
<label><?= $word('STOCK_OPS', 'date') ?>
  <input type="date" name="doc_date" value="<?= $e($form['doc_date']) ?>">
</label>
<label><?= $word('STOCK_OPS', 'note') ?>
  <textarea name="note" rows="2" maxlength="1000"><?= $e($form['note']) ?></textarea>
</label>
