<p class="crumbs"><a href="/ui/receiving">Receive + invoice</a> <a href="<?= $u('/ui/receiving/' . $doc->id . '/bench') ?>">Bench check of this delivery</a></p>
<h1><?= $e($doc->label()) ?> <span class="muted"><?= $e($supplier['code'] ?? '') ?> <?= $e($supplier['name'] ?? '') ?></span> <span class="status">draft</span></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($refusedPost): ?> <a href="#ready-h">What stops it is listed under "Before posting".</a><?php endif; ?></p>
<?php endif; ?>
<?php if ($problems !== [] && !$refusedPost): ?>
<ul class="error problems">
<?php foreach ($problems as $p): ?>
  <li><?= $e($p) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($importErrors !== []): ?>
<section class="error" aria-labelledby="import-errors-h">
  <h2 id="import-errors-h">The sheet was not imported</h2>
  <ul class="import-errors">
<?php foreach ($importErrors as $ie): ?>
    <li><?= $e($ie) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($skipped !== []): ?>
<details class="skipped">
  <summary>Rows of the sheet without an item code (<?= $n(count($skipped)) ?>): skipped</summary>
  <ul class="plain">
<?php foreach ($skipped as $sk): ?>
    <li><?= $e($sk) ?></li>
<?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>
<?php if ($supplier !== [] && $supplier['status'] !== 'active'): ?>
<p class="note">Supplier <?= $e($supplier['code']) ?> is <?= $e(str_replace('_', ' ', $supplier['status'])) ?>: the receipt can be keyed, but it is posted only once a second person
  has approved the supplier.</p>
<?php endif; ?>

<form class="po-editor grn-editor" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/lines') ?>" data-unsaved="the lines and the header">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="line_count" value="<?= $e($editable ? count($lines) : 0) ?>">
  <input type="hidden" name="lines_editable" value="<?php if ($editable): ?>1<?php else: ?>0<?php endif; ?>">
  <input type="hidden" name="stamp" value="<?= $e($stamp) ?>">
  <input type="hidden" name="header_present" value="1">
  <fieldset class="po-header">
    <legend>Delivery</legend>
<?php if ($suppliers !== []): ?>
    <label>Supplier
      <select name="supplier_id">
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ((int) $s['id'] === (int) ($gr['supplier_id'] ?? 0)): ?> selected<?php endif; ?>><?= $e($s['code']) ?> <?= $e($s['name']) ?><?php if ($s['status'] !== 'active'): ?> (<?= $e(str_replace('_', ' ', $s['status'])) ?>)<?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <p>Supplier: <?= $e($supplier['code'] ?? '') ?> <?= $e($supplier['name'] ?? '') ?> <span class="muted">(it changes only while the receipt has no lines)</span></p>
<?php endif; ?>
<?php if (!$linked): ?>
    <label>Purchase order
      <select name="po_id">
        <option value="">None</option>
<?php foreach ($orders as $o): ?>
        <option value="<?= $e($o['id']) ?>"<?php if ($po !== null && (int) $po['id'] === (int) $o['id']): ?> selected<?php endif; ?>><?= $e($o['number']) ?> (<?= $n($o['outstanding']) ?> units to come)</option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <p>Against <a href="<?= $u('/ui/purchasing/orders/' . $po['id']) ?>"><?= $e($po['number']) ?></a> (<?= $e(str_replace('_', ' ', $po['state'])) ?>)
      <span class="muted">(it changes only while no line is received against it)</span></p>
