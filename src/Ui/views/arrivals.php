<?php
// Goods In › Arriving, a simple list (the approved redesign of 30 Oct 2026, docs/decisions.md RD-B6; before it W9.8): the goods of final
// supplier invoices on their way, grouped late, being received (its receipt started), on the way; each group a heading with its coloured
// dot and its count over a white table card, the status a small pill. Five columns and the row's action: the invoice (its supplier's number
// under it), the supplier, where it is, final since, the units on their way (how many are sold before they arrive under them). A row opens
// its invoice; its "Receive" (or "Open its receipt") is in its last cell (td.c-next, above the row's link). A supplier's link (?supplier=)
// still narrows the list.
?>
<?= $head(\CW\Ui\Words::SEGMENT['arriving'], intro: 'arriving') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ARRIVALS['none'], \CW\Ui\Words::ARRIVALS['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('ARRIVALS', 'group_one') ?><?php else: ?><?= $say('ARRIVALS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list arrivals">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('ARRIVALS', 'invoice') ?></th>
        <th scope="col"><?= $word('ARRIVALS', 'supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('ARRIVALS', 'state') ?></th>
        <th scope="col"><?= $word('ARRIVALS', 'since') ?></th>
        <th scope="col" class="num"><?= $word('ARRIVALS', 'units_on_way') ?></th>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('ARRIVALS', 'receive') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['external_ref'] ?? '') ?></span></th>
        <td data-label="<?= $word('ARRIVALS', 'supplier') ?>"><?= $e($r['supplier_name']) ?></td>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $e($g['name']) ?></span></td>
        <td data-label="<?= $word('ARRIVALS', 'since') ?>"<?php if ($r['late']): ?> class="late"<?php endif; ?>><?= $when($r['posted_at']) ?><span class="o-sub"><?= $say('ARRIVALS', 'hours', $r['hours']) ?></span></td>
<?php // Units on their way, and under them how many of them are sold before they arrive (RD-B6: the old sixth column). ?>
        <td class="num" data-label="<?= $word('ARRIVALS', 'units_on_way') ?>"><?= $n($r['units']) ?><?php if ((int) $r['sellable_units'] > 0): ?><span class="o-sub"><?= $say('ARRIVALS', 'sold_line', \CW\Ui\Html::int($r['sellable_units'])) ?></span><?php endif; ?></td>
        <td class="c-next"><?php if ($r['receipt_href'] !== null): ?><a class="btn sm" href="<?= $u($r['receipt_href']) ?>"><?= $word('ARRIVALS', 'open_receipt') ?></a><?php elseif ($canReceive): ?>
          <form class="inline" method="post" action="<?= $u('/ui/receiving/arriving/' . $r['invoice_id'] . '/receive') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn sm"><?= $word('ARRIVALS', 'receive') ?></button>
          </form><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('ARRIVALS', 'more', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
