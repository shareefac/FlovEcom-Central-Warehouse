<?php
// A purchase order, read like a document (docs/decisions.md PV12, PV19: the owner's approved design; the order's rules and actions as
// before): its status a solid block beside the title, one action bar at the head's right (po_bar, PV27: the next step in the accent:
// confirm, send, receive the goods, record its invoice, take it back or copy; the others under More: the PDF, correct, close, reopen,
// cancel ...; a step that needs a reason or a choice opens under the bar: po_action), the progress strip (ordered, received, invoiced, expected and what is still to come), the
// supplier's block beside its facts, the lines, the totals on the right with its files to download, then its files and its history.
?>
<?php $bar = $partial('po_bar', ['moreActions' => $moreActions, 'mainAction' => $mainAction, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit,
    'supplier' => $supplier, 'sendVia' => $sendVia, 'sendWarnings' => $sendWarnings, 'sendCompany' => $sendCompany, 'companyNote' => $companyNote, 'formKey' => $formKey,
    'cancelReasons' => $cancelReasons, 'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode]); ?>
<div class="doc-head">
<?php // PO_View (DC22): the heading is the order's number with its status block beside it (a draft without a number keeps its title, its status under it). ?>
<?= $head((string) ($doc->number ?? $title), intro: 'order', side: $bar, back: ['/ui/purchasing/orders', \CW\Ui\Words::MENU['orders']], chips: $doc->number !== null ? $stateChip('PO_STATE', $state) : '',
    meta: ($doc->number === null ? $stateChip('PO_STATE', $state) . ' ' : '') . ($doc->reviewState !== null && $doc->reviewState !== 'not_required'
    ? '<span class="tag ' . $e(\CW\Ui\Words::tone('REVIEW_STATE', $doc->reviewState)) . '">' . $e(\CW\Ui\Words::of('REVIEW_STATE', $doc->reviewState)) . '</span>' : '')) ?>
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

<?php if ($progress !== null): ?>
<section class="doc-progress" aria-label="<?= $word('DOC', 'progress') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('DOC', 'ordered') ?></p><p class="doc-step-big"><?= $n($progress['ordered']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('DOC', 'received') ?></p><p class="doc-step-big"><?= $e($progress['received_line']) ?></p><progress class="done" max="<?= $e(max(1, $progress['ordered'])) ?>" value="<?= $e(min($progress['received'], max(1, $progress['ordered']))) ?>"></progress></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('DOC', 'invoiced') ?></p><p class="doc-step-big"><?= $e($progress['invoiced_line']) ?></p><progress class="review" max="<?= $e(max(1, $progress['received'])) ?>" value="<?= $e(min($progress['invoiced'], max(1, $progress['received']))) ?>"></progress></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('DOC', 'expected') ?></p><p class="doc-step-big"><?php if ($progress['expected'] !== null): ?><?= $day($progress['expected']) ?><?php endif; ?></p><?php if ($progress['to_come'] > 0): ?><p class="muted"><?= $say('DOC', 'to_come', $progress['to_come']) ?></p><?php endif; ?></div>
</section>
<?php endif; ?>

<article class="doc-view" aria-labelledby="lines-h">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
  <h2 id="lines-h" class="doc-label"><?= $word('ORDER', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack po-lines doc-lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('ORDER', 'product') ?></th>
        <th scope="col"><?= $word('ORDER', 'pack') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'packs') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'items') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'delivered') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'per_pack') ?></th>
        <th scope="col"><?= $word('ORDER', 'vat') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'total') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
<?php if ($l['kind'] === 'charge'): ?>
        <th scope="row" class="c-head"><?= $e($l['description'] ?? \CW\Ui\Words::ORDER['charge']) ?> <span class="o-sub"><?= $say('ORDER', 'line', (int) $l['line_no']) ?></span></th>
        <td data-label="<?= $word('ORDER', 'pack') ?>"></td>
        <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"></td>
        <td class="num" data-label="<?= $word('ORDER', 'items') ?>"></td>
        <td class="num" data-label="<?= $word('ORDER', 'delivered') ?>"></td>
<?php else: ?>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a> <span class="o-sub"><?= $e($l['sku_code']) ?><?php if (($l['code'] ?? null) !== null && $l['code'] !== ''): ?> · <?= $e($l['code']) ?><?php endif; ?> · <?= $say('ORDER', 'line', (int) $l['line_no']) ?></span><?php if (($l['description'] ?? null) !== null && $l['description'] !== ''): ?><span class="o-sub"><?= $e($l['description']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('ORDER', 'pack') ?>"><?= $e($l['pack_label']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"><?= $n($l['packs']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'items') ?>"><?= $n($l['qty']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'delivered') ?>"><?= $n($l['received_units']) ?></td>
<?php endif; ?>
        <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>">£<?= $e($l['price']) ?></td>
        <td data-label="<?= $word('ORDER', 'vat') ?>"><?= $e($l['vat_code']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'total') ?>"><?= $e($l['net']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <div class="doc-foot">
    <div class="doc-notes">
      <ul class="plain links">
        <li><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>"><?= $word('ORDER', 'xlsx') ?></a> · <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.csv') ?>"><?= $word('ORDER', 'csv') ?></a></li>
        <li><a href="<?= $u('/ui/documents/' . $doc->id) ?>"><?= $word('ORDER', 'record') ?></a></li>
      </ul>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totalRows]) ?>
  </div>
</article>

<section aria-labelledby="files-h">
  <h2 id="files-h"><?= $word('ORDER', 'files') ?></h2>
<?php if ($files === []): ?>
  <p class="muted"><?php if ($doc->status === 'posted'): ?><?= $word('ORDER', 'no_files') ?><?php else: ?><?= $word('ORDER', 'no_files_draft') ?><?php endif; ?></p>
<?php else: ?>
  <ul class="plain">
<?php foreach ($files as $f): ?>
    <li><?= $e($f['what']) ?> – <a href="<?= $u('/ui/files/' . $f['id']) ?>"><?= $e($f['original_name']) ?></a> <span class="muted">(<?= $say('ORDER', 'file_line', $f['size'], \CW\Ui\Html::day((string) $f['attached_at'])) ?>)</span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <div class="head-help">
    <h2 id="history-h"><?= $word('ORDER', 'history') ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_history') ?></p>
<?php else: ?>
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
