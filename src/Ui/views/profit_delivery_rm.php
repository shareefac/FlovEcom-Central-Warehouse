<?php
// Profit › Delivery bills › one Royal Mail bill (0078; docs/decisions.md DL69): the bill in one sentence, its facts, where its money went (on
// orders per shop, a shop's "not on an order", the days' lines of labels not charged, Hayati Vapes on its own line, before CW start, held per
// reason: adding up to its net), its flat days against the labels (labels, billed by every live bill, priced, the day's line, open or closed,
// needs a look), its Special Delivery manifests (by weight or the average), its rows as printed (the reference in its known form only), and for
// the Delivery bills job: Replace with a corrected bill (page or pasted text), Undo this upload. On a phone each row is a card.
$b = $bill;
$gbp = static fn (int $p): string => \CW\Profit\DpdInvoiceFile::gbp($p);
$W = \CW\Ui\Words::DELIVERY;
$statusWords = ['live' => $W['status_live'], 'superseded' => $W['status_superseded'], 'undone' => $W['status_undone'], 'loading' => $W['status_loading']];
$tone = ['live' => 'done', 'superseded' => 'off', 'undone' => 'off', 'loading' => 'waiting'];
$status = $not_stored ? $W['status_not_stored'] : ($statusWords[(string) $b['status']] ?? (string) $b['status']);
$refText = static function (array $r): string {
    return match ((string) $r['ref_kind']) {
        'manifest' => 'CLICK AND DROP M' . $r['ref_no'] . ($r['sales_order'] !== null ? ' (' . $r['sales_order'] . ')' : ''),
        'sales_order' => 'ADJ' . $r['ref_no'] . 'RCN',
        'week' => 'Week ' . $r['ref_no'],
        'preadvice' => (string) $r['ref_no'],
        default => '',
    };
};
$groupText = static function (array $w) use ($W): string {
    $reason = $w['reason'] === null ? '' : ($W['rm_held_' . $w['reason']] ?? (string) $w['reason']);
    return match ($w['group']) {
        'party' => sprintf($W['rm_group_party'], $w['name']),
        'held' => sprintf($W['rm_group_held'], $reason),
        default => sprintf($W['rm_group_' . $w['group']], $w['name']),
    };
};
?>
<?= $head(\CW\Ui\Words::say('DELIVERY', 'bill_title', $W['carrier_royal_mail'], (string) $b['invoice_no']), intro: 'profit_delivery_bill',
    aside: $explain('delivery_bills', \CW\Ui\Words::MENU['profit_delivery']), back: ['/ui/profit/delivery', $W['back']]) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<p class="lede delivery-headline"><?= $e($headline) ?></p>
<?php if (!$adds_up && (string) $b['status'] === 'live'): ?>
<p class="error" role="alert"><?= $word('DELIVERY', 'not_adding_up') ?></p>
<?php endif; ?>

<div class="table-wrap">
<table class="stack rp-table delivery-facts">
  <tbody>
    <tr><th scope="row"><?= $word('DELIVERY', 'status') ?></th><td><?= $chip($tone[(string) $b['status']] ?? 'info', $status) ?><?php if ($b['ended_at'] !== null && !$not_stored): ?> <span class="muted"><?= $say('DELIVERY', 'ended', $status, (string) ($b['ended_name'] ?? $b['ended_actor']), \CW\Ui\Html::when((string) $b['ended_at'])) ?></span><?php endif; ?></td></tr>
<?php if ($replaced !== null): ?>
    <tr><th scope="row"><?= $word('DELIVERY', 'bill') ?></th><td><a href="<?= $u('/ui/profit/delivery/' . (int) $replaced['id']) ?>"><?= $say('DELIVERY', 'replaced', (string) $replaced['invoice_no'], \CW\Ui\Html::when((string) $replaced['uploaded_at'])) ?></a></td></tr>
<?php endif; ?>
<?php if ($replaced_by !== null): ?>
    <tr><th scope="row"><?= $word('DELIVERY', 'bill') ?></th><td><a href="<?= $u('/ui/profit/delivery/' . (int) $replaced_by['id']) ?>"><?= $say('DELIVERY', 'replaced_by', (string) $replaced_by['invoice_no']) ?></a></td></tr>
