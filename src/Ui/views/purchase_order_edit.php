<p class="crumbs"><a href="/ui/purchasing/orders">Purchase orders</a></p>
<h1><?= $e($doc->label()) ?> <span class="muted"><?= $e($supplier['code'] ?? '') ?> <?= $e($supplier['name'] ?? '') ?></span> <span class="status">draft</span></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($importErrors !== []): ?>
<section class="error" aria-labelledby="import-errors-h">
  <h2 id="import-errors-h">The file was not imported</h2>
  <ul>
<?php foreach ($importErrors as $ie): ?>
    <li><?= $e($ie) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($warnings !== []): ?>
<ul class="warnings">
<?php foreach ($warnings as $w): ?>
  <li><?= $e($w) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($companyNote !== null): ?>
<p class="note company-note"><?= $e($companyNote['text']) ?> <a href="/ui/reference/company"><?= $e($companyNote['link']) ?></a></p>
<?php endif; ?>

<form class="po-editor" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="line_count" value="<?= $e($editable ? count($lines) : 0) ?>">
  <input type="hidden" name="lines_editable" value="<?php if ($editable): ?>1<?php else: ?>0<?php endif; ?>">
  <fieldset class="po-header">
    <legend>Order</legend>
<?php if ($suppliers !== []): ?>
    <label>Supplier
      <select name="supplier_id">
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ((int) $s['id'] === (int) $po['supplier_id']): ?> selected<?php endif; ?>><?= $e($s['code']) ?> <?= $e($s['name']) ?><?php if ($s['status'] !== 'active'): ?> (<?= $e(str_replace('_', ' ', $s['status'])) ?>)<?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <p>Supplier: <a href="<?= $u('/ui/purchasing/suppliers/' . ($supplier['id'] ?? 0)) ?>"><?= $e($supplier['code'] ?? '') ?></a> <?= $e($supplier['name'] ?? '') ?>
      <span class="muted">(it changes only while the order has no lines)</span></p>
<?php endif; ?>
    <label>Order date <input type="date" name="doc_date" value="<?= $e($typed['doc_date'] ?? $doc->docDate) ?>"></label>
    <label>Expected delivery <input type="date" name="expected_date" value="<?= $e($typed['expected_date'] ?? ($po['expected_date'] ?? '')) ?>"></label>
    <label>Supplier's quote reference <input type="text" name="external_ref" maxlength="191" value="<?= $e($typed['external_ref'] ?? $doc->externalRef) ?>"></label>
    <label>Notes to supplier (printed) <textarea name="note" maxlength="1000" rows="2"><?= $e($typed['note'] ?? $doc->note) ?></textarea></label>
  </fieldset>

  <fieldset class="scan" id="scan">
    <legend>Add a line</legend>
    <label>Scan a barcode, or type a supplier code, a CW code or words of the name
      <input type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autofocus autocomplete="off"></label>
    <label>Packs <input type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short"></label>
    <button type="submit" name="action" value="add" class="primary">Add</button>
    <span class="muted">Enter adds the line and saves every change in the table below; scanning the same item again adds a pack.</span>
  </fieldset>
<?php if ($choices !== null): ?>
  <section class="choices" aria-labelledby="choices-h">
    <h2 id="choices-h">Choose the item for "<?= $e($q) ?>"</h2>
    <ul class="plain">
<?php foreach ($choices as $c): ?>
      <li>
<?php if ($c['supplier_item_id'] !== null): ?>
        <button type="submit" name="add_si" value="<?= $e($c['supplier_item_id']) ?>">Add</button>
<?php else: ?>
        <button type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>">Add</button>
<?php endif; ?>
        <?= $e($c['sku_code']) ?> <?= $e($c['name']) ?><?php if ($c['brand'] !== null): ?> <span class="muted"><?= $e($c['brand']) ?></span><?php endif; ?>
<?php if ($c['supplier_item_id'] !== null): ?>
        <span class="tag ok">this supplier: <?= $e($c['supplier_code'] ?? 'no code') ?>, <?= $e($c['purchase_unit']) ?> ×<?= $n($c['units_per_pack']) ?></span>
