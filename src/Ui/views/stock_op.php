<p class="crumbs"><a href="<?= $e($base) ?>"><?= $e($listName) ?></a></p>
<h1><?= $e($title) ?> <?= $stateChip('DOC_STATUS', $doc->status) ?></h1>
<?= $intro('stock_op', $lookOnly) ?>
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

<section aria-labelledby="facts-h">
  <h2 id="facts-h" class="visually-hidden"><?= $word('STOCK_OPS', 'facts') ?></h2>
  <dl class="wide">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact['label']) ?></dt><dd><?= $e($fact['value']) ?></dd>
<?php endforeach; ?>
<?php if ($doc->isReversal()): ?>
    <dt><?= $word('RECORD', 'cancels') ?></dt><dd><a href="<?= $u($base . '/' . $doc->reversesId) ?>"><?= $e($rec['reverses_number']) ?></a></dd>
<?php endif; ?>
<?php if ($reversedBy !== null): ?>
    <dt><?= $word('STOCK_OPS', 'cancelled_by') ?></dt><dd><a href="<?= $u($base . '/' . $reversedBy['id']) ?>"><?php if ($reversedBy['number'] === null): ?><?= $word('STOCK_OPS', 'cancel_waiting') ?><?php else: ?><?= $e($reversedBy['number']) ?><?php endif; ?></a></dd>
<?php endif; ?>
  </dl>
<?php if ($balanceHref !== null): ?>
  <p><a href="<?= $e($balanceHref) ?>"><?= $word('ACCOUNTS', 'title') ?></a></p>
<?php endif; ?>
<?php if ($pdf !== null): ?>
  <p><a href="<?= $u($pdf) ?>"><?= $e($k['pdf']) ?></a></p>
<?php endif; ?>
</section>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('STOCK_OPS', 'lines') ?></h2>
<?php if ($editable): ?>
  <form class="inline stock-add" method="post" action="<?= $u($base . '/' . $doc->id . '/add') ?>">
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
    <button type="submit" class="primary"><?= $word('STOCK_OPS', 'add_button') ?></button>
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
            <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/add') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="version" value="<?= $e($version) ?>">
              <input type="hidden" name="sku_id" value="<?= $e($c['sku_id']) ?>">
              <input type="hidden" name="qty" value="<?= $e($typedAdd['qty']) ?>">
              <input type="hidden" name="cost" value="<?= $e($typedAdd['cost']) ?>">
              <input type="hidden" name="reason" value="<?= $e($typedAdd['reason']) ?>">
              <button type="submit"><?= $word('STOCK_OPS', 'choose_this') ?></button>
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
  <p class="muted"><?= $word('STOCK_OPS', 'no_lines') ?></p>
<?php elseif ($editable): ?>
  <form class="record stock-lines" method="post" action="<?= $u($base . '/' . $doc->id . '/lines') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="line_count" value="<?= $e(count($lines)) ?>">
    <div class="table-wrap">
    <table class="stack lines">
      <thead>
        <tr>
          <th scope="col"><?= $word('STOCK_OPS', 'line_product') ?></th>
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
<?php foreach ($lines as $l): ?>
        <tr>
          <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
          <td data-label="<?= $word('STOCK_OPS', 'line_units') ?>" class="num"><input type="text" name="u_<?= $e($l['line_no']) ?>" value="<?= $e($l['qty']) ?>" inputmode="numeric" maxlength="9" aria-label="<?= $word('STOCK_OPS', 'line_units') ?>"></td>
