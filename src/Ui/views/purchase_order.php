<?php
// A purchase order, read like a document as the approved PurchaseOrder.dc.html draws it (docs/decisions.md RD-B4; before it PV12, PV19; the
// order's rules and actions as before): the list's link above the title, the order's number with its status pill beside it, one line under
// it (the reviewer's check, the supplier, when it was ordered and by whom), the actions at the head's right (po_bar, PV27: More,
// then the next step in the accent; a step that needs a reason or a choice opens under the bar: po_action). The progress steps (approved,
// sent to the supplier, received, invoiced). The paper: the supplier, where it delivers and the dates in three blocks, the lines (item,
// ordered, received with what is still to come, unit cost, line total), the note to the supplier and the totals. Then Related (its
// deliveries and invoices, the orders it corrects or that correct it, its cancellation, its files) and History (what happened to it, and
// its checks and OKs).
?>
<?php $bar = $partial('po_bar', ['moreActions' => $moreActions, 'mainAction' => $mainAction, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit,
    'supplier' => $supplier, 'sendVia' => $sendVia, 'sendWarnings' => $sendWarnings, 'sendCompany' => $sendCompany, 'companyNote' => $companyNote, 'formKey' => $formKey,
    'cancelReasons' => $cancelReasons, 'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode]); ?>
<div class="doc-head">
<?php // PO_View (DC22): the heading is the order's number with its status beside it (a draft without a number keeps its title, its status in the line under it). ?>
<?= $head((string) ($doc->number ?? $title), intro: 'order', side: $bar, back: ['/ui/purchasing/orders', \CW\Ui\Words::MENU['orders']], chips: $doc->number !== null ? $stateChip('PO_STATE', $state) : '',
    meta: ($doc->number === null ? $stateChip('PO_STATE', $state) . ' ' : '') . ($doc->reviewState !== null && $doc->reviewState !== 'not_required'
    ? '<span class="tag ' . $e(\CW\Ui\Words::tone('REVIEW_STATE', $doc->reviewState)) . '">' . $e(\CW\Ui\Words::of('REVIEW_STATE', $doc->reviewState)) . '</span> ' : '') . $e($factLine)) ?>
</div>
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
<p class="note read-only"><?php if ($canPost): ?><?= $say('ORDER', 'only_creator', (string) $people['created']) ?><?php else: ?><?= $say('ORDER', 'only_creator_look', (string) $people['created']) ?><?php endif; ?></p>
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

<article class="doc-view po-paper" aria-labelledby="lines-h">
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
  <h2 id="lines-h" class="visually-hidden"><?= $word('ORDER', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack po-lines doc-lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('ORDER', 'col_item') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'col_ordered') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'col_received') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'col_cost') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'col_line_total') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
<?php if ($l['kind'] === 'charge'): ?>
        <th scope="row" class="c-head"><span class="o-name"><?= $e($l['description'] ?? \CW\Ui\Words::ORDER['charge']) ?></span> <span class="o-sub"><?= $word('ORDER', 'charge') ?> · <?= $e($l['vat_code']) ?> · <?= $say('ORDER', 'line', (int) $l['line_no']) ?></span></th>
        <td class="num" data-label="<?= $word('ORDER', 'col_ordered') ?>"></td>
        <td class="num" data-label="<?= $word('ORDER', 'col_received') ?>"></td>
