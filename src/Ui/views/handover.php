<?php
// One hand-over (pack H) on the approved patterns (docs/decisions.md W8). Its creator's draft is the one-page form (pattern 3, PI_New): the
// customer, warehouse, VAT code, their reference, date and note in two columns (their own form, as before), the items as a board under the
// add box, the totals on the right under them, and the bar at the bottom of the lines form: Leave without saving, what it holds, Save, then
// "Make it final" (the one form of OV1-OV12, kept as it is). Any other hand-over is the record page (pattern 2, PO_View): the status block in
// the head with the actions at its right (handover_bar), the figures strip, the paper card (the customer beside the facts, the items, the
// totals on the right), then the history. An invoice not ready yet says why beside the company details' link.
$bar = $partial('handover_bar', ['doc' => $doc, 'version' => $version, 'pdf' => $pdf, 'pdfBlocked' => $pdfBlocked, 'canCopy' => $canCopy, 'reverse' => $reverse,
    'editable' => $editable, 'typedCancel' => $typedCancel, 'typedStop' => $typedStop]);
?>
<p class="crumbs"><a href="/ui/stock/handovers"><?= $word('SEGMENT', 'handovers') ?></a></p>
<div class="doc-head">
<?= $head($title, intro: 'handover', look: $lookOnly, chips: $stateChip('DOC_STATUS', $doc->status), side: $bar) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?><?php if ($errorCode === 'company_not_confirmed' || $errorCode === 'vat_code_missing'): ?> <a href="<?= $e($companyHref) ?>"><?= $word('HANDOVER', 'company_link') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($decide !== null): ?>
<?= $partial('decide_box', ['decide' => $decide]) ?>
<?php endif; ?>
<?php if ($notCreator): ?>
<p class="note read-only"><?= $word('HANDOVER', 'not_creator') ?></p>
<?php endif; ?>
<?php if ($pdf !== null && $pdfBlocked !== null): ?>
<p class="note"><?= $e($pdfBlocked) ?> <a href="<?= $e($companyHref) ?>"><?= $word('HANDOVER', 'company_link') ?></a></p>
<?php endif; ?>

<?php if ($editable): ?>
<form class="doc-details" method="post" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/details') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1"<?php if ($headerTyped): ?> data-dirty="1"<?php endif; ?>>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <fieldset class="doc-grid">
    <legend class="visually-hidden"><?= $word('HANDOVER', 'facts') ?></legend>
<?= $partial('handover_fields', ['form' => $form]) ?>
    <p class="actions doc-wide"><button class="btn" type="submit"><?= $word('HANDOVER', 'save_details') ?></button></p>
  </fieldset>
</form>

<section class="doc-lines-box" id="lines" aria-labelledby="lines-h">
  <div class="board-head"><h2 id="lines-h" class="board-sub"><?= $word('HANDOVER', 'lines') ?></h2></div>
  <form class="inline stock-add" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/add') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('HANDOVER', 'add_q') ?> <input type="search" name="q" value="<?= $e($typedAdd['q']) ?>" maxlength="100" autocomplete="off" autofocus required></label>
    <label><?= $word('HANDOVER', 'add_qty') ?> <input type="text" name="qty" value="<?= $e($typedAdd['qty']) ?>" inputmode="numeric" maxlength="9" required></label>
    <label><?= $word('HANDOVER', 'add_price') ?> <input type="text" name="price" value="<?= $e($typedAdd['price']) ?>" inputmode="decimal" maxlength="16"></label>
    <button type="submit" class="btn"><?= $word('HANDOVER', 'add_button') ?></button>
  </form>
  <p class="hint"><?= $word('HANDOVER', 'add_price_hint') ?></p>
<?php if ($choices !== null): ?>
  <div class="card choices">
    <p><?= $say('HANDOVER', 'choices', (string) $typedAdd['q']) ?></p>
    <div class="table-wrap">
    <table class="stack list">
      <tbody>
<?php foreach ($choices as $c): ?>
        <tr>
          <th scope="row" class="c-head"><?= $e($c['name']) ?><span class="o-sub"><?= $e($c['code']) ?><?php if ($c['brand'] !== null): ?> &middot; <?= $e($c['brand']) ?><?php endif; ?></span></th>
          <td class="c-next">
            <form class="inline" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/add') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="version" value="<?= $e($version) ?>">
              <input type="hidden" name="sku_id" value="<?= $e($c['sku_id']) ?>">
              <input type="hidden" name="qty" value="<?= $e($typedAdd['qty']) ?>">
              <input type="hidden" name="price" value="<?= $e($typedAdd['price']) ?>">
              <button class="btn" type="submit"><?= $word('HANDOVER', 'choose_this') ?></button>
            </form>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($lines === []): ?>
  <p class="muted"><?= $word('HANDOVER', 'no_lines') ?></p>