<?php endif; ?>
    <tr><th scope="row"><?= $word('DELIVERY', 'account') ?></th><td><?= $e((string) $b['account']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'days') ?></th><td><?= $day($b['period_from']) ?> - <?= $day($b['period_to']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'net') ?></th><td class="num"><?= $e($gbp((int) $b['net_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'vat') ?></th><td class="num"><?= $e($gbp((int) $b['vat_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'gross') ?></th><td class="num"><?= $e($gbp((int) $b['gross_pence'])) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'rm_rows_title') ?></th><td class="num"><?= $n($b['line_count']) ?></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'uploaded_by') ?></th><td><?= $e((string) ($b['uploaded_name'] ?? $b['uploaded_actor'])) ?> <span class="muted"><?= $when($b['uploaded_at']) ?></span></td></tr>
    <tr><th scope="row"><?= $word('DELIVERY', 'fingerprint') ?></th><td><code><?= $e(substr((string) $b['file_sha256'], 0, 16)) ?></code></td></tr>
  </tbody>
</table>
</div>

<h2><?= $word('DELIVERY', 'rm_where_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-where">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'state') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_allocations') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($where as $w): ?>
    <tr>
      <th scope="row"><?= $e($groupText($w)) ?></th>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_allocations') ?>"><?= $n($w['n']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp($w['pence'])) ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="rp-total">
      <th scope="row"><?= $word('DELIVERY', 'tie_total') ?> <span class="muted"><?= $word('DELIVERY', $adds_up ? 'adds_up' : 'not_adding_up') ?></span></th>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_allocations') ?>"></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp((int) $b['net_pence'])) ?></td>
    </tr>
  </tbody>
</table>
</div>

<?php if ($days !== []): ?>
<h2><?= $word('DELIVERY', 'rm_days_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-days">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'rm_day') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_product') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_labels') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_billed') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_priced') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_line') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'status') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($days as $d): ?>
    <tr>
      <th scope="row"><?= $day($d['day']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'rm_product') ?>"><?= $e($d['product']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_labels') ?>"><?= $n($d['labels']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_billed') ?>"><?= $n($d['billed']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_priced') ?>"><?= $n($d['priced']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_line') ?>"><?= $e($gbp($d['line_pence'])) ?> <span class="muted"><?= $e($d['difference'] > 0 ? sprintf($W['rm_not_charged'], number_format($d['difference'])) : ($d['difference'] < 0 ? sprintf($W['rm_no_label'], number_format(-$d['difference'])) : $W['rm_equal'])) ?></span></td>
      <td data-label="<?= $word('DELIVERY', 'status') ?>"><?= $d['look'] ? $chip('needs', $W['rm_look']) : ($d['closed'] ? $e($W['rm_closed']) : $e($W['rm_open'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($manifests !== []): ?>
<h2><?= $word('DELIVERY', 'rm_manifests_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-manifests">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'rm_manifest') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_billed') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_labels') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'cost') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_how') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($manifests as $m): ?>
    <tr>
      <th scope="row">M<?= $e($m['manifest']) ?></th>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_billed') ?>"><?= $n($m['qty']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_labels') ?>"><?= $n($m['labels']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp($m['net'])) ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_how') ?>"><?= $e(implode(', ', array_map(static fn (string $h): string => $W['rm_how_' . $h] ?? $h, array_filter(explode(', ', $m['how']))))) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2><?= $word('DELIVERY', 'rm_rows_title') ?></h2>
<div class="table-wrap">
<table class="stack rp-table delivery-rm-rows">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'rm_row') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'date') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_product') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'rm_qty') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'rm_ref') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'cost') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?= $n((int) $r['row_no']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'date') ?>"><?= $day((string) $r['service_date']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_product') ?>"><?= $e((string) $r['product_code']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'rm_qty') ?>"><?= $n((int) $r['qty']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'rm_ref') ?>"><?= $e($refText($r)) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'cost') ?>"><?= $e($gbp((int) $r['net_pence'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($canUpload): ?>
<h2><?= $word('DELIVERY', 'replace_title') ?></h2>
<form class="record delivery-replace" method="post" enctype="multipart/form-data" action="<?= $u('/ui/profit/delivery/' . (int) $b['id'] . '/replace') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <label for="r-file"><?= $word('DELIVERY', 'file') ?> <span class="hint"><?= $word('DELIVERY', 'rm_replace_hint') ?></span></label>
  <input id="r-file" type="file" name="file" accept=".html,.htm,text/html">
  <label for="r-text"><?= $word('DELIVERY', 'rm_text') ?></label>
  <textarea id="r-text" name="text" rows="3"></textarea>
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
