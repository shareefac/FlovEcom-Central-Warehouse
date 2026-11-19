<?php
// A draft order as it is now, for the page that saves each box by itself (the live drafts plan, step 4, 4.3; docs/decisions.md LS10): the
// answer of GET /live/doc (the page's poll found the draft moved) and of the page's own save of a line or a head box (POST /live/line, its head twin). app.js
// accepts it only by its root's marker (data-live-fragment="po:<id>", fragment()), reads it inert (DOMParser: nothing in it runs) and patches
// its page from these parts, never the box the person is typing in:
//  - the head's part: its values (expected date, their reference, the note: data-live-field), the supplier (data-supplier), its address and terms;
//  - lines: every line as the editor draws it (po_paper_line: data-uid, data-rev, data-by, the boxes named by their line number), or as words
//    for a person who may only look; data-count: how many;
//  - foot: the totals (data-live-total), the server's figures, which win over the page's own;
//  - notes: the warnings and what the supplier still needs; history: the History card (po_history: the line-by-line changes grouped);
//  - events: the changes since the newest the page shows, not its own, in words (one at a time by the page's polite region);
//  - conflict: a refusal, in words on its line or box, with its choices (live_conflict).
// The root: the draft's version (data-v), its newest change (data-e), its status, the time (UK) for "All changes saved 14:02" (data-at), and
// what the page's own save did (data-op, data-op-status, data-op-uid, data-op-key, data-op-rev: the line's version this save itself wrote,
// data-op-was: what an add or an undo did, also when sent again, data-op-value: a head box's value as this save stored it; "refused" with a
// refusal).
$refusal = $refusal ?? null;
$op = $op ?? null;
?>
<div class="live-fragment" data-live-fragment="po:<?= $e($doc->id) ?>" data-v="<?= $e($doc->version) ?>" data-e="<?= $e($newest) ?>" data-status="<?= $e($doc->status) ?>" data-at="<?= $e($at) ?>"<?php if ($op !== null): ?> data-op="<?= $e($op['op']) ?>" data-op-status="<?= $e($refusal !== null ? 'refused' : ($op['status'] ?? '')) ?>"<?php if (($op['uid'] ?? null) !== null): ?> data-op-uid="<?= $e($op['uid']) ?>"<?php endif; ?><?php if (($op['rev'] ?? null) !== null): ?> data-op-rev="<?= $e($op['rev']) ?>"<?php endif; ?><?php if (($op['was'] ?? null) !== null): ?> data-op-was="<?= $e($op['was']) ?>"<?php endif; ?><?php if (array_key_exists('value', $op ?? [])): ?> data-op-value="<?= $e($op['value'] ?? '') ?>"<?php endif; ?><?php if (($op['key'] ?? null) !== null): ?> data-op-key="<?= $e($op['key']) ?>"<?php endif; ?><?php endif; ?>>
  <div data-live-part="header" data-supplier="<?= $e($supplierId) ?>">
    <input type="hidden" data-live-field="expected_date" value="<?= $e($expectedDate) ?>">
    <input type="hidden" data-live-field="external_ref" value="<?= $e($doc->externalRef ?? '') ?>">
    <input type="hidden" data-live-field="note" value="<?= $e($doc->note ?? '') ?>">
    <div data-live-party>
<?php foreach ($party['lines'] as $line): ?>
      <p><?= $e($line) ?></p>
<?php endforeach; ?>
    </div>
    <span data-live-terms><?= $e($terms) ?></span>
  </div>
  <table data-live-part="lines" data-count="<?= $e(count($lines)) ?>">
    <tbody>
<?php foreach ($lines as $i => $l): ?>
<?php if ($editor): ?>
<?= $partial('po_paper_line', ['edit' => true, 'l' => $l, 'no' => $i + 1, 'rowNo' => $i + 1, 'gone' => false, 'bad' => null, 'editable' => true, 'typed' => [], 'stock' => $stock,
    'liveSave' => true]) ?>
<?php else: ?>
<?= $partial('po_paper_line', ['edit' => false, 'l' => $l, 'state' => $state, 'stock' => $stock, 'showReceived' => false, 'marks' => true]) ?>
<?php endif; ?>
<?php endforeach; ?>
    </tbody>
  </table>
  <dl data-live-part="foot">
    <dt><?= $word('DOC_GRID', 'total_qty') ?></dt><dd data-live-total="units"><?= $n($units) ?></dd>
    <dt><?= $word('ORDER', 'products') ?></dt><dd data-live-total="count"><?= $say('ORDER', count($lines) === 1 ? 'products_line_one' : 'products_line', count($lines), $units) ?></dd>
    <dt><?= $word('ORDER', 'net') ?></dt><dd data-live-total="net"><?= $e($totals['net']) ?></dd>
    <dt><?= $word('ORDER', 'vat') ?></dt><dd data-live-total="vat"><?= $e($totals['vat']) ?></dd>
    <dt><?= $word('ORDER', 'gross') ?></dt><dd data-live-total="gross"><?= $e($totals['gross']) ?></dd>
  </dl>
  <div data-live-part="notes">
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
  </div>
  <div data-live-part="history">
<?= $partial('po_history', ['events' => $events, 'eventsMore' => $eventsMore, 'history' => $history]) ?>
  </div>
  <ol data-live-part="events">
<?php foreach ($liveEvents as $ev): ?>
    <li data-id="<?= $e($ev['id']) ?>" data-by="<?= $e($ev['by']) ?>" data-name="<?= $e($ev['name']) ?>" data-op="<?= $e($ev['op']) ?>"<?php if ($ev['uid'] !== null): ?> data-uid="<?= $e($ev['uid']) ?>"<?php endif; ?>><?= $e($ev['text']) ?></li>
<?php endforeach; ?>
  </ol>
<?php if ($refusal !== null): ?>
<?= $partial('live_conflict', ['r' => $refusal]) ?>
<?php endif; ?>
</div>
