<?php
// One stock record (pack A1) on the approved patterns (docs/decisions.md W8). Its creator's draft is the one-page form (pattern 3, PI_New):
// the details in two columns (their own form, as before), the products as a board under the add box, the totals on the right under them,
// and the bar at the bottom of the lines form: Leave without saving, what it holds, Save, then the record's final button (the one form of
// OV1-OV12, kept as it is: Save and the final button send the lines on the screen); its files after. Any other record is the record page
// (pattern 2, PO_View): the status block in the head with the actions at its right (stock_op_bar), the figures strip, the paper card (where
// the stock is, or the account a release owes, beside the facts; the products; the files on the left and the totals on the right), then
// the history. The reviewer's box comes first when a rule asks for an OK.
$bar = $partial('stock_op_bar', ['doc' => $doc, 'base' => $base, 'k' => $k, 'version' => $version, 'pdf' => $pdf, 'canWithdraw' => $canWithdraw,
    'reverse' => $reverse, 'editable' => $editable, 'typedCancel' => $typedCancel, 'typedStop' => $typedStop]);
?>
<p class="crumbs"><a href="<?= $e($base) ?>"><?= $e($listName) ?></a></p>
<div class="doc-head">
<?= $head((string) $title, intro: 'stock_op', look: $lookOnly, chips: $stateChip('DOC_STATUS', $doc->status), side: $bar) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($decide !== null): ?>
<?= $partial('decide_box', ['decide' => $decide]) ?>
<?php elseif ($waiting): ?>
<p class="muted"><?= $word('RECORD', 'waiting') ?></p>
<?php endif; ?>
<?php if ($notCreator): ?>
<p class="note read-only"><?= $word('STOCK_OPS', 'not_creator') ?></p>
<?php endif; ?>

<?php if ($editable): ?>
<form class="doc-details" method="post" action="<?= $u($base . '/' . $doc->id . '/details') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1"<?php if ($headerTyped): ?> data-dirty="1"<?php endif; ?>>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <fieldset class="doc-grid">
    <legend class="visually-hidden"><?= $word('STOCK_OPS', 'facts') ?></legend>
<?= $partial('stock_op_fields', ['form' => $form, 'kind' => $kind]) ?>
    <p class="actions doc-wide"><button class="btn" type="submit"><?= $word('STOCK_OPS', 'save_details') ?></button></p>
  </fieldset>
</form>

<section class="doc-lines-box" id="lines" aria-labelledby="lines-h">
  <div class="board-head"><h2 id="lines-h" class="board-sub"><?= $word('STOCK_OPS', 'lines') ?></h2></div>
  <form class="inline stock-add" method="post" data-leaves="1" action="<?= $u($base . '/' . $doc->id . '/add') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('STOCK_OPS', 'add_q') ?> <input type="search" name="q" value="<?= $e($typedAdd['q']) ?>" maxlength="100" autocomplete="off" autofocus required></label>
    <label><?php if ($kind === 'adjust'): ?><?= $word('STOCK_OPS', 'add_qty_adjust') ?><?php else: ?><?= $word('STOCK_OPS', 'add_qty') ?><?php endif; ?> <input type="text" name="qty" value="<?= $e($typedAdd['qty']) ?>" inputmode="numeric" maxlength="9" required></label>
<?php if ($priced): ?>
    <label><?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'add_price') ?><?php else: ?><?= $word('STOCK_OPS', 'add_cost') ?><?php endif; ?> <input type="text" name="cost" value="<?= $e($typedAdd['cost']) ?>" inputmode="decimal" maxlength="16"></label>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
    <label><?= $word('STOCK_OPS', 'line_reason') ?>
      <select name="reason" required>
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"<?php if ($typedAdd['reason'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
    <button type="submit" class="btn"><?= $word('STOCK_OPS', 'add_button') ?></button>
  </form>
<?php if ($kind === 'release'): ?>
  <p class="hint"><?= $word('STOCK_OPS', 'add_price_hint') ?></p>
<?php endif; ?>
<?php if ($choices !== null): ?>
  <div class="card choices">
    <p><?= $say('STOCK_OPS', 'choices', (string) $typedAdd['q']) ?></p>
    <div class="table-wrap">
    <table class="stack list">
      <tbody>