<?php else: ?>
        <span class="tag warn">not one of this supplier's items yet</span>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

  <section aria-labelledby="lines-h">
    <h2 id="lines-h">Lines</h2>
<?php if (!$editable): ?>
    <p class="note">This order has too many lines for one form (about <?= $n($maxLines) ?> fit): they are shown read-only here. Change them with the
      file import (download the lines, edit them, import them with "replace"); the header, a scan and a charge still work here.</p>
<?php endif; ?>
<?php if ($lines === []): ?>
    <p class="muted">No lines yet.</p>
<?php else: ?>
    <table class="po-lines">
      <thead>
        <tr>
          <th scope="col" class="num">#</th>
          <th scope="col">Item</th>
          <th scope="col">Supplier code</th>
          <th scope="col">Pack</th>
          <th scope="col" class="num">Packs</th>
          <th scope="col" class="num">Units</th>
          <th scope="col" class="num">Pack price (GBP)</th>
          <th scope="col">VAT</th>
          <th scope="col" class="num">Net</th>
          <th scope="col" class="num">Stock now</th>
          <th scope="col" class="num">On order</th>
          <th scope="col">Last price / last PO</th>
          <th scope="col">Note</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $i => $l): ?>
<?php $no = $i + 1; ?>
        <tr<?php if ($l['merged_into_sku_id'] !== null || ($l['si_active'] !== null && (int) $l['si_active'] !== 1)): ?> class="differs"<?php endif; ?>>
          <td class="num"><?= $n($no) ?></td>
<?php if ($l['kind'] === 'charge'): ?>
          <td colspan="3"><span class="tag">charge</span></td>
          <td class="num"><?php if ($editable): ?><input type="hidden" name="line_<?= $e($no) ?>_packs" value="<?= $e($typed['line_' . $no . '_packs'] ?? '1') ?>"><?php endif; ?>&nbsp;</td>
          <td></td>
          <td class="num"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
          <td><?= $e($l['vat_code']) ?></td>
<?php else: ?>
          <td><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_code']) ?></a> <?= $e($l['sku_name']) ?><?php if ($l['merged_into_sku_id'] !== null): ?> <span class="tag bad">merged</span><?php endif; ?></td>
          <td><?= $e($l['code']) ?></td>
          <td><?php if ($l['supplier_item_id'] === null && $editable): ?><input type="number" name="line_<?= $e($no) ?>_upp" min="1" max="100000" value="<?= $e($typed['line_' . $no . '_upp'] ?? $l['units_per_pack']) ?>" class="short" aria-label="units per pack of line <?= $e($no) ?>">
            <label class="choice"><input type="checkbox" name="line_<?= $e($no) ?>_save" value="1"> save as this supplier's item</label><?php else: ?><?= $e($l['pack_label']) ?><?php endif; ?></td>
          <td class="num"><?php if ($editable): ?><input type="number" name="line_<?= $e($no) ?>_packs" min="0" max="1000000" value="<?= $e($typed['line_' . $no . '_packs'] ?? $l['packs']) ?>" class="short" aria-label="packs of line <?= $e($no) ?> (0 removes it)"><?php else: ?><?= $n($l['packs']) ?><?php endif; ?></td>
          <td class="num"><?= $n($l['qty']) ?></td>
          <td class="num"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" aria-label="pack price of line <?= $e($no) ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
          <td><?php if ($editable): ?><select name="line_<?= $e($no) ?>_vat" aria-label="VAT code of line <?= $e($no) ?>">
<?php foreach ($vatCodes as $v): ?>
              <option value="<?= $e($v['code']) ?>"<?php if (($typed['line_' . $no . '_vat'] ?? $l['vat_code']) === $v['code']): ?> selected<?php endif; ?>><?= $e($v['code']) ?></option>
<?php endforeach; ?>
            </select><?php else: ?><?= $e($l['vat_code']) ?><?php endif; ?></td>
