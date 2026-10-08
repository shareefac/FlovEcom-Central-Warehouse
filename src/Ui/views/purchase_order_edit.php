<p class="crumbs"><a href="/ui/purchasing/orders"><?= $word('MENU', 'orders') ?></a></p>
<h1><?= $e($title) ?></h1>
<p class="eyebrow"><?= $stateChip('PO_STATE', 'draft') ?></p>
<?= $intro('order_draft') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($importErrors !== []): ?>
<section class="error" aria-labelledby="import-errors-h">
  <h2 id="import-errors-h"><?= $word('ORDER', 'import_failed') ?></h2>
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
    <legend><?= $word('ORDER', 'order') ?></legend>
<?php if ($suppliers !== []): ?>
    <label><?= $word('ORDER', 'supplier') ?>
      <select name="supplier_id">
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ((int) $s['id'] === (int) $po['supplier_id']): ?> selected<?php endif; ?>><?php if ($s['status'] === 'active'): ?><?= $e($s['name']) ?><?php else: ?><?= $say('ORDERS', 'not_ready', (string) $s['name'], $s['status'] === 'draft' ? \CW\Ui\Words::ORDERS['not_approved'] : \CW\Ui\Words::ORDERS['waiting_ok']) ?><?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <p><?= $word('ORDER', 'supplier') ?>: <a href="<?= $u('/ui/purchasing/suppliers/' . ($supplier['id'] ?? 0)) ?>"><?= $e($supplierName) ?></a>
      <span class="hint"><?= $word('ORDER', 'supplier_fixed') ?></span></p>
<?php endif; ?>
    <label><?= $word('ORDER', 'order_date') ?> <input type="date" name="doc_date" value="<?= $e($typed['doc_date'] ?? $doc->docDate) ?>"></label>
    <label><?= $word('ORDER', 'expected') ?> <input type="date" name="expected_date" value="<?= $e($typed['expected_date'] ?? ($po['expected_date'] ?? '')) ?>"></label>
    <label><?= $word('ORDER', 'ref') ?> <input type="text" name="external_ref" maxlength="191" value="<?= $e($typed['external_ref'] ?? $doc->externalRef) ?>"></label>
    <label><?= $word('ORDER', 'note_label') ?> <textarea name="note" maxlength="1000" rows="2"><?= $e($typed['note'] ?? $doc->note) ?></textarea></label>
  </fieldset>

  <fieldset class="scan" id="scan">
    <legend><?= $word('ORDER', 'add') ?></legend>
    <label><?= $word('ORDER', 'scan') ?>
      <input type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autofocus autocomplete="off" aria-describedby="scan-hint"></label>
    <label><?= $word('ORDER', 'packs_label') ?> <input type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short"></label>
    <button type="submit" name="action" value="add" class="primary"><?= $word('ORDER', 'add_button') ?></button>
    <p class="hint" id="scan-hint"><?= $word('ORDER', 'scan_hint') ?></p>
  </fieldset>
<?php if ($choices !== null): ?>
  <section class="choices" aria-labelledby="choices-h">
    <h2 id="choices-h"><?= $say('ORDER', 'choose', $q) ?></h2>
    <ul class="plain">
<?php foreach ($choices as $c): ?>
      <li>
<?php if ($c['supplier_item_id'] !== null): ?>
        <button type="submit" name="add_si" value="<?= $e($c['supplier_item_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php else: ?>
        <button type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php endif; ?>
        <?= $e($c['name']) ?> <span class="muted"><?= $e($c['sku_code']) ?></span><?php if ($c['brand'] !== null): ?> <span class="muted"><?= $e($c['brand']) ?></span><?php endif; ?>
<?php if ($c['supplier_item_id'] !== null): ?>
        <span class="tag ok"><?= $say('ORDER', 'choice_set_up', (string) ($c['supplier_code'] ?? \CW\Ui\Words::ORDER['choice_no_code']), \CW\Ui\Controller\PurchaseOrdersController::pack((string) $c['purchase_unit'], (int) $c['units_per_pack'])) ?></span>
<?php else: ?>
        <span class="tag warn"><?= $word('ORDER', 'choice_new') ?></span>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

  <section aria-labelledby="lines-h">
    <h2 id="lines-h"><?= $word('ORDER', 'lines') ?></h2>
<?php if (!$editable): ?>
    <p class="note"><?= $say('ORDER', 'too_many', $maxLines) ?></p>
<?php endif; ?>
<?php if ($lines === []): ?>
    <p class="muted"><?= $word('ORDER', 'no_lines') ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table class="stack po-lines">
      <thead>
        <tr>
          <th scope="col"><?= $word('ORDER', 'product') ?></th>
          <th scope="col"><?= $word('ORDER', 'their_code') ?></th>
          <th scope="col"><?= $word('ORDER', 'pack') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'packs') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'items') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'per_pack') ?></th>
          <th scope="col"><?= $word('ORDER', 'vat') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'total') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'in_stock') ?></th>
          <th scope="col" class="num"><?= $word('ORDER', 'ordered') ?></th>
          <th scope="col"><?= $word('ORDER', 'last_price') ?></th>
          <th scope="col"><?= $word('ORDER', 'line_note') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $i => $l): ?>