<?php foreach ($choices as $c): ?>
        <tr>
          <th scope="row" class="c-head"><?= $e($c['name']) ?><span class="o-sub"><?= $e($c['code']) ?><?php if ($c['brand'] !== null): ?> · <?= $e($c['brand']) ?><?php endif; ?></span></th>
          <td class="c-next">
            <form class="inline" method="post" data-leaves="1" action="<?= $u($base . '/' . $doc->id . '/add') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="version" value="<?= $e($version) ?>">
              <input type="hidden" name="sku_id" value="<?= $e($c['sku_id']) ?>">
              <input type="hidden" name="qty" value="<?= $e($typedAdd['qty']) ?>">
              <input type="hidden" name="cost" value="<?= $e($typedAdd['cost']) ?>">
              <input type="hidden" name="reason" value="<?= $e($typedAdd['reason']) ?>">
              <button class="btn" type="submit"><?= $word('STOCK_OPS', 'choose_this') ?></button>
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
  <p class="muted"><?= $word('STOCK_OPS', 'no_lines') ?></p>
<?php else: ?>
  <form class="record stock-lines" method="post" action="<?= $u($base . '/' . $doc->id . '/lines') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1"<?php if ($typedLines !== []): ?> data-dirty="1"<?php endif; ?>>
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
          <th scope="col" class="c-item"><?= $word('STOCK_OPS', 'line_product') ?></th>
          <th scope="col" class="num"><?= $word('STOCK_OPS', 'line_units') ?></th>
<?php if ($priced): ?>
          <th scope="col" class="num"><?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'line_price') ?><?php else: ?><?= $word('STOCK_OPS', 'line_cost') ?><?php endif; ?></th>
          <th scope="col" class="num"><?= $word('STOCK_OPS', 'line_amount') ?></th>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
          <th scope="col"><?= $word('STOCK_OPS', 'line_reason') ?></th>
<?php endif; ?>
          <th scope="col"><?= $word('STOCK_OPS', 'line_stock') ?></th>
          <th scope="col"><?= $word('STOCK_OPS', 'line_remove') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($editLines as $l): ?>
        <tr>
          <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
          <td data-label="<?= $word('STOCK_OPS', 'line_units') ?>" class="num"><input type="text" name="u_<?= $e($l['line_no']) ?>" value="<?= $e($typedLines[$l['line_no']]['u'] ?? $l['qty']) ?>" inputmode="numeric" maxlength="9" aria-label="<?= $word('STOCK_OPS', 'line_units') ?>"></td>
