<?php
// Purchasing › Invoices and Credit notes (pack C, PC17), a clean list (docs/decisions.md PV, Zoho Inventory's list as the layout): one
// search box (our number, theirs, the supplier), a status filter, "+ New supplier invoice" at the top right (the statement's create button:
// the one page, PV1), and one row a paper: date, number (theirs under it), supplier, status, total, and for an invoice its due date and
// what is still owed. A row opens its paper. A credit note is still started from its fold (pack C). The URL's other filters (supplier,
// match, stopped) still narrow the list (links from elsewhere use them) and a search keeps them (hidden fields).
?>
<?= $head((string) $k['tab'], intro: $kind === 'invoice' ? 'invoices' : 'credit_notes', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('INVOICES', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('INVOICES', 'search') ?>"></div>
  <div class="tb-state">
    <label class="visually-hidden" for="tb-state"><?= $word('DOC_LIST', 'state_label') ?></label>
    <select id="tb-state" name="state">
      <option value=""><?= $word('DOC_LIST', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
<?php if ($f['supplier'] !== null): ?>
    <input type="hidden" name="supplier" value="<?= $e($f['supplier']) ?>">
<?php endif; ?>
<?php if ($f['match'] !== null): ?>
    <input type="hidden" name="match" value="<?= $e($f['match']) ?>">
<?php endif; ?>
<?php if ($f['cancelled']): ?>
    <input type="hidden" name="cancelled" value="1">
<?php endif; ?>
    <button type="submit" class="btn secondary sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end"><a class="btn ghost sm" href="<?= $e($base) ?>"><?= $word('STOCK_OPS', 'clear') ?></a></div>
<?php endif; ?>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $e($k['start']) ?></summary>
  <form class="record" method="post" action="<?= $e($base) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label><?= $word('INVOICES', 'supplier') ?>
      <select name="supplier_id" required>
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if (($typed['supplier_id'] ?? '') === (string) $s['id']): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $e($k['number']) ?> <input type="text" name="invoice_number" value="<?= $e($typed['invoice_number'] ?? '') ?>" maxlength="64"></label>
    <label><?= $e($k['date']) ?> <input type="date" name="invoice_date" value="<?= $e($typed['invoice_date'] ?? '') ?>"></label>
    <p class="actions"><button type="submit" class="primary"><?= $e($k['start_button']) ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_OPS['none_filter'], \CW\Ui\Words::STOCK_OPS['none_filter_text'], $base, \CW\Ui\Words::STOCK_OPS['clear']) ?>
<?php else: ?>
<?= $empty($k['none'], $k['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list doc-list">
  <thead>
    <tr>
      <th scope="col"><?= $word('DOC_LIST', 'date') ?></th>
      <th scope="col" class="c-item"><?= $word('DOC_LIST', 'number') ?></th>
      <th scope="col"><?= $word('DOC_LIST', 'supplier') ?></th>
      <th scope="col" class="c-status"><?= $word('DOC_LIST', 'status') ?></th>
      <th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th>
<?php if ($kind === 'invoice'): ?>
      <th scope="col"><?= $word('DOC_LIST', 'due') ?></th>
      <th scope="col" class="num"><?= $word('DOC_LIST', 'balance') ?></th>
<?php else: ?>
      <th scope="col"><?= $word('INVOICES', 'credits') ?></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e($r['tone']) ?>">
      <td data-label="<?= $word('DOC_LIST', 'date') ?>"><?= $e($r['date']) ?></td>
      <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['sub']) ?></span></th>
      <td data-label="<?= $word('DOC_LIST', 'supplier') ?>"><?= $e($r['supplier']) ?></td>
      <td class="c-status"><?= $chip($r['tone'], $r['state_word']) ?><?php if ($kind === 'invoice' && $r['match'] !== null && $r['match'] !== 'matched'): ?> <?= $stateChip('MATCH_STATE', $r['match']) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($r['gross'] !== null): ?><?= $money($r['gross']) ?><?php endif; ?></td>
<?php if ($kind === 'invoice'): ?>
      <td data-label="<?= $word('DOC_LIST', 'due') ?>"><?= $e($r['due']) ?></td>
      <td class="num" data-label="<?= $word('DOC_LIST', 'balance') ?>"><?= $e($r['balance'] ?? '') ?></td>
<?php else: ?>
      <td data-label="<?= $word('INVOICES', 'credits') ?>"><?= $e($r['credits'] ?? '') ?></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
