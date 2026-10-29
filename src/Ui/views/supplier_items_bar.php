<?php
// The action bar of a supplier's products page (docs/decisions.md W9.3): the CSV, then "Add a product" in the accent for a buyer.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <a class="btn ghost" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items.csv') ?>" download><?= $word('SUPPLIER_ITEMS', 'download') ?></a>
<?php if ($canManage): ?>
  <a class="btn primary" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items/new') ?>"><?= $word('SUPPLIER_ITEMS', 'add') ?></a>
<?php endif; ?>
</div>
