<?php
// One delivery on ONE page as the approved GoodsReceived.dc.html draws it (docs/decisions.md RD-B5; before it GV2-GV7): the editor and
// the goods-in bench together, for everyone who receives goods while it is not booked in. The head: the list's link above the title, the
// delivery with its status pill, one line under it (the supplier, its order, how many lines are checked and how many have a problem),
// More at its right (its keyer's: copy the order, import the sheet, cancel). The details (three columns, one on a phone): the keyer's
// supplier, order and arrival time, then the delivery note, the supplier's invoice number and the paperwork answer for everyone; the
// rarely used fields in "More details". A big scan box over the lines (Enter adds, or with the script finds the line). The lines: item,
// expected, received (the artboard's "Counted": the keyer's packs in, the items they make under it), the check as a pill, then the
// line's own answers: the duty stamp (a select in its pill) and "All fine" (the counted tick with no problem: a submit button without
// the script) and "Problem" (a link to the panel row: CSS :target without the script), "Open" once checked; the panel holds the
// problems, what to do with items without a stamp, the stamp code, the proof, the note, the photo and the tick. The prices fold for the
// buying desk only (invoices.view). Photos and papers (a file of the same form) and the note. "Before it can be booked in". The sticky
// bar: leave, what is left to check, Save, "Book in N items".
$bar = $partial('gi_more', ['keyer' => $keyer, 'canCancel' => $canCancel, 'doc' => $doc, 'canCopy' => $canCopy, 'importErrors' => $importErrors, 'maxMb' => $maxMb]);
$left = $left === 0 ? \CW\Ui\Words::GI['left_none'] : ($left === 1 ? \CW\Ui\Words::GI['left_one'] : \CW\Ui\Words::say('GI', 'left_many', $left));
$book = $progress['in'] === 1 ? \CW\Ui\Words::GI['book_one'] : \CW\Ui\Words::say('GI', 'book', $progress['in']);
$buttons = [['value' => 'save', 'label' => \CW\Ui\Words::GI['save']]];
if ($paged && $from + $pageSize <= $count) {
    $buttons[] = ['name' => 'next', 'value' => '1', 'label' => \CW\Ui\Words::say('GI', 'next', $pageSize)];
}
$buttons[] = ['value' => 'post', 'label' => $book, 'primary' => true];
?>
<p class="crumbs gi-crumbs"><a class="gi-leave" href="/ui/receiving" data-leave><?= $word('DOC', 'leave') ?></a></p>
<?php // The line under the title (RD-B5): the supplier, its order (a link, as in the details), how many lines are checked, the problems. ?>
<div class="doc-head">
<?= $head($doc->number ?? $title, intro: $keyer ? 'receipt_draft' : 'receipt_other', side: $bar, back: ['/ui/receiving', \CW\Ui\Words::MENU['receiving']],
    chips: $stateChip('GI_STATE', $state), meta: $e((string) ($supplier['name'] ?? '')) . ($po !== null ? ' · <a href="' . $u('/ui/purchasing/orders/' . $po['id']) . '">' . $e($po['number']) . '</a>' : '')
    . ' · ' . $say('GI', 'fact_checked', $progress['checked'], $progress['lines']) . ($progress['problems'] > 0 ? ' · ' . ($progress['problems'] === 1 ? $word('GI', 'problems_one')
    : $say('GI', 'problems_many', $progress['problems'])) : '')) ?>
</div>
<?php if ($error !== null): ?>
<div class="error gi-refused" role="alert" data-code="<?= $e($errorCode) ?>">
  <p><?= $e($error) ?></p>
<?php if ($fixes !== []): ?>
  <ul class="gi-fixes">
<?php foreach ($fixes as $fx): ?>
    <li><?php if ($fx['for'] !== null): ?><label class="gi-fix" for="<?= $e($fx['for']) ?>"><?= $e($fx['text']) ?></label><?php elseif ($fx['href'] !== null): ?><a href="<?= $e($fx['href']) ?>"><?= $e($fx['text']) ?></a><?php else: ?><?= $e($fx['text']) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($splitStep !== null): ?>
<?= $partial('receipt_split', ['doc' => $doc, 'step' => $splitStep]) ?>
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


