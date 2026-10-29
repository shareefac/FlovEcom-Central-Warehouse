<p class="crumbs"><a href="/ui/stock/handovers"><?= $word('SEGMENT', 'handovers') ?></a></p>
<?= $head($title, intro: 'handover', look: $lookOnly, chips: $stateChip('DOC_STATUS', $doc->status)) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?><?php if ($errorCode === 'company_not_confirmed' || $errorCode === 'vat_code_missing'): ?> <a href="<?= $e($companyHref) ?>"><?= $word('HANDOVER', 'company_link') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($decide !== null): ?>
<?= $partial('decide_box', ['decide' => $decide]) ?>
<?php endif; ?>
<?php if ($notCreator): ?>
<p class="note read-only"><?= $word('HANDOVER', 'not_creator') ?></p>
<?php endif; ?>

<section aria-labelledby="facts-h">
  <h2 id="facts-h" class="visually-hidden"><?= $word('HANDOVER', 'facts') ?></h2>
  <dl class="wide">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact['label']) ?></dt><dd><?= $e($fact['value']) ?></dd>
<?php endforeach; ?>
<?php if ($doc->isReversal()): ?>
    <dt><?= $word('RECORD', 'cancels') ?></dt><dd><a href="<?= $u('/ui/stock/handovers/' . $doc->reversesId) ?>"><?= $e($rec['reverses_number']) ?></a></dd>
<?php endif; ?>
<?php if ($reversedBy !== null): ?>
    <dt><?= $word('HANDOVER', 'cancelled_by') ?></dt><dd><a href="<?= $u('/ui/stock/handovers/' . $reversedBy['id']) ?>"><?php if ($reversedBy['number'] === null): ?><?= $word('HANDOVER', 'cancel_waiting') ?><?php else: ?><?= $e($reversedBy['number']) ?><?php endif; ?></a></dd>
<?php endif; ?>
  </dl>
  <p><a href="<?= $u($customerHref) ?>"><?= $word('HANDOVER', 'customer_link') ?></a> &middot; <a href="<?= $u($balanceHref) ?>"><?= $word('HANDOVER', 'balance_link') ?></a></p>
<?php if ($pdf !== null): ?>
<?php if ($pdfBlocked === null): ?>
  <p><a href="<?= $u($pdf) ?>"><?= $word('HANDOVER', 'pdf') ?></a></p>
<?php else: ?>
  <p class="note"><?= $e($pdfBlocked) ?> <a href="<?= $e($companyHref) ?>"><?= $word('HANDOVER', 'company_link') ?></a></p>
<?php endif; ?>
<?php endif; ?>
</section>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('HANDOVER', 'lines') ?></h2>
<?php if ($editable): ?>
  <form class="inline stock-add" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/add') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('HANDOVER', 'add_q') ?> <input type="search" name="q" value="<?= $e($typedAdd['q']) ?>" maxlength="100" autocomplete="off" autofocus required></label>
    <label><?= $word('HANDOVER', 'add_qty') ?> <input type="text" name="qty" value="<?= $e($typedAdd['qty']) ?>" inputmode="numeric" maxlength="9" required></label>
    <label><?= $word('HANDOVER', 'add_price') ?> <input type="text" name="price" value="<?= $e($typedAdd['price']) ?>" inputmode="decimal" maxlength="16"></label>
    <button type="submit" class="primary"><?= $word('HANDOVER', 'add_button') ?></button>
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
              <button type="submit"><?= $word('HANDOVER', 'choose_this') ?></button>
            </form>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php if ($lines === []): ?>
  <p class="muted"><?= $word('HANDOVER', 'no_lines') ?></p>
<?php elseif ($editable): ?>
  <form class="record stock-lines" method="post" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/lines') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1"<?php if ($typedLines !== []): ?> data-dirty="1"<?php endif; ?>>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="line_count" value="<?= $e(count($lines)) ?>">
<?php if ($linesPager !== null): ?>
    <input type="hidden" name="lines_page" value="<?= $e($linesPager['no']) ?>">
<?php endif; ?>
    <div class="table-wrap">
    <table class="stack lines">
      <thead>
        <tr>
          <th scope="col"><?= $word('HANDOVER', 'line_item') ?></th>
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
          <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
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
    <p class="hint"><?= $word('HANDOVER', 'final_does') ?></p>
<?php if ($ruleWaits): ?>
    <p class="muted"><?= $word('HANDOVER', 'final_waits') ?></p>
<?php endif; ?>
    <div class="actions sticky">
      <button type="submit" name="action" value="save"><?= $word('OP_FINAL', 'save') ?></button>
      <button type="submit" name="action" value="post" class="primary"><?= $word('HANDOVER', 'final_button') ?></button>
      <span class="muted"><?= $say('HANDOVER', 'total_units', number_format($units)) ?> &middot; <?= $say('HANDOVER', 'net_so_far', \CW\Ui\Html::money($sums['net'])) ?></span>
    </div>
