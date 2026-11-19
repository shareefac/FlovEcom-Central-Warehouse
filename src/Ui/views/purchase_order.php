<?php
// A purchase order, read like a document as the approved PurchaseOrder.dc.html draws it (docs/decisions.md RD-B4; before it PV12, PV19; the
// order's rules and actions as before): the list's link above the title, the order's number with its status pill beside it, one line under
// it (the reviewer's check, the supplier, when it was ordered and by whom), the actions at the head's right (po_bar, PV27: More,
// then the next step in the accent; a step that needs a reason or a choice opens under the bar: po_action). The progress steps (approved,
// sent to the supplier, received, invoiced). The paper: the supplier, where it delivers and the dates in three blocks, the lines (item,
// ordered, received with what is still to come, unit cost, line total), the note to the supplier and the totals. Then Related (its
// deliveries and invoices, the orders it corrects or that correct it, its cancellation, its files) and History (what happened to it, and
// its checks and OKs).
// One look (live drafts step 2, docs/decisions.md LP1-LP8; the owner, 18 Nov 2026: "I liked how it looks on Shareef's window, the design"):
// a new order and a draft, for anyone who writes orders, are the same paper with their boxes in place ($paperEdit): the paper's parts
// (po_paper_head, po_paper_lines, po_paper_foot) inside the one editor form, which posts the whole page with its version as the one-page form
// did (Save and Confirm in the head's bar, for the form; the grid's keys, Add multiple, + New item and the save without leaving the page by
// app.js). A person who may only look gets the same parts with words instead of boxes. The old one-page form is purchase_order_edit.php
// (po.draft_look = form, the way back for one release). The order's ONE history is the History card: who changed it while it was a draft is
// in it (LP10; no separate "History of changes" here).
// Who is here (live drafts step 3b, docs/decisions.md LV18-LV25): with live.mode on, a draft's editor form (or its paper, for a person who may
// only look) carries the poll and its words ($live: LiveController::pageData, live_attrs) and the chips sit under the title (live_here); each
// line names its own id (data-uid), each box its place (data-live-cell, data-live-box). A confirmed order and a new one show nobody.
$paperEdit = $paperEdit ?? false;
$live = $live ?? null;
?>
<?php if ($paperEdit): ?>
<?php
$bar = $partial('po_bar', ['editor' => $editor, 'moreActions' => $moreActions ?? [], 'mainAction' => null, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit,
    'supplier' => $supplier ?? [], 'sendVia' => $sendVia ?? [], 'sendWarnings' => $sendWarnings ?? [], 'sendCompany' => $sendCompany ?? false, 'companyNote' => $companyNote,
    'formKey' => $barKey ?? '', 'cancelReasons' => $cancelReasons ?? [], 'amendReasons' => $amendReasons ?? [], 'reasonError' => $reasonError ?? null, 'errorCode' => $errorCode ?? null]);
$paper = ['edit' => true, 'isNew' => $isNew, 'doc' => $doc, 'party' => $party, 'deliverTo' => $deliverTo, 'orderDate' => $orderDate, 'terms' => $terms, 'info' => $info,
    'supplierFixed' => $supplierFixed, 'supplierName' => $supplierName ?? '', 'supplierId' => $supplierId, 'supplierChoices' => $supplierChoices, 'canAddSupplier' => $canAddSupplier,
    'askSupplier' => $askSupplier, 'supplierError' => $supplierError, 'newSupplier' => $newSupplier];
?>
<div class="doc-head">
<?= $head((string) $title, intro: $isNew ? 'order_new' : 'order_draft', side: $bar, back: ['/ui/purchasing/orders', \CW\Ui\Words::MENU['orders']],
    aside: $isNew ? $chip('waiting', \CW\Ui\Words::DOC_GRID['not_saved']) : '', meta: $isNew ? '' : $stateChip('PO_STATE', 'draft') . ' ' . $e($factLine)) ?>
</div>
<?php if ($live !== null): ?>
<?= $partial('live_here', []) ?>
<?php endif; ?>
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
<?php if (($rejected ?? null) !== null): ?>
<div class="error" role="status">
  <p><?= $say('ORDER', 'rejected', (string) $rejected['decision_note'], (string) ($rejected['decided_by_name'] ?? ''), \CW\Ui\Html::day((string) $rejected['decided_at'])) ?></p>
  <p><?= $word('ORDER', 'rejected_next') ?></p>
</div>
<?php endif; ?>
<?php if ($warnings !== []): ?>
<ul class="warnings">
<?php foreach ($warnings as $w): ?>
  <li><?= $e($w) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($supplierNeeds !== null): ?>
<?= $partial('po_supplier_needs', ['needs' => $supplierNeeds]) ?>
<?php endif; ?>
<?php // No company note on the page that makes the order (PX10): the confirmed order's page says it. ?>

