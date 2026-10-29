<?php
// Start a delivery (docs/decisions.md GV13): the one way in, from the Goods in board's "+ New delivery" or an order's "Receive the goods"
// (?po=: that order chosen). The order the delivery is against, or only its supplier; the supplier invoice number if it came with the goods;
// copy the order's lines still to come. Nothing is written until "Start the delivery" (POST /ui/receiving, FormOnce); a refusal comes back
// here with what was typed.
?>
<p class="crumbs"><a href="/ui/receiving"><?= $word('MENU', 'receiving') ?></a></p>
<?= $head($title, intro: 'receipt_new', side: '') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($newSuppliers === []): ?>
<?= $empty(\CW\Ui\Words::RECEIVING['no_supplier'], \CW\Ui\Words::RECEIVING['no_supplier_text']) ?>
<?php else: ?>
<form class="record receive-new card" method="post" action="/ui/receiving">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <label><?= $word('RECEIVING', 'po') ?>
    <select name="po_id" data-ticks="copy">
      <option value=""><?= $word('RECEIVING', 'no_po') ?></option>
<?php foreach ($orders as $o): ?>
      <option value="<?= $e($o['id']) ?>"<?php if (($typed['po_id'] ?? '') === (string) $o['id']): ?> selected<?php endif; ?>><?= $say('RECEIVING', 'po_option', (string) $o['number'], (string) $o['supplier_name'], (int) $o['outstanding'], mb_strtolower(\CW\Ui\Words::of('PO_STATE', (string) $o['state']))) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('RECEIVING', 'supplier') ?>
    <select name="supplier_id">
      <option value=""><?= $word('RECEIVING', 'po_supplier') ?></option>
<?php foreach ($newSuppliers as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if (($typed['supplier_id'] ?? '') === (string) $s['id']): ?> selected<?php endif; ?>><?php if ($s['status'] === 'active'): ?><?= $e($s['name']) ?><?php else: ?><?= $say('RECEIVING', 'not_ready', (string) $s['name'], mb_strtolower(\CW\Ui\Words::of('SUPPLIER_STATUS', (string) $s['status']))) ?><?php endif; ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('RECEIVING', 'invoice') ?> <input type="text" name="invoice_number" maxlength="64" value="<?= $e($typed['invoice_number'] ?? '') ?>" autocomplete="off"></label>
  <label class="choice"><input type="checkbox" name="copy" value="1" id="copy"<?php if (($typed['copy'] ?? (($typed['po_id'] ?? '') !== '' ? '1' : '')) === '1'): ?> checked<?php endif; ?>> <?= $word('RECEIVING', 'copy') ?></label>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('RECEIVING', 'start') ?></button></p>
</form>
<p class="hint"><a href="/ui/receiving/template.csv" download><?= $word('RECEIVING', 'sample') ?></a></p>
<?php endif; ?>
