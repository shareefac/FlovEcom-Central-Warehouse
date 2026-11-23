<?php
// Profit › Delivery bills › one bill (docs/decisions.md DL23): the bill in one sentence, its facts (account, shop, days, net / VAT / total,
// lines, who uploaded it, the file's fingerprint, the bill it replaced or that replaced it), where its money went (on parcels of orders,
// collections on orders, before CW start, not matched, to check: adding up to its net), its lines of one kind a page at a time (the lines on
// no order the dearest first), and for the Delivery bills job: Replace with a corrected file, Undo this upload. On a phone each row is a card.
$b = $bill;
$gbp = static fn (int $p): string => \CW\Profit\DpdInvoiceFile::gbp($p);
$statusWords = ['live' => \CW\Ui\Words::DELIVERY['status_live'], 'superseded' => \CW\Ui\Words::DELIVERY['status_superseded'],
    'undone' => \CW\Ui\Words::DELIVERY['status_undone']];
$tone = ['live' => 'done', 'superseded' => 'off', 'undone' => 'off'];
$stateWords = [];
foreach (\CW\Profit\DeliveryBills::STATES as $st) {
    $stateWords[$st] = \CW\Ui\Words::DELIVERY['state_' . $st];
}
?>
<?= $head(\CW\Ui\Words::say('DELIVERY', 'bill_title', strtoupper((string) $b['carrier']), (string) $b['invoice_no']), intro: 'profit_delivery_bill',
    aside: $explain('delivery_bills', \CW\Ui\Words::MENU['profit_delivery']), back: ['/ui/profit/delivery', \CW\Ui\Words::DELIVERY['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<p class="lede delivery-headline"><?= $e($headline) ?></p>
<?php if (!$adds_up): ?>
<p class="error" role="alert"><?= $word('DELIVERY', 'not_adding_up') ?></p>
<?php endif; ?>

<div class="table-wrap">
<table class="stack rp-table delivery-facts">
  <tbody>
    <tr><th scope="row"><?= $word('DELIVERY', 'status') ?></th><td><?= $chip($tone[(string) $b['status']] ?? 'info', $statusWords[(string) $b['status']] ?? (string) $b['status']) ?><?php if ($b['ended_at'] !== null): ?> <span class="muted"><?= $say('DELIVERY', 'ended', $statusWords[(string) $b['status']] ?? '', (string) ($b['ended_name'] ?? $b['ended_actor']), \CW\Ui\Html::when((string) $b['ended_at'])) ?></span><?php endif; ?></td></tr>
<?php if ($replaced !== null): ?>
    <tr><th scope="row"><?= $word('DELIVERY', 'bill') ?></th><td><a href="<?= $u('/ui/profit/delivery/' . (int) $replaced['id']) ?>"><?= $say('DELIVERY', 'replaced', (string) $replaced['invoice_no'], \CW\Ui\Html::when((string) $replaced['uploaded_at'])) ?></a></td></tr>
<?php endif; ?>
<?php if ($replaced_by !== null): ?>
    <tr><th scope="row"><?= $word('DELIVERY', 'bill') ?></th><td><a href="<?= $u('/ui/profit/delivery/' . (int) $replaced_by['id']) ?>"><?= $say('DELIVERY', 'replaced_by', (string) $replaced_by['invoice_no']) ?></a></td></tr>
<?php endif; ?>
    <tr><th scope="row"><?= $word('DELIVERY', 'account') ?></th><td><?= $e((string) $b['account']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'shop') ?></th><td><?= $e((string) $b['shop']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'days') ?></th><td><?= $day($b['period_from']) ?> - <?= $day($b['period_to']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'net') ?></th><td class="num"><?= $e($gbp((int) $b['net_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'vat') ?></th><td class="num"><?= $e($gbp((int) $b['vat_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'gross') ?></th><td class="num"><?= $e($gbp((int) $b['gross_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'lines') ?></th><td class="num"><?= $n($b['line_count']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'uploaded_by') ?></th><td><?= $e((string) ($b['uploaded_name'] ?? $b['uploaded_actor'])) ?> <span class="muted"><?= $when($b['uploaded_at']) ?></span></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'fingerprint') ?></th><td><code><?= $e(substr((string) $b['file_sha256'], 0, 16)) ?></code></td></tr>
  </tbody>
</table>
</div>

<h2><?= $word('DELIVERY', 'tie_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-tie">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'state') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'lines') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach (\CW\Profit\DeliveryBills::STATES as $st): ?>
    <tr>
      <th scope="row"><?= $e($stateWords[$st]) ?></th>
      <td class="num" data-label="<?= $word('DELIVERY', 'lines') ?>"><?= $n($states[$st]['lines']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp($states[$st]['pence'])) ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="rp-total">
      <th scope="row"><?= $word('DELIVERY', 'tie_total') ?> <span class="muted"><?= $word('DELIVERY', $adds_up ? 'adds_up' : 'not_adding_up') ?></span></th>
      <td class="num" data-label="<?= $word('DELIVERY', 'lines') ?>"><?= $n($b['line_count']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp((int) $b['net_pence'])) ?></td>
    </tr>
  </tbody>
</table>
</div>
<?php if ($unpaid['lines'] > 0): ?>
<p class="note"><?= $say('DELIVERY', 'unpaid_note', $gbp($unpaid['pence']), (string) $unpaid['lines']) ?></p>
<?php endif; ?>

<h2><?= $word('DELIVERY', 'lines') ?></h2>
<p class="filters delivery-filters">
<?php foreach ($filters as $f): ?>
<?php if ($f['current']): ?>
  <strong aria-current="page"><?= $word('DELIVERY', 'filter_' . $f['key']) ?></strong>
<?php else: ?>
  <a href="<?= $e($f['href']) ?>"><?= $word('DELIVERY', 'filter_' . $f['key']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
</p>
<?php if ($lines['rows'] === []): ?>
<p class="muted"><?= $word('DELIVERY', 'no_lines') ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table delivery-lines">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'line') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'date') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'consignment') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'service') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'weight') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'cost') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'state') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'order') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($lines['rows'] as $l): ?>
    <tr>
      <th scope="row"><?= $n($l['line_no']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'date') ?>"><?= $day($l['line_date']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'consignment') ?>"><?= $e((string) ($l['consignment'] ?? '')) ?><?php if ((string) $l['marks'] !== ''): ?> <span class="muted"><?= $say('DELIVERY', 'marks', (string) $l['marks']) ?></span><?php endif; ?></td>
      <td data-label="<?= $word('DELIVERY', 'service') ?>"><?= $e(trim((string) $l['product'] . ' ' . (string) $l['service'])) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'weight') ?>"><?= $e((string) ($l['weight'] ?? '')) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp((int) $l['cost'])) ?></td>
      <td data-label="<?= $word('DELIVERY', 'state') ?>"><?= $e($stateWords[(string) $l['match_state']] ?? (string) $l['match_state']) ?><?php if ($l['fits'] !== []): ?> <span class="muted"><?= $say('DELIVERY', 'fits', implode(', ', array_map(static fn (array $f): string => $f['order_no'] !== '' ? $f['order_no'] : $f['order_ref'], $l['fits']))) ?></span><?php endif; ?></td>
      <td data-label="<?= $word('DELIVERY', 'order') ?>"><?php if ($l['order_ref'] !== null): ?><?php if ($l['order_href'] !== null): ?><a href="<?= $e($l['order_href']) ?>"><?= $e((string) (($l['order_no'] ?? '') !== '' ? $l['order_no'] : $l['order_ref'])) ?></a><?php else: ?><?= $e((string) (($l['order_no'] ?? '') !== '' ? $l['order_no'] : $l['order_ref'])) ?><?php endif; ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($lines['pages'] > 1): ?>
<p class="pager"><?= $say('DELIVERY', 'page', $lines['page'], $lines['pages']) ?>
<?php if ($lines['page'] > 1): ?> <a href="<?= $e($page_href($lines['page'] - 1)) ?>"><?= $word('DELIVERY', 'prev') ?></a><?php endif; ?>
<?php if ($lines['page'] < $lines['pages']): ?> <a href="<?= $e($page_href($lines['page'] + 1)) ?>"><?= $word('DELIVERY', 'next') ?></a><?php endif; ?>
</p>
<?php endif; ?>
<?php endif; ?>

<?php if ($canUpload): ?>
<h2><?= $word('DELIVERY', 'replace_title') ?></h2>
<form class="record delivery-replace" method="post" enctype="multipart/form-data" action="<?= $u('/ui/profit/delivery/' . (int) $b['id'] . '/replace') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <label for="r-file"><?= $word('DELIVERY', 'file') ?> <span class="hint"><?= $word('DELIVERY', 'replace_hint') ?></span></label>
  <input id="r-file" type="file" name="file" accept=".csv,text/csv">
  <p class="actions"><button type="submit" class="btn"><?= $word('DELIVERY', 'replace') ?></button></p>
</form>
<h2><?= $word('DELIVERY', 'undo_title') ?></h2>
<form class="record delivery-undo" method="post" action="<?= $u('/ui/profit/delivery/' . (int) $b['id'] . '/undo') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <p class="hint"><?= $word('DELIVERY', 'undo_hint') ?></p>
  <label class="choice"><input type="checkbox" name="sure" value="1"> <?= $word('DELIVERY', 'undo_sure') ?></label>
  <p class="actions"><button type="submit" class="btn risky"><?= $word('DELIVERY', 'undo') ?></button></p>
</form>
<?php endif; ?>