<?php else: ?>
  <form class="record stock-lines" method="post" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/lines') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1"<?php if ($typedLines !== []): ?> data-dirty="1"<?php endif; ?>>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="line_count" value="<?= $e(count($lines)) ?>">
<?php if ($linesPager !== null): ?>
    <input type="hidden" name="lines_page" value="<?= $e($linesPager['no']) ?>">
<?php endif; ?>
    <div class="table-wrap">
    <table class="stack list board doc-lines">
      <thead>
        <tr>
          <th scope="col" class="c-item"><?= $word('HANDOVER', 'line_item') ?></th>
          <th scope="col" class="num"><?= $word('HANDOVER', 'line_units') ?></th>
          <th scope="col" class="num"><?= $word('HANDOVER', 'line_price') ?></th>
          <th scope="col" class="num"><?= $word('HANDOVER', 'line_amount') ?></th>
          <th scope="col"><?= $word('HANDOVER', 'line_stock') ?></th>
          <th scope="col"><?= $word('HANDOVER', 'line_remove') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($editLines as $l): ?>
        <tr>
          <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
          <td data-label="<?= $word('HANDOVER', 'line_units') ?>" class="num"><input type="text" name="u_<?= $e($l['line_no']) ?>" value="<?= $e($typedLines[$l['line_no']]['u'] ?? $l['qty']) ?>" inputmode="numeric" maxlength="9" aria-label="<?= $word('HANDOVER', 'line_units') ?>"></td>
          <td data-label="<?= $word('HANDOVER', 'line_price') ?>" class="num"><input type="text" name="p_<?= $e($l['line_no']) ?>" value="<?php if (isset($typedLines[$l['line_no']]['p'])): ?><?= $e($typedLines[$l['line_no']]['p']) ?><?php else: ?><?= $dec($l['unit_cost']) ?><?php endif; ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('HANDOVER', 'line_price') ?>"><?php if ($l['remembered'] !== null): ?><span class="o-sub"><?= $say('HANDOVER', 'line_remembered', \CW\Ui\Html::money($l['remembered'])) ?></span><?php elseif ($l['unit_cost'] === null): ?><span class="o-sub"><?= $word('HANDOVER', 'line_no_price') ?></span><?php endif; ?></td>
          <td data-label="<?= $word('HANDOVER', 'line_amount') ?>" class="num"><?php if ($l['amount'] !== null): ?><?= $money($l['amount']) ?><?php endif; ?></td>
          <td data-label="<?= $word('HANDOVER', 'line_stock') ?>"><?php if ($l['stock'] !== null): ?><?= $say('HANDOVER', 'line_stock_text', number_format($l['stock']['on_hand']), number_format($l['stock']['available'])) ?><?php endif; ?></td>
          <td data-label="<?= $word('HANDOVER', 'line_remove') ?>"><label class="choice"><input type="checkbox" name="x_<?= $e($l['line_no']) ?>" value="1"<?php if (($typedLines[$l['line_no']]['x'] ?? '') === '1'): ?> checked<?php endif; ?>> <?= $word('HANDOVER', 'line_remove') ?></label></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php if ($oneForm): ?>
    <div class="doc-foot">
      <div class="doc-notes">
        <p class="hint"><?= $word('HANDOVER', 'final_does') ?></p>
<?php if ($ruleWaits): ?>
        <p class="muted"><?= $word('HANDOVER', 'final_waits') ?></p>
<?php endif; ?>
<?php if ($missingPrices > 0): ?>
        <p class="note"><?= $say('HANDOVER', 'missing_prices', $missingPrices) ?></p>
<?php endif; ?>
      </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totalRows]) ?>
    </div>
    <div class="actions sticky doc-bar">
      <a class="btn ghost" href="/ui/stock/handovers" data-leave><?= $word('DOC', 'leave') ?></a>
      <span class="doc-bar-note"><?= $say('HANDOVER', 'total_units', number_format($units)) ?> &middot; <?= $say('HANDOVER', 'net_so_far', \CW\Ui\Html::money($sums['net'])) ?></span>
      <button class="btn" type="submit" name="action" value="save"><?= $word('OP_FINAL', 'save') ?></button>
      <button type="submit" name="action" value="post" class="btn primary"><?= $word('HANDOVER', 'final_button') ?></button>
    </div>
