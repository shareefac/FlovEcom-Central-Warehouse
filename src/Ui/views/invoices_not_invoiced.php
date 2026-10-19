<?= $head(\CW\Ui\Words::SEGMENT['not_invoiced'], intro: 'not_invoiced', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('INVOICES', 'search_delivery') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <label class="visually-hidden" for="tb-supplier"><?= $word('INVOICES', 'supplier') ?></label>
  <select id="tb-supplier" name="supplier">
    <option value=""><?= $word('INVOICES', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
    <option value="<?= $e($s['id']) ?>"<?php if ($supplier === $s['id']): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
  </select>
  <button type="submit" class="btn ghost sm"><?= $word('UI', 'apply') ?></button>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::INVOICES['none_waiting'], \CW\Ui\Words::INVOICES['none_waiting_text']) ?>
<?php else: ?>
<p class="muted"><?= $say('INVOICES', 'waiting_total', \CW\Ui\Html::money(\CW\Invoices\InvoiceMath::fromE2($total))) ?></p>
<div class="table-wrap">
<table class="stack list board">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('INVOICES', 'delivery') ?></th>
      <th scope="col"><?= $word('INVOICES', 'supplier') ?></th>
      <th scope="col"><?= $word('INVOICES', 'arrived') ?></th>
      <th scope="col" class="num"><?= $word('INVOICES', 'units_left') ?></th>
      <th scope="col" class="num"><?= $word('INVOICES', 'value_left') ?></th>
      <th scope="col"><?= $word('STOCK_OPS', 'next') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/receiving/' . $r['id']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['external_ref'] ?? '') ?></span></th>
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
          <button type="submit" class="primary"><?= $word('INVOICES', 'book_invoice') ?></button>
        </form>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
