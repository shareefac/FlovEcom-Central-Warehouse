<?php
// Purchasing › Invoices › Not invoiced yet, a board (docs/decisions.md W9.8): one search box (the delivery, their invoice number, the
// supplier), the Show row with the supplier; then the deliveries waiting for their invoice as one group (a coloured heading with its count
// and the value waiting) over a card with its colour on the left edge, the status cell filled. A row opens its delivery; its "Book its
// invoice" is its own form, in td.c-next (above the row's link).
?>
<?= $head(\CW\Ui\Words::SEGMENT['not_invoiced'], intro: 'not_invoiced', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('INVOICES', 'search_delivery') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('INVOICES', 'search_delivery') ?>"></div>
  <div class="tb-state">
    <label for="tb-supplier"><?= $word('INVOICES', 'supplier') ?></label>
    <select id="tb-supplier" name="supplier">
      <option value=""><?= $word('INVOICES', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($supplier === $s['id']): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::INVOICES['none_waiting'], \CW\Ui\Words::INVOICES['none_waiting_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('INVOICES', 'deliveries_one') ?><?php else: ?><?= $say('INVOICES', 'deliveries_many', count($g['rows'])) ?><?php endif; ?> · <?= $say('INVOICES', 'waiting_total', \CW\Ui\Html::money(\CW\Invoices\InvoiceMath::fromE2($total))) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('INVOICES', 'delivery') ?></th>
        <th scope="col" class="c-status"><?= $word('DOC_LIST', 'status') ?></th>
        <th scope="col"><?= $word('INVOICES', 'supplier') ?></th>
        <th scope="col"><?= $word('INVOICES', 'arrived') ?></th>
        <th scope="col" class="num"><?= $word('INVOICES', 'units_left') ?></th>
        <th scope="col" class="num"><?= $word('INVOICES', 'value_left') ?></th>
        <th scope="col"><?= $word('STOCK_OPS', 'next') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/receiving/' . $r['id']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['external_ref'] ?? '') ?></span></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $word('INVOICES', 'not_invoiced_cell') ?></span></td>
        <td data-label="<?= $word('INVOICES', 'supplier') ?>"><?= $e($r['supplier_name']) ?></td>
        <td data-label="<?= $word('INVOICES', 'arrived') ?>"><?= $day($r['doc_date']) ?></td>
        <td class="num" data-label="<?= $word('INVOICES', 'units_left') ?>"><?= $n($r['units_left']) ?></td>
        <td class="num" data-label="<?= $word('INVOICES', 'value_left') ?>"><?= $money($r['value_left']) ?></td>
        <td class="c-next">
<?php if ($r['form_key'] !== null): ?>
          <form class="inline" method="post" action="/ui/purchasing/invoices/from-receipt">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($r['form_key']) ?>">
            <input type="hidden" name="grn_id" value="<?= $e($r['id']) ?>">
            <button type="submit" class="btn sm"><?= $word('INVOICES', 'book_invoice') ?></button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