<form id="po-form" class="po-editor kg-doc kg-paper" method="post" action="<?= $u($formAction) ?>" data-kg-editor="po:<?= $e($isNew ? 'new' : $doc->id) ?>"<?php if (!$isNew): ?> data-kg-address="<?= $u('/ui/purchasing/orders/' . $doc->id) ?>"<?php endif; ?> novalidate data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>"<?php if ($grid['newRows'] !== [] || $grid['removed'] !== []): ?> data-dirty="1"<?php endif; ?><?php if (($errorCode ?? null) === 'version_conflict'): ?> data-kg-conflict<?php endif; ?><?php if ($live !== null): ?><?= $partial('live_attrs', ['live' => $live]) ?><?php endif; ?>>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if (!$isNew): ?>
  <input type="hidden" name="version" value="<?= $e($formVersion ?? $doc->version) ?>">
  <input type="hidden" name="line_count" value="<?= $e($editable ? count($lines) : 0) ?>">
  <input type="hidden" name="lines_editable" value="<?php if ($editable): ?>1<?php else: ?>0<?php endif; ?>">
<?php endif; ?>
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="<?= $e(\CW\Ui\DocGrid::FIELD) ?>" value="<?= $e($grid['newJson']) ?>" data-kg-field>
  <button type="submit" name="action" value="add" class="po-default" tabindex="-1" aria-hidden="true"><?= $word('ORDER', 'add_button') ?></button>
  <div class="kg-look" data-kg-look role="alert" hidden></div>
  <article class="doc-view po-paper" aria-labelledby="lines-h">
<?= $partial('po_paper_head', $paper) ?>
  <section class="po-board po-paper-lines" id="scan" aria-labelledby="lines-h">
    <h2 id="lines-h" class="visually-hidden"><?= $word('ORDER', 'lines') ?></h2>
    <div data-kg-swap-part="notes">
<?php if ($unpriced !== null): ?>
    <p class="note kg-unpriced" role="status"><?= $e($unpriced) ?></p>
<?php endif; ?>
<?php if (!$editable): ?>
    <p class="note"><?= $say('ORDER', 'too_many', $maxLines) ?></p>
<?php endif; ?>
    </div>
<?= $partial('po_paper_lines', ['edit' => true, 'isNew' => $isNew, 'doc' => $doc, 'lines' => $lines, 'stock' => $stock ?? [], 'state' => $state, 'editable' => $editable, 'grid' => $grid,
    'gridVat' => $gridVat, 'typed' => $typed, 'q' => $q, 'packs' => $packs, 'pick' => $pick, 'productsUrl' => $productsUrl, 'canAddItem' => $canAddItem, 'askItem' => $askItem]) ?>
<?php if ($canAddItem): ?>
    <details class="card po-add" id="po-add-item" data-po-panel data-ni-url="<?= $e(\CW\Ui\Controller\PoAddNew::NEW_ITEM['po']) ?>"<?php if ($supplierFixed): ?> data-supplier="<?= $e($supplierId) ?>"<?php endif; ?><?php if ($itemOpen): ?> open<?php endif; ?>>
      <summary><?= $word('PO_ADD', 'new_item') ?></summary>
      <input type="hidden" name="new_item_key" value="<?= $e(\CW\Ui\FormOnce::newKey()) ?>" data-ni-key>
<?php if ($itemError !== null): ?>
      <p class="error" role="alert"><?= $e($itemError) ?></p>
<?php endif; ?>
<?php if ($itemOther !== null): ?>
      <p class="note"><?= $say('PO_ADD', 'barcode_other', $itemOther['label']) ?> <button type="submit" name="add_sku" value="<?= $e($itemOther['id']) ?>" class="btn"><?= $word('PO_ADD', 'barcode_use') ?></button></p>
<?php endif; ?>
<?php if ($itemSame !== null): ?>
      <div class="note same-items">
        <p><strong><?= $word('PO_ADD', 'same_title') ?></strong> <?= $word('PO_ADD', 'same_text') ?></p>
        <ul class="plain">
<?php foreach ($itemSame as $s): ?>
          <li><a href="<?= $u('/ui/items/' . $s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?> <button type="submit" name="add_sku" value="<?= $e($s['id']) ?>" class="btn"><?= $word('PO_ADD', 'same_use') ?></button></li>
<?php endforeach; ?>
        </ul>
      </div>