<?php if ($priced): ?>
          <td data-label="<?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'line_price') ?><?php else: ?><?= $word('STOCK_OPS', 'line_cost') ?><?php endif; ?>" class="num"><input type="text" name="c_<?= $e($l['line_no']) ?>" value="<?php if (isset($typedLines[$l['line_no']]['c'])): ?><?= $e($typedLines[$l['line_no']]['c']) ?><?php else: ?><?= $dec($l['unit_cost']) ?><?php endif; ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('STOCK_OPS', 'line_cost') ?>"><?php if ($l['average'] !== null): ?><span class="o-sub"><?= $say('STOCK_OPS', 'line_average', \CW\Ui\Html::money($l['average'])) ?></span><?php endif; ?></td>
          <td data-label="<?= $word('STOCK_OPS', 'line_amount') ?>" class="num"><?php if ($l['amount'] !== null): ?><?= $money($l['amount']) ?><?php endif; ?></td>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
          <td data-label="<?= $word('STOCK_OPS', 'line_reason') ?>">
            <select name="r_<?= $e($l['line_no']) ?>" aria-label="<?= $word('STOCK_OPS', 'line_reason') ?>">
              <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reasons as $r): ?>
              <option value="<?= $e($r['code']) ?>"<?php if (($typedLines[$l['line_no']]['r'] ?? $l['reason_code']) === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
            </select>
          </td>
<?php endif; ?>
          <td data-label="<?= $word('STOCK_OPS', 'line_stock') ?>"><?php if ($l['stock'] !== null): ?><?= $say('STOCK_OPS', 'line_stock_text', number_format($l['stock']['on_hand']), number_format($l['stock']['available'])) ?><?php endif; ?></td>
          <td data-label="<?= $word('STOCK_OPS', 'line_remove') ?>"><label class="choice"><input type="checkbox" name="x_<?= $e($l['line_no']) ?>" value="1"<?php if (($typedLines[$l['line_no']]['x'] ?? '') === '1'): ?> checked<?php endif; ?>> <?= $word('STOCK_OPS', 'line_remove') ?></label></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php if ($oneForm): ?>
    <div class="doc-foot">
      <div class="doc-notes">
        <p class="hint"><?= $e($k['final_does']) ?></p>
<?php if ($ruleWaits): ?>
        <p class="muted"><?= $word('STOCK_OPS', 'final_waits') ?></p>
<?php endif; ?>
      </div>
<?= $partial('doc_totals', ['label' => \CW\Ui\Words::DOC['totals'], 'rows' => $totalRows]) ?>
    </div>
    <div class="actions sticky doc-bar">
      <a class="btn ghost" href="<?= $u($base) ?>" data-leave><?= $word('DOC', 'leave') ?></a>
      <span class="doc-bar-note"><?= $say('STOCK_OPS', 'total_units', number_format($units)) ?><?php if ($priced): ?> · <?= $money($total) ?><?php endif; ?></span>
      <button class="btn" type="submit" name="action" value="save"><?= $word('OP_FINAL', 'save') ?></button>
      <button type="submit" name="action" value="post" class="btn primary"><?= $e($k['final_button']) ?></button>
    </div>
<?php else: ?>
    <p class="actions"><button class="btn" type="submit"><?= $word('STOCK_OPS', 'save_lines') ?></button> <span class="muted"><?= $say('STOCK_OPS', 'total_units', number_format($units)) ?><?php if ($priced): ?> · <?= $money($total) ?><?php endif; ?></span></p>
<?php endif; ?>
  </form>
<?php if ($linesPager !== null): ?>
  <form class="pager" method="get" action="<?= $u($base . '/' . $doc->id) ?>" data-leaves="1">
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
  <h2 id="final-h"><?= $word('STOCK_OPS', 'final_title') ?></h2>
  <p><?= $e($k['final_does']) ?></p>
<?php if ($ruleWaits): ?>
  <p class="muted"><?= $word('STOCK_OPS', 'final_waits') ?></p>
<?php endif; ?>
<?php if ($lines !== []): ?>
  <p class="note"><?= $say('OP_FINAL', 'too_many', \CW\Ui\Words::STOCK_OPS['save_lines']) ?></p>
<?php endif; ?>
  <form class="inline" method="post" data-leaves="1" action="<?= $u($base . '/' . $doc->id . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <button type="submit" class="btn primary"><?= $e($k['final_button']) ?></button>
  </form>
</section>
<?php endif; ?>

<section class="doc-files" aria-labelledby="files-h" id="files">
  <h2 id="files-h"><?= $word('STOCK_OPS', 'files') ?></h2>
<?= $partial('stock_op_files', ['files' => $files, 'canFile' => $canFile, 'fileRoles' => $fileRoles, 'maxMb' => $maxMb, 'action' => $base . '/' . $doc->id . '/files']) ?>
</section>
<?php else: ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('STOCK_OPS', 'facts') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_OPS', 'products') ?></p><p class="doc-step-big"><?= $n($products) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_OPS', 'units') ?></p><p class="doc-step-big"><?= $n($units) ?></p></div>
<?php if ($priced): ?>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_OPS', 'value') ?></p><p class="doc-step-big"><?= $money($total) ?></p></div>
<?php endif; ?>
  <div class="doc-step"><p class="doc-step-label"><?= $word('STOCK_OPS', 'date') ?></p><p class="doc-step-big"><?= $day($doc->docDate) ?></p></div>
</section>

<article class="doc-view" aria-labelledby="lines-h">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
  <h2 id="lines-h" class="doc-label"><?= $word('STOCK_OPS', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('STOCK_OPS', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list board doc-lines">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STOCK_OPS', 'line_product') ?></th>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'line_units') ?></th>
<?php if ($priced): ?>
        <th scope="col" class="num"><?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'line_price') ?><?php else: ?><?= $word('STOCK_OPS', 'line_cost') ?><?php endif; ?></th>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'line_amount') ?></th>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
        <th scope="col"><?= $word('STOCK_OPS', 'line_reason') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
        <td data-label="<?= $word('STOCK_OPS', 'line_units') ?>" class="num"><?= $n($l['qty']) ?></td>
<?php if ($priced): ?>
        <td data-label="<?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'line_price') ?><?php else: ?><?= $word('STOCK_OPS', 'line_cost') ?><?php endif; ?>" class="num"><?php if ($l['unit_cost'] !== null): ?><?= $money($l['unit_cost']) ?><?php endif; ?></td>
        <td data-label="<?= $word('STOCK_OPS', 'line_amount') ?>" class="num"><?php if ($l['amount'] !== null): ?><?= $money($l['amount']) ?><?php endif; ?></td>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
        <td data-label="<?= $word('STOCK_OPS', 'line_reason') ?>"><?= $e($l['reason_text']) ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <div class="doc-foot">
    <div class="doc-notes" id="files">
      <p class="doc-label"><?= $word('STOCK_OPS', 'files') ?></p>
<?= $partial('stock_op_files', ['files' => $files, 'canFile' => $canFile, 'fileRoles' => $fileRoles, 'maxMb' => $maxMb, 'action' => $base . '/' . $doc->id . '/files']) ?>
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
