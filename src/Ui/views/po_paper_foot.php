<?php
// The order's paper, its foot (docs/decisions.md RD-B4; one look, LP1, LP4): the note to the supplier on the left, the totals on the right.
// Read only ($edit false; $note, $totalRows: docView's): as words, the shared doc_totals.
// On a draft or a new order ($edit; the save's part "summary"): the note a box in place; the totals worked out again by app.js as the boxes
// are typed (data-kg-total; the server's figures on every save): the total quantity, the products, the total before VAT, a row a VAT code
// (data-kg-vat-rows: app.js draws them again before data-kg-vat-end), the delivery typed in place (a charge line on Save), the VAT and the
// total with VAT. $lines, $units, $totals, $vatLabels: page()'s; $typed: the form as sent (after a refusal).
// Who is here (step 3b): the note and the delivery name their place (data-live-box: h:note, h:delivery); read only, the note, on a draft that
// shows who is here ($marks).
$marks = $marks ?? false;
?>
<?php if (!$edit): ?>
  <div class="doc-foot">
    <div class="doc-notes"<?php if ($marks): ?> data-live-box="h:note"<?php endif; ?>>
      <p class="doc-label"><?= $word('ORDER', 'note_title') ?></p>
      <p><?php if ($note !== null && $note !== ''): ?><?= $e($note) ?><?php else: ?><span class="muted"><?= $word('DOC_LIST', 'none') ?></span><?php endif; ?></p>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totalRows]) ?>
  </div>
<?php else: ?>
  <div class="doc-foot" data-kg-swap-part="summary">
    <div class="doc-notes">
      <p class="doc-label"><label for="po-note"><?= $word('ORDER', 'note_title') ?></label></p>
      <p class="kg-in" data-live-box="h:note"><input id="po-note" type="text" name="note" maxlength="1000" autocomplete="off" value="<?= $e($note) ?>" placeholder="<?= $word('DOC_LIST', 'none') ?>" class="kg-flat"></p>
    </div>
    <section class="doc-totals" aria-label="<?= $word('DOC', 'totals') ?>">
      <dl data-kg-vat-rows data-words="<?= $word('ORDER', 'vat_line') ?>">
        <dt><?= $word('DOC_GRID', 'total_qty') ?></dt><dd data-kg-total="units"><?= $n($units) ?></dd>
        <dt><?= $word('ORDER', 'products') ?></dt><dd data-kg-total="count" data-one="<?= $word('ORDER', 'products_line_one') ?>" data-many="<?= $word('ORDER', 'products_line') ?>"><?= $say('ORDER', count($lines) === 1 ? 'products_line_one' : 'products_line', count($lines), $units) ?></dd>
        <dt><?= $word('ORDER', 'net') ?></dt><dd data-kg-total="net"><?= $e($totals['net']) ?></dd>
<?php foreach ($totals['by_code'] as $code => $v): ?>
        <div class="kg-vat-row" data-kg-vat-row><dt><?= $say('ORDER', 'vat_line', (string) $code, $vatLabels[$code] ?? $v['rate'] . '%') ?></dt><dd><?= $e($v['vat']) ?></dd></div>
<?php endforeach; ?>
        <dt data-kg-vat-end><label for="po-delivery"><?= $word('PO_ONE', 'delivery') ?></label></dt><dd class="kg-in" data-live-box="h:delivery"><input id="po-delivery" type="text" name="charge_amount" inputmode="decimal" maxlength="16" autocomplete="off" value="<?= $e($typed['charge_amount'] ?? '') ?>" placeholder="<?= $word('DOC_LIST', 'none') ?>" class="short kg-flat" data-kg-delivery></dd>
        <dt><?= $word('ORDER', 'vat') ?></dt><dd data-kg-total="vat"><?= $e($totals['vat']) ?></dd>
        <dt class="grand"><?= $word('ORDER', 'gross') ?></dt><dd class="grand" data-kg-total="gross"><?= $e($totals['gross']) ?></dd>
      </dl>
    </section>
  </div>
<?php endif; ?>
