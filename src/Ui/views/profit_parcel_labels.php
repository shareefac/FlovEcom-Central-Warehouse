<?php
// Profit › Delivery bills › Parcel2Go (CW-1160; docs/decisions.md PGL11): the two accounts (switched on from which day or off, read up to when,
// their labels by state, their money by where it went) and the newest labels: P2G number, paid day, service, country, tracking number, price
// without VAT and where it went (its order, the main shop's "not on an order" with the reason, before CW start, waiting). The known limit is
// said (a refund paid back to a card is not seen: Q1). On a phone each row is a card.
$D = \CW\Ui\Words::DELIVERY;
$gbp = static fn (int $p): string => \CW\Profit\DpdInvoiceFile::gbp($p);
$stateWord = static fn (string $s): string => $D['p2g_state_' . $s] ?? $s;
$howWord = static fn (string $h): string => \CW\Profit\Parcel2Go::HOWS[$h] ?? $h;
?>
<?= $head($D['p2g_title'], back: ['/ui/profit/delivery', \CW\Ui\Words::MENU['profit_delivery']]) ?>
<p class="note" role="note"><?= $word('DELIVERY', 'p2g_card_refunds') ?></p>
<?php if ($canKey): ?>
<p><a class="btn" href="/ui/profit/delivery/parcel2go/key"><?= $word('DELIVERY', 'p2g_key_title') ?></a></p>
<?php endif; ?>
<div class="table-wrap">
<table class="stack rp-table p2g-accounts">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'p2g_account') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'status') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'p2g_read_at') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'p2g_labels') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'p2g_money') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($accounts as $a): ?>
    <tr>
      <th scope="row"><?= $e((string) $a['name']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'status') ?>"><?php if ($a['on']): ?><?= $say('DELIVERY', 'p2g_on_from', $day((string) $a['from'])) ?><?php else: ?><?= $word('DELIVERY', 'p2g_off') ?><?php endif; ?></td>
      <td data-label="<?= $word('DELIVERY', 'p2g_read_at') ?>"><?= $a['read_at'] === null ? '-' : $when((string) $a['read_at']) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'p2g_labels') ?>"><?= $n((int) $a['lines']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'p2g_money') ?>"><?php if ($a['money'] === []): ?>-<?php endif; ?><?php foreach ($a['money'] as $m): ?><span class="p2g-money"><?= $e($gbp((int) $m['pence'])) ?> <?= $e($howWord((string) $m['how'])) ?><?php if ($m['shop'] !== null && $m['dest'] !== 'order'): ?> (<?= $e((string) $m['shop']) ?>)<?php endif; ?>, <?= $n((int) $m['lines']) ?> <?= $word('DELIVERY', 'p2g_labels_short') ?></span><br><?php endforeach; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<h2><?= $word('DELIVERY', 'p2g_newest') ?></h2>
<?php if ($lines === []): ?>
<p class="muted"><?= $word('DELIVERY', 'p2g_none') ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table p2g-lines">
  <thead>
    <tr>
      <th scope="col"><?= $word('DELIVERY', 'p2g_number') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'p2g_account') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'p2g_paid') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'p2g_service') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'p2g_tracking') ?></th>
      <th scope="col" class="num"><?= $word('DELIVERY', 'p2g_net') ?></th>
      <th scope="col"><?= $word('DELIVERY', 'p2g_where') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($lines as $l): ?>
    <tr>
      <th scope="row">P2G<?= $e((string) $l['order_line_id']) ?></th>
      <td data-label="<?= $word('DELIVERY', 'p2g_account') ?>"><?= $e((string) $l['account']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'p2g_paid') ?>"><?= $l['paid_day'] === null ? '-' : $day((string) $l['paid_day']) ?></td>
      <td data-label="<?= $word('DELIVERY', 'p2g_service') ?>"><?= $e((string) ($l['service_slug'] ?? '-')) ?><?php if ($l['country_iso3'] !== null): ?> <span class="muted"><?= $e((string) $l['country_iso3']) ?></span><?php endif; ?></td>
      <td data-label="<?= $word('DELIVERY', 'p2g_tracking') ?>"><?= $e((string) ($l['tracking'] ?? '-')) ?></td>
      <td class="num" data-label="<?= $word('DELIVERY', 'p2g_net') ?>"><?= in_array($l['price_state'], ['priced'], true) || (int) $l['net'] !== 0 ? $e($gbp((int) $l['net'])) : '-' ?></td>
      <td data-label="<?= $word('DELIVERY', 'p2g_where') ?>"><?php if ($l['where'] === []): ?><?= $e($stateWord((string) $l['price_state'])) ?><?php if ($l['refuse_code'] !== null && $l['price_state'] === 'refused'): ?> <span class="muted"><?= $e(\CW\Profit\P2gInvoice::REFUSALS[(string) $l['refuse_code']] ?? (string) $l['refuse_code']) ?></span><?php endif; ?><?php endif; ?><?php foreach ($l['where'] as $w): ?><?php if ($w['dest'] === 'order'): ?><a href="<?= $u('/ui/profit/orders/view', ['store' => (string) $w['channel_id'], 'ref' => (string) $w['order_ref']]) ?>"><?= $e((string) $w['shop']) ?> <?= $e((string) $w['order_ref']) ?></a><?php else: ?><?= $e($howWord((string) $w['how'])) ?><?php if ($w['shop'] !== null): ?> (<?= $e((string) $w['shop']) ?>)<?php endif; ?><?php endif; ?><br><?php endforeach; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