<?php endif; ?>
          <td class="num"><?= $e($l['net']) ?></td>
          <td class="num"><?= $n($l['stock']) ?></td>
          <td class="num"><?= $n($l['on_order']) ?></td>
          <td><?php if ($l['last_pack_price'] !== null): ?><?= $dec($l['last_pack_price']) ?><?php endif; ?><?php if ($l['last_po_pack_price'] !== null): ?> <span class="muted">/ PO <?= $dec($l['last_po_pack_price']) ?></span><?php endif; ?></td>
          <td><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_note" maxlength="255" value="<?= $e($typed['line_' . $no . '_note'] ?? $l['description']) ?>" aria-label="note of line <?= $e($no) ?>"><?php else: ?><?= $e($l['description']) ?><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
    <p class="muted">Packs 0 removes a line. A line without a supplier item takes the units per pack you give; tick "save as this supplier's item" to
      remember the pack (and its code) for next time.</p>
  </section>

  <fieldset class="charge">
    <legend>Add a charge (delivery, ...)</legend>
    <label>What <input type="text" name="charge_description" maxlength="255"></label>
    <label>Amount (GBP, excl. VAT) <input type="text" name="charge_amount" inputmode="decimal" class="short"></label>
    <label>VAT
      <select name="charge_vat">
        <option value="">Supplier's default</option>
<?php foreach ($vatCodes as $v): ?>
        <option value="<?= $e($v['code']) ?>"><?= $e($v['code']) ?> <?= $e($v['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
  </fieldset>
  <p class="actions"><button type="submit" name="action" value="save">Save</button>
    <button type="submit" name="action" value="approve" class="primary">Save and approve the order</button></p>
  <p class="muted">Approving saves what is in this form first (the packs and prices as typed), then approves exactly that.</p>
</form>

<section class="totals card box" aria-labelledby="totals-h">
  <h2 id="totals-h">Totals</h2>
  <dl>
    <dt>Lines</dt><dd><?= $n(count($lines)) ?>, <?= $n($units) ?> units</dd>
    <dt>Net</dt><dd><?= $e($totals['net']) ?></dd>
<?php foreach ($totals['by_code'] as $code => $v): ?>
    <dt>VAT <?= $e($code) ?> <?= $e($v['rate']) ?>%</dt><dd><?= $e($v['vat']) ?></dd>
<?php endforeach; ?>
    <dt>Total</dt><dd><strong><?= $e($totals['gross']) ?></strong></dd>
  </dl>
  <p class="muted">Above £<?= $n($limit) ?> net a reviewer approves the order before it is numbered; below, it is numbered at once and reviewed within
    7 days.</p>
</section>

<section aria-labelledby="files-h">
  <h2 id="files-h">Lines file and PDF</h2>
  <p><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>">Download the lines (XLSX)</a> &middot;
    <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.csv') ?>">(CSV)</a> &middot;
    <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/pdf') ?>">Draft PDF</a></p>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines/import') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label>Lines file (CSV or XLSX, at most 2 MiB, 2,000 lines) <input type="file" name="file" required></label>
    <label>
      <select name="mode">
        <option value="append">add to the lines (packs add up on the same item)</option>
        <option value="replace">replace every line</option>
      </select>
    </label>
    <button type="submit">Import</button>
  </form>
  <p class="muted">Columns: cw_code, supplier_code or barcode (one of them), packs (required), and optionally purchase_unit, units_per_pack, units,
    pack_price, vat_code, note. All or nothing: a file with any problem changes nothing and the problems are listed with their row.</p>
</section>

<section aria-labelledby="cancel-h">
  <h2 id="cancel-h">Cancel the draft</h2>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label>Why
      <select name="reason_code" required>
        <option value="not_needed">No longer needed</option>
        <option value="supplier_cannot_supply">Supplier cannot supply</option>
        <option value="entered_in_error">Entered in error</option>
        <option value="duplicate">Duplicate document</option>
        <option value="other">Other (say why)</option>
      </select>
    </label>
    <label>Note <input type="text" name="note" maxlength="400"></label>
    <button type="submit">Cancel the draft</button>
  </form>
</section>