<?php if ($priced): ?>
          <td data-label="<?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'line_price') ?><?php else: ?><?= $word('STOCK_OPS', 'line_cost') ?><?php endif; ?>" class="num"><input type="text" name="c_<?= $e($l['line_no']) ?>" value="<?= $dec($l['unit_cost']) ?>" inputmode="decimal" maxlength="16" aria-label="<?= $word('STOCK_OPS', 'line_cost') ?>"><?php if ($l['average'] !== null): ?><span class="o-sub"><?= $say('STOCK_OPS', 'line_average', \CW\Ui\Html::money($l['average'])) ?></span><?php endif; ?></td>
          <td data-label="<?= $word('STOCK_OPS', 'line_amount') ?>" class="num"><?php if ($l['amount'] !== null): ?><?= $money($l['amount']) ?><?php endif; ?></td>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
          <td data-label="<?= $word('STOCK_OPS', 'line_reason') ?>">
            <select name="r_<?= $e($l['line_no']) ?>" aria-label="<?= $word('STOCK_OPS', 'line_reason') ?>">
              <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reasons as $r): ?>
              <option value="<?= $e($r['code']) ?>"<?php if ($l['reason_code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
            </select>
          </td>
<?php endif; ?>
          <td data-label="<?= $word('STOCK_OPS', 'line_stock') ?>"><?php if ($l['stock'] !== null): ?><?= $say('STOCK_OPS', 'line_stock_text', number_format($l['stock']['on_hand']), number_format($l['stock']['available'])) ?><?php endif; ?></td>
          <td data-label="<?= $word('STOCK_OPS', 'line_remove') ?>"><label class="choice"><input type="checkbox" name="x_<?= $e($l['line_no']) ?>" value="1"> <?= $word('STOCK_OPS', 'line_remove') ?></label></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'save_lines') ?></button> <span class="muted"><?= $say('STOCK_OPS', 'total_units', number_format($units)) ?><?php if ($priced): ?> · <?= $money($total) ?><?php endif; ?></span></p>
  </form>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('STOCK_OPS', 'line_product') ?></th>
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
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $e($l['code']) ?></span></th>
        <td data-label="<?= $word('STOCK_OPS', 'line_units') ?>" class="num"><?= $n($l['qty']) ?></td>
<?php if ($priced): ?>
        <td data-label="<?= $word('STOCK_OPS', 'line_cost') ?>" class="num"><?php if ($l['unit_cost'] !== null): ?><?= $money($l['unit_cost']) ?><?php endif; ?></td>
        <td data-label="<?= $word('STOCK_OPS', 'line_amount') ?>" class="num"><?php if ($l['amount'] !== null): ?><?= $money($l['amount']) ?><?php endif; ?></td>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
        <td data-label="<?= $word('STOCK_OPS', 'line_reason') ?>"><?= $e($l['reason_text']) ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
      <tr class="total">
        <th scope="row" class="c-head"><?= $word('STOCK_OPS', 'total') ?></th>
        <td data-label="<?= $word('STOCK_OPS', 'line_units') ?>" class="num"><?= $n($units) ?></td>
<?php if ($priced): ?>
        <td data-label=""></td>
        <td data-label="<?= $word('STOCK_OPS', 'line_amount') ?>" class="num"><?= $money($total) ?></td>
<?php endif; ?>
<?php if ($kind === 'adjust'): ?>
        <td data-label=""></td>
<?php endif; ?>
      </tr>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<?php if ($editable): ?>
<details class="fold"<?php if (in_array($errorCode, ['warehouse_required', 'warehouse_inactive', 'location_mismatch', 'location_inactive', 'not_own_stock', 'owner_differs', 'release_from_own', 'release_to_other', 'reason_not_applicable', 'reason_inactive', 'bad_field'], true)): ?> open<?php endif; ?>>
  <summary><?= $word('STOCK_OPS', 'details') ?></summary>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/details') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
<?= $partial('stock_op_fields', ['form' => $form, 'kind' => $kind]) ?>
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'save_details') ?></button></p>
  </form>
</details>
<section class="card final-box" aria-labelledby="final-h">
  <h2 id="final-h"><?= $word('STOCK_OPS', 'final_title') ?></h2>
  <p><?= $e($k['final_does']) ?></p>
<?php if ($ruleWaits): ?>
  <p class="muted"><?= $word('STOCK_OPS', 'final_waits') ?></p>
<?php endif; ?>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <button type="submit" class="primary"><?= $e($k['final_button']) ?></button>
  </form>
</section>
<details class="fold">
  <summary><?= $word('STOCK_OPS', 'stop_title') ?></summary>
  <p class="muted"><?= $word('STOCK_OPS', 'stop_text') ?></p>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('STOCK_OPS', 'stop_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($canWithdraw): ?>
<section aria-labelledby="withdraw-h">
  <h2 id="withdraw-h"><?= $word('STOCK_OPS', 'withdraw_title') ?></h2>
  <p class="muted"><?= $word('STOCK_OPS', 'withdraw_text') ?></p>
  <form class="inline" method="post" action="<?= $u($base . '/' . $doc->id . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit"><?= $word('STOCK_OPS', 'withdraw_button') ?></button>
  </form>
</section>
<?php endif; ?>

<?php if ($reverse !== null): ?>
<details class="fold"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('STOCK_OPS', 'cancel_title') ?></summary>
  <p class="muted"><?= $word('STOCK_OPS', 'cancel_text') ?></p>
  <form class="record" method="post" action="<?= $u($base . '/' . $doc->id . '/reverse') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('STOCK_OPS', 'cancel_why') ?>
      <select name="reason_code" required>
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reverse['reasons'] as $r): ?>
        <option value="<?= $e($r['code']) ?>"<?php if ($typedCancel['code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('STOCK_OPS', 'cancel_note') ?> <input type="text" name="note" value="<?= $e($typedCancel['note']) ?>" maxlength="1000"></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('STOCK_OPS', 'cancel_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<section aria-labelledby="files-h" id="files">
  <h2 id="files-h"><?= $word('STOCK_OPS', 'files') ?></h2>
<?php if ($files === []): ?>
  <p class="muted"><?= $word('STOCK_OPS', 'no_files') ?></p>
<?php else: ?>
  <ul class="files">
<?php foreach ($files as $file): ?>
    <li><a href="<?= $u('/ui/files/' . $file['id']) ?>"><?= $e($file['original_name']) ?></a> <span class="muted"><?= $e($file['what']) ?> · <?= $e($file['kind']) ?> · <?= $e($file['size']) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($canFile): ?>
  <form class="record" method="post" enctype="multipart/form-data" action="<?= $u($base . '/' . $doc->id . '/files') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('STOCK_OPS', 'file_role') ?>
      <select name="role">
<?php foreach ($fileRoles as $code => $label): ?>
        <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('STOCK_OPS', 'file') ?> <span class="hint"><?= $say('STOCK_OPS', 'file_hint', $maxMb) ?></span><input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
    <p class="actions"><button type="submit"><?= $word('STOCK_OPS', 'file_button') ?></button></p>
  </form>
<?php endif; ?>
</section>

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