<?php $no = $i + 1; ?>
        <tr<?php if ($l['merged_into_sku_id'] !== null || ($l['si_active'] !== null && (int) $l['si_active'] !== 1)): ?> class="differs"<?php endif; ?>>
<?php if ($l['kind'] === 'charge'): ?>
          <th scope="row" class="c-head"><?= $word('ORDER', 'charge') ?> <span class="o-sub"><?= $say('ORDER', 'line', $no) ?></span><?php if ($editable): ?><input type="hidden" name="line_<?= $e($no) ?>_packs" value="<?= $e($typed['line_' . $no . '_packs'] ?? '1') ?>"><?php endif; ?></th>
          <td data-label="<?= $word('ORDER', 'their_code') ?>"></td>
          <td data-label="<?= $word('ORDER', 'pack') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'items') ?>"></td>
          <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
          <td data-label="<?= $word('ORDER', 'vat') ?>"><?= $e($l['vat_code']) ?></td>
<?php else: ?>
          <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a> <span class="o-sub"><?= $e($l['sku_code']) ?> · <?= $say('ORDER', 'line', $no) ?></span><?php if ($l['merged_into_sku_id'] !== null): ?> <span class="tag bad"><?= $word('ORDER', 'replaced') ?></span><?php elseif ($l['si_active'] !== null && (int) $l['si_active'] !== 1): ?> <span class="tag warn"><?= $word('ORDER', 'switched_off') ?></span><?php endif; ?></th>
          <td data-label="<?= $word('ORDER', 'their_code') ?>"><?= $e($l['code']) ?></td>
          <td data-label="<?= $word('ORDER', 'pack') ?>"><?php if ($l['supplier_item_id'] === null && $editable): ?><label><?= $word('ORDER', 'items_per_pack') ?> <input type="number" name="line_<?= $e($no) ?>_upp" min="1" max="100000" value="<?= $e($typed['line_' . $no . '_upp'] ?? $l['units_per_pack']) ?>" class="short"></label>
            <label class="choice"><input type="checkbox" name="line_<?= $e($no) ?>_save" value="1"> <?= $word('ORDER', 'remember') ?></label><?php else: ?><?= $e($l['pack_label']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"><?php if ($editable): ?><input type="number" name="line_<?= $e($no) ?>_packs" min="0" max="1000000" value="<?= $e($typed['line_' . $no . '_packs'] ?? $l['packs']) ?>" class="short" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'packs') ?>"><?php else: ?><?= $n($l['packs']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'items') ?>"><?= $n($l['qty']) ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_price" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'per_pack') ?>"><?php else: ?><?= $e($l['price']) ?><?php endif; ?></td>
          <td data-label="<?= $word('ORDER', 'vat') ?>"><?php if ($editable): ?><select name="line_<?= $e($no) ?>_vat" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'vat') ?>">
<?php foreach ($vatCodes as $v): ?>
              <option value="<?= $e($v['code']) ?>"<?php if (($typed['line_' . $no . '_vat'] ?? $l['vat_code']) === $v['code']): ?> selected<?php endif; ?>><?= $e($v['code']) ?> – <?= $e($v['label']) ?></option>
<?php endforeach; ?>
            </select><?php else: ?><?= $e($l['vat_code']) ?><?php endif; ?></td>
<?php endif; ?>
          <td class="num" data-label="<?= $word('ORDER', 'total') ?>"><?= $e($l['net']) ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'in_stock') ?>"><?php if ($l['stock'] !== null): ?><?= $n($l['stock']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('ORDER', 'ordered') ?>"><?php if ($l['on_order'] !== null): ?><?= $n($l['on_order']) ?><?php endif; ?></td>
          <td data-label="<?= $word('ORDER', 'last_price') ?>"><?php if ($l['last_pack_price'] !== null): ?><?= $money($l['last_pack_price']) ?><?php endif; ?><?php if ($l['last_po_pack_price'] !== null): ?> <span class="muted"><?= $say('ORDER', 'last_order', \CW\Ui\Html::money($l['last_po_pack_price'])) ?></span><?php endif; ?></td>
          <td data-label="<?= $word('ORDER', 'line_note') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($no) ?>_note" maxlength="255" value="<?= $e($typed['line_' . $no . '_note'] ?? $l['description']) ?>" aria-label="<?= $say('ORDER', 'line', $no) ?>: <?= $word('ORDER', 'line_note') ?>"><?php else: ?><?= $e($l['description']) ?><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
    <p class="hint"><?= $word('ORDER', 'packs_zero') ?> <?= $word('ORDER', 'no_si') ?></p>
  </section>

  <details class="fold charge">
    <summary><?= $word('ORDER', 'charge_open') ?></summary>
    <fieldset class="charge">
      <legend class="visually-hidden"><?= $word('ORDER', 'charge_open') ?></legend>
      <label><?= $word('ORDER', 'charge_what') ?> <input type="text" name="charge_description" maxlength="255"></label>
      <label><?= $word('ORDER', 'charge_amount') ?> <input type="text" name="charge_amount" inputmode="decimal" class="short"></label>
      <label><?= $word('ORDER', 'charge_vat') ?>
        <select name="charge_vat">
          <option value=""><?= $word('ORDER', 'charge_vat_default') ?></option>