<?php endif; ?>
      <label><?= $word('PO_ADD', 'item_name') ?> <input type="text" name="item_name" maxlength="255" autocomplete="off" value="<?= $e($item['name']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_brand') ?> <input type="text" name="item_brand" maxlength="100" value="<?= $e($item['brand']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_barcode') ?> <input type="text" name="item_barcode" maxlength="64" inputmode="numeric" autocomplete="off" value="<?= $e($item['barcode']) ?>"></label>
<?php if ($itemSame !== null): ?>
      <label class="choice"><input type="checkbox" name="item_anyway" value="1"<?php if ($item['anyway']): ?> checked<?php endif; ?>> <?= $word('PO_ADD', 'same_anyway') ?></label>
<?php endif; ?>
      <label><?= $word('PO_ADD', 'item_code') ?> <input type="text" name="item_code" maxlength="64" autocomplete="off" value="<?= $e($item['code']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_upp') ?> <input type="number" name="item_upp" min="1" max="100000" class="short" value="<?= $e($item['upp']) ?>"></label>
      <label><?= $word('PO_ADD', 'item_price') ?> <input type="text" name="item_price" inputmode="decimal" class="short" value="<?= $e($item['price']) ?>"></label>
      <button type="submit" name="action" value="new_item" class="btn"><?= $word('PO_ADD', 'item_button') ?></button>
    </details>
<?php else: ?>
    <p class="hint po-ask"><?= $e($askItem) ?></p>
<?php endif; ?>
  </section>
<?php if ($choices !== null): ?>
  <section class="choices" aria-labelledby="choices-h">
    <h2 id="choices-h"><?= $say('ORDER', 'choose', $q) ?></h2>
    <ul class="plain">
<?php foreach ($choices as $c): ?>
      <li>
<?php if ($c['supplier_item_id'] !== null): ?>
        <button class="btn" type="submit" name="add_si" value="<?= $e($c['supplier_item_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php else: ?>
        <button class="btn" type="submit" name="add_sku" value="<?= $e($c['sku_id']) ?>"><?= $word('ORDER', 'add_button') ?></button>
<?php endif; ?>
        <?= $e($c['name']) ?> <span class="muted"><?= $e($c['sku_code']) ?></span><?php if ($c['brand'] !== null): ?> <span class="muted"><?= $e($c['brand']) ?></span><?php endif; ?>
<?php if ($c['supplier_item_id'] !== null): ?>
        <span class="tag done"><?= $say('ORDER', 'choice_set_up', (string) ($c['supplier_code'] ?? \CW\Ui\Words::ORDER['choice_no_code']), \CW\Ui\Controller\PurchaseOrdersController::pack((string) $c['purchase_unit'], (int) $c['units_per_pack'])) ?></span>
<?php else: ?>
        <span class="tag needs"><?= $word('ORDER', 'choice_new') ?></span>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
<?= $partial('po_paper_foot', ['edit' => true, 'note' => $info['note'], 'lines' => $lines, 'units' => $units, 'totals' => $totals, 'vatLabels' => $vatLabels, 'typed' => $typed]) ?>
  </article>
</form>
<?php if (!$isNew && !$editable): ?>

<form class="upload" id="po-upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines/import') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="mode" value="replace">
  <p><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>" download><?= $word('ORDER', 'download_xlsx') ?></a></p>
  <label><?= $say('ORDER', 'file_label', $maxMb, $maxRows) ?> <input type="file" name="file" required></label>
  <p class="actions"><button type="submit" class="btn"><?= $word('ORDER', 'import') ?></button></p>
</form>
<?php endif; ?>
<?php if (!$isNew): ?>
<?= $partial('po_cards', ['related' => $related, 'events' => $events, 'eventsMore' => $eventsMore ?? null, 'history' => $history]) ?>
<?php endif; ?>
<?php else: ?>
<?php $sendBelow = $mainAction === 'send' && $state === 'approved'; // confirmed, not sent: the send form a card under the head (LP11)
$bar = $partial('po_bar', ['moreActions' => $moreActions, 'mainAction' => $mainAction, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit,
    'supplier' => $supplier, 'sendVia' => $sendVia, 'sendWarnings' => $sendWarnings, 'sendCompany' => $sendCompany, 'companyNote' => $companyNote, 'formKey' => $formKey,
    'cancelReasons' => $cancelReasons, 'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode, 'sendBelow' => $sendBelow]); ?>
<div class="doc-head">
<?php // PO_View (DC22): the heading is the order's number with its status beside it (a draft without a number keeps its title, its status in the line under it). ?>
<?= $head((string) ($doc->number ?? $title), intro: 'order', side: $bar, back: ['/ui/purchasing/orders', \CW\Ui\Words::MENU['orders']], chips: $doc->number !== null ? $stateChip('PO_STATE', $state) : '',
    meta: ($doc->number === null ? $stateChip('PO_STATE', $state) . ' ' : '') . ($doc->reviewState !== null && $doc->reviewState !== 'not_required'
    ? '<span class="tag ' . $e(\CW\Ui\Words::tone('REVIEW_STATE', $doc->reviewState)) . '">' . $e(\CW\Ui\Words::of('REVIEW_STATE', $doc->reviewState)) . '</span> ' : '') . $e($factLine)) ?>
</div>
<?php if ($live !== null): ?>
<?= $partial('live_here', []) ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rejected !== null): ?>
<div class="error" role="status">
  <p><?= $say('ORDER', 'rejected', (string) $rejected['decision_note'], (string) ($rejected['decided_by_name'] ?? ''), \CW\Ui\Html::day((string) $rejected['decided_at'])) ?></p>
  <p><?= $word('ORDER', 'rejected_next') ?></p>
</div>
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
<?php if ($supplierNeeds !== null): ?>
<?= $partial('po_supplier_needs', ['needs' => $supplierNeeds]) ?>
<?php endif; ?>
<?php if ($doc->status === 'draft'): ?>
<p class="note read-only"><?= $say('ORDER', 'draft_look', (string) $people['created']) ?></p>
<?php endif; ?>
<?php if ($sendBelow): ?>
<section class="po-send" id="po-send" aria-label="<?= $word('ORDER', 'send_title') ?>">
<?= $partial('po_action', ['a' => 'send_panel', 'primary' => true, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit, 'supplier' => $supplier, 'sendVia' => $sendVia,
    'sendWarnings' => $sendWarnings, 'sendCompany' => $sendCompany, 'companyNote' => $companyNote, 'formKey' => $formKey, 'cancelReasons' => $cancelReasons,
    'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode]) ?>
</section>
<?php endif; ?>

<?php foreach ($decide as $d): ?>
<section class="card decide-box<?php if ($d['refusal'] === null): ?> waiting<?php endif; ?>" aria-labelledby="decide-<?= $e($d['task']['id']) ?>-h">
  <div class="head-help">
    <h2 id="decide-<?= $e($d['task']['id']) ?>-h"><?= $e($d['title']) ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $e($d['text']) ?><?php if ($d['task']['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::ORDER['late']) ?><?php endif; ?></p>
<?php if ($d['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($d['refusal']) ?></p>
<?php else: ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done">
        <dt><?= $e($d['ok']) ?></dt>
        <dd><?= $e($d['okDoes']) ?></dd>
      </div>
      <div class="answer blocked<?php if ($d['safer']): ?> safe<?php endif; ?>">
        <dt><?= $word('ORDER', 'not_ok') ?><?php if ($d['safer']): ?> <span class="safe-tag"><?= $word('UI', 'safer') ?></span><?php endif; ?></dt>
        <dd><?= $e($d['notOkDoes']) ?></dd>
      </div>
    </dl>
  </div>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('ORDER', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="btn primary"><?= $e($d['ok']) ?></button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('ORDER', 'why_not_ok') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button class="btn" type="submit"><?= $word('ORDER', 'not_ok') ?></button>
  </form>
<?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($steps !== null): ?>
<ol class="po-steps" aria-label="<?= $word('DOC', 'progress') ?>">
<?php foreach ($steps as $i => $st): ?>
  <li class="po-step <?= $e($st['state']) ?>"<?php if ($st['state'] === 'now'): ?> aria-current="step"<?php endif; ?>><span class="po-step-mark" aria-hidden="true"><?php if ($st['state'] === 'done'): ?>✓<?php else: ?><?= $e($i + 1) ?><?php endif; ?></span><span class="po-step-text"><span class="po-step-name"><?= $e($st['name']) ?></span><span class="po-step-sub"><?= $e($st['sub']) ?></span></span></li>
<?php endforeach; ?>
</ol>
<?php endif; ?>

<article class="doc-view po-paper" aria-labelledby="lines-h"<?php if ($live !== null): ?><?= $partial('live_attrs', ['live' => $live]) ?><?php endif; ?>>
<?php if ($live !== null): ?>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php endif; ?>
<?= $partial('po_paper_head', ['edit' => false, 'party' => $party, 'deliverTo' => $deliverTo, 'dates' => $dates, 'marks' => $live !== null]) ?>
  <h2 id="lines-h" class="visually-hidden"><?= $word('ORDER', 'lines') ?></h2>
<?= $partial('po_paper_lines', ['edit' => false, 'doc' => $doc, 'lines' => $lines, 'stock' => $stock ?? [], 'state' => $state, 'marks' => $live !== null]) ?>
<?= $partial('po_paper_foot', ['edit' => false, 'note' => $doc->note, 'totalRows' => $totalRows, 'marks' => $live !== null]) ?>
</article>

<?= $partial('po_cards', ['related' => $related, 'events' => $events, 'eventsMore' => $eventsMore ?? null, 'history' => $history]) ?>
<?php endif; ?>