<form class="po-editor gi-form" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/lines') ?>" data-unsaved="1" data-unsaved-text="<?= $word('RECEIPT', 'unsaved') ?>" data-max-total="<?= $e($postMax) ?>" data-max-files="<?= $e($maxFiles) ?>" data-total-words="<?= $say('GI', 'files_too_big', $postMaxMb) ?>" data-files-words="<?= $say('GI', 'files_too_many', $maxFiles - 1) ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="page" value="one">
  <input type="hidden" name="line_count" value="<?= $e($count) ?>">
  <input type="hidden" name="lines_editable" value="<?php if ($keyer): ?>1<?php else: ?>0<?php endif; ?>">
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <input type="hidden" name="page_count" value="<?= $e(count($rows)) ?>">
  <input type="hidden" name="stamp" value="<?= $e($stamp) ?>">
  <input type="hidden" name="bench_was" value="<?= $e($benchWas) ?>">
<?php if ($invWas !== null): ?>
  <input type="hidden" name="inv_was" value="<?= $e($invWas) ?>">
<?php endif; ?>
<?php if ($keyer): ?>
  <input type="hidden" name="header_present" value="1">
<?php endif; ?>
  <button type="submit" name="action" value="<?php if ($keyer): ?>add<?php else: ?>save<?php endif; ?>" class="po-default" tabindex="-1" aria-hidden="true"><?php if ($keyer): ?><?= $word('GI', 'add') ?><?php else: ?><?= $word('GI', 'save') ?><?php endif; ?></button>

  <div class="card gi-details" role="group" aria-label="<?= $word('GI', 'details') ?>">
<?php if ($keyer && $suppliers !== []): ?>
    <label class="doc-field"><?= $word('GI', 'supplier') ?>
      <select name="supplier_id">
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ((int) $s['id'] === (int) ($gr['supplier_id'] ?? 0)): ?> selected<?php endif; ?>><?php if ($s['status'] === 'active'): ?><?= $e($s['name']) ?><?php else: ?><?= $say('RECEIVING', 'not_ready', (string) $s['name'], mb_strtolower(\CW\Ui\Words::of('SUPPLIER_STATUS', (string) $s['status']))) ?><?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <div class="doc-field"><span class="gi-label"><?= $word('GI', 'supplier') ?></span><span class="gi-fixed"><?= $e($supplier['name'] ?? '') ?></span></div>
<?php endif; ?>
<?php if ($keyer && !$linked): ?>
    <label class="doc-field"><?= $word('GI', 'from_order') ?>
      <select name="po_id">
        <option value=""><?= $word('GI', 'no_order_choice') ?></option>
