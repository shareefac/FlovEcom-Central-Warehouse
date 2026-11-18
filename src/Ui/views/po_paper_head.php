<?php
// The order's paper, its head (docs/decisions.md RD-B4; one look, LP1): three blocks, the supplier (its name, a link to its page, its
// address), where it delivers, and the dates (order date, expected, payment terms, their reference). $party, $deliverTo:
// PurchaseOrdersController::paperHead's.
// Read only ($edit false): $dates, each a label and a value in words.
// On a draft or a new order ($edit, inside the editor form): the supplier is the site's one dropdown (the plain select app.js makes a box,
// ending with "+ Add new supplier", or the line naming whom to ask), Expected and Their reference are boxes in place (kg-flat: plain text
// until the mouse is on one or the cursor is in it). The head is the save's part named for the head: kept as it is by a save without leaving the page
// (its supplier box), but the supplier's address and terms ("party", "terms") follow a supplier changed on Save. $orderDate, $terms: words.
?>
<?php if (!$edit): ?>
  <div class="po-paper-head">
    <section class="doc-party" aria-label="<?= $e($party['label']) ?>">
      <p class="doc-label"><?= $e($party['label']) ?></p>
      <p class="doc-party-name"><a href="<?= $u($party['href']) ?>"><?= $e($party['name']) ?></a></p>
<?php foreach ($party['lines'] as $line): ?>
      <p><?= $e($line) ?></p>
<?php endforeach; ?>
    </section>
    <section class="doc-party po-deliver" aria-label="<?= $e($deliverTo['label']) ?>">
      <p class="doc-label"><?= $e($deliverTo['label']) ?></p>
      <p class="doc-party-name"><?= $e($deliverTo['name']) ?></p>
<?php foreach ($deliverTo['lines'] as $line): ?>
      <p><?= $e($line) ?></p>
<?php endforeach; ?>
    </section>
    <dl class="doc-facts po-dates">
<?php foreach ($dates as $d): ?>
      <dt><?= $e($d['label']) ?></dt><dd><?= $e($d['value']) ?></dd>
<?php endforeach; ?>
    </dl>
  </div>
<?php else: ?>
  <div class="po-paper-head" data-kg-swap-part="header">
    <section class="doc-party po-party-edit" aria-label="<?= $e($party['label']) ?>">
<?php if ($supplierFixed): ?>
      <p class="doc-label"><?= $e($party['label']) ?></p>
      <p class="doc-party-name po-fixed"><a href="<?= $u('/ui/purchasing/suppliers/' . ($supplierId ?? 0)) ?>"><?= $e($supplierName) ?></a></p>
<?php else: ?>
      <p class="doc-label"><label for="po-supplier"><?= $e($party['label']) ?></label></p>
      <div class="po-box-field">
        <select name="supplier_id" id="po-supplier" class="kg-flat" data-po-supplier data-placeholder="<?= $word('PO_ONE', 'supplier_hint') ?>" data-label="<?= $word('PO_ONE', 'suppliers') ?>" data-change-ask="<?= $word('DOC_GRID', 'change_ask') ?>" data-change-yes="<?= $word('DOC_GRID', 'change_yes') ?>" data-change-keep="<?= $word('DOC_GRID', 'change_keep') ?>"<?php if ($canAddSupplier): ?> data-add="<?= $word('PO_ADD', 'new_supplier') ?>" data-panel="po-add-supplier"<?php else: ?> data-ask="<?= $e($askSupplier) ?>"<?php endif; ?>>
          <option value=""><?= $word('ORDERS', 'choose_supplier') ?></option>
<?php foreach ($supplierChoices as $s): ?>
          <option value="<?= $e($s['id']) ?>" data-name="<?= $e($s['name']) ?>" data-meta="<?= $e($s['meta']) ?>" data-vat="<?= $e($s['vat']) ?>"<?php if ($s['id'] === $supplierId): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
        </select>
<?php if ($canAddSupplier): ?>
        <details class="card po-add" id="po-add-supplier" data-po-panel<?php if ($supplierError !== null): ?> open<?php endif; ?>>
          <summary><?= $word('PO_ADD', 'new_supplier') ?></summary>
<?php if ($supplierError !== null): ?>
          <p class="error" role="alert"><?= $e($supplierError) ?></p>
<?php endif; ?>
          <label><?= $word('PO_ADD', 'supplier_name') ?> <input type="text" name="new_supplier_name" maxlength="128" autocomplete="off" value="<?= $e($newSupplier['name']) ?>"></label>
          <label><?= $word('PO_ADD', 'supplier_email') ?> <input type="email" name="new_supplier_email" maxlength="191" autocomplete="off" value="<?= $e($newSupplier['email']) ?>"></label>
          <label><?= $word('PO_ADD', 'supplier_phone') ?> <input type="tel" name="new_supplier_phone" maxlength="32" autocomplete="off" value="<?= $e($newSupplier['phone']) ?>"></label>
          <button type="submit" name="action" value="new_supplier" class="btn"><?= $word('PO_ADD', 'supplier_button') ?></button>
        </details>
<?php else: ?>
        <p class="hint po-ask"><?= $e($askSupplier) ?></p>
<?php endif; ?>
      </div>
<?php endif; ?>
      <div class="po-party-lines" data-kg-swap-part="party">
<?php foreach ($party['lines'] as $line): ?>
        <p><?= $e($line) ?></p>
<?php endforeach; ?>
      </div>
    </section>
    <section class="doc-party po-deliver" aria-label="<?= $e($deliverTo['label']) ?>">
      <p class="doc-label"><?= $e($deliverTo['label']) ?></p>
      <p class="doc-party-name"><?= $e($deliverTo['name']) ?></p>
<?php foreach ($deliverTo['lines'] as $line): ?>
      <p><?= $e($line) ?></p>
<?php endforeach; ?>
    </section>
    <dl class="doc-facts po-dates">
      <dt><?= $word('ORDER', 'order_date') ?></dt><dd><?= $e($orderDate) ?></dd>
      <dt><label for="po-expected"><?= $word('ORDER', 'expected_short') ?></label></dt><dd class="kg-in"><input id="po-expected" type="date" name="expected_date" value="<?= $e($info['expected_date']) ?>" class="kg-flat"></dd>
      <dt><?= $word('ORDER', 'terms') ?></dt><dd data-kg-swap-part="terms"><?= $e($terms) ?></dd>
      <dt><label for="po-ref"><?= $word('ORDER', 'their_ref') ?></label></dt><dd class="kg-in"><input id="po-ref" type="text" name="external_ref" maxlength="191" autocomplete="off" value="<?= $e($info['external_ref']) ?>" placeholder="<?= $word('DOC_LIST', 'none') ?>" class="kg-flat"></dd>
    </dl>
  </div>
<?php endif; ?>
