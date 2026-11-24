<?php
// Profit › Delivery bills (docs/decisions.md DL23): the carriers' bills CW has read, the newest upload first: carrier, bill, shop, days, net,
// on orders, not on an order, before CW start, who uploaded it and when, whether it counts. The upload (the Delivery bills job only): the
// carrier (DPD in this version), the file, and the shop only for an account CW does not know yet. On a phone each row is a card.
$statusWords = ['live' => \CW\Ui\Words::DELIVERY['status_live'], 'superseded' => \CW\Ui\Words::DELIVERY['status_superseded'],
    'undone' => \CW\Ui\Words::DELIVERY['status_undone'], 'loading' => \CW\Ui\Words::DELIVERY['status_loading']];
$tone = ['live' => 'done', 'superseded' => 'off', 'undone' => 'off', 'loading' => 'waiting'];
// Royal Mail (0078; DL69): its bills in the same list (their money summed by destination), the account to choose on the upload, the pasted text,
// and the accounts' labels (read up to which day; the Click & Drop report's upload).
$accounts ??= [];
$said ??= null;
$gbp = static fn (int $p): string => \CW\Profit\DpdInvoiceFile::gbp($p);
?>
<?= $head(\CW\Ui\Words::MENU['profit_delivery'], intro: 'profit_delivery', aside: $explain('delivery_bills', \CW\Ui\Words::MENU['profit_delivery'])) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($said !== null): ?>
<p class="notice" role="status"><?= $e($said) ?></p>
<?php endif; ?>
<?php if (($retry ?? null) !== null): ?>
<form class="record delivery-store" method="post" action="/ui/profit/delivery/store">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="check" value="<?= $e((string) $retry['check']) ?>">
  <input type="hidden" name="bill_sha256" value="<?= $e((string) $retry['bill_sha256']) ?>">
  <p class="hint"><?= $word('DELIVERY', 'rm_check_retry_hint') ?></p>
  <p class="actions"><button type="submit" class="btn primary"><?= $word('DELIVERY', 'rm_check_retry') ?></button></p>
</form>
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
    <label for="d-file"><?= $word('DELIVERY', 'file') ?> <span class="hint"><?= $say('DELIVERY', 'rm_file_hint', $maxMiB) ?></span></label>
    <input id="d-file" type="file" name="file" accept=".csv,text/csv,.html,.htm,text/html">
    <label for="d-account"><?= $word('DELIVERY', 'rm_account') ?> <span class="hint"><?= $word('DELIVERY', 'rm_account_hint') ?></span></label>
    <select id="d-account" name="account">
      <option value=""></option>
