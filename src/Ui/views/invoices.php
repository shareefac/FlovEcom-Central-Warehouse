<?php
// Purchasing › Invoices and Credit notes (pack C, PC17), a board (the owner's approved design of 29 Oct 2026, monday.com's style;
// docs/decisions.md PV18): one search box (our number, theirs, the supplier), one Show menu (open, all, cancelled), "+ New supplier invoice"
// at the top right (the statement's create button: the one page, PV1); then the papers grouped: needs a look, draft (and waiting for an OK),
// final; cancelled under Show. Each group a coloured heading with its count (it folds, app.js) over a white card with its colour on the left
// edge; a row: the supplier's number (the order or delivery and our number under it), the status filled with its colour, the supplier, the
// match as a soft tag, the due date (red once passed with money owed), the total, what is still owed. No "overdue" or "paid" group: CW does
// not know the supplier payments. A row opens its paper. A credit note is still started from its fold (pack C). The URL's other filters
// (status, supplier, match) still narrow the list and a search keeps them (hidden fields).
?>
<?= $head((string) $k['tab'], intro: $kind === 'invoice' ? 'invoices' : 'credit_notes', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('INVOICES', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('INVOICES', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('ORDERS', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value="open"<?php if ($f['show'] === 'open'): ?> selected<?php endif; ?>><?= $word('INVOICES', 'show_open') ?></option>
      <option value="all"<?php if ($f['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('INVOICES', 'show_all') ?></option>
      <option value="cancelled"<?php if ($f['show'] === 'cancelled'): ?> selected<?php endif; ?>><?= $word('INVOICES', 'show_cancelled') ?></option>
    </select>
<?php if ($f['state'] !== null): ?>
    <input type="hidden" name="state" value="<?= $e($f['state']) ?>">
<?php endif; ?>
<?php if ($f['supplier'] !== null): ?>
    <input type="hidden" name="supplier" value="<?= $e($f['supplier']) ?>">
<?php endif; ?>
<?php if ($f['match'] !== null): ?>
    <input type="hidden" name="match" value="<?= $e($f['match']) ?>">
<?php endif; ?>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu"><a class="menu-item" href="<?= $e($base) ?>"><?= $word('STOCK_OPS', 'clear') ?></a></div>
    </details>
  </div>
<?php endif; ?>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $e($k['start']) ?></summary>
  <form class="doc-details" method="post" action="<?= $e($base) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset class="doc-grid">
    <legend class="visually-hidden"><?= $e($k['start']) ?></legend>
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
    <p class="actions doc-wide"><button type="submit" class="btn primary"><?= $e($k['start_button']) ?></button></p>
    </fieldset>
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
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $e($k['group_one']) ?><?php else: ?><?= $e(sprintf($k['group_many'], count($g['rows']))) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $e($k['col']) ?></th>
        <th scope="col" class="c-status"><?= $word('DOC_LIST', 'status') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'supplier') ?></th>
<?php if ($kind === 'invoice'): ?>
        <th scope="col"><?= $word('DOC_LIST', 'match') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'due') ?></th>
        <th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th>
        <th scope="col" class="num"><?= $word('DOC_LIST', 'balance') ?></th>
<?php else: ?>
        <th scope="col"><?= $word('INVOICES', 'credits') ?></th>
        <th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['title']) ?></a><span class="o-sub"><?= $e($r['date']) ?><?php if ($r['refs'] !== ''): ?> · <?= $e($r['refs']) ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($r['cell_tone']) ?>"><span class="chip"><?= $e($r['cell_word']) ?></span></td>
        <td data-label="<?= $word('DOC_LIST', 'supplier') ?>"><?= $e($r['supplier']) ?></td>
<?php if ($kind === 'invoice'): ?>
        <td data-label="<?= $word('DOC_LIST', 'match') ?>"><?php if ($r['tag'] !== null): ?><span class="tag <?= $e($r['tag']['tone']) ?>"><?= $e($r['tag']['text']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DOC_LIST', 'due') ?>"<?php if ($r['late']): ?> class="late"<?php endif; ?>><?= $e($r['due']) ?><?php if ($r['late_line'] !== null): ?> · <?= $e($r['late_line']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($r['gross'] !== null): ?><?= $money($r['gross']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'balance') ?>"><?= $e($r['balance'] ?? '') ?></td>
<?php else: ?>
        <td data-label="<?= $word('INVOICES', 'credits') ?>"><?= $e($r['credits'] ?? '') ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($r['gross'] !== null): ?><?= $money($r['gross']) ?><?php endif; ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
<?php if ($g['key'] === 'draft' && $canPost && $kind === 'invoice'): ?>
      <tr class="grp-add"><td colspan="7" data-label=""><a class="add-item" href="/ui/purchasing/invoices/new"><?= $e($k['add_row']) ?></a></td></tr>
<?php elseif ($g['key'] === 'draft' && $formKey !== null && $kind === 'credit_note'): ?>
      <tr class="grp-add"><td colspan="5" data-label=""><a class="add-item" href="#new"><?= $e($k['add_row']) ?></a></td></tr>
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