<?php foreach ($orders as $o): ?>
        <option value="<?= $e($o['id']) ?>"<?php if ($po !== null && (int) $po['id'] === (int) $o['id']): ?> selected<?php endif; ?>><?= $say('GI', 'order_option', (string) $o['number'], (int) $o['outstanding']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php else: ?>
    <div class="doc-field"><span class="gi-label"><?= $word('GI', 'from_order') ?></span><span class="gi-fixed"><?php if ($po !== null): ?><a href="<?= $u('/ui/purchasing/orders/' . $po['id']) ?>"><?= $e($po['number']) ?></a> <?= $stateChip('PO_STATE', (string) $po['state']) ?><?php else: ?><?= $word('GI', 'no_order_choice') ?><?php endif; ?></span></div>
<?php endif; ?>
<?php if ($keyer): ?>
    <label class="doc-field"><?= $word('GI', 'arrived') ?> <input type="datetime-local" name="received_at" value="<?= $e($typed['received_at'] ?? $receivedLocal) ?>"></label>
<?php else: ?>
    <div class="doc-field"><span class="gi-label"><?= $word('GI', 'arrived') ?></span><span class="gi-fixed"><?= $when($gr['received_at'] ?? null) ?></span>
<?php if (!$receivedToday): ?>
      <label class="choice"><input type="checkbox" name="arrived_now" value="1"<?php if (($typed['arrived_now'] ?? '') === '1'): ?> checked<?php endif; ?>> <?= $say('BENCH', 'arrived_now', $receivedDay) ?></label>
<?php endif; ?>
    </div>
<?php endif; ?>
    <label class="doc-field"><?= $word('GI', 'delivery_note') ?> <input type="text" name="delivery_note" maxlength="64" autocomplete="off" value="<?= $e($typed['delivery_note'] ?? ($gr['delivery_note'] ?? '')) ?>"></label>
    <label class="doc-field"><?= $word('GI', 'invoice') ?> <input type="text" id="gi-invoice" name="invoice_number" maxlength="64" autocomplete="off" placeholder="<?= $word('GI', 'invoice_hint') ?>" value="<?= $e($typed['invoice_number'] ?? $doc->externalRef) ?>"><?php if ($invoicePart > 1): ?> <span class="hint"><?= $say('RECEIPT', 'split_part', $invoicePart) ?></span><?php endif; ?></label>
    <label class="doc-field"><?= $word('GI', 'paperwork') ?>
      <select name="paperwork_ok" id="gi-paperwork">
        <option value=""><?= $word('GI', 'paperwork_unchecked') ?></option>
        <option value="1"<?php if ((string) ($typed['paperwork_ok'] ?? ($gr['paperwork_ok'] ?? '')) === '1'): ?> selected<?php endif; ?>><?= $word('GI', 'paperwork_yes') ?></option>
        <option value="0"<?php if ((string) ($typed['paperwork_ok'] ?? ($gr['paperwork_ok'] ?? '')) === '0'): ?> selected<?php endif; ?>><?= $word('GI', 'paperwork_no') ?></option>
      </select>
    </label>
<?php if ($arrival !== null): ?>
    <div class="doc-field"><span class="gi-label"><?= $word('GI', 'against_invoice') ?></span><span class="gi-fixed"><a href="<?= $u('/ui/purchasing/invoices/' . $arrival['invoice']['id']) ?>"><?= $e($arrival['invoice']['number']) ?></a></span></div>
<?php endif; ?>
    <details class="gi-more-details" id="gi-more"<?php if ($backdated || (int) ($gr['paper_sheet'] ?? 0) === 1 || ($gr['backdate_reason'] ?? null) !== null || in_array($errorCode, ['backdate_reason_required', 'bad_field'], true)): ?> open<?php endif; ?>>
      <summary><?= $word('GI', 'more_details') ?></summary>
      <div class="gi-more-grid">
        <label><?= $word('RECEIPT', 'invoice_date') ?> <input type="date" name="invoice_date" value="<?= $e($typed['invoice_date'] ?? ($gr['invoice_date'] ?? '')) ?>"></label>
<?php if ($keyer): ?>
        <label class="choice"><input type="checkbox" name="paper_sheet" value="1"<?php if ((isset($typed['header_present']) ? ($typed['paper_sheet'] ?? '') === '1' : (int) ($gr['paper_sheet'] ?? 0) === 1)): ?> checked<?php endif; ?>> <?= $word('RECEIPT', 'paper_sheet') ?></label>
<?php if ($backdated || (int) ($gr['paper_sheet'] ?? 0) === 1 || ($gr['backdate_reason'] ?? null) !== null || ($typed['backdate_reason'] ?? '') !== ''): ?>
        <label><?= $word('RECEIPT', 'backdate') ?> <input type="text" id="gi-backdate" name="backdate_reason" maxlength="500" value="<?= $e($typed['backdate_reason'] ?? ($gr['backdate_reason'] ?? '')) ?>"></label>
<?php endif; ?>
        <label class="gi-wide"><?= $word('RECEIPT', 'note_label') ?> <textarea name="note" maxlength="1000" rows="2"><?= $e($typed['note'] ?? $doc->note) ?></textarea></label>
<?php elseif ($doc->note !== null): ?>
        <p class="gi-wide"><?= $word('RECEIPT', 'note_label') ?>: <?= $e($doc->note) ?></p>
<?php endif; ?>
      </div>
    </details>
  </div>

  <section class="card gi-lines-card" id="lines" aria-labelledby="lines-h">
    <div class="gi-scan" id="scan">
      <label class="gi-scan-box"><?php if ($keyer): ?><?= $word('GI', 'scan') ?><?php else: ?><?= $word('GI', 'scan_find') ?><?php endif; ?>
        <input type="search" id="gi-scan"<?php if ($keyer): ?> name="q" value="<?= $e($q) ?>"<?php endif; ?> maxlength="100" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?php if ($keyer): ?><?= $word('GI', 'scan_placeholder') ?><?php else: ?><?= $word('GI', 'find_placeholder') ?><?php endif; ?>" data-not-here="<?= $word('GI', 'not_here') ?>" data-found="<?= $word('GI', 'found') ?>"<?php if ($keyer): ?> data-adds="1"<?php endif; ?>></label>
<?php if ($keyer): ?>
      <label class="gi-scan-packs"><?= $word('GI', 'packs') ?> <input type="number" name="packs" min="1" max="1000000" inputmode="numeric" value="<?= $e($packs) ?>"></label>
<?php if ($prices): ?>
      <label class="gi-scan-price"><?= $word('GI', 'price') ?> <input type="text" name="price" inputmode="decimal" autocomplete="off" value="<?= $e($price) ?>"></label>
<?php endif; ?>
      <button type="submit" name="action" value="add" class="btn soft" formnovalidate><?= $word('GI', 'add') ?></button>
<?php endif; ?>
      <p class="hint gi-said" id="gi-scan-said" role="status"></p>
    </div>
<?php if ($choices !== null): ?>
    <section class="choices" aria-labelledby="choices-h">
      <h2 id="choices-h"><?= $say('RECEIPT', 'choose', $q) ?></h2>
      <ul class="plain">
<?php foreach ($choices as $c): ?>
        <li>
<?php if ($c['supplier_item_id'] !== null): ?>
          <button class="btn" type="submit" name="add_si" value="<?= $e($c['supplier_item_id']) ?>"><?= $word('RECEIPT', 'add_button') ?></button>
<?php else: ?>
          <button class="btn" type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>"><?= $word('RECEIPT', 'add_button') ?></button>
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
    <h2 id="lines-h" class="visually-hidden"><?= $word('GI', 'lines') ?></h2>
<?php if ($paged): ?>
    <p class="note gi-page"><?= $say('GI', 'page_n', $from, min($count, $from + $pageSize - 1), $count) ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
    <p class="hint"><?= $word('RECEIPT', 'no_lines') ?></p>
<?php else: ?>
    <div class="table-wrap doc-board <?= $e(\CW\Ui\Words::tone('GI_STATE', $state)) ?>">
    <table class="stack list board doc-lines gi-lines">
      <thead>
        <tr>
          <th scope="col" class="c-item"><?= $word('GI', 'col_item') ?></th>
          <th scope="col" class="num"><?= $word('GI', 'col_expected') ?></th>
          <th scope="col" class="num"><?= $word('GI', 'col_got') ?></th>
          <th scope="col" class="c-status"><?= $word('GI', 'col_check') ?></th>
          <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('GI', 'col_action') ?></span></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($rows as $no => $l): ?>
<?php $typedRow = isset($typed['b_' . $no . '_present']); ?>
<?php $over = (int) ($typed['b_' . $no . '_over'] ?? $l['over_units']); ?>
<?php $duty = (string) ($typed['b_' . $no . '_duty'] ?? ($l['duty'] === 'unchecked' ? '' : $l['duty'])); ?>
        <tr class="gi-row <?= $e(\CW\Ui\Words::tone('GI_CHECK', $l['check'])) ?>" id="line-<?= $e($no) ?>" data-codes="<?= $e($l['codes']) ?>" data-gi-line="<?= $e($no) ?>">
          <th scope="row" class="c-head c-item">
            <input type="hidden" name="b_<?= $e($no) ?>_present" value="<?= $e($l['identity']) ?>">
            <input type="hidden" name="b_<?= $e($no) ?>_was" value="<?= $e($l['was']) ?>">
            <input type="hidden" name="b_<?= $e($no) ?>_fine" value="" data-gi-fine-mark="1">
            <a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a>
            <span class="o-sub"><?= $e($l['sku_code']) ?> · <?= $e($l['pack_label']) ?> · <?= $say('GI', 'line_n', $no) ?><?php if ($l['supplier_code'] !== null): ?> · <?= $say('RECEIPT', 'their_code', (string) $l['supplier_code']) ?><?php endif; ?></span>
<?php if ($l['findings'] !== ''): ?>
            <span class="o-sub gi-findings"><?= $e($l['findings']) ?></span>
<?php endif; ?>
<?php foreach ($l['problems'] as $p): ?>
            <span class="field-error"><?= $e($p) ?></span>
<?php endforeach; ?>
          </th>
          <td class="num" data-label="<?= $word('GI', 'col_expected') ?>"><?php if (isset($l['ordered']['packs'])): ?><?= $n($l['ordered']['packs']) ?><?php elseif (isset($l['ordered']['items'])): ?><?= $say('GI', 'ordered_items', $l['ordered']['items']) ?><?php else: ?>&mdash;<?php endif; ?></td>
          <td class="num gi-items" data-label="<?= $word('GI', 'col_got') ?>"><?php if ($keyer): ?><input type="number" name="line_<?= $e($no) ?>_packs" min="0" max="1000000" inputmode="numeric" value="<?= $e($typed['line_' . $no . '_packs'] ?? $l['packs']) ?>" class="short" aria-label="<?= $say('GI', 'line_n', $no) ?>: <?= $word('GI', 'col_packs') ?>"><?php if ($l['supplier_item_id'] === null): ?><label class="gi-upp"><?= $word('GI', 'upp') ?> <input type="number" name="line_<?= $e($no) ?>_upp" min="1" max="100000" inputmode="numeric" value="<?= $e($typed['line_' . $no . '_upp'] ?? $l['units_per_pack']) ?>" class="short"></label><?php endif; ?><?php else: ?><?= $n($l['packs']) ?><?php endif; ?><span class="o-sub"><?= $say('GI', 'ordered_items', \CW\Ui\Html::int($l['accepted'])) ?><?php if ($l['accepted'] !== (int) $l['units']): ?> <?= $say('GI', 'of_items', (int) $l['units']) ?><?php endif; ?></span></td>
          <td class="c-status doc-st <?= $e(\CW\Ui\Words::tone('GI_CHECK', $l['check'])) ?> gi-check" data-label="<?= $word('GI', 'col_check') ?>" data-words="<?= $word('GI_CHECK', 'done') ?>"><span class="chip"><?= $word('GI_CHECK', $l['check']) ?></span></td>
          <td class="c-next gi-acts"><?php if ($l['stamp_req']): ?><label class="gi-duty <?= $e(\CW\Ui\Words::tone('GI_DUTY', $duty === '' ? 'unchecked' : $duty)) ?>"><span class="gi-duty-name"><?= $word('GI', 'col_duty') ?></span> <select name="b_<?= $e($no) ?>_duty" id="gi-duty-<?= $e($no) ?>" aria-label="<?= $say('GI', 'line_n', $no) ?>: <?= $word('GI', 'col_duty') ?>" data-gi-duty="1">
              <option value=""><?= $word('GI_DUTY', 'unchecked') ?></option>
<?php foreach ($duties as $d): ?>
<?php if ($d !== 'yes' || $duty === 'yes'): ?>
              <option value="<?= $e($d) ?>"<?php if ($duty === $d): ?> selected<?php endif; ?>><?= $word('GI_DUTY', $d) ?></option>
<?php endif; ?>
<?php endforeach; ?>
            </select></label><?php endif; ?><span class="gi-why" role="alert" data-gi-why="1"></span><?php if ($l['check'] === 'todo'): ?><button type="submit" name="fine" value="<?= $e($no) ?>" class="btn ok sm" data-gi-fine="<?= $e($no) ?>" data-needs-stamp="<?= $word('GI', 'needs_stamp') ?>" data-none-stamp="<?= $word('GI', 'none_stamp') ?>" aria-label="<?= $say('GI', 'fine_label', $no) ?>"><?= $word('GI', 'all_fine') ?></button> <a class="btn sm" href="#gi-p-<?= $e($no) ?>" data-gi-panel="1" aria-controls="gi-p-<?= $e($no) ?>" aria-expanded="<?php if ($l['open']): ?>true<?php else: ?>false<?php endif; ?>" aria-label="<?= $say('GI', 'problem_label', $no) ?>"><?= $word('GI', 'problem') ?></a><?php else: ?><a class="btn ghost sm" href="#gi-p-<?= $e($no) ?>" data-gi-panel="1" aria-controls="gi-p-<?= $e($no) ?>" aria-expanded="<?php if ($l['open']): ?>true<?php else: ?>false<?php endif; ?>" aria-label="<?= $say('GI', 'open_label', $no) ?>"><?= $word('GI', 'open') ?></a><?php endif; ?></td>
        </tr>
        <tr class="gi-panel<?php if ($l['open']): ?> open<?php endif; ?>" id="gi-p-<?= $e($no) ?>">
          <td colspan="5" class="c-head">
            <div class="gi-mini">
              <label><?= $word('GI', 'short') ?> <input type="number" name="b_<?= $e($no) ?>_short" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_short'] ?? $l['short_units']) ?>"></label>
              <label><?= $word('GI', 'over') ?> <input type="number" name="b_<?= $e($no) ?>_over" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_over'] ?? $l['over_units']) ?>"></label>
              <label><?= $word('GI', 'damaged') ?> <input type="number" name="b_<?= $e($no) ?>_damaged" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_damaged'] ?? $l['damaged_units']) ?>"></label>
              <label><?= $word('GI', 'wrong') ?> <input type="number" name="b_<?= $e($no) ?>_wrong" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_wrong'] ?? $l['wrong_item_units']) ?>"></label>
<?php if ($l['stamp_req']): ?>
              <label><?= $word('GI', 'unstamped') ?> <input type="number" name="b_<?= $e($no) ?>_unstamped" min="0" inputmode="numeric" data-unstamped="1" value="<?= $e($typed['b_' . $no . '_unstamped'] ?? $l['unstamped_units']) ?>"></label>
<?php endif; ?>
            </div>
<?php if ($over > (int) $l['units']): ?>
            <label class="choice gi-overok"><input type="checkbox" name="b_<?= $e($no) ?>_overok" value="1"<?php if (($typed['b_' . $no . '_overok'] ?? ($typedRow ? '' : ($over === (int) $l['over_units'] ? '1' : ''))) === '1'): ?> checked<?php endif; ?>> <?= $word('GI', 'over_ok') ?></label>
<?php endif; ?>
            <div class="gi-panel-row">
<?php if ($l['stamp_req']): ?>
              <div class="gi-what" data-unstamped-only="1">
                <label><?= $word('GI', 'what') ?>
                  <select name="b_<?= $e($no) ?>_action">
                    <option value=""><?= $word('GI', 'what_choose') ?></option>
<?php foreach ($actions as $code => $label): ?>
<?php if ($code !== 'accept_pre_october' || !$refusal): ?>
                    <option value="<?= $e($code) ?>"<?php if (($typed['b_' . $no . '_action'] ?? ($l['unstamped_action'] ?? '')) === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endif; ?>
<?php endforeach; ?>
                  </select>
                </label>
                <?= $explain('unstamped_rule', \CW\Ui\Words::BENCH['rule_label']) ?>
              </div>
<?php if (!$refusal): ?>
              <label class="gi-proof" data-unstamped-only="1"><?= $word('GI', 'evidence') ?> <textarea name="b_<?= $e($no) ?>_evidence" maxlength="500" rows="2"><?= $e($typed['b_' . $no . '_evidence'] ?? ($l['pre_october_evidence'] ?? '')) ?></textarea></label>
<?php endif; ?>
              <label class="gi-code"><?= $word('GI', 'code') ?> <input type="text" name="b_<?= $e($no) ?>_code" maxlength="128" autocomplete="off" data-enter="next" value="<?= $e($typed['b_' . $no . '_code'] ?? ($l['stamp_code'] ?? '')) ?>"></label>
<?php endif; ?>
<?php if ($keyer): ?>
              <label class="gi-note"><?= $word('GI', 'note') ?> <input type="text" name="line_<?= $e($no) ?>_note" maxlength="255" value="<?= $e($typed['line_' . $no . '_note'] ?? $l['description']) ?>"></label>
<?php elseif ($l['description'] !== null): ?>
              <p class="gi-note"><?= $word('GI', 'note') ?>: <?= $e($l['description']) ?></p>
<?php endif; ?>
              <label class="gi-photo"><?= $word('GI', 'photo') ?> <input type="file" name="photo_<?= $e($no) ?>" accept="image/jpeg,image/png" capture="environment" data-max-bytes="<?= $e($maxBytes) ?>" data-too-big="<?= $say('RECEIPT', 'too_big', $maxMb) ?>"></label>
              <label class="choice gi-ok"><input type="checkbox" name="b_<?= $e($no) ?>_ok" value="1"<?php if ($typedRow ? ($typed['b_' . $no . '_ok'] ?? '') === '1' : $l['checked_at'] !== null): ?> checked<?php endif; ?>> <?= $word('GI', 'checked_box') ?></label>
            </div>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
<?php if ($prices && $rows !== []): ?>
    <details class="fold gi-prices"<?php if (in_array($errorCode, ['bad_line', 'mode_conflict'], true)): ?> open<?php endif; ?>>
      <summary><?= $word('GI', 'prices') ?></summary>
      <p class="muted"><?= $say('GI', 'prices_value', (string) $totals['net']) ?> <?= $e($reaches) ?></p>
      <div class="table-wrap">
      <table class="stack list gi-price-lines">
        <thead>
          <tr>
            <th scope="col" class="c-item"><?= $word('GI', 'col_item') ?></th>
            <th scope="col" class="num"><?= $word('GI', 'col_price') ?></th>
            <th scope="col" class="num"><?= $word('GI', 'col_value') ?></th>
            <th scope="col"><?= $word('GI', 'col_order_line') ?></th>
            <th scope="col"><?= $word('GI', 'col_selling') ?></th>
            <th scope="col" class="num"><?= $word('GI', 'col_stock') ?></th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($rows as $no => $l): ?>
          <tr>
            <th scope="row" class="c-head c-item"><?= $e($l['sku_name']) ?> <span class="o-sub"><?= $say('GI', 'line_n', $no) ?></span></th>
            <td class="num" data-label="<?= $word('GI', 'col_price') ?>"><?php if ($keyer): ?><input type="text" name="line_<?= $e($no) ?>_price" inputmode="decimal" value="<?= $e($typed['line_' . $no . '_price'] ?? $l['price']) ?>" class="short" aria-label="<?= $say('GI', 'line_n', $no) ?>: <?= $word('GI', 'col_price') ?>"><?php else: ?>£<?= $e($l['price']) ?><?php endif; ?></td>
            <td class="num" data-label="<?= $word('GI', 'col_value') ?>"><?= $e($l['net']) ?></td>
            <td data-label="<?= $word('GI', 'col_order_line') ?>"><?php if ($keyer && $poLines !== []): ?><select name="line_<?= $e($no) ?>_po" aria-label="<?= $say('GI', 'line_n', $no) ?>: <?= $word('GI', 'col_order_line') ?>">
                <option value="0"><?= $word('RECEIPT', 'not_against') ?></option>
<?php foreach ($poLines as $pno => $plabel): ?>
                <option value="<?= $e($pno) ?>"<?php if ((string) ($typed['line_' . $no . '_po'] ?? ($l['po_line_no'] ?? 0)) === (string) $pno): ?> selected<?php endif; ?>><?= $e($plabel) ?></option>
<?php endforeach; ?>
              </select><?php elseif ($l['po_line_no'] !== null): ?><?= $say('RECEIPT', 'order_line_no', (int) $l['po_line_no']) ?><?php endif; ?></td>
            <td data-label="<?= $word('GI', 'col_selling') ?>"><?php if ($keyer): ?><select name="line_<?= $e($no) ?>_mode" aria-label="<?= $say('GI', 'line_n', $no) ?>: <?= $word('GI', 'col_selling') ?>">
<?php foreach ($modeChoices as $mc): ?>
                <option value="<?= $e($mc) ?>"<?php if (($typed['line_' . $no . '_mode'] ?? $l['mode_choice']) === $mc): ?> selected<?php endif; ?>><?php if ($mc === 'default'): ?><?= $say('RECEIPT', 'mode_default', (string) ($l['mode_default']['mode'] ?? ''), \CW\Ui\Words::of('MODE_SOURCE', (string) ($l['mode_default']['source'] ?? 'fallback'))) ?><?php else: ?><?= $say('RECEIPT', 'mode_choice', $mc, \CW\Ui\Words::of('MODE_MEANING', $mc)) ?><?php endif; ?></option>
<?php endforeach; ?>
              </select><?php else: ?><?= $e($l['mode_resolved']['mode'] ?? '') ?><?php endif; ?></td>
            <td class="num" data-label="<?= $word('GI', 'col_stock') ?>"><?= $say('RECEIPT', 'stock_line', (int) $l['stock']['on_hand'], (int) $l['stock']['available']) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
<?php endif; ?>
  </section>

  <div class="gi-foot">
    <section class="card gi-files" id="gi-files" aria-labelledby="gi-files-h">
      <h2 id="gi-files-h"><?= $word('GI', 'files') ?></h2>
<?php if ($files === []): ?>
      <p class="hint"><?= $word('GI', 'files_none') ?></p>
<?php else: ?>
      <ul class="gi-thumbs">
<?php foreach ($files as $f): ?>
        <li><?php if ($f['locked']): ?><span class="gi-thumb"><span class="gi-thumb-what"><?= $e($f['role_word']) ?></span> <span class="gi-thumb-name"><?= $e($f['original_name']) ?></span></span><?php else: ?><a class="gi-thumb" href="<?= $u('/ui/files/' . $f['id']) ?>"><span class="gi-thumb-what"><?= $e($f['role_word']) ?></span> <span class="gi-thumb-name"><?= $e($f['original_name']) ?></span></a><?php endif; ?></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
      <div class="doc-drop gi-add">
        <label><?= $say('GI', 'file_add', $maxMb) ?> <input type="file" name="file" id="gi-file" accept="image/jpeg,image/png,application/pdf" data-max-bytes="<?= $e($maxBytes) ?>" data-too-big="<?= $say('RECEIPT', 'too_big', $maxMb) ?>"></label>
        <label><?= $word('GI', 'file_what') ?>
          <select name="file_role">
<?php foreach ($fileRoles as $role => $label): ?>
            <option value="<?= $e($role) ?>"<?php if ($role === $fileRole): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
      </div>
    </section>
    <section class="card gi-note-card" aria-labelledby="gi-note-h">
      <h2 id="gi-note-h"><label for="gi-note"><?= $word('GI', 'note_title') ?></label></h2>
      <textarea id="gi-note" name="bench_note" maxlength="500" rows="3" placeholder="<?= $word('GI', 'note_hint') ?>"><?= $e($typed['bench_note'] ?? ($gr['bench_note'] ?? '')) ?></textarea>
    </section>
  </div>

  <details class="fold gi-ready" id="ready"<?php if ($refusedPost): ?> open<?php endif; ?>>
    <summary><?php if ($plan['problems'] === []): ?><?= $word('RECEIPT', 'ready_tag') ?><?php elseif (count($plan['problems']) === 1): ?><?= $word('RECEIPT', 'to_settle_one') ?><?php else: ?><?= $say('RECEIPT', 'to_settle', count($plan['problems'])) ?><?php endif; ?></summary>
    <h2 class="section-title"><?= $word('RECEIPT', 'ready_title') ?></h2>
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
  </details>
<?php if ($paged): ?>
  <p class="pager"><?php if ($from > 1): ?><a href="<?= $u('/ui/receiving/' . $doc->id, ['from' => max(1, $from - $pageSize)]) ?>#lines"><?= $word('BENCH', 'earlier') ?></a><?php endif; ?>
<?php if ($from + $pageSize <= $count): ?><a href="<?= $u('/ui/receiving/' . $doc->id, ['from' => $from + $pageSize]) ?>#lines"><?= $word('BENCH', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>
<?= $partial('doc_bar', ['back' => '/ui/receiving', 'note' => $left, 'noteData' => ['gi-left' => (string) ($count - $progress['checked']), 'one' => \CW\Ui\Words::GI['left_one'],
    'many' => \CW\Ui\Words::GI['left_many'], 'none' => \CW\Ui\Words::GI['left_none']], 'buttons' => $buttons]) ?>
</form>