<?php foreach ($vatCodes as $v): ?>
          <option value="<?= $e($v['code']) ?>"><?= $e($v['code']) ?> – <?= $e($v['label']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
    </fieldset>
  </details>

  <section class="card totals" aria-labelledby="totals-h">
    <h2 id="totals-h"><?= $word('ORDER', 'totals') ?></h2>
    <dl>
      <dt><?= $word('ORDER', 'products') ?></dt><dd><?= $say('ORDER', count($lines) === 1 ? 'products_line_one' : 'products_line', count($lines), $units) ?></dd>
      <dt><?= $word('ORDER', 'net') ?></dt><dd><?= $e($totals['net']) ?></dd>
<?php foreach ($totals['by_code'] as $code => $v): ?>
      <dt><?= $say('ORDER', 'vat_line', (string) $code, $vatLabels[$code] ?? $v['rate'] . '%') ?></dt><dd><?= $e($v['vat']) ?></dd>
<?php endforeach; ?>
      <dt><?= $word('ORDER', 'gross') ?></dt><dd><strong><?= $e($totals['gross']) ?></strong></dd>
    </dl>
    <p class="hint"><?= $say('ORDER', 'limit_note', $limit, $limit) ?></p>
    <div class="actions">
      <button type="submit" name="action" value="save" class="btn big secondary"><span class="btn-title"><?= $word('ORDER', 'save') ?></span></button>
      <button type="submit" name="action" value="approve" class="primary btn big"><span class="btn-title"><?php if ($overLimit): ?><?= $word('PO', 'confirm_over_limit') ?><?php else: ?><?= $word('ORDER', 'confirm_draft') ?><?php endif; ?></span>
        <span class="sub"><?php if ($overLimit): ?><?= $word('ORDER', 'confirm_over_does') ?><?php else: ?><?= $word('ORDER', 'confirm_does') ?><?php endif; ?></span></button>
    </div>
    <p class="hint"><?php if ($overLimit): ?><?= $word('PO', 'confirm_over_limit_note') ?> <?= $say('ORDER', 'confirm_note_under', $limit) ?><?php else: ?><?= $word('ORDER', 'confirm_note') ?><?php if ($limit !== null): ?> <?= $say('ORDER', 'confirm_note_limit', $limit) ?><?php endif; ?><?php endif; ?></p>
  </section>
</form>

<details class="fold files">
  <summary><?= $word('ORDER', 'file_title') ?></summary>
  <p><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>"><?= $word('ORDER', 'download_xlsx') ?></a> · <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.csv') ?>"><?= $word('ORDER', 'csv') ?></a> · <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/pdf') ?>"><?= $word('ORDER', 'pdf_draft') ?></a></p>
  <p><?= $say('ORDER', 'file_easy', \CW\Ui\Words::ORDER['download_xlsx']) ?> <?= $word('ORDER', 'file_all_or_nothing') ?></p>
  <p class="hint"><?= $word('ORDER', 'file_columns') ?> <?php foreach ($fileColumns as $col): ?><code><?= $e($col) ?></code> <?php endforeach; ?></p>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines/import') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label><?= $say('ORDER', 'file_label', $maxMb, $maxRows) ?> <input type="file" name="file" required></label>
    <label><?= $word('ORDER', 'mode') ?>
      <select name="mode">
        <option value="append"><?= $word('ORDER', 'mode_append') ?></option>
        <option value="replace"><?= $word('ORDER', 'mode_replace') ?></option>
      </select>
    </label>
    <p class="actions"><button type="submit"><?= $word('ORDER', 'import') ?></button></p>
  </form>
</details>

<details class="fold action"<?php if ($reasonError === 'cancel'): ?> open<?php endif; ?>>
  <summary><?= $word('ORDER', 'cancel_draft') ?></summary>
  <h2 class="section-title"><?= $word('ORDER', 'cancel_draft_title') ?></h2>
  <p class="hint"><?= $word('ORDER', 'cancel_draft_does') ?></p>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label><?= $word('ORDER', 'cancel_why') ?>
      <select name="reason_code" required<?php if (($reasonError ?? null) === 'cancel'): ?> aria-invalid="true"<?php endif; ?>>
        <option value=""><?= $word('ORDER', 'choose_reason') ?></option>
<?php foreach ($cancelReasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('ORDER', 'cancel_note') ?> <input type="text" name="note" maxlength="400"></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('ORDER', 'cancel_draft_button') ?></button></p>
  </form>
</details>
