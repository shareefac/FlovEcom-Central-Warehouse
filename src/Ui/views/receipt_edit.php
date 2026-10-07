<p class="crumbs"><a href="/ui/receiving"><?= $word('MENU', 'receiving') ?></a> <a href="<?= $u('/ui/receiving/' . $doc->id . '/bench') ?>"><?= $word('RECEIPT', 'to_bench') ?></a></p>
<h1><?= $e($title) ?></h1>
<p class="eyebrow"><?= $stateChip('RECEIPT_STATE', 'draft') ?><?php if ($benchState !== null): ?> <?= $stateChip('BENCH_STATE', $benchState) ?><?php endif; ?></p>
<?= $intro('receipt_draft') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($refusedPost): ?> <a href="#ready-h"><?= $word('RECEIPT', 'refused_see') ?></a><?php endif; ?></p>
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
  <h2 id="import-errors-h"><?= $word('RECEIPT', 'import_failed') ?></h2>
  <ul class="import-errors">
<?php foreach ($importErrors as $ie): ?>
    <li><?= $e($ie) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($skipped !== []): ?>
<details class="fold skipped">
  <summary><?= $say('RECEIPT', 'skipped', count($skipped)) ?></summary>
  <ul class="plain">
<?php foreach ($skipped as $sk): ?>
    <li><?= $e($sk) ?></li>
<?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>
<?php if ($supplier !== [] && $supplier['status'] !== 'active'): ?>
<p class="note"><?= $say('RECEIPT', 'not_approved_supplier', (string) $supplier['name']) ?></p>
<?php endif; ?>

<form class="po-editor grn-editor" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/lines') ?>" data-unsaved="1" data-unsaved-text="<?= $word('RECEIPT', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="line_count" value="<?= $e($editable ? count($lines) : 0) ?>">
  <input type="hidden" name="lines_editable" value="<?php if ($editable): ?>1<?php else: ?>0<?php endif; ?>">
  <input type="hidden" name="stamp" value="<?= $e($stamp) ?>">
  <input type="hidden" name="header_present" value="1">
  <fieldset class="po-header">
    <legend><?= $word('RECEIPT', 'delivery') ?></legend>
<?php if ($suppliers !== []): ?>
    <label><?= $word('RECEIPT', 'supplier') ?>
      <select name="supplier_id">
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ((int) $s['id'] === (int) ($gr['supplier_id'] ?? 0)): ?> selected<?php endif; ?>><?php if ($s['status'] === 'active'): ?><?= $e($s['name']) ?><?php else: ?><?= $say('RECEIVING', 'not_ready', (string) $s['name'], mb_strtolower(\CW\Ui\Words::of('SUPPLIER_STATUS', (string) $s['status']))) ?><?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <p><?= $word('RECEIPT', 'supplier') ?>: <?= $e($supplier['name'] ?? '') ?> <span class="hint"><?= $word('RECEIPT', 'supplier_fixed') ?></span></p>
<?php endif; ?>
<?php if (!$linked): ?>
    <label><?= $word('RECEIPT', 'po') ?>
      <select name="po_id">
        <option value=""><?= $word('RECEIPT', 'po_none') ?></option>