<?php else: ?>
<?php if ($missingPrices > 0): ?>
    <p class="note"><?= $say('HANDOVER', 'missing_prices', $missingPrices) ?></p>
<?php endif; ?>
    <p class="actions"><button class="btn" type="submit"><?= $word('HANDOVER', 'save_lines') ?></button> <span class="muted"><?= $say('HANDOVER', 'total_units', number_format($units)) ?> &middot; <?= $say('HANDOVER', 'net_so_far', \CW\Ui\Html::money($sums['net'])) ?></span></p>
<?php endif; ?>
  </form>
<?php if ($linesPager !== null): ?>
  <form class="pager" method="get" action="<?= $u('/ui/stock/handovers/' . $doc->id) ?>" data-leaves="1">
    <span><?= $say('OP_FINAL', 'page', $linesPager['from'], $linesPager['to'], $linesPager['n']) ?></span>
<?php if ($linesPager['no'] > 1): ?>
    <button class="btn" type="submit" name="lines_page" value="<?= $e($linesPager['no'] - 1) ?>"><?= $word('OP_FINAL', 'prev') ?></button>
<?php endif; ?>
<?php if ($linesPager['no'] < $linesPager['pages']): ?>
    <button class="btn" type="submit" name="lines_page" value="<?= $e($linesPager['no'] + 1) ?>"><?= $word('OP_FINAL', 'next') ?></button>
<?php endif; ?>
  </form>
<?php endif; ?>
<?php endif; ?>
</section>

<?php if (!$oneForm): ?>
<section class="card final-box" aria-labelledby="final-h">
  <h2 id="final-h"><?= $word('HANDOVER', 'final_title') ?></h2>
  <p><?= $word('HANDOVER', 'final_does') ?></p>
<?php if ($ruleWaits): ?>
  <p class="muted"><?= $word('HANDOVER', 'final_waits') ?></p>
<?php endif; ?>
<?php if ($lines !== []): ?>
  <p class="note"><?= $say('OP_FINAL', 'too_many', \CW\Ui\Words::HANDOVER['save_lines']) ?></p>
<?php endif; ?>
  <form class="inline" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <button type="submit" class="btn primary"><?= $word('HANDOVER', 'final_button') ?></button>
  </form>
</section>
<?php endif; ?>
<?php else: ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('HANDOVER', 'facts') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('HANDOVER', 'products') ?></p><p class="doc-step-big"><?= $n($products) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('HANDOVER', 'units') ?></p><p class="doc-step-big"><?= $n($units) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?php if ($posted): ?><?= $word('HANDOVER', 'gross') ?><?php else: ?><?= $word('HANDOVER', 'net_draft') ?><?php endif; ?></p><p class="doc-step-big"><?php if ($posted): ?><?= $money($sums['gross']) ?><?php else: ?><?= $money($sums['net']) ?><?php endif; ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('HANDOVER', 'date') ?></p><p class="doc-step-big"><?= $day($doc->docDate) ?></p></div>
</section>

<article class="doc-view" aria-labelledby="lines-h">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
  <h2 id="lines-h" class="doc-label"><?= $word('HANDOVER', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('HANDOVER', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list board doc-lines">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('HANDOVER', 'line_item') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'line_units') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'line_price') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'line_amount') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
        <td data-label="<?= $word('HANDOVER', 'line_units') ?>" class="num"><?= $n($l['qty']) ?></td>
        <td data-label="<?= $word('HANDOVER', 'line_price') ?>" class="num"><?php if ($l['unit_cost'] !== null): ?><?= $money($l['unit_cost']) ?><?php endif; ?></td>
        <td data-label="<?= $word('HANDOVER', 'line_amount') ?>" class="num"><?php if ($l['amount'] !== null): ?><?= $money($l['amount']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <div class="doc-foot">
    <div class="doc-notes">
<?php if ($missingPrices > 0 && $doc->status === 'draft'): ?>
      <p class="note"><?= $say('HANDOVER', 'missing_prices', $missingPrices) ?></p>
<?php endif; ?>
    </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totalRows]) ?>
  </div>
</article>
<?php endif; ?>

<?php if ($history !== []): ?>
<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('RECORD', 'history') ?></h2>
  <ol class="versions checks-history">
<?php foreach ($history as $h): ?>
    <li>
      <p><?= $e($h['asked']) ?> <span class="muted"><?= $e($h['reason']) ?></span></p>
      <p><?= $chip($h['tone'], $h['outcome']) ?><?php if ($h['note'] !== null): ?> <?= $e($h['note']) ?><?php endif; ?></p>
    </li>
<?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