<?php foreach ($accounts as $a): ?>
      <option value="<?= $e((string) $a['id']) ?>"<?php if (($typed['account'] ?? null) === (int) $a['id']): ?> selected<?php endif; ?>><?= $e((string) $a['account_no'] . ' - ' . (string) $a['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label for="d-text"><?= $word('DELIVERY', 'rm_text') ?> <span class="hint"><?= $word('DELIVERY', 'rm_text_hint') ?></span></label>
    <textarea id="d-text" name="text" rows="4"></textarea>
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
<?php if ($accounts !== []): ?>
<h2><?= $word('DELIVERY', 'rm_labels_title') ?></h2>
<p class="hint"><?= $word('DELIVERY', 'rm_labels_intro') ?></p>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-accounts">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'account') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'status') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_labels_held') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_read_to') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_last_ok') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_last_error') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($accounts as $a): ?>
    <tr>
      <th scope="row"><?= $e((string) $a['account_no']) ?> <span class="muted"><?= $e((string) $a['name']) ?></span></th>
      <td data-label="<?= $word('DELIVERY', 'status') ?>"><?= $word('DELIVERY', 'rm_state_' . ((string) $a['state'] === 'active' ? 'active' : 'off')) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_labels_held') ?>"><?= $n((int) $a['labels']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_read_to') ?>"><?php if ($a['read_to'] === null): ?>-<?php else: ?><?= $day((string) $a['read_to']) ?><?php endif; ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_last_ok') ?>"><?php if ($a['last_ok_at'] === null): ?>-<?php else: ?><?= $when((string) $a['last_ok_at']) ?><?php endif; ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_last_error') ?>"><?= $e((string) ($a['last_error'] ?? '')) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($canUpload): ?>
<form class="record import delivery-labels" method="post" action="/ui/profit/delivery/labels" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <fieldset>
    <legend><?= $word('DELIVERY', 'rm_report') ?></legend>
    <label for="l-account"><?= $word('DELIVERY', 'rm_account') ?></label>
    <select id="l-account" name="account">
<?php foreach ($accounts as $a): ?>
      <option value="<?= $e((string) $a['id']) ?>"><?= $e((string) $a['account_no'] . ' - ' . (string) $a['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label for="l-file"><?= $word('DELIVERY', 'file') ?> <span class="hint"><?= $word('DELIVERY', 'rm_report_hint') ?></span></label>
    <input id="l-file" type="file" name="file" accept=".xls,application/vnd.ms-excel">
  </fieldset>
  <p class="actions"><button type="submit" class="btn"><?= $word('DELIVERY', 'rm_report_upload') ?></button></p>
</form>
<?php endif; ?>
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
    $before = $b['states']['before_start'];
    if (($b['pooled'] ?? null) !== null) {
        // a Royal Mail bill: its allocations (the money in no shop's profit, Hayati Vapes and held, counted with "not on an order" here; its page parts them)
        [$on, $onN] = [$b['pooled']['order']['p'], $b['pooled']['order']['n']];
        [$other, $otherN] = [$b['pooled']['shop']['p'] + $b['pooled']['outside']['p'], $b['pooled']['shop']['n'] + $b['pooled']['outside']['n']];
        $before = ['pence' => $b['pooled']['before_start']['p'], 'lines' => $b['pooled']['before_start']['n']];
    }
    $status = (string) $b['status'] === 'undone' && (string) ($b['ended_actor'] ?? '') === \CW\Profit\RoyalMailBills::NOT_STORED ? 'not_stored' : (string) $b['status'];
?>
    <tr>
      <th scope="row"><a href="<?= $e($b['href']) ?>"><?= $say('DELIVERY', 'bill_title', (string) $b['carrier'] === 'royal_mail' ? \CW\Ui\Words::DELIVERY['carrier_royal_mail'] : strtoupper((string) $b['carrier']), (string) $b['invoice_no']) ?></a> <span class="muted"><?= $e((string) $b['account']) ?></span></th>
      <td data-label="<?= $word('DELIVERY', 'shop') ?>"><?= $e((string) $b['shop']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'days') ?>"><?= $day($b['period_from']) ?> - <?= $day($b['period_to']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'net') ?>"><?= $e($gbp((int) $b['net_pence'])) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'on_orders') ?>"><?= $e($gbp($on)) ?> <span class="muted">(<?= $n($onN) ?>)</span></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'not_on_order') ?>"><?= $e($gbp($other)) ?> <span class="muted">(<?= $n($otherN) ?>)</span></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'before_start') ?>"><?= $e($gbp($before['pence'])) ?> <span class="muted">(<?= $n($before['lines']) ?>)</span></td>
      <td data-label="<?= $word('DELIVERY', 'uploaded_by') ?>"><?= $e((string) ($b['uploaded_name'] ?? $b['uploaded_actor'])) ?> <span class="muted"><?= $when($b['uploaded_at']) ?></span></td>
      <td data-label="<?= $word('DELIVERY', 'status') ?>"><?= $chip($tone[(string) $b['status']] ?? 'info', $status === 'not_stored' ? \CW\Ui\Words::DELIVERY['status_not_stored'] : ($statusWords[$status] ?? $status)) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