<?php endif; ?>
    <label>Supplier invoice number (needed before posting) <input type="text" name="invoice_number" maxlength="64" value="<?= $e($typed['invoice_number'] ?? $doc->externalRef) ?>" autocomplete="off"></label>
    <label>Invoice date <input type="date" name="invoice_date" value="<?= $e($typed['invoice_date'] ?? ($gr['invoice_date'] ?? '')) ?>"></label>
    <label>Delivery note <input type="text" name="delivery_note" maxlength="64" value="<?= $e($typed['delivery_note'] ?? ($gr['delivery_note'] ?? '')) ?>"></label>
    <label>Received at (UK time) <input type="datetime-local" name="received_at" value="<?= $e($typed['received_at'] ?? $receivedLocal) ?>"></label>
    <label class="choice"><input type="checkbox" name="paper_sheet" value="1"<?php if ((isset($typed['header_present']) ? ($typed['paper_sheet'] ?? '') === '1' : (int) ($gr['paper_sheet'] ?? 0) === 1)): ?> checked<?php endif; ?>> Keyed from a paper receiving sheet (CW was down)</label>
    <label>Why it is keyed late (only when the goods arrived before the day the receipt was started) <input type="text" name="backdate_reason" maxlength="500" value="<?= $e($typed['backdate_reason'] ?? ($gr['backdate_reason'] ?? '')) ?>"></label>
    <label>Note <textarea name="note" maxlength="1000" rows="2"><?= $e($typed['note'] ?? $doc->note) ?></textarea></label>
  </fieldset>

  <fieldset class="scan" id="scan">
    <legend>Add a line</legend>
    <label>Scan a barcode (an outer case counts its units), or type this supplier's code, a CW code or words of the name
      <input type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autofocus autocomplete="off"></label>
    <label>Packs <input type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short"></label>
    <label>Pack price (GBP, optional) <input type="text" name="price" value="<?= $e($price) ?>" class="short" inputmode="decimal" autocomplete="off"></label>
    <button type="submit" name="action" value="add" class="primary" formnovalidate>Add</button>
    <span class="muted">Enter adds the line and saves every change in the table below. A barcode counts the units it stands for: a unit's own barcode one
      unit, a case barcode its case; scanning the same item again adds to its line.</span>
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
        <span class="tag ok">this supplier: <?= $e($c['supplier_code'] ?? 'no code') ?>, <?= $e($c['purchase_unit']) ?> x<?= $n($c['units_per_pack']) ?></span>