<?php else: ?>
<?php $toCome = in_array($state, ['approved', 'sent', 'part_received'], true) ? max(0, (int) $l['qty'] - (int) $l['received_units']) : 0; ?>
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a> <span class="o-sub"><?= $e($l['sku_code']) ?><?php if (($l['code'] ?? null) !== null && $l['code'] !== ''): ?> · <?= $e($l['code']) ?><?php endif; ?> · <?= $e($l['pack_label']) ?> · <?= $word('ORDER', 'vat') ?> <?= $e($l['vat_code']) ?> · <?= $say('ORDER', 'line', (int) $l['line_no']) ?></span><?php if (($l['description'] ?? null) !== null && $l['description'] !== ''): ?><span class="o-sub"><?= $e($l['description']) ?></span><?php endif; ?></th>
        <td class="num" data-label="<?= $word('ORDER', 'col_ordered') ?>"><?= $n($l['qty']) ?><?php if ((int) $l['units_per_pack'] > 1): ?><span class="o-sub"><?= $say('ORDER', 'packs_of', \CW\Ui\Html::int($l['packs']), (string) $l['pack_label']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'col_received') ?>"><span class="po-got<?php if ((int) $l['received_units'] >= (int) $l['qty']): ?> done<?php elseif ((int) $l['received_units'] > 0): ?> part<?php endif; ?>"><?= $n($l['received_units']) ?></span><?php if ($toCome > 0 && (int) $l['received_units'] > 0): ?> <?= $chip('needs', \CW\Ui\Words::say('ORDER', 'to_come_pill', \CW\Ui\Html::int($toCome))) ?><?php endif; ?></td>
<?php endif; ?>
        <td class="num" data-label="<?= $word('ORDER', 'col_cost') ?>">£<?= $e($l['price']) ?><?php if ($l['kind'] !== 'charge' && (int) $l['units_per_pack'] > 1): ?><span class="o-sub"><?= $say('ORDER', 'per', (string) $l['pack_label']) ?></span><?php endif; ?></td>
        <td class="num strong" data-label="<?= $word('ORDER', 'col_line_total') ?>"><?= $e($l['net']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <div class="doc-foot">
    <div class="doc-notes">
      <p class="doc-label"><?= $word('ORDER', 'note_title') ?></p>
      <p><?php if ($doc->note !== null && $doc->note !== ''): ?><?= $e($doc->note) ?><?php else: ?><span class="muted"><?= $word('DOC_LIST', 'none') ?></span><?php endif; ?></p>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totalRows]) ?>
  </div>
</article>

<div class="po-cards">
<section class="card po-related" aria-labelledby="related-h">
  <h2 id="related-h"><?= $word('ORDER', 'related') ?></h2>
<?php if ($related === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_related') ?></p>
<?php else: ?>
  <ul class="plain po-rel-list">
<?php foreach ($related as $rel): ?>
<?php if ($rel['href'] === null): ?>
    <li><span class="po-rel-item"><span class="po-rel-text"><?= $e($rel['text']) ?><?php if (($rel['sub'] ?? null) !== null): ?><span class="o-sub"><?= $e($rel['sub']) ?></span><?php endif; ?></span><?php if (($rel['pill'] ?? null) !== null): ?><?= $chip($rel['tone'], $rel['pill']) ?><?php endif; ?></span></li>
<?php else: ?>
    <li><a href="<?= $u($rel['href']) ?>"><span class="po-rel-text"><?= $e($rel['text']) ?><?php if (($rel['sub'] ?? null) !== null): ?><span class="o-sub"><?= $e($rel['sub']) ?></span><?php endif; ?></span><?php if (($rel['pill'] ?? null) !== null): ?><?= $chip($rel['tone'], $rel['pill']) ?><?php endif; ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section class="card po-history" aria-labelledby="history-h">
  <div class="head-help">
    <h2 id="history-h"><?= $word('ORDER', 'history_title') ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
<?php if ($events === [] && $history === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_history') ?></p>
<?php endif; ?>
<?php if ($events !== []): ?>
  <ol class="plain po-events">
<?php foreach ($events as $ev): ?>
    <li><strong><?= $e($ev['when']) ?></strong> · <?= $e($ev['text']) ?></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
<?php if ($history !== []): ?>
  <h3 class="doc-label"><?= $word('ORDER', 'history') ?></h3>
  <ol class="plain checks-history">
<?php foreach ($history as $h): ?>
    <li>
      <p><?= $e($h['asked']) ?> <?= $e($h['why']) ?><?php if ($h['about'] !== null): ?> <?= $e($h['about']) ?><?php endif; ?></p>
      <p class="muted"><?= $e($h['outcome']) ?><?php if ($h['note'] !== null): ?> "<?= $e($h['note']) ?>"<?php endif; ?></p>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
</div>