<?php foreach ($orders as $o): ?>
        <option value="<?= $e($o['id']) ?>"<?php if ($po !== null && (int) $po['id'] === (int) $o['id']): ?> selected<?php endif; ?>><?= $say('RECEIPT', 'po_option', (string) $o['number'], (int) $o['outstanding']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <p><?= $word('RECEIPT', 'against') ?> <a href="<?= $u('/ui/purchasing/orders/' . $po['id']) ?>"><?= $e($po['number']) ?></a> <?= $stateChip('PO_STATE', (string) $po['state']) ?>
      <span class="hint"><?= $word('RECEIPT', 'po_fixed') ?></span></p>
<?php endif; ?>
    <label><?= $word('RECEIPT', 'invoice_number') ?> <input type="text" name="invoice_number" maxlength="64" value="<?= $e($typed['invoice_number'] ?? $doc->externalRef) ?>" autocomplete="off"></label>
    <label><?= $word('RECEIPT', 'invoice_date') ?> <input type="date" name="invoice_date" value="<?= $e($typed['invoice_date'] ?? ($gr['invoice_date'] ?? '')) ?>"></label>
    <label><?= $word('RECEIPT', 'delivery_note_no') ?> <input type="text" name="delivery_note" maxlength="64" value="<?= $e($typed['delivery_note'] ?? ($gr['delivery_note'] ?? '')) ?>"></label>
    <label><?= $word('RECEIPT', 'arrived_at') ?> <input type="datetime-local" name="received_at" value="<?= $e($typed['received_at'] ?? $receivedLocal) ?>"></label>
    <label class="choice"><input type="checkbox" name="paper_sheet" value="1"<?php if ((isset($typed['header_present']) ? ($typed['paper_sheet'] ?? '') === '1' : (int) ($gr['paper_sheet'] ?? 0) === 1)): ?> checked<?php endif; ?>> <?= $word('RECEIPT', 'paper_sheet') ?></label>
    <label><?= $word('RECEIPT', 'backdate') ?> <input type="text" name="backdate_reason" maxlength="500" value="<?= $e($typed['backdate_reason'] ?? ($gr['backdate_reason'] ?? '')) ?>"></label>
    <label><?= $word('RECEIPT', 'note_label') ?> <textarea name="note" maxlength="1000" rows="2"><?= $e($typed['note'] ?? $doc->note) ?></textarea></label>
  </fieldset>

  <fieldset class="scan" id="scan">
    <legend><?= $word('RECEIPT', 'add') ?></legend>
    <label><?= $word('RECEIPT', 'scan') ?>
      <input type="search" name="q" value="<?= $e($q) ?>" maxlength="100" autofocus autocomplete="off" aria-describedby="scan-hint"></label>
    <label><?= $word('RECEIPT', 'scan_packs') ?> <input type="number" name="packs" min="1" max="1000000" value="<?= $e($packs) ?>" class="short"></label>
    <label><?= $word('RECEIPT', 'scan_price') ?> <input type="text" name="price" value="<?= $e($price) ?>" class="short" inputmode="decimal" autocomplete="off"></label>
    <button type="submit" name="action" value="add" class="primary" formnovalidate><?= $word('RECEIPT', 'add_button') ?></button>
    <p class="hint" id="scan-hint"><?= $word('RECEIPT', 'scan_hint') ?></p>
  </fieldset>
<?php if ($choices !== null): ?>
  <section class="choices" aria-labelledby="choices-h">
    <h2 id="choices-h"><?= $say('RECEIPT', 'choose', $q) ?></h2>
    <ul class="plain">
<?php foreach ($choices as $c): ?>
      <li>
<?php if ($c['supplier_item_id'] !== null): ?>
        <button type="submit" name="add_si" value="<?= $e($c['supplier_item_id']) ?>"><?= $word('RECEIPT', 'add_button') ?></button>
<?php else: ?>
        <button type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>"><?= $word('RECEIPT', 'add_button') ?></button>
<?php endif; ?>
        <?= $e($c['name']) ?> <span class="muted"><?= $e($c['sku_code']) ?></span><?php if ($c['brand'] !== null): ?> <span class="muted"><?= $e($c['brand']) ?></span><?php endif; ?>
<?php if ($c['supplier_item_id'] !== null): ?>
        <?php if ($c['supplier_code'] !== null): ?><?= $chip('done', \CW\Ui\Words::say('RECEIPT', 'choice_set_up', (string) $c['supplier_code'], \CW\Ui\Controller\PurchaseOrdersController::pack((string) $c['purchase_unit'], (int) $c['units_per_pack']))) ?><?php else: ?><?= $chip('done', \CW\Ui\Words::say('RECEIPT', 'choice_set_up_no_code', \CW\Ui\Controller\PurchaseOrdersController::pack((string) $c['purchase_unit'], (int) $c['units_per_pack']))) ?><?php endif; ?>
<?php else: ?>
        <?= $chip('needs', \CW\Ui\Words::RECEIPT['choice_single']) ?>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

  <section aria-labelledby="lines-h">
    <h2 id="lines-h"><?= $word('RECEIPT', 'lines') ?></h2>
<?php if (!$editable): ?>
    <p class="note"><?= $say('RECEIPT', 'too_many', $maxLines) ?></p>
<?php endif; ?>
<?php if ($lines === []): ?>
    <p class="hint"><?= $word('RECEIPT', 'no_lines') ?></p>
<?php else: ?>
    <p class="hint"><?= $e($reaches) ?></p>
    <div class="table-wrap">
    <table class="stack po-lines grn-lines">
      <thead>
        <tr>
          <th scope="col"><?= $word('RECEIPT', 'product') ?></th>
          <th scope="col"><?= $word('RECEIPT', 'pack') ?></th>
          <th scope="col" class="num"><?= $word('RECEIPT', 'packs') ?></th>
          <th scope="col" class="num"><?= $word('RECEIPT', 'items') ?></th>
          <th scope="col" class="num"><?= $word('RECEIPT', 'per_pack_edit') ?></th>
          <th scope="col" class="num"><?= $word('RECEIPT', 'line_value') ?></th>
          <th scope="col"><?= $word('RECEIPT', 'order_line') ?></th>
          <th scope="col" class="num"><?= $word('RECEIPT', 'stock_now') ?></th>
          <th scope="col"><?= $word('RECEIPT', 'selling_edit') ?></th>
          <th scope="col"><?= $word('RECEIPT', 'duty_stamp') ?></th>
          <th scope="col"><?= $word('RECEIPT', 'bench_col') ?></th>
          <th scope="col"><?= $word('RECEIPT', 'note_col') ?></th>
        </tr>
      </thead>
      <tbody>
<?php $i = 0; ?>
<?php foreach ($lines as $no => $l): ?>
<?php $i++; ?>
        <tr id="line-<?= $e($no) ?>"<?php if ($l['problems'] !== []): ?> class="differs"<?php endif; ?>>
          <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a> <span class="o-sub"><?= $e($l['sku_code']) ?> · <?= $say('RECEIPT', 'line', $no) ?><?php if ($l['supplier_code'] !== null): ?> · <?= $say('RECEIPT', 'their_code', (string) $l['supplier_code']) ?><?php endif; ?></span>
<?php foreach ($l['problems'] as $p): ?>
            <span class="field-error"><?= $e($p) ?></span>
<?php endforeach; ?>
          </th>
          <td data-label="<?= $word('RECEIPT', 'pack') ?>"><?php if ($l['supplier_item_id'] === null && $editable): ?><label><?= $word('RECEIPT', 'items_per_pack') ?> <input type="number" name="line_<?= $e($i) ?>_upp" min="1" max="100000" value="<?= $e($typed['line_' . $i . '_upp'] ?? $l['units_per_pack']) ?>" class="short"></label><?php else: ?><?= $e($l['pack_label']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('RECEIPT', 'packs') ?>"><?php if ($editable): ?><input type="number" name="line_<?= $e($i) ?>_packs" min="0" max="1000000" value="<?= $e($typed['line_' . $i . '_packs'] ?? $l['packs']) ?>" class="short" aria-label="<?= $say('RECEIPT', 'line', $no) ?>: <?= $word('RECEIPT', 'packs') ?>"><?php else: ?><?= $n($l['packs']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('RECEIPT', 'items') ?>"><?= $n($l['units']) ?></td>
          <td class="num" data-label="<?= $word('RECEIPT', 'per_pack') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($i) ?>_price" value="<?= $e($typed['line_' . $i . '_price'] ?? $l['price']) ?>" class="short" inputmode="decimal" aria-label="<?= $say('RECEIPT', 'line', $no) ?>: <?= $word('RECEIPT', 'per_pack_edit') ?>"><?php else: ?>£<?= $e($l['price']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('RECEIPT', 'line_value') ?>"><?= $e($l['net']) ?></td>
          <td data-label="<?= $word('RECEIPT', 'order_line') ?>"><?php if ($editable && $poLines !== []): ?><select name="line_<?= $e($i) ?>_po" aria-label="<?= $say('RECEIPT', 'line', $no) ?>: <?= $word('RECEIPT', 'order_line') ?>">
              <option value="0"><?= $word('RECEIPT', 'not_against') ?></option>
<?php foreach ($poLines as $pno => $plabel): ?>
              <option value="<?= $e($pno) ?>"<?php if ((string) ($typed['line_' . $i . '_po'] ?? ($l['po_line_no'] ?? 0)) === (string) $pno): ?> selected<?php endif; ?>><?= $e($plabel) ?></option>
<?php endforeach; ?>
            </select><?php elseif ($l['po_line_no'] !== null): ?><?= $say('RECEIPT', 'order_line_no', (int) $l['po_line_no']) ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('RECEIPT', 'stock_now') ?>"><?= $say('RECEIPT', 'stock_line', (int) $l['stock']['on_hand'], (int) $l['stock']['available']) ?></td>
          <td data-label="<?= $word('RECEIPT', 'selling_edit') ?>"><?php if ($editable): ?><select name="line_<?= $e($i) ?>_mode" aria-label="<?= $say('RECEIPT', 'line', $no) ?>: <?= $word('RECEIPT', 'selling_edit') ?>">
<?php foreach ($modeChoices as $mc): ?>
              <option value="<?= $e($mc) ?>"<?php if (($typed['line_' . $i . '_mode'] ?? $l['mode_choice']) === $mc): ?> selected<?php endif; ?>><?php if ($mc === 'default'): ?><?= $say('RECEIPT', 'mode_default', (string) ($l['mode_default']['mode'] ?? ''), \CW\Ui\Words::of('MODE_SOURCE', (string) ($l['mode_default']['source'] ?? 'fallback'))) ?><?php else: ?><?= $say('RECEIPT', 'mode_choice', $mc, \CW\Ui\Words::of('MODE_MEANING', $mc)) ?><?php endif; ?></option>
<?php endforeach; ?>
            </select><?php else: ?><?= $e($l['mode_resolved']['mode'] ?? '') ?><?php endif; ?>
            <span class="o-sub"><?php if ($l['mode_now'] !== null): ?><?= $say('RECEIPT', 'mode_now', (string) $l['mode_now']) ?><?php else: ?><?= $word('RECEIPT', 'mode_none') ?><?php endif; ?></span></td>
          <td data-label="<?= $word('RECEIPT', 'duty_stamp') ?>"><?php if ($l['stamp_req']): ?><?= $chip('info', \CW\Ui\Words::RECEIPT['stamp_needed']) ?><?php if ($l['duty_unknown']): ?> <?= $chip('needs', \CW\Ui\Words::RECEIPT['duty_unknown']) ?><?php endif; ?><?php else: ?><?= $chip('off', \CW\Ui\Words::RECEIPT['stamp_not_needed']) ?><?php endif; ?><?php if ($l['duty_text'] !== null): ?> <span class="o-sub"><?= $say('RECEIPT', 'duty_about', (string) $l['duty_text']) ?></span><?php endif; ?></td>
          <td data-label="<?= $word('RECEIPT', 'bench_col') ?>"><?php if ($l['checked_at'] === null): ?><?= $chip('waiting', \CW\Ui\Words::RECEIPT['waiting_bench']) ?><?php else: ?><?= $chip('done', \CW\Ui\Words::RECEIPT['checked']) ?><?php if ($l['findings'] !== ''): ?> <span class="o-sub"><?= $e($l['findings']) ?></span><?php endif; ?><?php endif; ?></td>
          <td data-label="<?= $word('RECEIPT', 'note_col') ?>"><?php if ($editable): ?><input type="text" name="line_<?= $e($i) ?>_note" maxlength="255" value="<?= $e($typed['line_' . $i . '_note'] ?? $l['description']) ?>" aria-label="<?= $say('RECEIPT', 'line', $no) ?>: <?= $word('RECEIPT', 'note_col') ?>"><?php else: ?><?= $e($l['description']) ?><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
    <p class="hint"><?= $word('RECEIPT', 'lines_hint') ?></p>
  </section>
  <div class="actions sticky">
    <button type="submit" name="action" value="save" formnovalidate><?= $word('RECEIPT', 'save') ?></button>
    <button type="submit" name="action" value="post" class="primary"><?= $word('RECEIPT', 'save_book') ?></button>
    <?php if ($plan['problems'] === []): ?><?= $chip('done', \CW\Ui\Words::RECEIPT['ready_tag']) ?><?php else: ?><a href="#ready-h"><?php if (count($plan['problems']) === 1): ?><?= $word('RECEIPT', 'to_settle_one') ?><?php else: ?><?= $say('RECEIPT', 'to_settle', count($plan['problems'])) ?><?php endif; ?></a><?php endif; ?>
  </div>
  <p class="hint"><?= $word('RECEIPT', 'save_book_does') ?></p>
</form>

<section class="card box checklist" aria-labelledby="ready-h">
  <div class="head-help">
    <h2 id="ready-h"><?= $word('RECEIPT', 'ready_title') ?></h2>
    <?= $explain('posting', \CW\Ui\Words::RECEIVING['posting_label']) ?>
  </div>
<?php if ($plan['problems'] === []): ?>
  <p class="notice"><?= $word('RECEIPT', 'ready') ?></p>
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
  <div class="head-help">
    <h3><?= $word('RECEIPT', 'where_draft') ?></h3>
    <?= $explain('where_units_go', \CW\Ui\Words::RECEIPT['where_label']) ?>
  </div>
  <dl>
    <dt><?= $word('RECEIPT', 'size') ?></dt><dd><?= $e($totals['size']) ?></dd>
    <dt><?= $word('RECEIPT', 'value') ?></dt><dd><?= $e($totals['net']) ?></dd>
    <dt><?= $word('RECEIPT', 'into_stock') ?></dt><dd><?= $n($totals['accepted']) ?></dd>
    <dt><?= $word('RECEIPT', 'set_aside') ?></dt><dd><?= $n($totals['verify']) ?></dd>
    <dt><?= $word('RECEIPT', 'quarantine') ?></dt><dd><?= $n($totals['quarantine']) ?></dd>
    <dt><?= $word('RECEIPT', 'refused') ?></dt><dd><?= $n($totals['refused']) ?></dd>
    <dt><?= $word('RECEIPT', 'short') ?></dt><dd><?= $n($totals['short']) ?></dd>
    <dt><?= $word('RECEIPT', 'duty') ?></dt><dd><?= $say('RECEIPT', 'duty_note', (string) $totals['duty']) ?></dd>
  </dl>
</section>

<?php if ($po !== null): ?>
<section class="card copy-order" aria-labelledby="copy-h">
  <h2 id="copy-h"><?= $word('RECEIPT', 'copy_title') ?></h2>
  <form class="quick" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/copy') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit"><?= $say('RECEIPT', 'copy_button', (string) $po['number']) ?></button>
  </form>
  <p class="hint"><?= $word('RECEIPT', 'copy_hint') ?></p>
</section>
<?php endif; ?>

<details class="fold sheet"<?php if ($importErrors !== []): ?> open<?php endif; ?>>
  <summary><?= $word('RECEIPT', 'sheet') ?></summary>
  <h2 class="section-title" id="sheet-h"><?= $word('RECEIPT', 'sheet_title') ?></h2>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/import') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label><?= $say('RECEIPT', 'sheet_file', $maxMb) ?> <input type="file" name="file" required></label>
    <label><?= $word('RECEIPT', 'sheet_mode') ?>
      <select name="mode">
        <option value="append"><?= $word('RECEIPT', 'sheet_append') ?></option>
        <option value="replace"><?= $word('RECEIPT', 'sheet_replace') ?></option>
      </select>
    </label>
    <p class="actions"><button type="submit"><?= $word('RECEIPT', 'sheet_button') ?></button></p>
  </form>
  <p class="hint"><?= $word('RECEIPT', 'sheet_hint') ?> <a href="/ui/receiving/template.csv"><?= $word('RECEIVING', 'sample') ?></a></p>
</details>

<?= $partial('receipt_files', ['doc' => $doc, 'files' => $files, 'fileRoles' => $fileRoles, 'csrf' => $csrf, 'canAttach' => true, 'back' => '', 'maxMb' => $maxMb]) ?>

<details class="fold action">
  <summary><?= $word('RECEIPT', 'cancel') ?></summary>
  <h2 class="section-title" id="cancel-h"><?= $word('RECEIPT', 'cancel_title') ?></h2>
  <p class="hint"><?= $word('RECEIPT', 'cancel_does') ?></p>
  <form class="record" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/cancel') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <label><?= $word('RECEIPT', 'cancel_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('RECEIPT', 'cancel_button') ?></button></p>
  </form>
</details>
