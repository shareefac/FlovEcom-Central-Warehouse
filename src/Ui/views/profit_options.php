<?php
// The Profit pages' options (docs/decisions.md PR24): the days (the reports' choices, or From / To), the shop, and the page's own choices
// ($extra: by, sort, ...: name => [label, [value => words], current]); one Show button. A GET form: nothing is changed here.
$extra = $extra ?? [];
$hidden = $hidden ?? [];
?>
<form class="card rp-options" method="get" aria-label="<?= $word('PROFIT', 'options') ?>">
<?php foreach ($hidden as $hk => $hv): ?>
  <input type="hidden" name="<?= $e($hk) ?>" value="<?= $e($hv) ?>">
<?php endforeach; ?>
<?php foreach ($extra as $name => [$label, $choices, $current]): ?>
  <div class="rp-opt"><label for="pf-<?= $e($name) ?>"><?= $e($label) ?></label>
    <select id="pf-<?= $e($name) ?>" name="<?= $e($name) ?>">
<?php foreach ($choices as $value => $words): ?>
      <option value="<?= $e($value) ?>"<?php if ((string) $current === (string) $value): ?> selected<?php endif; ?>><?= $e($words) ?></option>
<?php endforeach; ?>
    </select>
  </div>
<?php endforeach; ?>
<?php if ($period !== null): ?>
  <div class="rp-opt"><label for="pf-period"><?= $word('PROFIT', 'days') ?></label>
    <select id="pf-period" name="period">
<?php foreach ($periods as $p): ?>
      <option value="<?= $e($p) ?>"<?php if ($period === $p): ?> selected<?php endif; ?>><?= $word('REPORTS', $p) ?></option>
<?php endforeach; ?>
      <option value="choose"<?php if ($period === 'choose'): ?> selected<?php endif; ?>><?= $word('REPORTS', 'choose') ?></option>
    </select>
  </div>
  <div class="rp-opt"><label for="pf-from"><?= $word('PROFIT', 'from') ?></label><input id="pf-from" type="date" name="from" value="<?= $e($from) ?>"></div>
  <div class="rp-opt"><label for="pf-to"><?= $word('PROFIT', 'to') ?></label><input id="pf-to" type="date" name="to" value="<?= $e($to) ?>"></div>
<?php endif; ?>
  <div class="rp-opt"><label for="pf-store"><?= $word('PROFIT', 'store') ?></label>
    <select id="pf-store" name="store">
      <option value=""><?= $word('PROFIT', 'all_stores') ?></option>
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($storeId === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary"><?= $word('PROFIT', 'run') ?></button>
</form>
<?php if (($warn ?? null) !== null): ?>
<p class="note" role="status"><?= $e($warn) ?></p>
<?php endif; ?>