<?php else: ?>
    <p class="actions"><button type="submit"><?= $word('HANDOVER', 'save_lines') ?></button> <span class="muted"><?= $say('HANDOVER', 'total_units', number_format($units)) ?> &middot; <?= $say('HANDOVER', 'net_so_far', \CW\Ui\Html::money($sums['net'])) ?></span></p>
<?php endif; ?>
  </form>
<?php if ($linesPager !== null): ?>
  <form class="pager" method="get" action="<?= $u('/ui/stock/handovers/' . $doc->id) ?>" data-leaves="1">
    <span><?= $say('OP_FINAL', 'page', $linesPager['from'], $linesPager['to'], $linesPager['n']) ?></span>
<?php if ($linesPager['no'] > 1): ?>
    <button type="submit" name="lines_page" value="<?= $e($linesPager['no'] - 1) ?>"><?= $word('OP_FINAL', 'prev') ?></button>
<?php endif; ?>
<?php if ($linesPager['no'] < $linesPager['pages']): ?>
    <button type="submit" name="lines_page" value="<?= $e($linesPager['no'] + 1) ?>"><?= $word('OP_FINAL', 'next') ?></button>
<?php endif; ?>
  </form>
<?php endif; ?>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('HANDOVER', 'line_item') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'line_units') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'line_price') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'line_amount') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
        <td data-label="<?= $word('HANDOVER', 'line_units') ?>" class="num"><?= $n($l['qty']) ?></td>
        <td data-label="<?= $word('HANDOVER', 'line_price') ?>" class="num"><?php if ($l['unit_cost'] !== null): ?><?= $money($l['unit_cost']) ?><?php endif; ?></td>
        <td data-label="<?= $word('HANDOVER', 'line_amount') ?>" class="num"><?php if ($l['amount'] !== null): ?><?= $money($l['amount']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <dl class="wide sums">
<?php if ($posted): ?>
    <dt><?= $word('HANDOVER', 'net') ?></dt><dd><?= $money($sums['net']) ?></dd>
    <dt><?php if ($sums['vat_code'] === null): ?><?= $word('HANDOVER', 'no_vat') ?><?php else: ?><?= $say('HANDOVER', 'vat_at', (string) $sums['vat_code'], \CW\Ui\Html::dec($sums['vat_rate'])) ?><?php endif; ?></dt><dd><?= $money($sums['vat']) ?></dd>
    <dt><?= $word('HANDOVER', 'gross') ?></dt><dd><?= $money($sums['gross']) ?></dd>
<?php else: ?>
    <dt><?= $word('HANDOVER', 'net_draft') ?></dt><dd><?= $money($sums['net']) ?></dd>
<?php endif; ?>
  </dl>
<?php if ($missingPrices > 0 && $doc->status === 'draft'): ?>
  <p class="note"><?= $say('HANDOVER', 'missing_prices', $missingPrices) ?></p>
<?php endif; ?>
</section>

<?php if ($editable): ?>
<details class="fold"<?php if (in_array($errorCode, ['customer_required', 'unknown_customer', 'customer_off', 'warehouse_required', 'warehouse_inactive', 'not_own_stock', 'vat_code_off', 'vat_code_required', 'bad_field'], true)): ?> open<?php endif; ?>>
  <summary><?= $word('HANDOVER', 'details') ?></summary>
  <form class="record" method="post" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/details') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1"<?php if ($headerTyped): ?> data-dirty="1"<?php endif; ?>>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
<?= $partial('handover_fields', ['form' => $form]) ?>
    <p class="actions"><button type="submit"><?= $word('HANDOVER', 'save_details') ?></button></p>
  </form>
</details>
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
    <button type="submit" class="primary"><?= $word('HANDOVER', 'final_button') ?></button>
  </form>
</section>
<?php endif; ?>
<details class="fold">
  <summary><?= $word('HANDOVER', 'stop_title') ?></summary>
  <p class="muted"><?= $word('HANDOVER', 'stop_text') ?></p>
  <form class="record" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('HANDOVER', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('HANDOVER', 'stop_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($reverse !== null): ?>
<details class="fold"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('HANDOVER', 'cancel_title') ?></summary>
  <p class="muted"><?= $word('HANDOVER', 'cancel_text') ?></p>
  <form class="record" method="post" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/reverse') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('HANDOVER', 'cancel_why') ?>
      <select name="reason_code" required>
        <option value=""><?= $word('HANDOVER', 'choose') ?></option>
<?php foreach ($reverse['reasons'] as $r): ?>
        <option value="<?= $e($r['code']) ?>"<?php if ($typedCancel['code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('HANDOVER', 'cancel_note') ?> <input type="text" name="note" value="<?= $e($typedCancel['note']) ?>" maxlength="1000"></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('HANDOVER', 'cancel_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($canCopy): ?>
<section aria-labelledby="copy-h">
  <h2 id="copy-h"><?= $word('HANDOVER', 'copy_title') ?></h2>
  <p class="muted"><?= $word('HANDOVER', 'copy_text') ?></p>
  <form class="inline" method="post" data-leaves="1" action="<?= $u('/ui/stock/handovers/' . $doc->id . '/copy') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit"><?= $word('HANDOVER', 'copy_button') ?></button>
  </form>
</section>
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
