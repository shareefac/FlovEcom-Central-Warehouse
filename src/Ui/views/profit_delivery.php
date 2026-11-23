<?php
// Profit › Delivery bills (docs/decisions.md DL23): the carriers' bills CW has read, the newest upload first: carrier, bill, shop, days, net,
// on orders, not on an order, before CW start, who uploaded it and when, whether it counts. The upload (the Delivery bills job only): the
// carrier (DPD in this version), the file, and the shop only for an account CW does not know yet. On a phone each row is a card.
$statusWords = ['live' => \CW\Ui\Words::DELIVERY['status_live'], 'superseded' => \CW\Ui\Words::DELIVERY['status_superseded'],
    'undone' => \CW\Ui\Words::DELIVERY['status_undone']];
$tone = ['live' => 'done', 'superseded' => 'off', 'undone' => 'off'];
$gbp = static fn (int $p): string => \CW\Profit\DpdInvoiceFile::gbp($p);
?>
<?= $head(\CW\Ui\Words::MENU['profit_delivery'], intro: 'profit_delivery', aside: $explain('delivery_bills', \CW\Ui\Words::MENU['profit_delivery'])) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canUpload): ?>
<form class="record import delivery-upload" method="post" action="/ui/profit/delivery/upload" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <fieldset>
    <legend><?= $word('DELIVERY', 'upload_title') ?></legend>
    <label for="d-carrier"><?= $word('DELIVERY', 'carrier') ?></label>
    <select id="d-carrier" name="carrier">
<?php foreach ($carriers as $c): ?>
      <option value="<?= $e($c) ?>"<?php if (($typed['carrier'] ?? 'dpd') === $c): ?> selected<?php endif; ?>><?= $word('DELIVERY', 'carrier_' . $c) ?></option>
<?php endforeach; ?>
    </select>
    <label for="d-file"><?= $word('DELIVERY', 'file') ?> <span class="hint"><?= $say('DELIVERY', 'file_hint', $maxMiB) ?></span></label>
    <input id="d-file" type="file" name="file" accept=".csv,text/csv">
    <label for="d-shop"><?= $word('DELIVERY', 'shop_choice') ?> <span class="hint"><?= $word('DELIVERY', 'shop_hint') ?></span></label>
    <select id="d-shop" name="shop">
      <option value=""><?= $word('DELIVERY', 'shop_auto') ?></option>
<?php foreach ($stores as $s): ?>
      <option value="<?= $e((string) $s['id']) ?>"<?php if (($typed['shop'] ?? null) === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </fieldset>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('DELIVERY', 'upload') ?></button></p>
</form>
<?php endif; ?>
<?php if ($bills === []): ?>
<?= $empty(\CW\Ui\Words::DELIVERY['none'], $canUpload ? \CW\Ui\Words::DELIVERY['none_text'] : '') ?>
<?php else: ?>
<?php if (count($bills) >= $limit): ?>
<p class="note"><?= $say('DELIVERY', 'list_limit', $limit) ?></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table delivery-bills">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'bill') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'shop') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'days') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'net') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'on_orders') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'not_on_order') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'before_start') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'uploaded_by') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'status') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($bills as $b): ?>
<?php
    $on = $b['states']['matched']['pence'] + $b['states']['collection']['pence'];
    $onN = $b['states']['matched']['lines'] + $b['states']['collection']['lines'];
    $other = $b['states']['not_matched']['pence'] + $b['states']['ambiguous']['pence'];
    $otherN = $b['states']['not_matched']['lines'] + $b['states']['ambiguous']['lines'];
?>
    <tr>
      <th scope="row"><a href="<?= $e($b['href']) ?>"><?= $say('DELIVERY', 'bill_title', strtoupper((string) $b['carrier']), (string) $b['invoice_no']) ?></a> <span class="muted"><?= $e((string) $b['account']) ?></span></th>
      <td data-label="<?= $word('DELIVERY', 'shop') ?>"><?= $e((string) $b['shop']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'days') ?>"><?= $day($b['period_from']) ?> - <?= $day($b['period_to']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'net') ?>"><?= $e($gbp((int) $b['net_pence'])) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'on_orders') ?>"><?= $e($gbp($on)) ?> <span class="muted">(<?= $n($onN) ?>)</span></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'not_on_order') ?>"><?= $e($gbp($other)) ?> <span class="muted">(<?= $n($otherN) ?>)</span></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'before_start') ?>"><?= $e($gbp($b['states']['before_start']['pence'])) ?> <span class="muted">(<?= $n($b['states']['before_start']['lines']) ?>)</span></td>
      <td data-label="<?= $word('DELIVERY', 'uploaded_by') ?>"><?= $e((string) ($b['uploaded_name'] ?? $b['uploaded_actor'])) ?> <span class="muted"><?= $when($b['uploaded_at']) ?></span></td>
      <td data-label="<?= $word('DELIVERY', 'status') ?>"><?= $chip($tone[(string) $b['status']] ?? 'info', $statusWords[(string) $b['status']] ?? (string) $b['status']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
