<?php
// Purchasing › Returns to suppliers, a board (the owner's approved design of 29 Oct 2026; docs/decisions.md W9.5): one search box (our
// number, theirs, the supplier), one Show menu (any status or one, with the tick for cancelled drafts), "+ New return" at the top right
// (the head's create button) and at the end of the Draft group; then the returns grouped: draft, waiting for an OK, final with the credit
// still to come, credited, cancelled. Each group a coloured heading with its count over a card with its colour on the left edge; the status
// cell filled with its colour. The new return's fields in two columns in its drawer (#new), with the quick way from a delivery first.
?>
<?= $head(\CW\Ui\Words::MENU['returns'], intro: 'returns', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('INVOICES', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('INVOICES', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('ORDERS', 'show') ?></label>
    <select id="tb-show" name="state">
      <option value=""><?= $word('STOCK_OPS', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label class="choice tb-check"><input type="checkbox" name="cancelled" value="1"<?php if ($f['cancelled']): ?> checked<?php endif; ?>> <?= $word('STOCK_OPS', 'stopped') ?></label>
<?php if ($f['supplier'] !== null): ?>
    <input type="hidden" name="supplier" value="<?= $e($f['supplier']) ?>">
<?php endif; ?>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end"><a class="btn ghost sm" href="/ui/purchasing/returns"><?= $word('STOCK_OPS', 'clear') ?></a></div>
<?php endif; ?>
</form>

<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('RETURNS', 'start') ?></summary>
<?php if ($quickKey !== null && $deliveries !== []): ?>
  <form class="inline" method="post" action="/ui/purchasing/returns/from-receipt" aria-label="<?= $word('RETURNS', 'quick_title') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($quickKey) ?>">
    <label><?= $word('RETURNS', 'quick_title') ?>
      <select name="grn_id" required>
<?php foreach ($deliveries as $d): ?>
        <option value="<?= $e($d['id']) ?>"><?= $e($d['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn"><?= $word('RETURNS', 'quick_button') ?></button>
  </form>
<?php endif; ?>
  <form class="doc-details" method="post" action="/ui/purchasing/returns" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('RETURNS', 'start') ?></legend>
      <label><?= $word('INVOICES', 'supplier') ?>
        <select name="supplier_id" required>
          <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($suppliers as $s): ?>
          <option value="<?= $e($s['id']) ?>"<?php if (($typed['supplier_id'] ?? '') === (string) $s['id']): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('RETURNS', 'from') ?>
        <select name="warehouse_id">
<?php foreach ($warehouses as $w): ?>
          <option value="<?= $e($w['id']) ?>"<?php if (($typed['warehouse_id'] ?? '') === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('RETURNS', 'reason') ?>
        <select name="reason_code">
          <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reasons as $r): ?>
          <option value="<?= $e($r['code']) ?>"<?php if (($typed['reason_code'] ?? '') === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('RETURNS', 'ref') ?> <input type="text" name="return_ref" value="<?= $e($typed['return_ref'] ?? '') ?>" maxlength="100"></label>
      <p class="actions doc-wide"><button type="submit" class="btn primary"><?= $word('RETURNS', 'start_button') ?></button></p>
    </fieldset>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_OPS['none_filter'], \CW\Ui\Words::STOCK_OPS['none_filter_text'], '/ui/purchasing/returns', \CW\Ui\Words::STOCK_OPS['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::RETURNS['none'], \CW\Ui\Words::RETURNS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('RETURNS', 'group_one') ?><?php else: ?><?= $say('RETURNS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('INVOICES', 'record') ?></th>
        <th scope="col" class="c-status"><?= $word('STOCK_OPS', 'status') ?></th>
        <th scope="col"><?= $word('INVOICES', 'supplier') ?></th>
        <th scope="col"><?= $word('RETURNS', 'reason') ?></th>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'units') ?></th>
        <th scope="col" class="num"><?= $word('RETURNS', 'claim') ?></th>
        <th scope="col"><?= $word('RETURNS', 'credited') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['sub']) ?><?php if ($r['delivery'] !== null): ?> · <?= $e($r['delivery']) ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><?= $e($r['state_word']) ?><?php if ($r['made_by'] !== ''): ?><span class="o-sub"><?= $e($r['made_by']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('INVOICES', 'supplier') ?>"><?= $e($r['supplier']) ?></td>
        <td data-label="<?= $word('RETURNS', 'reason') ?>"><?= $e($r['reason'] ?? '') ?></td>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"><?= $n($r['units']) ?></td>
        <td class="num" data-label="<?= $word('RETURNS', 'claim') ?>"><?php if ($r['claim'] !== null): ?><?= $money($r['claim']) ?><?php endif; ?></td>
        <td data-label="<?= $word('RETURNS', 'credited') ?>"><?php if ($r['credit'] !== null): ?><span class="tag <?= $e($r['credit_tone']) ?>"><?= $e($r['credit_word']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($g['key'] === 'draft' && $canPost): ?>
      <tr class="grp-add"><td colspan="7" data-label=""><a class="add-item" href="#new"><?= $word('RETURNS', 'add_row') ?></a></td></tr>
<?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
