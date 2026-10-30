<?php
// Stock › Hand-overs › Customers, a board (the approved design of 29 Oct 2026, pattern 1; docs/decisions.md W8): one search (the name, the
// company number, the VAT number, the e-mail) and one Show row (the kind of customer, and switched-off customers too), Clear at the right;
// the head's "+ New …" and the last row of the In use group open the add form (its fields in two columns). The groups: in use, switched
// off; each a coloured heading with its count over a card with its colour on the left edge, the status cell filled with it. A row opens
// the customer. Loading the prices of old trade invoices is a fold under the toolbar; its result (and every skipped row) comes first.
?>
<?= $head(\CW\Ui\Words::SEGMENT['handover_customers'], intro: 'handover_customers', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('CUSTOMER', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('CUSTOMER', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('DOC_LIST', 'show') ?></label>
    <select id="tb-show" name="kind">
      <option value=""><?= $word('CUSTOMER', 'any_kind') ?></option>
<?php foreach ($kinds as $k): ?>
      <option value="<?= $e($k) ?>"<?php if ($f['kind'] === $k): ?> selected<?php endif; ?>><?= $word('CUSTOMER_KIND', $k) ?></option>
<?php endforeach; ?>
    </select>
    <label class="choice tb-check"><input type="checkbox" name="off" value="1"<?php if ($f['off']): ?> checked<?php endif; ?>> <?= $word('CUSTOMER', 'show_off') ?></label>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/stock/handovers/customers"><?= $word('CUSTOMER', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($import !== null): ?>
<section class="card import-result" aria-labelledby="import-h">
  <h2 id="import-h"><?= $word('CUSTOMER', 'import_done') ?></h2>
  <p><?= $say('CUSTOMER', 'import_counts', $import['rows'], $import['imported'], $import['kept_newer'], count($import['skipped'])) ?></p>
<?php if ($import['skipped'] !== []): ?>
  <p class="note"><?= $word('CUSTOMER', 'import_skipped') ?></p>
  <div class="table-wrap">
  <table class="stack list">
    <thead><tr><th scope="col"><?= $word('CUSTOMER', 'import_row') ?></th><th scope="col"><?= $word('CUSTOMER', 'import_value') ?></th><th scope="col"><?= $word('CUSTOMER', 'import_why') ?></th></tr></thead>
    <tbody>
<?php foreach ($import['skipped'] as $s): ?>
      <tr>
        <th scope="row" class="c-head"><?= $say('CUSTOMER', 'import_row_no', $s['row'] + 1) ?></th>
        <td data-label="<?= $word('CUSTOMER', 'import_value') ?>"><?= $e($s['value']) ?></td>
        <td data-label="<?= $word('CUSTOMER', 'import_why') ?>"><?= $word('PRICE_SKIP', $s['why']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if (($error !== null && $import === null && $errorCode !== 'import_columns' && $errorCode !== 'bad_file') || $rows === []): ?> open<?php endif; ?>>
  <summary><?= $word('CUSTOMER', 'add') ?></summary>
  <form class="record customer" method="post" action="/ui/stock/handovers/customers" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('CUSTOMER', 'add') ?></legend>
<?= $partial('handover_customer_fields', ['v' => $v, 'errors' => $errors, 'kinds' => $kinds, 'vatCodes' => $vatCodes]) ?>
    </fieldset>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('CUSTOMER', 'add_button') ?></button></p>
  </form>
</details>
<details class="fold"<?php if ($errorCode === 'import_columns' || $errorCode === 'bad_file'): ?> open<?php endif; ?>>
  <summary><?= $word('CUSTOMER', 'import') ?></summary>
  <p class="muted"><?= $word('CUSTOMER', 'import_text') ?></p>
  <form class="record doc-drop" method="post" enctype="multipart/form-data" action="/ui/stock/handovers/customers/prices">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('CUSTOMER', 'import_file') ?> <span class="hint"><?= $say('CUSTOMER', 'import_hint', $maxMb) ?></span><input type="file" name="file" accept=".csv,text/csv" required></label>
    <p class="actions"><button class="btn" type="submit"><?= $word('CUSTOMER', 'import_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::CUSTOMER['none_filter'], \CW\Ui\Words::CUSTOMER['none_filter_text'], '/ui/stock/handovers/customers', \CW\Ui\Words::CUSTOMER['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::CUSTOMER['none'], \CW\Ui\Words::CUSTOMER['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = $g === 'on' ? 'done' : 'off'; ?>
<div class="grp-block doc-group <?= $e($tone) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $word('CUSTOMER', $g) ?></button> <span class="grp-count"><?php if (count($list) === 1): ?><?= $word('CUSTOMER', 'group_one') ?><?php else: ?><?= $say('CUSTOMER', 'group_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list customers">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('CUSTOMER', 'name') ?></th>
        <th scope="col" class="c-status"><?= $word('CUSTOMER', 'state') ?></th>
        <th scope="col"><?= $word('CUSTOMER', 'vat_code') ?></th>
        <th scope="col" class="num"><?= $word('CUSTOMER', 'handovers') ?></th>
        <th scope="col" class="num"><?= $word('CUSTOMER', 'balance') ?></th>
        <th scope="col"><?= $word('CUSTOMER', 'last') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/stock/handovers/customers/' . $r['id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $word('CUSTOMER_KIND', (string) $r['kind']) ?></span></th>
        <td class="c-status doc-st <?= $e($tone) ?>"><span class="chip"><?= $word('CUSTOMER', $g) ?></span></td>
        <td data-label="<?= $word('CUSTOMER', 'vat_code') ?>"><?php if ($r['vat_code'] === null): ?><?= $word('CUSTOMER', 'vat_company') ?><?php else: ?><?= $e($r['vat_code']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('CUSTOMER', 'handovers') ?>"><?= $n($r['handovers']) ?></td>
        <td class="num" data-label="<?= $word('CUSTOMER', 'balance') ?>"><?= $money($r['balance']) ?></td>
        <td data-label="<?= $word('CUSTOMER', 'last') ?>"><?php if ($r['last_handover'] !== null): ?><?= $day($r['last_handover']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($g === 'on' && $formKey !== null): ?>
      <tr class="grp-add"><td colspan="6" data-label=""><a class="add-item" href="#new"><?= $word('CUSTOMER', 'add_row') ?></a></td></tr>
<?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
