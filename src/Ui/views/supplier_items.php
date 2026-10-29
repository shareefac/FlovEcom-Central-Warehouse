<?php
// A supplier's products (the supplier's "Items and packs", whole; docs/decisions.md W9.3): the board of supplier_items_board under the
// head, "Add a product" the page's one main action, the CSV beside it.
?>
<p class="crumbs"><a href="/ui/purchasing/suppliers"><?= $word('MENU', 'suppliers') ?></a> <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'], ['tab' => 'items']) ?>"><?= $say('SUPPLIER_ITEMS', 'back', (string) $s['name']) ?></a></p>
<?php $bar = $partial('supplier_items_bar', ['s' => $s, 'canManage' => $canManage]); ?>
<div class="doc-head">
<?= $head((string) $title, intro: 'supplier_items', look: $lookOnly, side: $bar) ?>
</div>
<?= $partial('supplier_items_board', ['rows' => $rows, 'canManage' => $canManage, 'supplierId' => (int) $s['id']]) ?>