<?php else: ?>
        <span class="tag warn">single units (packs of 1, not one of this supplier's packs)</span>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

  <section aria-labelledby="lines-h">
    <h2 id="lines-h">Lines</h2>
<?php if (!$editable): ?>
    <p class="note">This receipt has too many lines for one form (about <?= $n($maxLines) ?> fit): they are shown read-only here. Change them with the sheet
      import ("replace"); the header and a scan still work here.</p>
<?php endif; ?>
<?php if ($lines === []): ?>
    <p class="muted">No lines yet.</p>
<?php else: ?>
    <div class="scroll">
    <table class="po-lines grn-lines stack">
      <thead>
        <tr>
          <th scope="col" class="num">#</th>
          <th scope="col">Item</th>
          <th scope="col">Pack</th>
          <th scope="col" class="num">Packs</th>
          <th scope="col" class="num">Units</th>
          <th scope="col" class="num">Pack price (GBP, provisional)</th>
          <th scope="col" class="num">Net</th>
          <th scope="col">Order line</th>
          <th scope="col" class="num">Stock now</th>
          <th scope="col">Selling mode on receipt<?php if ($receiptSites !== []): ?> <span class="muted">(reaches <?php foreach ($receiptSites as $i2 => $rs): ?><?= $e($i2 > 0 ? ', ' : '') ?><?= $e($rs['name']) ?><?php if (!$rs['writer']): ?> once its site writer is on<?php endif; ?><?php endforeach; ?>)</span><?php else: ?> <span class="muted">(reaches no website yet)</span><?php endif; ?></th>
          <th scope="col">Duty</th>
          <th scope="col">Bench</th>
          <th scope="col">Note</th>
        </tr>
      </thead>
      <tbody>
<?php $i = 0; ?>
<?php foreach ($lines as $no => $l): ?>
<?php $i++; ?>
        <tr id="line-<?= $e($no) ?>"<?php if ($l['problems'] !== []): ?> class="differs"<?php endif; ?>>
          <td class="num" data-label="Line"><?= $n($no) ?></td>
          <td data-label="Item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_code']) ?></a> <?= $e($l['sku_name']) ?><?php if ($l['supplier_code'] !== null): ?> <span class="muted">(<?= $e($l['supplier_code']) ?>)</span><?php endif; ?>
<?php foreach ($l['problems'] as $p): ?>
            <span class="field-error"><?= $e($p) ?></span>
<?php endforeach; ?>
          </td>
          <td data-label="Pack"><?php if ($l['supplier_item_id'] === null && $editable): ?><input type="number" name="line_<?= $e($i) ?>_upp" min="1" max="100000" value="<?= $e($typed['line_' . $i . '_upp'] ?? $l['units_per_pack']) ?>" class="short" aria-label="units per pack of line <?= $e($no) ?>"><?php else: ?><?= $e($l['pack_label']) ?><?php endif; ?></td>
          <td class="num" data-label="Packs"><?php if ($editable): ?><input type="number" name="line_<?= $e($i) ?>_packs" min="0" max="1000000" value="<?= $e($typed['line_' . $i . '_packs'] ?? $l['packs']) ?>" class="short" aria-label="packs of line <?= $e($no) ?> (0 removes it)"><?php else: ?><?= $n($l['packs']) ?><?php endif; ?></td>
          <td class="num" data-label="Units"><?= $n($l['units']) ?></td>
          <td class="num" data-label="Pack price"><?php if ($editable): ?><input type="text" name="line_<?= $e($i) ?>_price" value="<?= $e($typed['line_' . $i . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" aria-label="pack price of line <?= $e($no) ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
          <td class="num" data-label="Net"><?= $e($l['net']) ?></td>
          <td data-label="Order line"><?php if ($editable && $poLines !== []): ?><select name="line_<?= $e($i) ?>_po" aria-label="order line of line <?= $e($no) ?>">
              <option value="0">not against the order</option>
<?php foreach ($poLines as $pno => $plabel): ?>
              <option value="<?= $e($pno) ?>"<?php if ((string) ($typed['line_' . $i . '_po'] ?? ($l['po_line_no'] ?? 0)) === (string) $pno): ?> selected<?php endif; ?>><?= $e($plabel) ?></option>
<?php endforeach; ?>
            </select><?php elseif ($l['po_line_no'] !== null): ?>line <?= $n($l['po_line_no']) ?><?php endif; ?></td>
          <td class="num" data-label="Stock now"><?= $n($l['stock']['on_hand']) ?> <span class="muted">(<?= $n($l['stock']['available']) ?> available)</span></td>
          <td data-label="Selling mode"><?php if ($editable): ?><select name="line_<?= $e($i) ?>_mode" aria-label="selling mode on receipt of line <?= $e($no) ?>">
<?php foreach ($modeChoices as $mc): ?>
              <option value="<?= $e($mc) ?>"<?php if (($typed['line_' . $i . '_mode'] ?? $l['mode_choice']) === $mc): ?> selected<?php endif; ?>><?php if ($mc === 'default'): ?>Default: <?= $e($l['mode_default']['mode'] ?? '') ?> (<?= $e($modeSources[$l['mode_default']['source'] ?? 'fallback'] ?? '') ?>)<?php else: ?><?= $e($mc) ?> (<?= $e($modes[$mc]) ?>)<?php endif; ?></option>
<?php endforeach; ?>
            </select><?php else: ?><?= $e($l['mode_resolved']['mode'] ?? '') ?><?php endif; ?>
            <?php if ($l['mode_now'] !== null): ?><span class="muted">now <?= $e($l['mode_now']) ?></span><?php else: ?><span class="muted">no mode known yet</span><?php endif; ?></td>
          <td data-label="Duty"><?php if ($l['stamp_req']): ?><span class="tag">stamp required</span><?php if ($l['duty_unknown']): ?> <span class="tag warn">duty not answered</span><?php endif; ?><?php else: ?><span class="muted">no stamp</span><?php endif; ?><?php if ($l['duty_text'] !== null): ?> <span class="muted">duty about <?= $e($l['duty_text']) ?></span><?php endif; ?></td>
          <td data-label="Bench"><?php if ($l['checked_at'] === null): ?><span class="tag">waiting for the bench</span><?php else: ?><span class="tag ok">counted</span>
            <?php if ((int) $l['short_units'] > 0): ?> short <?= $n($l['short_units']) ?><?php endif; ?><?php if ((int) $l['over_units'] > 0): ?> over <?= $n($l['over_units']) ?><?php endif; ?><?php if ((int) $l['damaged_units'] > 0): ?> damaged <?= $n($l['damaged_units']) ?><?php endif; ?><?php if ((int) $l['wrong_item_units'] > 0): ?> wrong item <?= $n($l['wrong_item_units']) ?><?php endif; ?><?php if ((int) $l['unstamped_units'] > 0): ?> unstamped <?= $n($l['unstamped_units']) ?> (<?= $e(str_replace('_', ' ', (string) $l['unstamped_action'])) ?>)<?php endif; ?><?php endif; ?></td>
          <td data-label="Note"><?php if ($editable): ?><input type="text" name="line_<?= $e($i) ?>_note" maxlength="255" value="<?= $e($typed['line_' . $i . '_note'] ?? $l['description']) ?>" aria-label="note of line <?= $e($no) ?>"><?php else: ?><?= $e($l['description']) ?><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
    <p class="muted">Packs 0 removes a line. Units are always packs x units per pack. The price is provisional (it settles when the supplier invoice is matched).
      "Default" mode: the item's last selling mode; an Out-Of-Stock item goes back to the mode it had before.</p>
  </section>
  <p class="actions sticky"><button type="submit" name="action" value="save" formnovalidate>Save</button>
    <button type="submit" name="action" value="post" class="primary">Save and post</button>
    <?php if ($plan['problems'] === []): ?><span class="tag ok">Ready to post</span><?php else: ?><a href="#ready-h"><?= $n(count($plan['problems'])) ?> thing<?= $e(count($plan['problems']) === 1 ? '' : 's') ?> to settle before posting</a><?php endif; ?></p>
  <p class="muted">Posting saves what is in this form first, then books exactly that.</p>
</form>

<section class="card box checklist" aria-labelledby="ready-h">
  <h2 id="ready-h">Before posting</h2>
<?php if ($plan['problems'] === []): ?>
  <p class="notice">Ready to post.</p>
<?php else: ?>
  <ul class="problems">
<?php foreach ($plan['problems'] as $p): ?>
    <li><?= $e($p['message']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($plan['warnings'] !== []): ?>
  <ul class="warnings">
<?php foreach ($plan['warnings'] as $w): ?>
    <li><?= $e($w) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <dl>
    <dt>Lines</dt><dd><?= $n($totals['lines']) ?>, <?= $n($totals['units']) ?> units on the paperwork, net <?= $e($totals['net']) ?></dd>
    <dt>Into MAIN</dt><dd><?= $n($totals['accepted']) ?></dd>
    <dt>Into VERIFY</dt><dd><?= $n($totals['verify']) ?> <span class="muted">(damaged, wrong item, over; an unstamped delivery's damaged and over units are quarantined)</span></dd>
    <dt>Quarantined (UNSTAMPED)</dt><dd><?= $n($totals['quarantine']) ?></dd>
    <dt>Refused / short</dt><dd><?= $n($totals['refused']) ?> / <?= $n($totals['short']) ?></dd>
    <dt>Expected duty</dt><dd><?= $e($totals['duty']) ?> <span class="muted">(for information: ml x 22p, rounded down per unit)</span></dd>
  </dl>
</section>

<?php if ($po !== null): ?>
<section aria-labelledby="copy-h">
  <h2 id="copy-h">Receive all as ordered</h2>
  <form class="inline" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/copy') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit">Copy <?= $e($po['number']) ?>'s outstanding lines</button>
    <span class="muted">(the order's lines still expecting units and not on this receipt yet; then edit what arrived differently)</span>
  </form>
</section>
<?php endif; ?>

<section aria-labelledby="sheet-h">
  <h2 id="sheet-h">The supplier's invoice or packing list (spreadsheet)</h2>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/import') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label>Sheet (CSV or XLSX, at most 2 MiB) <input type="file" name="file" required></label>
    <label>
      <select name="mode">
        <option value="append">add to the lines (packs add up on the same item)</option>
        <option value="replace">replace every line (the bench findings with them)</option>
      </select>
    </label>
    <button type="submit">Import</button>
  </form>
  <p class="muted">The header row may sit below the supplier's letterhead. Columns read: a code (code, item code, SKU, ...), a barcode (EAN) or a CW code; the
    quantity in packs (qty, quantity); and optionally the pack size, the units, the price of a pack and a description. Rows without a code (totals, carriage)
    are skipped and listed; any other problem imports nothing. <a href="/ui/receiving/template.csv">A sample sheet</a>.</p>
</section>

<?= $partial('receipt_files', ['doc' => $doc, 'files' => $files, 'fileRoles' => $fileRoles, 'csrf' => $csrf, 'canAttach' => true, 'back' => '']) ?>

<section aria-labelledby="cancel-h">
  <h2 id="cancel-h">Cancel the receipt</h2>
  <form class="inline" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/cancel') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label>Why <input type="text" name="reason" minlength="3" maxlength="500" required></label>
    <button type="submit">Cancel the receipt</button>
  </form>
  <p class="muted">Nothing is booked by a draft; cancelling frees its invoice number (a delivery refused at the door is cancelled, not posted).</p>
</section>
